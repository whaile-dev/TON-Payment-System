import asyncio
import aiohttp
import locale
import time
from datetime import datetime
from loguru import logger

from config import *
from TON.database import check_and_update_transaction, get_active_jetton_cashiers, floor_to_decimal_places, expire_old_pending_transactions

class RateLimiter:
    def __init__(self, rate_per_second: int):
        self.rate = rate_per_second
        self.tokens = rate_per_second
        self.last_check = time.monotonic()
        self.lock = asyncio.Lock()

    async def acquire(self):
        async with self.lock:
            now = time.monotonic()
            elapsed = now - self.last_check
            self.last_check = now
            self.tokens += elapsed * self.rate
            if self.tokens > self.rate:
                self.tokens = self.rate
            if self.tokens < 1:
                wait_time = (1 - self.tokens) / self.rate
                await asyncio.sleep(wait_time)
                self.tokens = 0
            else:
                self.tokens -= 1

class TransactionMonitor:
    def __init__(self):
        self.jetton_cashiers = {}
        self.last_cashiers_update = 0
        self.cashiers_update_interval = 60
        self.session = None
        self.semaphore = asyncio.Semaphore(MAX_CONCURRENT_REQUESTS)
        self.rate_limiter = RateLimiter(RATE_LIMIT)
        self.rate_limit_backoff = 1.0
        self.last_rate_limit_time = 0
        self.headers = {
            'accept': 'application/json',
            'Authorization': f'Bearer {api_key}'
        }
    
    def update_jetton_cashiers(self):
        try:
            cashiers = get_active_jetton_cashiers()
            self.jetton_cashiers = {}
            
            for cashier in cashiers:
                jetton_address = cashier.get('jetton_address')
                if jetton_address:
                    normalized = self.normalize_address(jetton_address)
                    if normalized not in self.jetton_cashiers:
                        self.jetton_cashiers[normalized] = []
                    self.jetton_cashiers[normalized].append({
                        'webhook_url': cashier.get('webhook_url'),
                        'cashier_id': cashier.get('id')
                    })
            
        except Exception as e:
            logger.error(f"Ошибка при обновлении списка касс с жетонами: {e}")
    
    def should_update_cashiers(self):
        current_time = time.time()
        if current_time - self.last_cashiers_update > self.cashiers_update_interval:
            self.last_cashiers_update = current_time
            return True
        return False

    async def request(self, url, params=None, retries=5, delay=1):
        async with self.semaphore:
            await self.rate_limiter.acquire()

            for attempt in range(retries):
                try:
                    async with self.session.get(
                        url, 
                        params=params, 
                        headers=self.headers,
                        timeout=aiohttp.ClientTimeout(total=30)
                    ) as resp:
                        if resp.status == 404:
                            return None
                        if resp.status == 429:
                            retry_after = resp.headers.get("Retry-After")
                            if retry_after:
                                wait_time = int(retry_after)
                            else:
                                wait_time = int(delay * (RATE_LIMIT_BACKOFF_MULTIPLIER ** attempt) * self.rate_limit_backoff)
                            
                            current_time = time.time()
                            if current_time - self.last_rate_limit_time < 60:
                                self.rate_limit_backoff = min(self.rate_limit_backoff * RATE_LIMIT_BACKOFF_MULTIPLIER, 10.0)
                            else:
                                self.rate_limit_backoff = 1.0
                            self.last_rate_limit_time = current_time
                            
                            await asyncio.sleep(wait_time)
                            continue
                        if resp.status == 401:
                            logger.error("Invalid API key")
                            await asyncio.sleep(delay * (attempt + 1))
                            continue
                        
                        if resp.status == 200:
                            current_time = time.time()
                            if current_time - self.last_rate_limit_time > 60:
                                self.rate_limit_backoff = 1.0
                        
                        resp.raise_for_status()
                        return await resp.json()

                except aiohttp.ClientError as e:
                    if attempt < retries - 1:
                        await asyncio.sleep(delay * (attempt + 1))
                    else:
                        return None
                except asyncio.TimeoutError:
                    if attempt < retries - 1:
                        await asyncio.sleep(delay * (attempt + 1))
                    else:
                        return None
                except Exception as e:
                    if attempt < retries - 1:
                        await asyncio.sleep(delay * (attempt + 1))
                    else:
                        if "Cannot connect" not in str(e) and "timeout" not in str(e).lower():
                            logger.error(f"Ошибка после {retries} попыток для {url}: {e}")
                        return None
            
            logger.debug(f"Превышено число попыток для {url}")
            return None

    async def get_wallet_transactions(self, limit=MAX_TRANSACTIONS):
        url = f"{API_BASE}/blockchain/accounts/{WALLET_ADDRESS}/transactions"
        params = {"limit": limit, "sort_order": "desc"}
        data = await self.request(url, params)
        return data.get("transactions", []) if data else []

    async def get_transaction(self, tx_hash: str):
        url = f"{API_BASE}/blockchain/messages/{tx_hash}/transaction"
        return await self.request(url)

    async def get_jetton_transfers(self, tx_hash: str):
        url = f"{API_BASE}/events/{tx_hash}/jettons"
        return await self.request(url)

    def nano_to_ton(self, nano):
        return nano / 10**9

    def format_amount(self, nano):
        amount = self.nano_to_ton(nano)
        try:
            locale.setlocale(locale.LC_NUMERIC, 'ru_RU.UTF-8')
            return locale.atof(f"{amount:.9f}")
        except:
            amount_str = f"{amount:.9f}".replace(",", "")
            return float(amount_str.rstrip('0').rstrip('.') if '.' in amount_str else amount_str)

    def normalize_address(self, address):
        if not address:
            return ''
        
        address = address.strip()
        
        try:
            from pytoniq_core import Address as PytoniqAddress
            addr_obj = PytoniqAddress(address)
            raw_address = addr_obj.to_str(is_user_friendly=False, is_bounceable=False)
            if ':' in raw_address:
                hex_part = raw_address.split(':')[-1]
                return hex_part.lower()
            return raw_address.lower()
        except Exception as e:
            if ':' in address:
                parts = address.split(':')
                if len(parts) >= 2:
                    hex_part = parts[-1]
                    hex_part = ''.join(c for c in hex_part if c in '0123456789abcdefABCDEF')
                    return hex_part.lower()
            if all(c in '0123456789abcdefABCDEF' for c in address):
                return address.lower()
            return address.lower()

    async def process_jetton_transaction(self, tx):
        if self.should_update_cashiers():
            self.update_jetton_cashiers()

        if not self.jetton_cashiers:
            self.update_jetton_cashiers()
        
        if not self.jetton_cashiers:
            return None
        
        tx_hash = tx.get('hash')
        if not tx_hash:
            return None
            
        jetton_data = await self.get_jetton_transfers(tx_hash)
        if not jetton_data:
            return None
            
        if not jetton_data.get('actions'):
            return None

        event_timestamp = jetton_data.get('timestamp')
        if event_timestamp:
            time1 = datetime.fromtimestamp(event_timestamp)
        else:
            time1 = datetime.fromtimestamp(tx.get('utime'))

        for action in jetton_data['actions']:
            if action.get('type') == 'JettonTransfer' and action.get('status') == 'ok':
                transfer = action['JettonTransfer']
                
                amount = int(transfer['amount'])
                formatted_amount = float(self.format_amount(amount))
                formatted_amount = floor_to_decimal_places(formatted_amount, DECIMAL_PLACES)
                
                original_jetton_address = transfer['jetton'].get('address', '')
                transfer_jetton_address = self.normalize_address(original_jetton_address)
                
                if transfer_jetton_address not in self.jetton_cashiers:
                    continue
                
                cashiers_list = self.jetton_cashiers[transfer_jetton_address]
                
                sender_address = transfer['sender']['address']
                recipient_address = transfer['recipient']['address']

                recipient_normalized = self.normalize_address(recipient_address)
                wallet_normalized = self.normalize_address(WALLET_ADDRESS)
                
                if recipient_normalized != wallet_normalized:
                    continue

                base_transactions = action.get('base_transactions', [])
                final_tx_hash = base_transactions[0] if base_transactions else tx_hash

                for cashier in cashiers_list:
                    webhook_url = cashier.get('webhook_url')
                    cashier_id = cashier.get('cashier_id')
                    
                    result = check_and_update_transaction(
                        tx_hash=final_tx_hash,
                        sender_address=sender_address,
                        amount=formatted_amount,
                        time=time1,
                        signature="",
                        is_jetton=True,
                        webhook_url=webhook_url
                    )
                    if result:
                        return jetton_data.get('lt') or tx.get('lt')
        
        return None

    async def process_ton_transaction(self, tx):
        if tx.get('in_msg') and tx['in_msg'].get('hash'):
            if tx['in_msg'].get('msg_type') == "int_msg" and tx.get('success'):
                tx_data = await self.get_transaction(tx['in_msg']['hash'])
                if not tx_data:
                    return None

                in_msg = tx_data['in_msg']
                
                destination_address = in_msg.get('destination', {}).get('address')
                if not destination_address:
                    account_address = tx_data.get('account', {}).get('address')
                    if account_address and self.normalize_address(account_address) != self.normalize_address(WALLET_ADDRESS):
                        return None
                else:
                    dest_normalized = self.normalize_address(destination_address)
                    wallet_normalized = self.normalize_address(WALLET_ADDRESS)
                    if dest_normalized != wallet_normalized:
                        return None
                
                amount = int(in_msg.get('value', 0))
                if amount == 0:
                    return None
                    
                formatted_amount = float(self.format_amount(amount))
                formatted_amount = floor_to_decimal_places(formatted_amount, DECIMAL_PLACES)
                time1 = datetime.fromtimestamp(tx_data.get('utime'))

                sender_address = in_msg.get('source', {}).get('address')
                if not sender_address:
                    return None
                    
                signature = in_msg.get('decoded_body', {}).get('signature', "")

                if tx_data.get('success'):
                    result = check_and_update_transaction(
                        tx_hash=tx_data['hash'],
                        sender_address=sender_address,
                        amount=formatted_amount,
                        time=time1,
                        signature=signature,
                        is_jetton=False
                    )
                    if result:
                        logger.info(f"TON транзакция {tx_data['hash']} успешно обработана: {formatted_amount} TON от {sender_address}")
                        return tx.get('lt')
                    else:
                        return None
        return None

    async def process_transaction(self, tx):
        jetton_lt = await self.process_jetton_transaction(tx)
        if jetton_lt:
            return jetton_lt
        
        return await self.process_ton_transaction(tx)

    async def main_loop(self, shutdown_event=None):
        async with aiohttp.ClientSession(timeout=aiohttp.ClientTimeout(total=30)) as self.session:
            last_lt = None
            consecutive_errors = 0
            last_expire_check = 0
            expire_check_interval = 300
            
            while shutdown_event is None or not shutdown_event.is_set():
                start_time = time.perf_counter()
                try:
                    if shutdown_event and shutdown_event.is_set():
                        logger.info("Получен сигнал завершения, останавливаем проверку транзакций")
                        break
                    
                    current_time = time.time()
                    if current_time - last_expire_check >= expire_check_interval:
                        expire_old_pending_transactions()
                        last_expire_check = current_time
                    
                    transactions = await self.get_wallet_transactions()
                    
                    if transactions:
                        if last_lt:
                            transactions = [tx for tx in transactions if tx.get('lt', 0) > last_lt]
                        
                        if transactions:
                            batch_size = min(3, len(transactions))
                            processed_count = 0
                            
                            for i in range(0, len(transactions), batch_size):
                                batch = transactions[i:i + batch_size]
                                tasks = [self.process_transaction(tx) for tx in batch]
                                results = await asyncio.gather(*tasks, return_exceptions=True)
                                
                                for result in results:
                                    if isinstance(result, (int, str)) and result:
                                        if last_lt is None or result > last_lt:
                                            last_lt = result
                                    elif isinstance(result, Exception):
                                        pass
                                
                                processed_count += len(batch)
                                
                                if i + batch_size < len(transactions):
                                    await asyncio.sleep(0.5)
                            

                    consecutive_errors = 0
                    duration = time.perf_counter() - start_time
                    
                    if shutdown_event and shutdown_event.is_set():
                        break
                    
                    try:
                        sleep_interval = 0.5
                        total_sleep = CHECK_INTERVAL
                        slept = 0
                        while slept < total_sleep:
                            if shutdown_event and shutdown_event.is_set():
                                break
                            await asyncio.sleep(min(sleep_interval, total_sleep - slept))
                            slept += sleep_interval
                    except asyncio.CancelledError:
                        break

                except asyncio.CancelledError:
                    logger.info("Задача проверки транзакций отменена")
                    break
                except Exception as e:
                    consecutive_errors += 1
                    logger.error(f"ERROR (ошибка #{consecutive_errors}): {e}")
                    
                    if shutdown_event and shutdown_event.is_set():
                        break
                    
                    sleep_time = ERROR_SLEEP * 2 if consecutive_errors >= 5 else ERROR_SLEEP
                    try:
                        sleep_interval = 0.5
                        slept = 0
                        while slept < sleep_time:
                            if shutdown_event and shutdown_event.is_set():
                                break
                            await asyncio.sleep(min(sleep_interval, sleep_time - slept))
                            slept += sleep_interval
                    except asyncio.CancelledError:
                        break


async def checker(shutdown_event=None):
    monitor = TransactionMonitor()
    await asyncio.sleep(1.0)
    monitor.update_jetton_cashiers()
    logger.success("TON transactions scanner working now!")
    logger.success("Все сервисы успешно запущены")
    try:
        await monitor.main_loop(shutdown_event)
    except asyncio.CancelledError:
        logger.info("Checker task cancelled")
        return
    except Exception as e:
        if shutdown_event is None or not shutdown_event.is_set():
            logger.exception(e)
            raise e
