import asyncio
from datetime import datetime, timedelta
import threading
from typing import Dict, Union, List
from loguru import logger
import pymysql
from pymysql import Error
import math

from config import *

def normalize_address_for_db(address: str) -> List[str]:
    if not address:
        return []
    
    address = address.strip()
    formats = [address]
    
    try:
        from pytoniq_core import Address as PytoniqAddress
        
        try:
            addr_obj = PytoniqAddress(address)
            user_friendly = addr_obj.to_str(is_user_friendly=True, is_bounceable=False)
            if user_friendly not in formats:
                formats.append(user_friendly)
            
            raw = addr_obj.to_str(is_user_friendly=False, is_bounceable=False)
            if raw not in formats:
                formats.append(raw)
        except:
            pass
    except ImportError:
        if ':' in address:
            parts = address.split(':')
            if len(parts) >= 2:
                hex_part = parts[-1]
                hex_part = ''.join(c for c in hex_part if c in '0123456789abcdefABCDEF')
                if hex_part:
                    formats.append(f"0:{hex_part}")
    
    return formats

def init_database():
    try:
        connection = pymysql.connect(
            host=host,
            user=user,
            password=password,
            database=database,
            charset='utf8mb4',
            cursorclass=pymysql.cursors.DictCursor,
            autocommit=False
        )

        if not connection.open:
            logger.error("Не удалось подключиться к базе данных для инициализации")
            return False

        try:
            cursor = connection.cursor()

            cursor.execute("""
                CREATE TABLE IF NOT EXISTS Users (
                    id BIGINT PRIMARY KEY AUTO_INCREMENT,
                    email VARCHAR(255) UNIQUE,
                    password_hash VARCHAR(255),
                    api_token VARCHAR(255) UNIQUE,
                    wallet VARCHAR(250),
                    status VARCHAR(50) DEFAULT 'active',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_email (email),
                    INDEX idx_api_token (api_token)
                )
            """)

            cursor.execute("""
                CREATE TABLE IF NOT EXISTS Cashiers (
                    id BIGINT PRIMARY KEY AUTO_INCREMENT,
                    user_id BIGINT NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    description TEXT,
                    category VARCHAR(100),
                    currency VARCHAR(10) DEFAULT 'TON',
                    jetton_address VARCHAR(255) DEFAULT NULL,
                    min_amount DECIMAL(18,2) DEFAULT 0.01,
                    max_amount DECIMAL(18,2) DEFAULT NULL,
                    webhook_url VARCHAR(500),
                    webhook_secret VARCHAR(255),
                    balance DECIMAL(18,2) DEFAULT 0,
                    status VARCHAR(50) DEFAULT 'active',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user_id (user_id),
                    INDEX idx_status (status),
                    INDEX idx_currency (currency),
                    FOREIGN KEY (user_id) REFERENCES Users(id) ON DELETE CASCADE
                )
            """)

            cursor.execute("""
                CREATE TABLE IF NOT EXISTS TONDeposit (
                    id BIGINT PRIMARY KEY AUTO_INCREMENT,
                    time_recorded TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    user_id BIGINT,
                    cashier_id BIGINT DEFAULT NULL,
                    callback_url VARCHAR(255),
                    return_url VARCHAR(500),
                    wallet VARCHAR(255),
                    hash VARCHAR(255),
                    price DECIMAL(18,2) DEFAULT 0,
                    status VARCHAR(50),
                    payload TEXT DEFAULT NULL,
                    transaction_uuid VARCHAR(36) UNIQUE DEFAULT NULL,
                    INDEX idx_cashier_id (cashier_id),
                    INDEX idx_transaction_uuid (transaction_uuid),
                    INDEX idx_status (status),
                    INDEX idx_time_recorded (time_recorded),
                    INDEX idx_hash (hash),
                    FOREIGN KEY (cashier_id) REFERENCES Cashiers(id) ON DELETE SET NULL
                )
            """)

            cursor.execute("""
                CREATE TABLE IF NOT EXISTS JETTONDeposit (
                    id BIGINT PRIMARY KEY AUTO_INCREMENT,
                    time_recorded TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    user_id BIGINT,
                    cashier_id BIGINT DEFAULT NULL,
                    callback_url VARCHAR(255),
                    return_url VARCHAR(500),
                    wallet VARCHAR(255),
                    hash VARCHAR(255),
                    price DECIMAL(18,2) DEFAULT 0,
                    status VARCHAR(50),
                    payload TEXT DEFAULT NULL,
                    transaction_uuid VARCHAR(36) UNIQUE DEFAULT NULL,
                    INDEX idx_cashier_id (cashier_id),
                    INDEX idx_transaction_uuid (transaction_uuid),
                    INDEX idx_status (status),
                    INDEX idx_time_recorded (time_recorded),
                    FOREIGN KEY (cashier_id) REFERENCES Cashiers(id) ON DELETE SET NULL
                )
            """)

            cursor.execute("""
                CREATE TABLE IF NOT EXISTS TONWithdraw (
                    id BIGINT PRIMARY KEY AUTO_INCREMENT,
                    time_recorded TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    user_id BIGINT,
                    cashier_id BIGINT DEFAULT NULL,
                    wallet VARCHAR(255),
                    request_id VARCHAR(255),
                    hash VARCHAR(255),
                    price DECIMAL(18,2) DEFAULT 0,
                    status VARCHAR(50),
                    INDEX idx_user_id (user_id),
                    INDEX idx_cashier_id (cashier_id),
                    INDEX idx_status (status),
                    INDEX idx_hash (hash),
                    FOREIGN KEY (cashier_id) REFERENCES Cashiers(id) ON DELETE SET NULL
                )
            """)

            cursor.execute("""
                CREATE TABLE IF NOT EXISTS JETTONWithdraw (
                    id BIGINT PRIMARY KEY AUTO_INCREMENT,
                    time_recorded TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    user_id BIGINT,
                    cashier_id BIGINT DEFAULT NULL,
                    jetton_address VARCHAR(255),
                    wallet VARCHAR(255),
                    request_id VARCHAR(255),
                    hash VARCHAR(255),
                    price DECIMAL(18,2) DEFAULT 0,
                    status VARCHAR(50),
                    INDEX idx_user_id (user_id),
                    INDEX idx_cashier_id (cashier_id),
                    INDEX idx_status (status),
                    INDEX idx_hash (hash),
                    INDEX idx_jetton_address (jetton_address),
                    FOREIGN KEY (cashier_id) REFERENCES Cashiers(id) ON DELETE SET NULL
                )
            """)

            connection.commit()
            logger.success("База данных успешно инициализирована")
            return True

        except Exception as e:
            connection.rollback()
            logger.error(f"Ошибка при создании таблиц: {e}")
            return False
        finally:
            connection.close()

    except Exception as e:
        logger.error(f"Ошибка подключения при инициализации БД: {e}")
        return False

def create_connection():
    try:
        connection = pymysql.connect(
            host=host, 
            user=user, 
            password=password, 
            database=database,
            charset='utf8mb4',
            cursorclass=pymysql.cursors.DictCursor,
            autocommit=False
        )
        return connection if connection.open else None
    except Error as e:
        logger.error(f"Ошибка подключения к базе данных: {e}")
        return None
    except Exception as e:
        logger.error(f"Неожиданная ошибка при подключении к БД: {e}")
        return None

_ALLOWED_TABLES = {'Users', 'Cashiers', 'TONDeposit', 'JETTONDeposit', 'TONWithdraw', 'JETTONWithdraw'}
_ALLOWED_USER_COLUMNS = {'email', 'password_hash', 'api_token', 'status', 'wallet', 'created_at'}
_ALLOWED_CASHIER_COLUMNS = {'name', 'description', 'category', 'currency', 'min_amount', 'max_amount', 'webhook_url', 'status', 'balance'}

def update_col(user_id, table, col, new_col):
    if table not in _ALLOWED_TABLES:
        logger.error(f"Attempted to update disallowed table: {table}")
        raise ValueError(f"Table {table} is not in whitelist")
    
    if table == 'Users' and col not in _ALLOWED_USER_COLUMNS:
        logger.error(f"Attempted to update disallowed column: {table}.{col}")
        raise ValueError(f"Column {col} is not allowed for table {table}")
    elif table == 'Cashiers' and col not in _ALLOWED_CASHIER_COLUMNS:
        logger.error(f"Attempted to update disallowed column: {table}.{col}")
        raise ValueError(f"Column {col} is not allowed for table {table}")
    
    conn = create_connection()
    try:
        cursor = conn.cursor()
        sql = f"UPDATE `{table}` SET `{col}` = %s WHERE id = %s"
        cursor.execute(sql, (new_col, user_id))
        conn.commit()
    finally:
        conn.close()

def get_col(user_id, table, column):
    if table not in _ALLOWED_TABLES:
        logger.error(f"Attempted to select from disallowed table: {table}")
        raise ValueError(f"Table {table} is not in whitelist")
    
    conn = create_connection()
    try:
        cursor = conn.cursor()
        sql = f"SELECT `{column}` FROM `{table}` WHERE id = %s"
        cursor.execute(sql, (user_id,))
        result = cursor.fetchone()
        return result[column] if result else None
    finally:
        conn.close()

def expire_old_pending_transactions():
    from datetime import datetime, timedelta
    from config import PAYMENT_TIMEOUT_SECONDS
    
    conn = create_connection()
    if not conn:
        return 0
    
    try:
        cursor = conn.cursor()
        
        expire_time = datetime.now() - timedelta(seconds=PAYMENT_TIMEOUT_SECONDS)
        
        cursor.execute("""
            UPDATE TONDeposit 
            SET status = 'expired' 
            WHERE status IN ('pending', 'nohash') 
            AND time_recorded < %s
        """, (expire_time,))
        ton_expired = cursor.rowcount
        
        cursor.execute("""
            UPDATE JETTONDeposit 
            SET status = 'expired' 
            WHERE status IN ('pending', 'nohash') 
            AND time_recorded < %s
        """, (expire_time,))
        jetton_expired = cursor.rowcount
        
        conn.commit()
        
        total_expired = ton_expired + jetton_expired
        if total_expired > 0:
            logger.info(f"Помечено как expired: {ton_expired} TON и {jetton_expired} JETTON транзакций")
        
        return total_expired
    except Exception as e:
        logger.error(f"Ошибка при обновлении истекших транзакций: {e}")
        if conn:
            conn.rollback()
        return 0
    finally:
        if conn:
            conn.close()

transaction_locks = {}
lock_dict_lock = threading.Lock() 

def validate_decimal_places(value: float, decimal_places: int = DECIMAL_PLACES) -> bool:
    if value is None:
        return False
    try:
        value_float = float(value)
        value_str = f"{value_float:.15f}".rstrip('0').rstrip('.')
        if '.' in value_str:
            decimal_part = value_str.split('.')[1]
            return len(decimal_part) <= decimal_places
        return True
    except (ValueError, TypeError):
        return False

def floor_to_decimal_places(value: float, decimal_places: int = DECIMAL_PLACES) -> float:
    multiplier = 10 ** decimal_places
    return math.floor(value * multiplier) / multiplier

def check_and_update_transaction(tx_hash, sender_address, amount, time, signature, is_jetton, webhook_url=None):
    try:
        amount_float = float(amount)
        
        if not validate_decimal_places(amount_float, DECIMAL_PLACES):
            amount_float = floor_to_decimal_places(amount_float, DECIMAL_PLACES)
        
        amount_str = f"{amount_float:.{DECIMAL_PLACES}f}"

        if is_jetton:
            table = "JETTON"
        else:
            table = "TON"

        with lock_dict_lock:
            if tx_hash not in transaction_locks:
                transaction_locks[tx_hash] = threading.Lock()
            tx_lock = transaction_locks[tx_hash]

        with tx_lock:
            conn = create_connection()
            if not conn:
                return False
            
            conn.autocommit(False)
            try:
                cursor = conn.cursor()

                cursor.execute(f"SELECT id, user_id, status FROM {table}Deposit WHERE hash = %s", (str(tx_hash),))
                existing_tx = cursor.fetchone()

                if existing_tx:
                    if existing_tx['status'] == 'success':
                        conn.commit()
                        return True
                    else:
                        conn.rollback()
                        return False
      
                time_window_start = time - timedelta(seconds=120)
                time_window_end = time + timedelta(seconds=120)
                
                address_formats = normalize_address_for_db(sender_address)
                if not address_formats:
                    logger.debug(f"Не удалось нормализовать адрес {sender_address}")
                    return False
                
                placeholders = ','.join(['%s'] * len(address_formats))
                
                if is_jetton and webhook_url:
                    cursor.execute("""
                        SELECT id, user_id, cashier_id, callback_url, payload, time_recorded, status
                        FROM {}Deposit 
                        WHERE wallet IN ({}) AND price = %s AND callback_url = %s AND status IN ('nohash', 'pending')
                        AND time_recorded BETWEEN %s AND %s
                        ORDER BY time_recorded DESC
                    """.format(table, placeholders), 
                    tuple(address_formats) + (amount_str, webhook_url, time_window_start, time_window_end))
                else:
                    cursor.execute("""
                        SELECT id, user_id, cashier_id, callback_url, payload, time_recorded, status
                        FROM {}Deposit 
                        WHERE wallet IN ({}) AND price = %s AND status IN ('nohash', 'pending')
                        AND time_recorded BETWEEN %s AND %s
                        ORDER BY time_recorded DESC
                    """.format(table, placeholders), 
                    tuple(address_formats) + (amount_str, time_window_start, time_window_end))
                    
                candidate_txs = cursor.fetchall()
                
                if not candidate_txs:
                    return False
                
                placeholders = ','.join(['%s'] * len(address_formats))
                if is_jetton and webhook_url:
                    cursor.execute("""
                        SELECT id 
                        FROM {}Deposit 
                        WHERE wallet IN ({}) AND price = %s AND callback_url = %s AND status = 'success'
                        AND time_recorded BETWEEN %s AND %s
                        LIMIT 1
                    """.format(table, placeholders), 
                    tuple(address_formats) + (amount_str, webhook_url, time_window_start, time_window_end))
                else:
                    cursor.execute("""
                        SELECT id 
                        FROM {}Deposit 
                        WHERE wallet IN ({}) AND price = %s AND status = 'success'
                        AND time_recorded BETWEEN %s AND %s
                        LIMIT 1
                    """.format(table, placeholders), 
                    tuple(address_formats) + (amount_str, time_window_start, time_window_end))

                if cursor.fetchone():
                    for tx in candidate_txs:
                        cursor.execute(f"""
                            UPDATE {table}Deposit 
                            SET status = 'duplicate' 
                            WHERE id = %s AND status IN ('nohash', 'pending')
                        """, (tx['id'],))
                    conn.commit()
                    return False

                target_tx = candidate_txs[0]
                tx_id = target_tx['id']
                cashier_id = target_tx.get('cashier_id')
                callback_url = target_tx.get('callback_url')
                payload = target_tx.get('payload')
                
                cursor.execute("""
                    UPDATE {}Deposit 
                    SET hash = %s, status = 'success'
                    WHERE id = %s AND status IN ('nohash', 'pending')
                """.format(table), (tx_hash, tx_id))
                
                if cursor.rowcount == 0:
                    conn.rollback()
                    return False
                
                if cashier_id:
                    expected_currency = 'JETTON' if is_jetton else 'TON'
                    cursor.execute("""
                        SELECT id, balance, currency FROM Cashiers 
                        WHERE id = %s FOR UPDATE
                    """, (cashier_id,))
                    cashier_data = cursor.fetchone()
                    
                    if not cashier_data:
                        logger.error(f"Касса {cashier_id} не найдена")
                        conn.rollback()
                        return False
                    
                    if cashier_data['currency'] != expected_currency:
                        logger.error(f"Валюта кассы не совпадает: ожидалось {expected_currency}, получено {cashier_data['currency']}")
                        conn.rollback()
                        return False
                    
                    cursor.execute("""
                        UPDATE Cashiers 
                        SET balance = balance + %s 
                        WHERE id = %s AND currency = %s
                    """, (amount_float, cashier_id, expected_currency))
                    
                    if cursor.rowcount == 0:
                        logger.error(f"Не удалось начислить баланс для кассы {cashier_id}")
                        conn.rollback()
                        return False
                else:
                    logger.warning(f"Transaction {tx_id} has no cashier_id, skipping balance update")
                
                for tx in candidate_txs[1:]:
                    cursor.execute(f"""
                        UPDATE {table}Deposit 
                        SET status = 'duplicate' 
                        WHERE id = %s AND status IN ('nohash', 'pending')
                    """, (tx['id'],))
                
                conn.commit()
                
                webhook_secret = None
                if cashier_id:
                    cursor.execute("SELECT webhook_secret FROM Cashiers WHERE id = %s", (cashier_id,))
                    cashier_secret = cursor.fetchone()
                    if cashier_secret and cashier_secret.get('webhook_secret'):
                        webhook_secret = cashier_secret['webhook_secret']
                
                if callback_url:
                    from TON.payment import send_callback
                    send_callback(callback_url, tx_id, "success", table, payload, webhook_secret)
                
                currency_name = "JETTON" if is_jetton else "TON"
                logger.info(f"{currency_name} транзакция {tx_hash} успешно обработана: касса {cashier_id}, сумма {amount_float} {currency_name}, отправитель {sender_address}")
                return True
            finally:
                conn.close()
                    
    except Error as e:
        logger.error(f"Database error in transaction validation: {e}")
        if 'conn' in locals():
            try:
                conn.rollback()
                conn.close()
            except:
                pass
        return False
    except Exception as e:
        logger.error(f"Unknown error in transaction validation: {e}")
        if 'conn' in locals():
            try:
                conn.rollback()
                conn.close()
            except:
                pass
        return False
    finally:
        with lock_dict_lock:
            if tx_hash in transaction_locks and not transaction_locks[tx_hash].locked():
                del transaction_locks[tx_hash]

def check_pending_transaction(user_id, amount: float, wallet_address: str) -> Dict[str, Union[bool, str]]:
    try:
        conn = create_connection()
        if conn is None:
            return {'exists': False}
        
        try:
            cursor = conn.cursor()
            query = """
                SELECT hash, status, request_id FROM TONWithdraw
                WHERE user_id = %s AND price = %s AND wallet = %s AND status = 'pending'
                ORDER BY time_recorded DESC LIMIT 1
            """
            user_id_int = int(user_id)
            cursor.execute(query, (user_id_int, amount, wallet_address))
            result = cursor.fetchone()

            return {
                'exists': bool(result),
                'hash': result['hash'] if result else None,
                'status': result['status'] if result else None,
                'request_id': result['request_id'] if result else None
            }
        finally:
            conn.close()
    except Exception as e:
        logger.error(f"Error checking pending transaction: {e}")
        return {'exists': False}

def save_transaction_to_db(
    user_id,
    amount: float,
    wallet_address: str,
    tx_hash: str,
    request_hash: str,
    status: str = 'pending'
) -> bool:
    try:
        conn = create_connection()
        if conn is None:
            return False
        
        try:
            cursor = conn.cursor()
            current_time = datetime.now().strftime('%Y-%m-%d %H:%M:%S')

            cursor.execute("SELECT id FROM TONWithdraw WHERE request_id = %s LIMIT 1", (request_hash,))
            existing_record = cursor.fetchone()

            if existing_record:
                update_query = """
                    UPDATE TONWithdraw
                    SET user_id = %s, time_recorded = %s, wallet = %s,
                        hash = %s, status = %s, price = %s
                    WHERE request_id = %s
                """
                user_id_int = int(user_id)
                cursor.execute(update_query, (
                    user_id_int, current_time, wallet_address,
                    tx_hash, status, amount, request_hash
                ))
            else:
                insert_query = """
                    INSERT INTO TONWithdraw
                    (user_id, time_recorded, wallet, request_id, hash, status, price)
                    VALUES (%s, %s, %s, %s, %s, %s, %s)
                """
                user_id_int = int(user_id)
                cursor.execute(insert_query, (
                    user_id_int, current_time, wallet_address,
                    request_hash, tx_hash, status, amount
                ))

            conn.commit()
            return True
        finally:
            conn.close()
    except Exception as e:
        logger.error(f"Error saving transaction to DB: {e}")
        return False

def update_transaction_status(tx_hash: str, status: str) -> bool:
    try:
        conn = create_connection()
        if conn is None:
            return False
        
        try:
            cursor = conn.cursor()
            cursor.execute(
                "UPDATE TONWithdraw SET status = %s WHERE hash = %s",
                (status, tx_hash)
            )
            conn.commit()
            return True
        finally:
            conn.close()
    except Exception as e:
        logger.error(f"Error updating transaction status: {e}")
        return False

def check_duplicate_request(request_hash: str) -> bool:
    try:
        conn = create_connection()
        if conn is None:
            return False
        
        try:
            cursor = conn.cursor()
            cursor.execute(
                "SELECT 1 FROM TONWithdraw WHERE request_id = %s AND status = 'success' LIMIT 1",
                (request_hash,)
            )
            return cursor.fetchone() is not None
        finally:
            conn.close()
    except Exception as e:
        logger.error(f"Error checking duplicate request: {e}")
        return False

def create_cashier(user_id, name: str, description: str = None, category: str = None, 
                   currency: str = 'TON', min_amount: float = 0.01, max_amount: float = None,
                   webhook_url: str = None, jetton_address: str = None) -> dict:
    if min_amount < 0.01:
        return {'success': False, 'error': 'Minimum amount must be at least 0.01'}
    
    if not validate_decimal_places(min_amount, DECIMAL_PLACES):
        return {'success': False, 'error': f'min_amount must have no more than {DECIMAL_PLACES} decimal places'}
    if max_amount is not None and not validate_decimal_places(max_amount, DECIMAL_PLACES):
        return {'success': False, 'error': f'max_amount must have no more than {DECIMAL_PLACES} decimal places'}
    
    try:
        import secrets
        
        webhook_secret = secrets.token_urlsafe(32)
        
        conn = create_connection()
        if conn is None:
            return {'success': False, 'error': 'Database connection failed'}
        
        try:
            cursor = conn.cursor()
            
            user_id_int = int(user_id) if isinstance(user_id, (int, str)) and str(user_id).isdigit() else None
            if user_id_int is None:
                return {'success': False, 'error': 'Invalid user_id'}
            
            cursor.execute("""
                INSERT INTO Cashiers 
                (user_id, name, description, category, currency, jetton_address, min_amount, max_amount, webhook_url, webhook_secret, status)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 'active')
            """, (user_id_int, name, description, category, currency, jetton_address, min_amount, max_amount, webhook_url, webhook_secret))
            
            conn.commit()
            cashier_db_id = cursor.lastrowid
            
            cursor.execute("SELECT * FROM Cashiers WHERE id = %s", (cashier_db_id,))
            cashier = cursor.fetchone()
            
            logger.success(f"Касса {cashier_db_id} создана для пользователя {user_id_int}")
            return {'success': True, 'cashier': cashier}
        finally:
            conn.close()
            
    except Exception as e:
        logger.error(f"Ошибка при создании кассы: {e}")
        if 'conn' in locals():
            try:
                conn.close()
            except:
                pass
        return {'success': False, 'error': str(e)}

def get_user_cashiers(user_id) -> list:
    try:
        conn = create_connection()
        if conn is None:
            return []
        
        try:
            cursor = conn.cursor()
            user_id_int = int(user_id) if isinstance(user_id, (int, str)) and str(user_id).isdigit() else None
            if user_id_int is None:
                return []
            
            cursor.execute("""
                SELECT c.*, 
                       COALESCE((SELECT COUNT(DISTINCT td.id) FROM TONDeposit td WHERE td.cashier_id = c.id AND td.status = 'success'), 0) +
                       COALESCE((SELECT COUNT(DISTINCT jd.id) FROM JETTONDeposit jd WHERE jd.cashier_id = c.id AND jd.status = 'success'), 0) as total_transactions
                FROM Cashiers c
                WHERE c.user_id = %s
                ORDER BY c.created_at DESC
            """, (user_id_int,))
            
            cashiers = cursor.fetchall()
            return cashiers if cashiers else []
        finally:
            conn.close()
            
    except Exception as e:
        logger.error(f"Ошибка при получении касс пользователя: {e}")
        import traceback
        logger.error(traceback.format_exc())
        return []

def get_cashier_by_id(cashier_id: int, user_id = None) -> dict:
    try:
        conn = create_connection()
        if conn is None:
            return None
        
        try:
            cursor = conn.cursor()
            cashier_id_int = int(cashier_id) if isinstance(cashier_id, (int, str)) and str(cashier_id).isdigit() else None
            if cashier_id_int is None:
                return None
            
            if user_id:
                user_id_int = int(user_id) if isinstance(user_id, (int, str)) and str(user_id).isdigit() else None
                if user_id_int is None:
                    return None
                cursor.execute("SELECT * FROM Cashiers WHERE id = %s AND user_id = %s", (cashier_id_int, user_id_int))
            else:
                cursor.execute("SELECT * FROM Cashiers WHERE id = %s", (cashier_id_int,))
            
            return cursor.fetchone()
        finally:
            conn.close()
            
    except Exception as e:
        logger.error(f"Ошибка при получении кассы: {e}")
        return None

def get_active_jetton_cashiers() -> list:
    try:
        conn = create_connection()
        if conn is None:
            return []
        
        try:
            cursor = conn.cursor()
            cursor.execute("""
                SELECT DISTINCT jetton_address, webhook_url, id
                FROM Cashiers 
                WHERE status = 'active' 
                AND currency = 'jetton' 
                AND jetton_address IS NOT NULL 
                AND jetton_address != ''
            """)
            
            cashiers = cursor.fetchall()
            return cashiers if cashiers else []
        finally:
            conn.close()
            
    except Exception as e:
        logger.error(f"Ошибка при получении касс с жетонами: {e}")
        return []

def update_cashier_status(cashier_id: int, user_id, status: str) -> bool:
    try:
        conn = create_connection()
        if conn is None:
            return False
        
        try:
            cursor = conn.cursor()
            cashier_id_int = int(cashier_id) if isinstance(cashier_id, (int, str)) and str(cashier_id).isdigit() else None
            user_id_int = int(user_id) if isinstance(user_id, (int, str)) and str(user_id).isdigit() else None
            if cashier_id_int is None or user_id_int is None:
                return False
            
            cursor.execute("""
                UPDATE Cashiers 
                SET status = %s 
                WHERE id = %s AND user_id = %s
            """, (status, cashier_id_int, user_id_int))
            
            conn.commit()
            return cursor.rowcount > 0
        finally:
            conn.close()
            
    except Exception as e:
        logger.error(f"Ошибка при обновлении статуса кассы: {e}")
        return False

def update_cashier_settings(cashier_id: int, user_id, name: str = None, description: str = None, 
                            category: str = None, min_amount: float = None, max_amount: float = None,
                            webhook_url: str = None) -> dict:
    if min_amount is not None and min_amount < 0.01:
        return {'success': False, 'error': 'Minimum amount must be at least 0.01'}
    
    if min_amount is not None and not validate_decimal_places(min_amount, DECIMAL_PLACES):
        return {'success': False, 'error': f'min_amount must have no more than {DECIMAL_PLACES} decimal places'}
    if max_amount is not None and not validate_decimal_places(max_amount, DECIMAL_PLACES):
        return {'success': False, 'error': f'max_amount must have no more than {DECIMAL_PLACES} decimal places'}
    
    try:
        conn = create_connection()
        if conn is None:
            return {'success': False, 'error': 'Database connection failed'}
        
        try:
            cursor = conn.cursor()
            cashier_id_int = int(cashier_id) if isinstance(cashier_id, (int, str)) and str(cashier_id).isdigit() else None
            user_id_int = int(user_id) if isinstance(user_id, (int, str)) and str(user_id).isdigit() else None
            if cashier_id_int is None or user_id_int is None:
                return {'success': False, 'error': 'Invalid cashier_id or user_id'}
            
            cursor.execute("SELECT id FROM Cashiers WHERE id = %s AND user_id = %s", (cashier_id_int, user_id_int))
            if cursor.fetchone() is None:
                return {'success': False, 'error': 'Cashier not found or access denied'}
            
            updates = []
            params = []
            
            if name is not None:
                updates.append("name = %s")
                params.append(name)
            if description is not None:
                updates.append("description = %s")
                params.append(description)
            if category is not None:
                updates.append("category = %s")
                params.append(category)
            if min_amount is not None:
                updates.append("min_amount = %s")
                params.append(min_amount)
            if max_amount is not None:
                updates.append("max_amount = %s")
                params.append(max_amount)
            if webhook_url is not None:
                updates.append("webhook_url = %s")
                params.append(webhook_url)
            
            if not updates:
                return {'success': False, 'error': 'No fields to update'}
            
            params.extend([cashier_id_int, user_id_int])
            
            query = f"UPDATE Cashiers SET {', '.join(updates)} WHERE id = %s AND user_id = %s"
            cursor.execute(query, params)
            conn.commit()
            
            if cursor.rowcount > 0:
                cursor.execute("SELECT * FROM Cashiers WHERE id = %s", (cashier_id_int,))
                cashier = cursor.fetchone()
                return {'success': True, 'cashier': cashier}
            else:
                return {'success': False, 'error': 'Update failed'}
        finally:
            conn.close()
            
    except Exception as e:
        logger.error(f"Ошибка при обновлении настроек кассы: {e}")
        return {'success': False, 'error': str(e)}

def delete_cashier(cashier_id: int, user_id) -> bool:
    try:
        conn = create_connection()
        if conn is None:
            return False
        
        try:
            cursor = conn.cursor()
            cashier_id_int = int(cashier_id) if isinstance(cashier_id, (int, str)) and str(cashier_id).isdigit() else None
            user_id_int = int(user_id) if isinstance(user_id, (int, str)) and str(user_id).isdigit() else None
            if cashier_id_int is None or user_id_int is None:
                return False
            
            cursor.execute("SELECT id FROM Cashiers WHERE id = %s AND user_id = %s", (cashier_id_int, user_id_int))
            if cursor.fetchone() is None:
                return False
            
            cursor.execute("DELETE FROM Cashiers WHERE id = %s AND user_id = %s", (cashier_id_int, user_id_int))
            conn.commit()
            
            return cursor.rowcount > 0
        finally:
            conn.close()
            
    except Exception as e:
        logger.error(f"Ошибка при удалении кассы: {e}")
        return False