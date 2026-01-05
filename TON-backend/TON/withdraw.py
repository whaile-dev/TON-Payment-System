import asyncio
import time
from collections import defaultdict
from threading import Lock
from fastapi import FastAPI, HTTPException, Request
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field, field_validator
from loguru import logger
from pytoniq_core import Address, Cell, begin_cell
from tonutils.client.toncenter import ToncenterV2Client
from tonutils.wallet import WalletV5R1
import uvicorn
from contextlib import asynccontextmanager
import logging
import sys
from typing import Optional

from config import api_key, toncenter_api_key, seed_phrase, DECIMAL_PLACES, API_BASE
from TON.database import create_connection, get_cashier_by_id, validate_decimal_places

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
    "https://pay.whaile.ru",
    "https://whaile.ru",
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


class WithdrawRequest(BaseModel):
    cashier_id: int = Field(..., description="Cashier ID")
    amount: float = Field(..., gt=0, description="Amount to withdraw")
    wallet: str = Field(..., description="Destination wallet address")
    api_token: str = Field(..., description="User API token")
    
    @field_validator('amount')
    @classmethod
    def validate_amount_decimal_places(cls, v):
        if not validate_decimal_places(v, DECIMAL_PLACES):
            raise ValueError(f'Amount must have no more than {DECIMAL_PLACES} decimal places')
        return v

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

async def estimate_transfer_fee(to: str, amount: float, seed_phrase: str) -> float:
    try:
        try:
            address_obj = Address(to)
            address = address_obj.to_str(is_user_friendly=True, is_bounceable=False)
        except Exception as e:
            logger.error(f"Неверный адрес получателя для оценки комиссии: {e}")
            return None
        
        from config import toncenter_api_key
        client = ToncenterV2Client(api_key=toncenter_api_key, is_testnet=False)
        wallet, _, _, _ = WalletV5R1.from_mnemonic(client, seed_phrase)
        
        wallet_address = wallet.address.to_str(is_user_friendly=False, is_bounceable=False)
        
        import aiohttp
        
        async with aiohttp.ClientSession() as session:
            estimate_url = "https://toncenter.com/api/v2/estimateFee"
            
            try:
                amount_nano = int(amount * 1e9)
                body_cell = (begin_cell()
                    .store_uint(0, 32)
                    .store_uint(0, 64)
                    .store_coins(amount_nano)
                    .store_address(Address(address))
                    .store_bit(0)
                    .store_bit(0)
                    .end_cell())
                
                import base64
                body_boc_bytes = body_cell.to_boc()
                body_boc_base64 = base64.b64encode(body_boc_bytes).decode('utf-8')
            except Exception as e:
                body_boc_base64 = None
            
            payload = {
                'address': wallet_address,
                'body': body_boc_base64 if body_boc_base64 else '',
                'init_code': '',
                'init_data': ''
            }
            
            headers = {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
            if toncenter_api_key:
                headers['X-API-Key'] = toncenter_api_key
            
            try:
                async with session.post(estimate_url, json=payload, headers=headers, timeout=aiohttp.ClientTimeout(total=10)) as resp:
                    if resp.status == 200:
                        data = await resp.json()
                        
                        if data.get('ok'):
                            result = data.get('result', {})
                            
                            total_fee_nano = None
                            if isinstance(result, dict):
                                if 'total_fee' in result:
                                    total_fee_nano = int(result['total_fee'])
                                elif 'source_fees' in result and isinstance(result['source_fees'], dict):
                                    if 'total' in result['source_fees']:
                                        total_fee_nano = int(result['source_fees']['total'])
                            
                            source_fees = result.get('source_fees', {}) if isinstance(result, dict) else data.get('source_fees', {})
                            
                            if total_fee_nano is None:
                                
                                if isinstance(source_fees, dict):
                                    total_fee_nano = (
                                        int(source_fees.get('storage_fee', 0)) +
                                        int(source_fees.get('in_fwd_fee', 0)) +
                                        int(source_fees.get('fwd_fee', 0)) +
                                        int(source_fees.get('gas_fee', 0))
                                    )
                                    
                                    
                                    dest_fees = result.get('destination_fees', []) if isinstance(result, dict) else data.get('destination_fees', [])
                                    if dest_fees:
                                        for dest_fee in dest_fees:
                                            if isinstance(dest_fee, dict):
                                                dest_fee_total = (
                                                    int(dest_fee.get('storage_fee', 0)) +
                                                    int(dest_fee.get('in_fwd_fee', 0)) +
                                                    int(dest_fee.get('fwd_fee', 0)) +
                                                    int(dest_fee.get('gas_fee', 0))
                                                )
                                                total_fee_nano += dest_fee_total
                                
                            if total_fee_nano is not None and total_fee_nano > 0:
                                api_fee_ton = total_fee_nano / 1e9
                                
                                computation_fees_nano = 0
                                action_fees_nano = 0
                                forward_fees_nano = 0
                                
                                if isinstance(source_fees, dict):
                                    computation_fees_nano = int(source_fees.get('computation_fee', 0))
                                    action_fees_nano = int(source_fees.get('action_fee', 0))
                                    forward_fees_nano = int(source_fees.get('fwd_fee', 0))
                                
                                if isinstance(result, dict):
                                    compute_phase = result.get('compute_phase', {})
                                    if isinstance(compute_phase, dict):
                                        if computation_fees_nano == 0:
                                            computation_fees_nano = int(compute_phase.get('gas_fees', 0))
                                        if computation_fees_nano == 0:
                                            computation_fees_nano = int(compute_phase.get('gas_used', 0))
                                    
                                    action_phase = result.get('action_phase', {})
                                    if isinstance(action_phase, dict):
                                        if action_fees_nano == 0:
                                            action_fees_nano = int(action_phase.get('total_action_fees', 0))
                                        if forward_fees_nano == 0:
                                            forward_fees_nano = int(action_phase.get('total_fwd_fees', 0))
                                    
                                    storage_phase = result.get('storage_phase', {})
                                    if isinstance(storage_phase, dict):
                                        storage_fees_from_phase = int(storage_phase.get('storage_fees_collected', 0))
                                
                                additional_forward_fees = 0
                                if forward_fees_nano > 0:
                                    in_fwd_fee_nano = int(source_fees.get('in_fwd_fee', 0)) if isinstance(source_fees, dict) else 0
                                    if forward_fees_nano > in_fwd_fee_nano * 1.5:
                                        additional_forward_fees = forward_fees_nano - in_fwd_fee_nano
                                
                                total_complete_fee_nano = total_fee_nano + computation_fees_nano + action_fees_nano + additional_forward_fees
                                complete_fee_ton = total_complete_fee_nano / 1e9
                                
                                if complete_fee_ton < 0.001:
                                    if api_fee_ton > 0:
                                        estimated_multiplier = 5.5
                                        fee_ton = api_fee_ton * estimated_multiplier
                                    else:
                                        fee_ton = 0.0055
                                elif api_fee_ton < 0.001:
                                    estimated_multiplier = 6.0
                                    fee_ton = api_fee_ton * estimated_multiplier
                                else:
                                    fee_ton = complete_fee_ton * 1.15
                                
                                fee_ton = max(fee_ton, 0.0025 if amount >= 0.01 else 0.002)
                                
                                fee_ton = min(fee_ton, 0.011)
                                
                                import math
                                fee_ton = math.floor(fee_ton * 1000 + 0.5) / 1000
                                fee_ton += 0.0002
                                
                                return fee_ton
                            else:
                                pass
                        else:
                            pass
                    else:
                        pass
            except asyncio.TimeoutError:
                pass
            except Exception as e:
                pass
        
        estimated_fee = 0.0055
        estimated_fee += 0.0002
        return estimated_fee
        
    except Exception as e:
        logger.error(f"Ошибка при оценке комиссии: {e}", exc_info=True)
        fallback_fee = 0.0055
        fallback_fee += 0.0002
        return fallback_fee

async def perform_ton_transfer(to: str, amount: float, api_key: str, seed_phrase: str):
    try:
        try:
            address_obj = Address(to)
            address = address_obj.to_str(is_user_friendly=True, is_bounceable=False)
        except Exception as e:
            logger.error(f"Неверный адрес получателя: {e}")
            return None
        
        if amount <= 0:
            logger.error(f"Неверная сумма для перевода: {amount}")
            return None
        
        if amount < 0.001:
            logger.error(f"Сумма слишком мала для перевода: {amount}")
            return None
        
        client = ToncenterV2Client(api_key=api_key, is_testnet=False)
        wallet, _, _, _ = WalletV5R1.from_mnemonic(client, seed_phrase)
        
        tx_hash = await wallet.transfer(
            destination=address,
            amount=amount,
            body=f"Transfer of {amount} TON"
        )
        
        if tx_hash:
            logger.success(f"Перевод {amount} TON на {address} выполнен, hash: {tx_hash}")
        else:
            logger.error(f"Перевод не выполнен, tx_hash пустой")
        
        return tx_hash
    except Exception as e:
        logger.error(f"Ошибка при выполнении перевода: {e}", exc_info=True)
        import traceback
        logger.error(f"Traceback: {traceback.format_exc()}")
        return None

def normalize_address_for_comparison(address: str) -> list:
    if not address:
        return []
    
    address = address.strip()
    formats = [address.lower()]
    
    try:
        from pytoniq_core import Address as PytoniqAddress
        
        try:
            addr_obj = PytoniqAddress(address)
            user_friendly = addr_obj.to_str(is_user_friendly=True, is_bounceable=False)
            raw = addr_obj.to_str(is_user_friendly=False, is_bounceable=False)
            if user_friendly.lower() not in formats:
                formats.append(user_friendly.lower())
            if raw.lower() not in formats:
                formats.append(raw.lower())
            
            if ':' in raw:
                parts = raw.split(':')
                if len(parts) >= 2:
                    hex_part = parts[-1]
                    hex_part = ''.join(c for c in hex_part if c in '0123456789abcdefABCDEF')
                    if hex_part.lower() not in formats:
                        formats.append(hex_part.lower())
        except:
            pass
    except ImportError:
        pass
    
    if ':' in address:
        parts = address.split(':')
        if len(parts) >= 2:
            hex_part = parts[-1]
            hex_part = ''.join(c for c in hex_part if c in '0123456789abcdefABCDEF')
            if hex_part.lower() not in formats:
                formats.append(hex_part.lower())
    
    return formats

async def verify_transaction_on_server(msg_hash: str, amount: float, wallet_address: str, skip_recipient_check: bool = False) -> dict:
    from config import API_BASE, WALLET_ADDRESS
    import aiohttp
    
    ton_api_url = f"{API_BASE}/blockchain/messages/{msg_hash}/transaction"
    ton_api_url_tx = f"{API_BASE}/blockchain/transactions/{msg_hash}"
    wallet_tx_url = f"{API_BASE}/blockchain/accounts/{WALLET_ADDRESS}/transactions"
    max_attempts = 10
    delay_seconds = 5

    headers = {
        'accept': 'application/json',
        'Authorization': f'Bearer {api_key}'
    }
    
    wallet_formats = normalize_address_for_comparison(wallet_address)
    our_wallet_formats = normalize_address_for_comparison(WALLET_ADDRESS)

    async with aiohttp.ClientSession(timeout=aiohttp.ClientTimeout(total=30)) as session:
        for attempt in range(1, max_attempts + 1):
            try:
                async with session.get(ton_api_url, headers=headers) as response:
                    if response.status == 404:
                        if attempt == 1:
                            async with session.get(ton_api_url_tx, headers=headers) as response_tx:
                                if response_tx.status == 200:
                                    tx_data = await response_tx.json()
                                    response = response_tx
                                else:
                                    if attempt < max_attempts:
                                        await asyncio.sleep(delay_seconds)
                                        continue
                                    else:
                                        return {
                                            'success': False,
                                            'error': 'Transaction not found on blockchain',
                                            'attempt': attempt
                                        }
                        else:
                            if attempt < max_attempts:
                                await asyncio.sleep(delay_seconds)
                                continue
                            else:
                                return {
                                    'success': False,
                                    'error': 'Transaction not found on blockchain',
                                    'attempt': attempt
                                }

                    if response.status == 429:
                        retry_after = int(response.headers.get("Retry-After", delay_seconds))
                        await asyncio.sleep(retry_after)
                        continue

                    if response.status != 200:
                        error_text = await response.text()
                        logger.error(f"TON API вернул код {response.status}: {error_text[:200]}")
                        if attempt < max_attempts:
                            await asyncio.sleep(delay_seconds * attempt)
                            continue
                        return {
                            'success': False,
                            'error': f"TON API returned code {response.status}",
                            'attempt': attempt,
                            'response': error_text[:200]
                        }

                    tx_data = await response.json()

                    account_address = tx_data.get('account', {}).get('address', '')
                    if account_address:
                        account_formats = normalize_address_for_comparison(account_address)
                        if not (set(account_formats) & set(our_wallet_formats)):
                            async with session.get(wallet_tx_url, headers=headers, params={"limit": 20}) as wallet_response:
                                if wallet_response.status == 200:
                                    wallet_txs = await wallet_response.json()
                                    found_tx = None

                                    for tx in wallet_txs.get('transactions', []):
                                        for out_msg in tx.get('out_msgs', []):
                                            out_msg_hash = out_msg.get('hash') or out_msg.get('msg_hash') or ''
                                            if out_msg_hash == msg_hash:
                                                found_tx = tx
                                                break
                                        if found_tx:
                                            break

                                    if found_tx:
                                        tx_data = found_tx
                                        account_address = tx_data.get('account', {}).get('address', '')
                                    else:
                                        if attempt < max_attempts:
                                            await asyncio.sleep(delay_seconds)
                                            continue
                                        return {
                                            'success': False,
                                            'error': f'Transaction with message {msg_hash} not found in our wallet transactions',
                                            'attempt': attempt
                                        }

                                else:
                                    if attempt < max_attempts:
                                        await asyncio.sleep(delay_seconds)
                                        continue
                                    return {
                                        'success': False,
                                        'error': f'Failed to get wallet transactions: {wallet_response.status}',
                                        'attempt': attempt
                                    }
                                    
                    if not tx_data.get('success', False):
                        return {
                            'success': False,
                            'error': 'Transaction was not successful on blockchain',
                            'attempt': attempt,
                            'txData': tx_data
                        }

                    expected_amount_nano = int(amount * 1e9)
                    actual_amount = 0
                    recipient_found = False

                    for out_msg in tx_data.get('out_msgs', []):
                        dest_address = None
                        if isinstance(out_msg.get('destination'), dict):
                            dest_address = out_msg.get('destination', {}).get('address', '')
                        elif isinstance(out_msg.get('destination'), str):
                            dest_address = out_msg.get('destination', '')
                        
                        if not dest_address:
                            dest_address = out_msg.get('address', '')
                        
                        if dest_address:
                            dest_formats = normalize_address_for_comparison(dest_address)
                            if set(dest_formats) & set(wallet_formats):
                                actual_amount = int(out_msg.get('value', 0))
                                recipient_found = True
                                break

                    if not recipient_found:
                        account_address = tx_data.get('account', {}).get('address', '')
                        if account_address:
                            account_formats = normalize_address_for_comparison(account_address)
                            if set(account_formats) & set(wallet_formats):
                                pass
                    
                    if not recipient_found:
                        account = tx_data.get('account', {})
                        if account:
                            account_address = account.get('address', '')
                            if account_address:
                                account_normalized = normalize_address_for_comparison(account_address)
                                pass
                    
                    if not recipient_found:
                        for out_msg in tx_data.get('out_msgs', []):
                            for key in ['destination', 'address', 'to', 'recipient', 'dst']:
                                value = out_msg.get(key)
                                if value:
                                    if isinstance(value, dict):
                                        addr = value.get('address', '') or value.get('addr', '')
                                    else:
                                        addr = str(value)
                                    if addr:
                                        addr_formats = normalize_address_for_comparison(addr)
                                        if set(addr_formats) & set(wallet_formats):
                                            actual_amount = int(out_msg.get('value', 0))
                                            recipient_found = True
                                            break
                                if recipient_found:
                                    break
                            if recipient_found:
                                break
                        
                        if not recipient_found:
                            for out_msg in tx_data.get('out_msgs', []):
                                for key, value in out_msg.items():
                                    if isinstance(value, str) and len(value) > 20:
                                        try:
                                            addr_formats = normalize_address_for_comparison(value)
                                            if set(addr_formats) & set(wallet_formats):
                                                actual_amount = int(out_msg.get('value', 0))
                                                recipient_found = True
                                                break
                                        except:
                                            pass
                                if recipient_found:
                                    break

                    if not skip_recipient_check:
                        if not recipient_found:
                            return {
                                'success': False,
                                'error': f'Recipient address {wallet_address} not found in transaction',
                                'attempt': attempt,
                                'txData': tx_data
                            }

                        tolerance = 100000000
                        if actual_amount < (expected_amount_nano - tolerance):
                            return {
                                'success': False,
                                'error': f'Payment amount is less than expected. Expected: {expected_amount_nano}, Got: {actual_amount}',
                                'attempt': attempt,
                                'expected': expected_amount_nano,
                                'actual': actual_amount,
                                'txData': tx_data
                            }
                    else:
                        pass

                    return {
                        'success': True,
                        'hash': tx_data.get('hash', msg_hash),
                        'lt': tx_data.get('lt'),
                        'utime': tx_data.get('utime'),
                        'amount': actual_amount / 1e9,
                        'fees': tx_data.get('total_fees', 0) / 1e9,
                        'attempt': attempt,
                        'txData': tx_data
                    }

            except aiohttp.ClientError as e:
                if attempt < max_attempts:
                    await asyncio.sleep(delay_seconds * attempt)
                    continue
                return {
                    'success': False,
                    'error': f'Network error verifying transaction: {str(e)}',
                    'attempt': attempt
                }
            except Exception as e:
                if "Cannot connect" not in str(e) and "timeout" not in str(e).lower() and "Connect call failed" not in str(e):
                    logger.error(f"Неожиданная ошибка при проверке транзакции: {e}")
                if attempt < max_attempts:
                    await asyncio.sleep(delay_seconds * attempt)
                    continue
                return {
                    'success': False,
                    'error': f'Error verifying transaction: {str(e)}',
                    'attempt': attempt
                }

    return {
        'success': False,
        'error': f"Transaction not found after {max_attempts} attempts",
        'attempts': max_attempts
    }

async def verify_withdrawal_transaction(tx_hash: str, amount: float, wallet_address: str, currency: str, request_id: str):
    try:
        if currency == 'JETTON':
            verification_result = await verify_transaction_on_server(tx_hash, amount, wallet_address, skip_recipient_check=True)
        else:
            verification_result = await verify_transaction_on_server(tx_hash, amount, wallet_address)
        
        conn = create_connection()
        if not conn:
            return
        
        try:
            cursor = conn.cursor()
            if verification_result.get('success'):
                if currency == 'TON':
                    cursor.execute("""
                        UPDATE TONWithdraw 
                        SET status = 'success' 
                        WHERE hash = %s OR request_id = %s
                    """, (tx_hash, request_id))
                elif currency == 'JETTON':
                    cursor.execute("""
                        UPDATE JETTONWithdraw 
                        SET status = 'success' 
                        WHERE hash = %s OR request_id = %s
                    """, (tx_hash, request_id))
                logger.success(f"Withdrawal transaction {tx_hash} verified successfully")
            else:
                if currency == 'TON':
                    cursor.execute("""
                        SELECT cashier_id FROM TONWithdraw 
                        WHERE hash = %s OR request_id = %s
                        LIMIT 1
                    """, (tx_hash, request_id))
                elif currency == 'JETTON':
                    cursor.execute("""
                        SELECT cashier_id FROM JETTONWithdraw 
                        WHERE hash = %s OR request_id = %s
                        LIMIT 1
                    """, (tx_hash, request_id))
                
                withdraw_tx = cursor.fetchone()
                cashier_id = withdraw_tx.get('cashier_id') if withdraw_tx else None
                
                if currency == 'TON':
                    cursor.execute("""
                        UPDATE TONWithdraw 
                        SET status = 'failed' 
                        WHERE hash = %s OR request_id = %s
                    """, (tx_hash, request_id))
                elif currency == 'JETTON':
                    cursor.execute("""
                        UPDATE JETTONWithdraw 
                        SET status = 'failed' 
                        WHERE hash = %s OR request_id = %s
                    """, (tx_hash, request_id))
                
                if cashier_id:
                    cursor.execute("""
                        SELECT currency FROM Cashiers WHERE id = %s
                    """, (cashier_id,))
                    cashier_data = cursor.fetchone()
                    
                    if cashier_data:
                        cashier_currency = cashier_data.get('currency', '').upper()
                        if cashier_currency == currency:
                            cursor.execute("""
                                UPDATE Cashiers 
                                SET balance = balance + %s 
                                WHERE id = %s
                            """, (amount, cashier_id))
                            logger.info(f"Баланс {amount} {currency} возвращен на кассу {cashier_id}")
                        else:
                            logger.error(f"Несоответствие валюты: транзакция {currency}, касса {cashier_currency}. Баланс не возвращен для безопасности.")
                    else:
                        logger.error(f"Касса {cashier_id} не найдена, баланс не возвращен")
                else:
                    logger.error(f"Не удалось найти cashier_id для транзакции {tx_hash}, баланс не возвращен")
                
                logger.error(f"Withdrawal transaction {tx_hash} verification failed: {verification_result.get('error')}")
            
            conn.commit()
        finally:
            conn.close()
    except Exception as e:
        logger.error(f"Error verifying withdrawal transaction: {e}")

async def get_pytonlib_client():
    from pytonlib import TonlibClient
    import requests
    from pathlib import Path
    
    url = 'https://ton.org/global.config.json'
    config = requests.get(url).json()
    
    keystore_dir = '/tmp/ton_keystore'
    Path(keystore_dir).mkdir(parents=True, exist_ok=True)
    
    client = TonlibClient(ls_index=2, config=config, keystore=keystore_dir, tonlib_timeout=10)
    await client.init()
    
    return client

async def get_seqno(client, address: str):
    data = await client.raw_run_method(method='seqno', stack_data=[], address=address)
    return int(data['stack'][0][1], 16)

async def perform_jetton_transfer(to: str, amount: float, jetton_address: str, api_key: str, seed_phrase: str):
    try:
        from pytoniq_core import Address, begin_cell
        from tonutils.client import ToncenterV3Client
        from tonutils.jetton import JettonMasterStandard, JettonWalletStandard
        from tonutils.wallet import WalletV5R1
        
        if amount <= 0:
            logger.error(f"Неверная сумма для перевода: {amount}")
            return None
        
        jetton_decimals = 9
        amount_nano = int(amount * (10 ** jetton_decimals))
        
        if amount_nano <= 0:
            logger.error(f"Сумма слишком мала для перевода: {amount}")
            return None
        
        client = ToncenterV3Client(is_testnet=False, api_key=api_key, rps=1, max_retries=1)
        wallet, _, _, _ = WalletV5R1.from_mnemonic(client, seed_phrase)
        
        jetton_wallet_address = await JettonMasterStandard.get_wallet_address(
            client=client,
            owner_address=wallet.address.to_str(),
            jetton_master_address=jetton_address,
        )
        
        
        body = JettonWalletStandard.build_transfer_body(
            recipient_address=Address(to),
            response_address=wallet.address,
            jetton_amount=amount_nano,
            forward_payload=None,
            forward_amount=0,
        )
        
        tx_hash = await wallet.transfer(
            destination=jetton_wallet_address,
            amount=0.05,
            body=body,
        )
        
        if tx_hash:
            logger.success(f"Перевод {amount} жетонов ({jetton_address}) на {to} выполнен, hash: {tx_hash}")
        else:
            logger.error(f"Перевод жетонов не выполнен, tx_hash пустой")
        
        return tx_hash
                
    except Exception as e:
        logger.error(f"Ошибка при выполнении перевода жетона: {e}", exc_info=True)
        import traceback
        logger.error(f"Traceback: {traceback.format_exc()}")
        return None

@app.post("/withdraw")
async def withdraw_from_cashier(req: WithdrawRequest, request: Request = None):
    try:
        
        cashier = get_cashier_by_id(req.cashier_id, None)
        if not cashier:
            logger.error(f"Касса {req.cashier_id} не найдена")
            raise HTTPException(status_code=404, detail="Cashier not found")
        
        user_id = cashier['user_id']
        if not verify_api_token(user_id, req.api_token, request):
            logger.error(f"Неверный API токен для пользователя {user_id}")
            raise HTTPException(status_code=401, detail="Invalid API token")
        
        currency = cashier.get('currency', 'TON').upper()
        
        fee_amount = None
        
        cashier_balance = float(cashier.get('balance', 0))
        if cashier_balance < req.amount:
            raise HTTPException(
                status_code=400, 
                detail=f"Insufficient balance. Available: {cashier_balance}, Requested: {req.amount}"
            )
        
        if req.amount < 0.01:
            raise HTTPException(status_code=400, detail="Minimum withdrawal amount is 0.01 TON")
        
        if currency == 'TON':
            fee_amount = await estimate_transfer_fee(req.wallet, req.amount, seed_phrase)
            
            if fee_amount is None:
                fee_amount = 0.007
            
            fee_amount_display = round(fee_amount, 3)
            
            actual_withdraw_amount = req.amount - fee_amount
            
            if actual_withdraw_amount <= 0:
                raise HTTPException(
                    status_code=400, 
                    detail=f"Amount too small. After blockchain fee (~{fee_amount:.3f} TON), you will receive: {actual_withdraw_amount:.3f} TON. Please increase withdrawal amount."
                )
            
            if cashier_balance < req.amount:
                raise HTTPException(
                    status_code=400,
                    detail=f"Insufficient balance. Required: {req.amount} TON, Available: {cashier_balance}"
                )
        elif currency == 'JETTON':
            fee_amount = 0.1
            
            fee_amount = round(fee_amount, DECIMAL_PLACES)
            
            conn_check = create_connection()
            if not conn_check:
                raise HTTPException(status_code=500, detail="Database connection error")
            try:
                cursor_check = conn_check.cursor()
                cursor_check.execute("""
                    SELECT id, balance FROM Cashiers 
                    WHERE user_id = %s AND currency = 'TON' AND status = 'active'
                    ORDER BY balance DESC
                    LIMIT 1
                """, (user_id,))
                ton_cashier = cursor_check.fetchone()
                
                if not ton_cashier or float(ton_cashier.get('balance', 0)) < fee_amount:
                    raise HTTPException(
                        status_code=400,
                        detail=f"Insufficient TON balance for blockchain fee ({fee_amount:.2f} TON). You need at least one active TON cashier with sufficient balance."
                    )
            finally:
                conn_check.close()

        if currency == 'TON':
            tx_hash = await perform_ton_transfer(req.wallet, actual_withdraw_amount, toncenter_api_key, seed_phrase)
        elif currency == 'JETTON':
            jetton_address = cashier.get('jetton_address')
            if not jetton_address:
                raise HTTPException(status_code=400, detail="Jetton address not set for this cashier")
            tx_hash = await perform_jetton_transfer(req.wallet, req.amount, jetton_address, toncenter_api_key, seed_phrase)
        else:
            raise HTTPException(status_code=400, detail=f"Unsupported currency: {currency}")
        
        if not tx_hash:
            logger.error(f"Перевод не выполнен, tx_hash пустой")
            raise HTTPException(status_code=500, detail="Transfer failed")

        import hashlib
        import time
        request_id = hashlib.sha256(f"{req.cashier_id}{req.amount}{req.wallet}{time.time()}".encode()).hexdigest()

        conn = create_connection()
        if not conn:
            raise HTTPException(status_code=500, detail="Database connection error")
        
        try:
            cursor = conn.cursor()
            
            if currency == 'TON':
                cursor.execute("""
                    UPDATE Cashiers 
                    SET balance = balance - %s 
                    WHERE id = %s AND balance >= %s AND currency = 'TON'
                """, (req.amount, req.cashier_id, req.amount))
                
                if cursor.rowcount == 0:
                    conn.rollback()
                    raise HTTPException(status_code=400, detail="Failed to deduct balance (concurrent modification)")

                cursor.execute("""
                    INSERT INTO TONWithdraw 
                    (user_id, cashier_id, wallet, request_id, hash, price, status)
                    VALUES (%s, %s, %s, %s, %s, %s, 'pending')
                """, (user_id, req.cashier_id, req.wallet, request_id, tx_hash, req.amount))
            elif currency == 'JETTON':
                cursor.execute("""
                    UPDATE Cashiers 
                    SET balance = balance - %s 
                    WHERE id = %s AND balance >= %s AND currency = 'JETTON'
                """, (req.amount, req.cashier_id, req.amount))
                
                if cursor.rowcount == 0:
                    conn.rollback()
                    raise HTTPException(status_code=400, detail="Failed to deduct balance (concurrent modification)")
                
                cursor.execute("""
                    SELECT id, balance FROM Cashiers 
                    WHERE user_id = %s AND currency = 'TON' AND status = 'active'
                    ORDER BY balance DESC
                    LIMIT 1
                """, (user_id,))
                ton_cashier = cursor.fetchone()
                
                if ton_cashier:
                    ton_cashier_id = ton_cashier['id']
                    cursor.execute("""
                        UPDATE Cashiers 
                        SET balance = balance - %s 
                        WHERE id = %s AND balance >= %s
                    """, (fee_amount, ton_cashier_id, fee_amount))
                    
                    if cursor.rowcount == 0:
                        conn.rollback()
                        raise HTTPException(status_code=400, detail="Failed to deduct TON fee (concurrent modification)")

                jetton_address = cashier.get('jetton_address')
                cursor.execute("""
                    INSERT INTO JETTONWithdraw 
                    (user_id, cashier_id, jetton_address, wallet, request_id, hash, price, status)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, 'pending')
                """, (user_id, req.cashier_id, jetton_address, req.wallet, request_id, tx_hash, req.amount))
            
            conn.commit()
            logger.success(f"Withdrawn {req.amount} {currency} from cashier {req.cashier_id}, tx_hash: {tx_hash}")

            asyncio.create_task(verify_withdrawal_transaction(tx_hash, req.amount, req.wallet, currency, request_id))
            
            response_data = {
                "status": "ok",
                "message": "Withdrawal successful",
                "tx_hash": tx_hash,
                "amount": req.amount,
                "currency": currency,
                "request_id": request_id
            }

            if currency == 'TON':
                response_data["blockchain_fee"] = round(fee_amount, 3)
                response_data["requested_amount"] = round(req.amount, DECIMAL_PLACES)
                response_data["actual_amount"] = round(actual_withdraw_amount, 3)
                response_data["message"] = f"Withdrawal successful. Requested: {req.amount:.2f} TON, blockchain fee: ~{round(fee_amount, 3):.3f} TON, you will receive: ~{round(actual_withdraw_amount, 3):.3f} TON"
            elif currency == 'JETTON':
                response_data["blockchain_fee"] = round(fee_amount, DECIMAL_PLACES)
                response_data["fee_currency"] = "TON"
                response_data["message"] = f"Withdrawal successful. Blockchain fee: {fee_amount:.2f} TON deducted from your TON cashier"
            
            return response_data
        except HTTPException:
            conn.rollback()
            raise
        except Exception as e:
            conn.rollback()
            logger.error(f"Error saving withdrawal transaction: {e}", exc_info=True)
            raise HTTPException(status_code=500, detail=f"Error saving transaction: {str(e)}")
        finally:
            conn.close()
            
    except HTTPException as e:
        logger.error(f"HTTPException in withdrawal: {e.status_code} - {e.detail}")
        raise
    except Exception as e:
        logger.error(f"Error in withdrawal: {e}", exc_info=True)
        import traceback
        logger.error(f"Full traceback: {traceback.format_exc()}")
        raise HTTPException(status_code=500, detail=f"Internal server error: {str(e)}")

async def withdraw_starter(shutdown_event=None):
    import os
    ssl_keyfile = "/etc/letsencrypt/live/pay.whaile.ru/privkey.pem"
    ssl_certfile = "/etc/letsencrypt/live/pay.whaile.ru/fullchain.pem"
    
    use_ssl = os.path.exists(ssl_keyfile) and os.path.exists(ssl_certfile)

    logging.getLogger("uvicorn.lifespan").setLevel(logging.CRITICAL)
    logging.getLogger("starlette.routing").setLevel(logging.CRITICAL)
    logging.getLogger("uvicorn.error").setLevel(logging.CRITICAL)

    original_excepthook = sys.excepthook
    
    def filtered_excepthook(exc_type, exc_value, exc_traceback):
        if exc_type is asyncio.CancelledError:
            return
        original_excepthook(exc_type, exc_value, exc_traceback)
    
    sys.excepthook = filtered_excepthook
    
    config_params = {
        "app": app,
        "host": "0.0.0.0",
        "port": 2998,
        "log_level": "warning",
        "log_config": None,
        "access_log": False
    }
    
    if use_ssl:
        config_params["ssl_keyfile"] = ssl_keyfile
        config_params["ssl_certfile"] = ssl_certfile
    
    config = uvicorn.Config(**config_params)
    server = uvicorn.Server(config)
    
    monitor_task = None
    try:
        async def monitor_shutdown():
            if shutdown_event:
                while not shutdown_event.is_set():
                    await asyncio.sleep(0.5)
                try:
                    server.should_exit = True
                except Exception:
                    pass
        
        monitor_task = asyncio.create_task(monitor_shutdown())
        logger.success("FastAPI withdraw сервер запущен на 0.0.0.0:2998")
        await server.serve()
    except asyncio.CancelledError:
        pass
    except Exception as e:
        if shutdown_event is None or not shutdown_event.is_set():
            logger.error(f"Error in withdraw server: {e}")
            raise
    finally:
        if monitor_task and not monitor_task.done():
            monitor_task.cancel()
            try:
                await monitor_task
            except asyncio.CancelledError:
                pass

