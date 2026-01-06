import asyncio
import multiprocessing
import uuid
import time
import hmac
import hashlib
from collections import defaultdict
from threading import Lock
from fastapi import FastAPI, HTTPException, Query, Request
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field, field_validator
from typing import Optional
from loguru import logger
import requests
import uvicorn

from config import *
from TON.database import create_connection, create_cashier, get_user_cashiers, get_cashier_by_id, update_cashier_status, update_cashier_settings, delete_cashier, validate_decimal_places
from contextlib import asynccontextmanager

_rate_limit_storage = defaultdict(list)
_rate_limit_lock = Lock()

def check_rate_limit(identifier: str, max_requests: int = 60, window_seconds: int = 60) -> bool:
    current_time = time.time()
    
    with _rate_limit_lock:
        _rate_limit_storage[identifier] = [
            timestamp for timestamp in _rate_limit_storage[identifier]
            if current_time - timestamp < window_seconds
        ]
        
        if len(_rate_limit_storage[identifier]) >= max_requests:
            return False
        
        _rate_limit_storage[identifier].append(current_time)
        return True

def get_client_ip(request: Request) -> str:
    if request.client:
        return request.client.host
    return "unknown"

@asynccontextmanager
async def lifespan(app: FastAPI):
    try:
        yield
    except (asyncio.CancelledError, GeneratorExit):
        pass

app = FastAPI(lifespan=lifespan)

origins = [
    SITE_URL,
    "http://localhost",
    "http://127.0.0.1"
]

app.add_middleware(
    CORSMiddleware,
    allow_origins=origins,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

class PaymentRequest(BaseModel):
    cashier_id: int = Field(..., description="Cashier id")
    amount: float = Field(..., gt=0, description="Amount of TON or Jetton")
    wallet: str = Field(..., description="User wallet")
    currency: Optional[str] = Field(None, description="Currency: ton or jetton (optional, will be taken from cashier)")
    payload: Optional[str] = Field(None, description="Custom payload to send in webhook")
    transaction_uuid: Optional[str] = Field(None, description="Transaction UUID (optional, for restoring existing payment)")
    return_url: Optional[str] = Field(None, description="URL to redirect after successful payment (optional)")
    
    @field_validator('amount')
    @classmethod
    def validate_amount_decimal_places(cls, v):
        if not validate_decimal_places(v, DECIMAL_PLACES):
            raise ValueError(f'Amount must have no more than {DECIMAL_PLACES} decimal places')
        return v

def send_callback(callback_url: str, payment_id: int, status: str, currency: str, payload: Optional[str] = None, webhook_secret: Optional[str] = None):
    try:
        callback_payload = {
            "payment_id": payment_id,
            "status": status,
            "currency": currency
        }
        if payload:
            callback_payload["payload"] = payload
        
        headers = {'Content-Type': 'application/json'}
        if webhook_secret:
            import json
            payload_json = json.dumps(callback_payload, sort_keys=True, separators=(',', ':'))
            signature = hmac.new(
                webhook_secret.encode('utf-8'),
                payload_json.encode('utf-8'),
                hashlib.sha256
            ).hexdigest()
            headers['X-Webhook-Signature'] = f'sha256={signature}'
        
        response = requests.post(callback_url, json=callback_payload, headers=headers, timeout=10)
        logger.info(f"Callback send: {callback_payload} -> {callback_url} (status: {response.status_code})")
    except Exception as e:
        logger.error(f"Error in callback for {payment_id}: {e}")

@app.post("/create_payment")
def create_payment(req: PaymentRequest, request: Request):
    from TON.database import create_connection, get_cashier_by_id
    from config import WALLET_ADDRESS_NO_BOUNCE
    
    client_ip = get_client_ip(request)
    if not check_rate_limit(f"create_payment:{client_ip}", max_requests=30, window_seconds=60):
        raise HTTPException(status_code=429, detail="Too many requests. Please try again later.")
    
    try:
        cashier = get_cashier_by_id(req.cashier_id, None)
        if not cashier:
            raise HTTPException(status_code=404, detail="Cashier not found")
        
        if cashier['status'] != 'active':
            raise HTTPException(status_code=400, detail="Cashier is not active")
        
        currency = req.currency.lower() if req.currency else cashier['currency'].lower()
        if currency != cashier['currency'].lower():
            raise HTTPException(status_code=400, detail=f"Currency mismatch. Cashier currency is {cashier['currency']}")
        
        if req.amount < cashier['min_amount']:
            raise HTTPException(status_code=400, detail=f"Amount is less than minimum: {cashier['min_amount']}")
        if cashier['max_amount'] and req.amount > cashier['max_amount']:
            raise HTTPException(status_code=400, detail=f"Amount exceeds maximum: {cashier['max_amount']}")
        
        table = "TONDeposit" if currency == "ton" else "JETTONDeposit"
        user_id = cashier['user_id']
        callback_url = cashier['webhook_url']
        
        if not callback_url:
            raise HTTPException(status_code=400, detail="Cashier webhook_url is not set")

        conn = create_connection()
        if not conn:
            raise HTTPException(status_code=500, detail="Database connection error")

        cursor = conn.cursor()
        
        if req.transaction_uuid:
            cursor.execute(f"""
                SELECT id, wallet, price, status, cashier_id, callback_url, payload, time_recorded
                FROM {table}
                WHERE transaction_uuid = %s
            """, (req.transaction_uuid,))
            existing_payment = cursor.fetchone()
            
            if existing_payment:
                final_statuses = ['confirmed', 'success', 'completed', 'failed', 'error', 'expired']
                if existing_payment['status'].lower() in final_statuses:
                    raise HTTPException(
                        status_code=400, 
                        detail=f"Cannot restore payment with final status: {existing_payment['status']}"
                    )
                
                epsilon = 0.0001
                price_match = abs(float(existing_payment['price']) - req.amount) < epsilon
                
                if (int(existing_payment['cashier_id']) == int(req.cashier_id) and 
                    existing_payment['wallet'] == req.wallet and
                    price_match):
                    logger.info(f"Restoring existing payment {existing_payment['id']} with UUID {req.transaction_uuid}")
                    
                    import time
                    time_recorded = existing_payment.get("time_recorded")
                    time_recorded_timestamp = None
                    if time_recorded:
                        if isinstance(time_recorded, str):
                            from datetime import datetime
                            dt = datetime.strptime(time_recorded, '%Y-%m-%d %H:%M:%S')
                            time_recorded_timestamp = int(dt.timestamp() * 1000)
                        else:
                            time_recorded_timestamp = int(time_recorded.timestamp() * 1000)
                    
                    return {
                        "status": "ok",
                        "payment_id": existing_payment['id'],
                        "transaction_uuid": req.transaction_uuid,
                        "currency": currency,
                        "amount": float(existing_payment['price']),
                        "wallet_to_send": WALLET_ADDRESS_NO_BOUNCE,
                        "message": "Existing payment restored",
                        "time_recorded": time_recorded_timestamp,
                        "return_url": existing_payment.get('return_url')
                    }
                else:
                    raise HTTPException(status_code=400, detail="Transaction UUID exists but parameters don't match")
        
        transaction_uuid = str(uuid.uuid4())

        amount_float = float(req.amount)
        
        return_url = req.return_url if req.return_url else None
        
        cursor.execute(f"""
            INSERT INTO {table} (user_id, cashier_id, callback_url, wallet, price, status, payload, transaction_uuid, return_url)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
        """, (user_id, req.cashier_id, callback_url, req.wallet, amount_float, "nohash", req.payload, transaction_uuid, return_url))
        conn.commit()

        payment_id = cursor.lastrowid
        
        cursor.execute(f"SELECT time_recorded FROM {table} WHERE id = %s", (payment_id,))
        payment_time = cursor.fetchone()
        
        time_recorded_timestamp = None
        if payment_time and payment_time.get('time_recorded'):
            import time
            time_recorded = payment_time['time_recorded']
            if isinstance(time_recorded, str):
                from datetime import datetime
                dt = datetime.strptime(time_recorded, '%Y-%m-%d %H:%M:%S')
                time_recorded_timestamp = int(dt.timestamp() * 1000)
            else:
                time_recorded_timestamp = int(time_recorded.timestamp() * 1000)

        logger.success(f"Payment {payment_id} ({currency.upper()}) created for cashier {req.cashier_id} with UUID {transaction_uuid}")

        return {
            "status": "ok",
            "payment_id": payment_id,
            "transaction_uuid": transaction_uuid,
            "currency": currency,
            "amount": amount_float,
            "wallet_to_send": WALLET_ADDRESS_NO_BOUNCE,
            "message": "Send the exact amount to the specified address. Once confirmed, the application will be automatically updated.",
            "time_recorded": time_recorded_timestamp,
            "return_url": return_url
        }

    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Error in creating payment: {e}")
        raise HTTPException(status_code=500, detail=str(e))
    finally:
        if 'conn' in locals() and conn:
            conn.close()

@app.get("/payment_by_uuid/{transaction_uuid}")
def get_payment_by_uuid(transaction_uuid: str):
    from TON.database import create_connection
    
    try:
        conn = create_connection()
        if not conn:
            raise HTTPException(status_code=500, detail="Database connection error")
        
        cursor = conn.cursor()
        
        for table_name, currency_name in [("TONDeposit", "ton"), ("JETTONDeposit", "jetton")]:
            cursor.execute(f"""
                SELECT id, user_id, cashier_id, callback_url, wallet, price, status, payload, time_recorded, return_url
                FROM {table_name}
                WHERE transaction_uuid = %s
            """, (transaction_uuid,))
            payment = cursor.fetchone()
            
            if payment:
                conn.close()
                from config import WALLET_ADDRESS_NO_BOUNCE
                import time
                time_recorded = payment.get("time_recorded")
                time_recorded_timestamp = None
                if time_recorded:
                    if isinstance(time_recorded, str):
                        from datetime import datetime
                        dt = datetime.strptime(time_recorded, '%Y-%m-%d %H:%M:%S')
                        time_recorded_timestamp = int(dt.timestamp() * 1000)
                    else:
                        time_recorded_timestamp = int(time_recorded.timestamp() * 1000)
                
                return {
                    "status": "ok",
                    "payment_id": payment["id"],
                    "transaction_uuid": transaction_uuid,
                    "currency": currency_name,
                    "amount": float(payment["price"]),
                    "wallet": payment["wallet"],
                    "wallet_to_send": WALLET_ADDRESS_NO_BOUNCE,
                    "payment_status": payment["status"],
                    "cashier_id": payment["cashier_id"],
                    "time_recorded": time_recorded_timestamp,
                    "return_url": payment.get("return_url")
                }
        
        conn.close()
        raise HTTPException(status_code=404, detail="Payment not found")
        
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Error getting payment by UUID: {e}")
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/payment_status/{currency}/{payment_id}")
def get_payment_status(currency: str, payment_id: int):
    from TON.database import create_connection
    from datetime import datetime, timedelta
    import time
    table = "TONDeposit" if currency.lower() == "ton" else "JETTONDeposit"

    try:
        conn = create_connection()
        if not conn:
            raise HTTPException(status_code=500, detail="Database connection error")

        cursor = conn.cursor()

        cursor.execute(f"SELECT id, user_id, wallet, price, status, time_recorded, return_url FROM {table} WHERE id = %s", (payment_id,))
        payment = cursor.fetchone()

        if not payment:
            raise HTTPException(status_code=404, detail="Payment not found")

        time_recorded = payment.get("time_recorded")
        time_recorded_datetime = None
        if time_recorded:
            if isinstance(time_recorded, str):
                time_recorded_datetime = datetime.strptime(time_recorded, '%Y-%m-%d %H:%M:%S')
            else:
                time_recorded_datetime = time_recorded

        current_status = payment.get("status")
        if time_recorded_datetime and current_status in ('pending', 'nohash'):
            time_elapsed = datetime.now() - time_recorded_datetime
            if time_elapsed.total_seconds() > PAYMENT_TIMEOUT_SECONDS:
                cursor.execute(f"UPDATE {table} SET status = 'expired' WHERE id = %s", (payment_id,))
                conn.commit()
                logger.info(f"Payment {payment_id} expired (elapsed: {time_elapsed.total_seconds()}s)")
                current_status = 'expired'

        time_recorded_timestamp = None
        if time_recorded_datetime:
            time_recorded_timestamp = int(time_recorded_datetime.timestamp() * 1000)

        return {
            "status": "ok",
            "payment_id": payment["id"],
            "user_id": payment["user_id"],
            "wallet": payment["wallet"],
            "amount": payment["price"],
            "currency": currency.lower(),
            "payment_status": current_status,
            "time_recorded": time_recorded_timestamp,
            "return_url": payment.get("return_url")
        }

    except Exception as e:
        logger.error(f"Error in getting payment status: {e}")
        raise HTTPException(status_code=500, detail=str(e))
    finally:
        if 'conn' in locals() and conn:
            conn.close()

def verify_api_token(user_id: int, api_token: str, request: Optional[Request] = None) -> bool:
    try:
        identifier = f"api_token_{user_id}"
        if request:
            client_ip = get_client_ip(request)
            identifier = f"api_token_{user_id}_{client_ip}"
        
        if not check_rate_limit(identifier, max_requests=60, window_seconds=60):
            logger.warning(f"Rate limit exceeded for API token verification: {identifier}")
            return False
        
        conn = create_connection()
        if not conn:
            return False
        
        cursor = conn.cursor()
        cursor.execute("SELECT id FROM Users WHERE id = %s AND api_token = %s", (user_id, api_token))
        result = cursor.fetchone()
        conn.close()
        
        return result is not None
    except Exception as e:
        logger.error(f"Error verifying API token: {e}")
        return False

class CashierRequest(BaseModel):
    user_id: int = Field(..., description="User id")
    api_token: str = Field(..., description="User API token for authentication")
    name: str = Field(..., min_length=1, max_length=255, description="Cashier name")
    description: Optional[str] = Field(None, description="Cashier description")
    category: Optional[str] = Field(None, description="Cashier category")
    currency: str = Field("ton", description="Default currency")
    min_amount: Optional[float] = Field(0.01, ge=0.01, description="Minimum payment amount")
    max_amount: Optional[float] = Field(None, description="Maximum payment amount")
    webhook_url: Optional[str] = Field(None, description="Webhook URL for notifications")
    jetton_address: Optional[str] = Field(None, description="Jetton contract address (required if currency is jetton)")
    
    @field_validator('min_amount', 'max_amount')
    @classmethod
    def validate_decimal_places(cls, v):
        if v is not None and not validate_decimal_places(v, DECIMAL_PLACES):
            raise ValueError(f'Value must have no more than {DECIMAL_PLACES} decimal places')
        return v

@app.post("/create_cashier")
def create_cashier_endpoint(req: CashierRequest, request: Request = None):
    if not verify_api_token(req.user_id, req.api_token, request):
        raise HTTPException(status_code=401, detail="Invalid API token")
    
    try:
        if req.currency.lower() == 'jetton' and not req.jetton_address:
            raise HTTPException(status_code=400, detail="Jetton address is required when currency is jetton")
        
        if req.jetton_address:
            jetton_address_clean = req.jetton_address.strip()
            if len(jetton_address_clean) < 20 or len(jetton_address_clean) > 100:
                raise HTTPException(status_code=400, detail="Invalid jetton address format: length must be between 20 and 100 characters")
            import re
            if not re.match(r'^[A-Za-z0-9_-]+$', jetton_address_clean) and not re.match(r'^0:[0-9a-fA-F]+$', jetton_address_clean):
                raise HTTPException(status_code=400, detail="Invalid jetton address format: contains invalid characters")
        
        result = create_cashier(
            user_id=req.user_id,
            name=req.name,
            description=req.description,
            category=req.category,
            currency=req.currency.lower(),
            min_amount=req.min_amount,
            max_amount=req.max_amount,
            webhook_url=req.webhook_url,
            jetton_address=req.jetton_address
        )
        
        if result['success']:
            cashier = result['cashier']
            logger.success(f"Cashier {cashier['id']} created for user {req.user_id}")
            return {
                "status": "ok",
                "cashier_id": cashier['id'],
                "cashier": cashier
            }
        else:
            raise HTTPException(status_code=500, detail=result.get('error', 'Unknown error'))
            
    except Exception as e:
        logger.error(f"Error creating cashier: {e}")
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/cashiers/{user_id}")
def get_cashiers(user_id: int, api_token: Optional[str] = Query(None, description="User API token"), request: Request = None):
    if not api_token:
        raise HTTPException(status_code=401, detail="API token required")
    
    if not verify_api_token(user_id, api_token, request):
        raise HTTPException(status_code=403, detail="Access denied: Invalid API token or user mismatch")
    
    try:
        cashiers = get_user_cashiers(user_id)
        return {
            "status": "ok",
            "cashiers": cashiers if cashiers else []
        }
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Error getting cashiers: {e}")
        import traceback
        logger.error(traceback.format_exc())
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/cashier/{cashier_id}")
def get_cashier(cashier_id: int, user_id: Optional[int] = Query(None, description="User ID for access verification"), api_token: Optional[str] = Query(None, description="User API token"), request: Request = None):
    if user_id and api_token:
        if not verify_api_token(user_id, api_token, request):
            raise HTTPException(status_code=401, detail="Invalid API token")
    
    try:
        cashier = get_cashier_by_id(cashier_id, user_id)
        if not cashier:
            logger.warning(f"Cashier {cashier_id} not found for user {user_id}")
            raise HTTPException(status_code=404, detail="Cashier not found")
        
        if user_id:
            cashier_user_id = int(cashier.get('user_id', 0))
            if cashier_user_id != user_id:
                logger.warning(f"Access denied: cashier {cashier_id} belongs to {cashier_user_id}, requested by {user_id}")
                raise HTTPException(status_code=403, detail="Access denied")
        
        return {
            "status": "ok",
            "cashier": cashier
        }
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Error getting cashier: {e}")
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/cashier/{cashier_id}/status")
def update_cashier_status_endpoint(
    cashier_id: int, 
    user_id: int = Query(..., description="User ID"),
    api_token: str = Query(..., description="User API token"),
    status: str = Query(..., description="Status: active or inactive"),
    request: Request = None
):
    if not verify_api_token(user_id, api_token, request):
        raise HTTPException(status_code=401, detail="Invalid API token")
    
    if status not in ['active', 'inactive']:
        raise HTTPException(status_code=400, detail="Status must be 'active' or 'inactive'")
    
    try:
        success = update_cashier_status(cashier_id, user_id, status)
        if success:
            return {"status": "ok", "message": f"Cashier status updated to {status}"}
        else:
            raise HTTPException(status_code=404, detail="Cashier not found or access denied")
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Error updating cashier status: {e}")
        raise HTTPException(status_code=500, detail=str(e))

class CashierUpdateRequest(BaseModel):
    user_id: int = Field(..., description="User id")
    api_token: str = Field(..., description="User API token for authentication")
    name: Optional[str] = Field(None, description="Cashier name")
    description: Optional[str] = Field(None, description="Cashier description")
    category: Optional[str] = Field(None, description="Cashier category")
    min_amount: Optional[float] = Field(None, ge=0.01, description="Minimum payment amount")
    max_amount: Optional[float] = Field(None, description="Maximum payment amount")
    webhook_url: Optional[str] = Field(None, description="Webhook URL for notifications")
    
    @field_validator('min_amount', 'max_amount')
    @classmethod
    def validate_decimal_places(cls, v):
        if v is not None and not validate_decimal_places(v, DECIMAL_PLACES):
            raise ValueError(f'Value must have no more than {DECIMAL_PLACES} decimal places')
        return v

@app.put("/cashier/{cashier_id}")
def update_cashier_settings_endpoint(cashier_id: int, req: CashierUpdateRequest, request: Request = None):
    if not verify_api_token(req.user_id, req.api_token, request):
        raise HTTPException(status_code=401, detail="Invalid API token")
    
    try:
        result = update_cashier_settings(
            cashier_id=cashier_id,
            user_id=req.user_id,
            name=req.name,
            description=req.description,
            category=req.category,
            min_amount=req.min_amount,
            max_amount=req.max_amount,
            webhook_url=req.webhook_url
        )
        
        if result.get('success'):
            cashier = get_cashier_by_id(cashier_id, req.user_id)
            if cashier:
                return {
                    "status": "ok",
                    "cashier": cashier
                }
            else:
                raise HTTPException(status_code=404, detail="Cashier not found")
        else:
            error_msg = result.get('error', 'Unknown error')
            if 'not found' in error_msg.lower() or 'access denied' in error_msg.lower():
                raise HTTPException(status_code=404, detail=error_msg)
            else:
                raise HTTPException(status_code=500, detail=error_msg)
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Error updating cashier settings: {e}")
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/health")
def health_check():
    from TON.database import create_connection
    import time
    
    start_time = time.time()
    db_status = "ok"
    db_latency = 0
    
    try:
        conn = create_connection()
        if conn:
            cursor = conn.cursor()
            cursor.execute("SELECT 1")
            cursor.fetchone()
            cursor.close()
            conn.close()
            db_latency = round((time.time() - start_time) * 1000, 2)
        else:
            db_status = "error"
    except Exception as e:
        db_status = "error"
        logger.error(f"Database health check failed: {e}")
    
    return {
        "status": "ok",
        "api": "operational",
        "database": {
            "status": db_status,
            "latency_ms": db_latency
        },
        "timestamp": time.time()
    }

async def payment_starter(shutdown_event=None):
    ssl_keyfile = "/etc/letsencrypt/live/pay.whaile.ru/privkey.pem"
    ssl_certfile = "/etc/letsencrypt/live/pay.whaile.ru/fullchain.pem"
    
    import logging
    import sys
    
    logging.getLogger("uvicorn.lifespan").setLevel(logging.CRITICAL)
    logging.getLogger("starlette.routing").setLevel(logging.CRITICAL)
    logging.getLogger("uvicorn.error").setLevel(logging.CRITICAL)
    logging.getLogger("uvicorn.access").setLevel(logging.CRITICAL)
    logging.getLogger("uvicorn").setLevel(logging.CRITICAL)
    
    original_stderr = sys.stderr
    original_excepthook = sys.excepthook
    
    def filtered_excepthook(exc_type, exc_value, exc_traceback):
        if exc_type is asyncio.CancelledError:
            return
        original_excepthook(exc_type, exc_value, exc_traceback)
    
    sys.excepthook = filtered_excepthook
    
    config = uvicorn.Config(
        app,
        host="0.0.0.0",
        port=3000,
        ssl_keyfile=ssl_keyfile,
        ssl_certfile=ssl_certfile,
        log_level='error',
        log_config=None,
        access_log=False
    )
    server = uvicorn.Server(config)
    
    monitor_task = None
    try:
        async def monitor_shutdown():
            if shutdown_event:
                while not shutdown_event.is_set():
                    await asyncio.sleep(0.5)
                logger.info("Получен сигнал завершения, останавливаем payment сервер...")
                try:
                    server.should_exit = True
                except Exception:
                    pass
        
        monitor_task = asyncio.create_task(monitor_shutdown())
        
        logger.success("FastAPI payment сервер запущен на https://pay.whaile.ru:3000")
        
        await server.serve()
    except asyncio.CancelledError:
        logger.info("Payment server cancelled, shutting down...")
    except Exception as e:
        if shutdown_event is None or not shutdown_event.is_set():
            logger.error(f"Error in payment server: {e}")
            raise
    finally:
        if monitor_task and not monitor_task.done():
            monitor_task.cancel()
            try:
                await monitor_task
            except asyncio.CancelledError:
                pass