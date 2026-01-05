import os
import sys
import time
import asyncio
import logging
import signal

from loguru import logger

from TON.checkTON import checker
from TON.payment import payment_starter
from TON.withdraw import withdraw_starter
from TON.database import init_database

os.environ['TZ'] = 'Europe/Moscow'
time.tzset() if hasattr(time, 'tzset') else None

tasks = []
shutdown_event = asyncio.Event()

logger.remove()
logger.add(sys.stderr, format='<white>{time:HH:mm:ss}</white>'
                              ' | <level>{level: <7}</level>'
                              ' | <white>{message}</white>')

logging.getLogger().setLevel(logging.CRITICAL) 

def signal_handler(signum, frame):
    logger.warning(f"Получен сигнал {signum}, начинаем корректное завершение...")
    shutdown_event.set()

async def main():
    logger.info("Инициализация базы данных...")
    if not init_database():
        logger.error("Не удалось инициализировать базу данных. Приложение не будет запущено.")
        return
    
    def signal_handler_sync(signum, frame):
        logger.warning(f"Получен сигнал {signum}, начинаем корректное завершение...")
        shutdown_event.set()
        import _thread
        _thread.interrupt_main()
    
    if sys.platform != 'win32':
        signal.signal(signal.SIGINT, signal_handler_sync)
        signal.signal(signal.SIGTERM, signal_handler_sync)
    
    tasks_list = []
    
    try:
        tasks_list = [
            asyncio.create_task(checker(shutdown_event)),
            asyncio.create_task(payment_starter(shutdown_event)),
            asyncio.create_task(withdraw_starter(shutdown_event))
        ]
        
        await asyncio.sleep(1.0)
        
        logger.success("База данных готова к работе")
        
        done, pending = await asyncio.wait(
            tasks_list,
            return_when=asyncio.FIRST_COMPLETED
        )
        
    except KeyboardInterrupt:
        logger.warning("Получен KeyboardInterrupt, начинаем корректное завершение...")
        shutdown_event.set()
    finally:
        logger.info("Отменяем все задачи...")
        for task in tasks_list:
            if not task.done():
                task.cancel()
        
        try:
            results = await asyncio.wait_for(
                asyncio.gather(*tasks_list, return_exceptions=True),
                timeout=5.0
            )
            for i, result in enumerate(results):
                if isinstance(result, Exception) and not isinstance(result, asyncio.CancelledError):
                    logger.warning(f"Задача {i} завершилась с ошибкой: {result}")
        except asyncio.TimeoutError:
            logger.warning("Некоторые задачи не завершились в течение 5 секунд")
        except Exception as e:
            logger.warning(f"Ошибка при ожидании завершения задач: {e}")
        
        logger.success("Все задачи завершены, выход из программы")

if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        logger.info("Программа завершена пользователем")
    except Exception as e:
        logger.error(f"Критическая ошибка: {e}")
        sys.exit(1)
