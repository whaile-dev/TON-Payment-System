<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/config.php');
$config = getConfig();
$site_name = $config['site']['name'] ?? 'TonPay';
$site_url = $config['site']['url'] ?? '<?php echo htmlspecialchars($site_url); ?>';
$api_port = $config['site']['api_port'] ?? 3000;
$withdraw_port = $config['site']['withdraw_port'] ?? 2998;
$api_base = $site_url . ':' . $api_port;
$withdraw_api = $site_url . ':' . $withdraw_port;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1.0, shrink-to-fit=no">
<link href="scripts/assets/images/favicon.png" rel="icon" />
<title>API Документация | <?php echo htmlspecialchars($site_name); ?></title>
<meta name="description" content="Документация API для интеграции платежной системы <?php echo htmlspecialchars($site_name); ?>">
<meta name="author" content="<?php echo htmlspecialchars($site_name); ?>">

<!-- Stylesheet
============================== -->
<!-- Bootstrap -->
<link rel="stylesheet" type="text/css" href="scripts/assets/vendor/bootstrap/css/bootstrap.min.css" />
<!-- Font Awesome Icon -->
<link rel="stylesheet" type="text/css" href="scripts/assets/vendor/font-awesome/css/all.min.css" />
<!-- Magnific Popup -->
<link rel="stylesheet" type="text/css" href="scripts/assets/vendor/magnific-popup/magnific-popup.min.css" />
<!-- Highlight Syntax -->
<link rel="stylesheet" type="text/css" href="scripts/assets/vendor/highlight.js/styles/github.css" />
<!-- Custom Stylesheet -->
<link rel="stylesheet" type="text/css" href="scripts/assets/css/stylesheet.css" />
</head>

<body data-spy="scroll" data-target=".idocs-navigation" data-offset="125">
<script>
// Темная тема для SVG: если есть localStorage setting
try {
 if(localStorage.getItem('dark-mode') === '1') document.body.classList.add('dark-theme');
} catch(e){}
</script>

<!-- Preloader -->
<div class="preloader">
  <div class="lds-ellipsis">
    <div></div>
    <div></div>
    <div></div>
    <div></div>
  </div>
</div>
<!-- Preloader End --> 

<!-- Document Wrapper   
=============================== -->
<div id="main-wrapper"> 
  
  <!-- Header
  ============================ -->
  <header id="header" class="sticky-top"> 
    <!-- Navbar -->
    <nav class="primary-menu navbar navbar-expand-lg navbar-dropdown-dark">
      <div class="container-fluid">
        <!-- Sidebar Toggler -->
		<button id="sidebarCollapse" class="navbar-toggler d-block d-md-none" type="button"><span></span><span class="w-75"></span><span class="w-50"></span></button>
		
		<!-- Logo --> 
        <a class="logo ml-md-3" href="/" title="iDocs Template" style="text-decoration: none !important; font-size: 1.5rem; font-weight: 700"><?php echo htmlspecialchars($site_name); ?></a>
        <!-- Logo End -->

        <button id="theme-toggle" class="btn btn-sm btn-outline-secondary ml-3" style="margin-right: 1rem;"><i class="fas fa-moon"></i></button>
      </div>
    </nav>
    <!-- Navbar End --> 
  </header>
  <!-- Header End --> 
  
  <!-- Content
  ============================ -->
  <div id="content" role="main">
    
	<!-- Sidebar Navigation
	============================ -->
	<div class="idocs-navigation bg-light">
      <ul class="nav flex-column ">
        <li class="nav-item"><a class="nav-link active" href="#idocs_start">Начало работы</a>
          <ul class="nav flex-column">
            <li class="nav-item"><a class="nav-link" href="#idocs_introduction">Введение</a></li>
            <li class="nav-item"><a class="nav-link" href="#idocs_authentication">Аутентификация</a></li>
            <li class="nav-item"><a class="nav-link" href="#idocs_base_url">Базовый URL</a></li>
            <li class="nav-item"><a class="nav-link" href="#idocs_currency">Валюта и суммы</a></li>
          </ul>
        </li>
        <li class="nav-item"><a class="nav-link" href="#idocs_payment_api">Payment API</a>
          <ul class="nav flex-column">
            <li class="nav-item"><a class="nav-link" href="#idocs_create_payment">Создание платежа</a></li>
            <li class="nav-item"><a class="nav-link" href="#idocs_payment_by_uuid">Получение по UUID</a></li>
            <li class="nav-item"><a class="nav-link" href="#idocs_payment_status">Статус платежа</a></li>
          </ul>
        </li>
        <li class="nav-item"><a class="nav-link" href="#idocs_cashier_api">Cashier API</a>
			<ul class="nav flex-column">
            <li class="nav-item"><a class="nav-link" href="#idocs_create_cashier">Создание кассы</a></li>
            <li class="nav-item"><a class="nav-link" href="#idocs_get_cashiers">Список касс</a></li>
            <li class="nav-item"><a class="nav-link" href="#idocs_get_cashier">Информация о кассе</a></li>
            <li class="nav-item"><a class="nav-link" href="#idocs_update_cashier_status">Изменение статуса</a></li>
            <li class="nav-item"><a class="nav-link" href="#idocs_update_cashier">Обновление настроек</a></li>
          </ul>
		</li>
        <li class="nav-item"><a class="nav-link" href="#idocs_withdraw_api">Withdraw API</a>
          <ul class="nav flex-column">
            <li class="nav-item"><a class="nav-link" href="#idocs_withdraw">Вывод средств</a></li>
          </ul>
        </li>
        <li class="nav-item"><a class="nav-link" href="#idocs_frontend">Frontend Integration</a>
			<ul class="nav flex-column">
            <li class="nav-item"><a class="nav-link" href="#idocs_payment_url">Создание платежа через URL</a></li>
            <li class="nav-item"><a class="nav-link" href="#idocs_webhooks">Webhook уведомления</a></li>
            <li class="nav-item"><a class="nav-link" href="#idocs_payment_status_check">Проверка статуса</a></li>
          </ul>
		</li>
        <li class="nav-item"><a class="nav-link" href="#idocs_examples">Примеры</a>
          <ul class="nav flex-column">
            <li class="nav-item"><a class="nav-link" href="#idocs_example_python">Python</a></li>
            <li class="nav-item"><a class="nav-link" href="#idocs_example_php">PHP</a></li>
            <li class="nav-item"><a class="nav-link" href="#idocs_example_javascript">JavaScript</a></li>
          </ul>
        </li>
        <li class="nav-item"><a class="nav-link" href="#idocs_errors">Обработка ошибок</a></li>
      </ul>
    </div>
    
    <!-- Docs Content
	============================ -->
    <div class="idocs-content">
      <div class="container"> 
        
        <!-- Getting Started
		============================ -->
        <section id="idocs_start">
        <h1>Документация <?php echo htmlspecialchars($site_name); ?> API</h1>
        <h2>Полное руководство по интеграции</h2>
        <p class="lead"><?php echo htmlspecialchars($site_name); ?> - это платежная система для приема платежей в TON и Jetton токенах. Данная документация поможет вам интегрировать <?php echo htmlspecialchars($site_name); ?> в ваше приложение.</p>
		<hr>
		<div class="row">
			<div class="col-sm-6 col-lg-4">
				<ul class="list-unstyled">
					<li><strong>Версия API:</strong> 1.0</li>
					<li><strong>Базовый URL:</strong> <code><?php echo htmlspecialchars($api_base); ?></code></li>
				</ul>
			</div>
			<div class="col-sm-6 col-lg-4">
				<ul class="list-unstyled">
					<li><strong class="font-weight-700">Withdraw API:</strong> <code><?php echo htmlspecialchars($withdraw_api); ?></code></li>
					<li><strong>Формат данных:</strong> JSON</li>
				</ul>
			</div>
		</div>
        <p class="alert alert-info">Все суммы должны иметь не более 2 знаков после точки. Все валюты отображаются в верхнем регистре (TON, JETTON). Таймаут платежа составляет 20 минут.</p>
        </section>
        
		<hr class="divider">
		
        <!-- Introduction
		============================ -->
        <section id="idocs_introduction">
          <h2>Введение</h2>
          <p class="lead"><?php echo htmlspecialchars($site_name); ?> предоставляет простой и безопасный способ приема платежей в TON и Jetton токенах.</p>
          
          <div class="text-center mb-4">
            <img src="./scripts/img/All.svg" alt="Общая архитектура системы" class="img-fluid">
          </div>
          
          <h3>Основные возможности</h3>
            <ul>
            <li>Прием платежей в TON и Jetton токенах</li>
            <li>Автоматическое отслеживание транзакций</li>
            <li>Webhook уведомления о статусе платежей</li>
            <li>Управление несколькими платежными кассами</li>
            <li>Вывод средств с касс</li>
            <li>Детальная статистика и отчеты</li>
              </ul>
          
          <h3>Как это работает</h3>
          <ol>
            <li>Создайте платежную кассу через API или веб-интерфейс</li>
            <li>Создайте платеж через API, указав сумму и адрес кошелька получателя</li>
            <li>Пользователь отправляет средства на указанный адрес</li>
            <li>Система автоматически отслеживает транзакцию в блокчейне</li>
            <li>При подтверждении транзакции отправляется webhook уведомление</li>
          </ol>
        </section>
        
		<hr class="divider">
		
        <!-- Authentication
		============================ -->
        <section id="idocs_authentication">
          <h2>Аутентификация</h2>
          <p>Для работы с API требуется API токен, который вы можете получить после регистрации и входа в систему.</p>
          
          <h3>Получение API токена</h3>
          <p>API токен доступен в вашем личном кабинете после авторизации. Токен используется для:</p>
          <ul>
            <li>Создания и управления кассами</li>
            <li>Вывода средств</li>
            <li>Доступа к информации о кассах</li>
          </ul>
          
          <h3>Использование токена</h3>
          <p>API токен передается в запросах следующим образом:</p>
          
          <h4>В теле запроса (POST/PUT):</h4>
          <pre><code class="json">{
  "user_id": 1,
  "api_token": "ваш_api_токен",
  "name": "Название кассы"
}
</code></pre>
          
          <h4>В query параметрах (GET):</h4>
          <pre><code class="bash"><?php echo htmlspecialchars($api_base); ?>/cashier/1?user_id=1&api_token=ваш_api_токен</code></pre>
          
          <p class="alert alert-warning"><span class="badge badge-danger text-uppercase mr-2">Важно</span>Никогда не публикуйте ваш API токен в открытом доступе. Храните его в безопасном месте.</p>
        </section>
        
		<hr class="divider">
		        
        <!-- Base URL
		============================ -->
        <section id="idocs_base_url">
          <h2>Базовый URL</h2>
          <p>API доступно по следующим адресам:</p>
          
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>Сервис</th>
                <th>URL</th>
                <th>Описание</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Payment API</td>
                <td><code><?php echo htmlspecialchars($api_base); ?></code></td>
                <td>Создание платежей, управление кассами</td>
              </tr>
              <tr>
                <td>Withdraw API</td>
                <td><code><?php echo htmlspecialchars($withdraw_api); ?></code></td>
                <td>Вывод средств с касс</td>
              </tr>
            </tbody>
          </table>
        </section>
        
		<hr class="divider">
		
        <!-- Currency
		============================ -->
        <section id="idocs_currency">
          <h2>Валюта и суммы</h2>
          
          <h3>Поддерживаемые валюты</h3>
          
          <div class="row mb-4">
            <div class="col-md-6 text-center mb-3">
              <img src="./scripts/img/TON.svg" alt="TON транзакции" class="img-fluid" style="max-width: 400px;">
              <p class="mt-2"><strong>TON</strong> - The Open Network (нативная валюта)</p>
            </div>
            <div class="col-md-6 text-center mb-3">
              <img src="./scripts/img/JETTON.svg" alt="Jetton транзакции" class="img-fluid" style="max-width: 400px;">
              <p class="mt-2"><strong>JETTON</strong> - Jetton токены (требуется указание адреса контракта)</p>
            </div>
          </div>
          
          <ul>
            <li><strong>TON</strong> - The Open Network (нативная валюта)</li>
            <li><strong>JETTON</strong> - Jetton токены (требуется указание адреса контракта)</li>
          </ul>
          
          <h3>Формат сумм</h3>
          <p>Все денежные суммы должны соответствовать следующим требованиям:</p>
          <ul>
            <li>Максимум <strong>2 знака после точки</strong> (например: <code>0.01</code>, <code>10.50</code>, <code>100.00</code>)</li>
            <li>Минимальная сумма платежа: <code>0.01</code></li>
            <li>Минимальная сумма вывода: <code>0.01</code></li>
            <li>Валюта всегда отображается в <strong>верхнем регистре</strong> (TON, JETTON)</li>
			</ul>
          
          <p class="alert alert-info">При создании кассы можно установить минимальную и максимальную сумму платежа. По умолчанию минимальная сумма: 0.01.</p>
        </section>
        
		<hr class="divider">
		
        <!-- Payment API
		============================ -->
        <section id="idocs_payment_api">
          <h2>Payment API</h2>
          <p class="lead mb-5">API для создания и управления платежами</p>
        </section>
        
        <!-- Create Payment
		============================ -->
        <section id="idocs_create_payment">
          <h2>POST /create_payment</h2>
          <p class="lead">Создание нового платежа</p>
          
          <h3>Описание</h3>
          <p>Создает новый платеж в системе. После создания платежа пользователь должен отправить указанную сумму на указанный адрес кошелька. Система автоматически отслеживает транзакцию в блокчейне и отправляет webhook уведомление при подтверждении.</p>
          
          <h3>URL</h3>
          <pre><code class="bash"><?php echo htmlspecialchars($api_base); ?>/create_payment</code></pre>
          
          <h3>Метод</h3>
          <p><code>POST</code></p>
		
          <h3>Аутентификация</h3>
          <p>Не требуется</p>
          
          <h3>Параметры запроса</h3>
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>Параметр</th>
                <th>Тип</th>
                <th>Обязательный</th>
                <th>Описание</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><code>cashier_id</code></td>
                <td>integer</td>
                <td>Да</td>
                <td>ID платежной кассы</td>
              </tr>
              <tr>
                <td><code>amount</code></td>
                <td>float</td>
                <td>Да</td>
                <td>Сумма платежа (максимум 2 знака после точки, минимум 0.01)</td>
              </tr>
              <tr>
                <td><code>wallet</code></td>
                <td>string</td>
                <td>Да</td>
                <td>Адрес кошелька получателя (в любом формате: UQ... или 0:...)</td>
              </tr>
              <tr>
                <td><code>currency</code></td>
                <td>string</td>
                <td>Нет</td>
                <td>Валюта: <code>ton</code> или <code>jetton</code>. Если не указана, берется из настроек кассы</td>
              </tr>
              <tr>
                <td><code>payload</code></td>
                <td>string</td>
                <td>Нет</td>
                <td>Дополнительные данные, которые будут отправлены в webhook</td>
              </tr>
              <tr>
                <td><code>transaction_uuid</code></td>
                <td>string</td>
                <td>Нет</td>
                <td>UUID существующей транзакции (для восстановления платежа)</td>
              </tr>
              <tr>
                <td><code>return_url</code></td>
                <td>string</td>
                <td>Нет</td>
                <td>URL для перенаправления пользователя после успешной оплаты (опционально)</td>
              </tr>
            </tbody>
          </table>
		
          <h3>Валидация</h3>
          <ul>
            <li>Сумма должна быть не меньше минимальной суммы кассы (<code>min_amount</code>)</li>
            <li>Сумма не должна превышать максимальную сумму кассы (<code>max_amount</code>), если она установлена</li>
            <li>Сумма должна иметь не более 2 знаков после точки</li>
            <li>Касса должна быть активна (<code>status = 'active'</code>)</li>
            <li>У кассы должен быть установлен <code>webhook_url</code></li>
          </ul>
          
          <h3>Пример запроса</h3>
          <pre><code class="python">import requests

response = requests.post(
    '<?php echo htmlspecialchars($api_base); ?>/create_payment',
    json={
        'cashier_id': 1,
        'amount': 0.01,
        'wallet': '...',
        'currency': 'ton',
        'payload': 'order_id=12345',
        'return_url': 'https://example.com/success'  # Опционально
    }
)

data = response.json()
print(data)
</code></pre>

          <h3>Формат ответа (успех)</h3>
          <pre><code class="json">{
  "status": "ok",
  "payment_id": 123,
  "transaction_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "currency": "ton",
  "amount": 0.01,
  "wallet_to_send": "...",
  "return_url": "https://example.com/success",
  "message": "Send the exact amount to the specified address. Once confirmed, the application will be automatically updated.",
  "time_recorded": 1704067200000
}
</code></pre>
          
          <h3>Примечания</h3>
          <ul>
            <li>Если указан <code>return_url</code>, пользователь будет автоматически перенаправлен на этот URL после успешной оплаты</li>
            <li><code>return_url</code> может быть передан как в запросе API, так и в URL страницы оплаты (<code>?return_url=...</code>)</li>
          </ul>
        
          <h3>Поля ответа</h3>
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>Поле</th>
                <th>Тип</th>
                <th>Описание</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><code>status</code></td>
                <td>string</td>
                <td>Статус ответа (всегда <code>"ok"</code> при успехе)</td>
              </tr>
              <tr>
                <td><code>payment_id</code></td>
                <td>integer</td>
                <td>Уникальный ID платежа</td>
              </tr>
              <tr>
                <td><code>transaction_uuid</code></td>
                <td>string</td>
                <td>UUID транзакции (можно использовать для восстановления платежа)</td>
              </tr>
              <tr>
                <td><code>currency</code></td>
                <td>string</td>
                <td>Валюта платежа (<code>ton</code> или <code>jetton</code>)</td>
              </tr>
              <tr>
                <td><code>amount</code></td>
                <td>float</td>
                <td>Сумма платежа</td>
              </tr>
              <tr>
                <td><code>wallet_to_send</code></td>
                <td>string</td>
                <td>Адрес кошелька, на который нужно отправить средства</td>
              </tr>
              <tr>
                <td><code>time_recorded</code></td>
                <td>integer</td>
                <td>Unix timestamp (в миллисекундах) времени создания платежа</td>
              </tr>
            </tbody>
          </table>
          
          <h3>Обработка ошибок</h3>
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>HTTP код</th>
                <th>Описание</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>400</td>
                <td>Неверные параметры запроса (сумма меньше минимума, превышает максимум, неверный формат суммы и т.д.)</td>
              </tr>
              <tr>
                <td>404</td>
                <td>Касса не найдена</td>
              </tr>
              <tr>
                <td>500</td>
                <td>Внутренняя ошибка сервера</td>
              </tr>
            </tbody>
          </table>
          
          <h3>Пример ответа с ошибкой</h3>
          <pre><code class="json">{
  "detail": "Amount is less than minimum: 0.01"
}
</code></pre>
          
          <h3>Примечания</h3>
          <ul>
            <li>Платеж имеет таймаут 20 минут. После истечения времени платеж перестает отслеживаться</li>
            <li>Если передан <code>transaction_uuid</code> существующей транзакции с теми же параметрами, платеж будет восстановлен</li>
            <li>После создания платежа система автоматически начинает отслеживать транзакцию в блокчейне</li>
            <li>При подтверждении транзакции отправляется webhook уведомление на <code>webhook_url</code> кассы</li>
          </ul>
        </section>
		
		<hr class="divider">
		
        <!-- Payment by UUID
		============================ -->
        <section id="idocs_payment_by_uuid">
          <h2>GET /payment_by_uuid/{transaction_uuid}</h2>
          <p class="lead">Получение платежа по UUID транзакции</p>
          
          <h3>Описание</h3>
          <p>Позволяет получить информацию о платеже по его UUID. Полезно для восстановления платежа или проверки его статуса.</p>
          
          <h3>URL</h3>
          <pre><code class="bash"><?php echo htmlspecialchars($api_base); ?>/payment_by_uuid/{transaction_uuid}</code></pre>
          
          <h3>Метод</h3>
          <p><code>GET</code></p>
          
          <h3>Параметры пути</h3>
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>Параметр</th>
                <th>Тип</th>
                <th>Описание</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><code>transaction_uuid</code></td>
                <td>string</td>
                <td>UUID транзакции</td>
              </tr>
            </tbody>
          </table>
          
          <h3>Пример запроса</h3>
          <pre><code class="python">import requests

uuid = "550e8400-e29b-41d4-a716-446655440000"
response = requests.get(
    f'<?php echo htmlspecialchars($api_base); ?>/payment_by_uuid/{uuid}'
)

data = response.json()
print(data)
</code></pre>
          
          <h3>Формат ответа</h3>
          <pre><code class="json">{
  "status": "ok",
  "payment_id": 123,
  "transaction_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "currency": "ton",
  "amount": 0.01,
  "wallet": "...",
  "wallet_to_send": "...",
  "payment_status": "nohash",
  "cashier_id": 1,
  "time_recorded": 1704067200000
}
</code></pre>
		
          <h3>Возможные статусы платежа</h3>
          <ul>
            <li><code>nohash</code> - Ожидание оплаты (транзакция еще не найдена)</li>
            <li><code>pending</code> - Транзакция найдена, ожидание подтверждения</li>
            <li><code>success</code> - Платеж успешно выполнен</li>
            <li><code>error</code> - Ошибка при обработке платежа</li>
          </ul>

          <h3>Обработка ошибок</h3>
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>HTTP код</th>
                <th>Описание</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>404</td>
                <td>Платеж не найден</td>
              </tr>
              <tr>
                <td>500</td>
                <td>Внутренняя ошибка сервера</td>
              </tr>
            </tbody>
          </table>
		</section>
		
		<hr class="divider">
		
        <!-- Payment Status
		============================ -->
        <section id="idocs_payment_status">
          <h2>GET /payment_status/{currency}/{payment_id}</h2>
          <p class="lead">Получение статуса платежа</p>
          
          <h3>Описание</h3>
          <p>Возвращает текущий статус платежа по его ID и валюте.</p>
          
          <h3>URL</h3>
          <pre><code class="bash"><?php echo htmlspecialchars($api_base); ?>/payment_status/{currency}/{payment_id}</code></pre>
          
          <h3>Метод</h3>
          <p><code>GET</code></p>
          
          <h3>Параметры пути</h3>
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>Параметр</th>
                <th>Тип</th>
                <th>Описание</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><code>currency</code></td>
                <td>string</td>
                <td>Валюта: <code>ton</code> или <code>jetton</code></td>
              </tr>
              <tr>
                <td><code>payment_id</code></td>
                <td>integer</td>
                <td>ID платежа</td>
              </tr>
            </tbody>
          </table>
        
          <h3>Пример запроса</h3>
          <pre><code class="python">import requests

response = requests.get(
    '<?php echo htmlspecialchars($api_base); ?>/payment_status/ton/123'
)

data = response.json()
print(data)
</code></pre>
          
          <h3>Формат ответа</h3>
          <pre><code class="json">{
  "status": "ok",
  "payment_id": 123,
  "user_id": 1,
  "wallet": "...",
  "amount": 0.01,
  "payment_status": "success",
  "time_recorded": 1704067200000,
  "return_url": "https://example.com/success"
}
</code></pre>
          <p class="alert alert-info">Поле <code>return_url</code> присутствует только если было указано при создании платежа.</p>
        </section>
		
		<hr class="divider">
		
		<!-- Cashier API
		============================ -->
        <section id="idocs_cashier_api">
          <h2>Cashier API</h2>
          <p class="lead mb-5">API для управления платежными кассами</p>
        </section>
		  
        <!-- Create Cashier
		============================ -->
        <section id="idocs_create_cashier">
          <h2>POST /create_cashier</h2>
          <p class="lead">Создание новой платежной кассы</p>
          
          <h3>Описание</h3>
          <p>Создает новую платежную кассу. Касса используется для приема платежей. У каждой кассы есть свой баланс, настройки минимальной/максимальной суммы и webhook URL для уведомлений.</p>
          
          <h3>URL</h3>
          <pre><code class="bash"><?php echo htmlspecialchars($api_base); ?>/create_cashier</code></pre>
          
          <h3>Метод</h3>
          <p><code>POST</code></p>
          
          <h3>Аутентификация</h3>
          <p>Требуется. Передайте <code>user_id</code> и <code>api_token</code> в теле запроса.</p>
          
          <h3>Параметры запроса</h3>
		  <table class="table table-bordered">
                  <thead>
                    <tr>
                <th>Параметр</th>
                <th>Тип</th>
                <th>Обязательный</th>
                <th>Описание</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                <td><code>user_id</code></td>
                <td>integer</td>
                <td>Да</td>
                <td>ID пользователя</td>
                    </tr>
                    <tr>
                <td><code>api_token</code></td>
                <td>string</td>
                <td>Да</td>
                <td>API токен пользователя</td>
                    </tr>
                    <tr>
                <td><code>name</code></td>
                <td>string</td>
                <td>Да</td>
                <td>Название кассы (1-255 символов)</td>
                    </tr>
                    <tr>
                <td><code>description</code></td>
                <td>string</td>
                <td>Нет</td>
                <td>Описание кассы</td>
                    </tr>
                    <tr>
                <td><code>category</code></td>
                <td>string</td>
                <td>Нет</td>
                <td>Категория кассы</td>
                    </tr>
                    <tr>
                <td><code>currency</code></td>
                <td>string</td>
                <td>Нет</td>
                <td>Валюта по умолчанию: <code>ton</code> или <code>jetton</code> (по умолчанию: <code>ton</code>)</td>
              </tr>
              <tr>
                <td><code>min_amount</code></td>
                <td>float</td>
                <td>Нет</td>
                <td>Минимальная сумма платежа (по умолчанию: 0.01, минимум: 0.01, максимум 2 знака после точки)</td>
              </tr>
              <tr>
                <td><code>max_amount</code></td>
                <td>float</td>
                <td>Нет</td>
                <td>Максимальная сумма платежа (необязательно, максимум 2 знака после точки)</td>
              </tr>
              <tr>
                <td><code>webhook_url</code></td>
                <td>string</td>
                <td>Нет</td>
                <td>URL для отправки webhook уведомлений о платежах</td>
              </tr>
              <tr>
                <td><code>jetton_address</code></td>
                <td>string</td>
                <td>Условно</td>
                <td>Адрес контракта Jetton (обязательно, если <code>currency = "jetton"</code>)</td>
                    </tr>
                  </tbody>
                </table>
		
          <h3>Валидация</h3>
          <ul>
            <li>Если <code>currency = "jetton"</code>, то <code>jetton_address</code> обязателен</li>
            <li><code>min_amount</code> должен быть не менее 0.01</li>
            <li><code>max_amount</code> должен быть больше <code>min_amount</code>, если указан</li>
            <li>Все суммы должны иметь не более 2 знаков после точки</li>
          </ul>
          
          <h3>Пример запроса</h3>
          <pre><code class="python">import requests

response = requests.post(
    '<?php echo htmlspecialchars($api_base); ?>/create_cashier',
    json={
        'user_id': 1,
        'api_token': 'ваш_api_токен',
        'name': 'Мой интернет-магазин',
        'description': 'Касса для приема платежей',
        'category': 'Электронная коммерция',
        'currency': 'ton',
        'min_amount': 0.01,
        'max_amount': 1000.00,
        'webhook_url': 'https://example.com/webhook'
    }
)

data = response.json()
print(data)
</code></pre>
          
          <h3>Формат ответа</h3>
          <pre><code class="json">{
  "status": "ok",
  "cashier_id": 1,
  "cashier": {
    "id": 1,
    "user_id": 1,
    "name": "Мой интернет-магазин",
    "description": "Касса для приема платежей",
    "category": "Электронная коммерция",
    "currency": "TON",
    "status": "active",
    "min_amount": 0.01,
    "max_amount": 1000.00,
    "balance": 0.00,
    "webhook_url": "https://example.com/webhook",
    "created_at": "2024-01-01 12:00:00"
  }
}
</code></pre>
          
          <h3>Обработка ошибок</h3>
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>HTTP код</th>
                <th>Описание</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>400</td>
                <td>Неверные параметры (неверный формат суммы, отсутствует jetton_address для jetton и т.д.)</td>
              </tr>
              <tr>
                <td>401</td>
                <td>Неверный API токен</td>
              </tr>
              <tr>
                <td>500</td>
                <td>Внутренняя ошибка сервера</td>
              </tr>
            </tbody>
          </table>
		</section>
		
		<hr class="divider">
		
        <!-- Get Cashiers
		============================ -->
        <section id="idocs_get_cashiers">
          <h2>GET /cashiers/{user_id}</h2>
          <p class="lead">Получение списка касс пользователя</p>
		  
          <h3>Описание</h3>
          <p>Возвращает список всех касс, принадлежащих указанному пользователю.</p>
		  
          <h3>URL</h3>
          <pre><code class="bash"><?php echo htmlspecialchars($api_base); ?>/cashiers/{user_id}</code></pre>
		  
          <h3>Метод</h3>
          <p><code>GET</code></p>
          
          <h3>Аутентификация</h3>
          <p>Не требуется</p>
          
          <h3>Параметры пути</h3>
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>Параметр</th>
                <th>Тип</th>
                <th>Описание</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><code>user_id</code></td>
                <td>integer</td>
                <td>ID пользователя</td>
              </tr>
            </tbody>
          </table>
          
          <h3>Пример запроса</h3>
          <pre><code class="python">import requests

response = requests.get(
    '<?php echo htmlspecialchars($api_base); ?>/cashiers/1'
)

data = response.json()
print(data)
</code></pre>
		
          <h3>Формат ответа</h3>
          <pre><code class="json">{
  "status": "ok",
  "cashiers": [
    {
      "id": 1,
      "user_id": 1,
      "name": "Мой интернет-магазин",
      "currency": "TON",
      "status": "active",
      "balance": 10.50,
      "min_amount": 0.01,
      "max_amount": 1000.00,
      "created_at": "2024-01-01 12:00:00"
    }
  ]
}
</code></pre>
		</section>
		
		<hr class="divider">
		
        <!-- Get Cashier
		============================ -->
        <section id="idocs_get_cashier">
          <h2>GET /cashier/{cashier_id}</h2>
          <p class="lead">Получение информации о кассе</p>
          
          <h3>Описание</h3>
          <p>Возвращает детальную информацию о конкретной кассе. Если указаны <code>user_id</code> и <code>api_token</code>, проверяется принадлежность кассы пользователю.</p>
          
          <h3>URL</h3>
          <pre><code class="bash"><?php echo htmlspecialchars($api_base); ?>/cashier/{cashier_id}?user_id={user_id}&api_token={api_token}</code></pre>
		
          <h3>Метод</h3>
          <p><code>GET</code></p>
          
          <h3>Аутентификация</h3>
          <p>Опционально. Если указаны <code>user_id</code> и <code>api_token</code>, проверяется доступ.</p>
		
          <h3>Параметры пути</h3>
		<table class="table table-bordered">
  <thead>
    <tr>
                <th>Параметр</th>
                <th>Тип</th>
                <th>Описание</th>
    </tr>
  </thead>
  <tbody>
    <tr>
                <td><code>cashier_id</code></td>
                <td>integer</td>
                <td>ID кассы</td>
    </tr>
            </tbody>
          </table>
          
          <h3>Query параметры</h3>
          <table class="table table-bordered">
            <thead>
    <tr>
                <th>Параметр</th>
                <th>Тип</th>
                <th>Обязательный</th>
                <th>Описание</th>
    </tr>
            </thead>
            <tbody>
    <tr>
                <td><code>user_id</code></td>
                <td>integer</td>
                <td>Нет</td>
                <td>ID пользователя (для проверки доступа)</td>
              </tr>
              <tr>
                <td><code>api_token</code></td>
                <td>string</td>
                <td>Нет</td>
                <td>API токен (для проверки доступа)</td>
    </tr>
  </tbody>
</table>
		
          <h3>Пример запроса</h3>
          <pre><code class="python">import requests

response = requests.get(
    '<?php echo htmlspecialchars($api_base); ?>/cashier/1',
    params={
        'user_id': 1,
        'api_token': 'ваш_api_токен'
    }
)

data = response.json()
print(data)
</code></pre>
          
          <h3>Формат ответа</h3>
          <pre><code class="json">{
  "status": "ok",
  "cashier": {
    "id": 1,
    "user_id": 1,
    "name": "Мой интернет-магазин",
    "description": "Касса для приема платежей",
    "category": "Электронная коммерция",
    "currency": "TON",
    "status": "active",
    "min_amount": 0.01,
    "max_amount": 1000.00,
    "balance": 10.50,
    "webhook_url": "https://example.com/webhook",
    "jetton_address": null,
    "created_at": "2024-01-01 12:00:00"
  }
}
</code></pre>
          
          <h3>Обработка ошибок</h3>
          <table class="table table-bordered">
  <thead>
    <tr>
                <th>HTTP код</th>
                <th>Описание</th>
    </tr>
  </thead>
  <tbody>
    <tr>
                <td>401</td>
                <td>Неверный API токен</td>
    </tr>
    <tr>
                <td>403</td>
                <td>Доступ запрещен (касса принадлежит другому пользователю)</td>
    </tr>
    <tr>
                <td>404</td>
                <td>Касса не найдена</td>
    </tr>
  </tbody>
</table>
		</section>
		
		<hr class="divider">
		
        <!-- Update Cashier Status
		============================ -->
        <section id="idocs_update_cashier_status">
          <h2>POST /cashier/{cashier_id}/status</h2>
          <p class="lead">Изменение статуса кассы</p>
          
          <h3>Описание</h3>
          <p>Активирует или деактивирует кассу. Неактивные кассы не могут принимать платежи.</p>
          
          <h3>URL</h3>
          <pre><code class="bash"><?php echo htmlspecialchars($api_base); ?>/cashier/{cashier_id}/status</code></pre>
          
          <h3>Метод</h3>
          <p><code>POST</code></p>
          
          <h3>Аутентификация</h3>
          <p>Требуется. Передайте <code>user_id</code> и <code>api_token</code> в теле запроса.</p>
          
          <h3>Параметры пути</h3>
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>Параметр</th>
                <th>Тип</th>
                <th>Описание</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><code>cashier_id</code></td>
                <td>integer</td>
                <td>ID кассы</td>
              </tr>
            </tbody>
          </table>
          
          <h3>Параметры запроса</h3>
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>Параметр</th>
                <th>Тип</th>
                <th>Обязательный</th>
                <th>Описание</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><code>user_id</code></td>
                <td>integer</td>
                <td>Да</td>
                <td>ID пользователя</td>
              </tr>
              <tr>
                <td><code>api_token</code></td>
                <td>string</td>
                <td>Да</td>
                <td>API токен пользователя</td>
              </tr>
              <tr>
                <td><code>status</code></td>
                <td>string</td>
                <td>Да</td>
                <td>Новый статус: <code>active</code> или <code>inactive</code></td>
              </tr>
            </tbody>
          </table>
          
          <h3>Пример запроса</h3>
          <pre><code class="python">import requests

response = requests.post(
    '<?php echo htmlspecialchars($api_base); ?>/cashier/1/status',
    json={
        'user_id': 1,
        'api_token': 'ваш_api_токен',
        'status': 'active'
    }
)

data = response.json()
print(data)
</code></pre>
          
          <h3>Формат ответа</h3>
          <pre><code class="json">{
  "status": "ok",
  "message": "Cashier status updated successfully"
}
</code></pre>
        </section>
		
		<hr class="divider">
		
        <!-- Update Cashier
		============================ -->
        <section id="idocs_update_cashier">
          <h2>PUT /cashier/{cashier_id}</h2>
          <p class="lead">Обновление настроек кассы</p>
          
          <h3>Описание</h3>
          <p>Обновляет настройки кассы. Валюта и адрес Jetton не могут быть изменены после создания.</p>
          
          <h3>URL</h3>
          <pre><code class="bash"><?php echo htmlspecialchars($api_base); ?>/cashier/{cashier_id}</code></pre>
          
          <h3>Метод</h3>
          <p><code>PUT</code></p>
          
          <h3>Аутентификация</h3>
          <p>Требуется. Передайте <code>user_id</code> и <code>api_token</code> в теле запроса.</p>
          
          <h3>Параметры запроса</h3>
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>Параметр</th>
                <th>Тип</th>
                <th>Обязательный</th>
                <th>Описание</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><code>user_id</code></td>
                <td>integer</td>
                <td>Да</td>
                <td>ID пользователя</td>
              </tr>
              <tr>
                <td><code>api_token</code></td>
                <td>string</td>
                <td>Да</td>
                <td>API токен пользователя</td>
              </tr>
              <tr>
                <td><code>name</code></td>
                <td>string</td>
                <td>Нет</td>
                <td>Новое название кассы</td>
              </tr>
              <tr>
                <td><code>description</code></td>
                <td>string</td>
                <td>Нет</td>
                <td>Новое описание</td>
              </tr>
              <tr>
                <td><code>category</code></td>
                <td>string</td>
                <td>Нет</td>
                <td>Новая категория</td>
              </tr>
              <tr>
                <td><code>min_amount</code></td>
                <td>float</td>
                <td>Нет</td>
                <td>Новая минимальная сумма (максимум 2 знака после точки, минимум 0.01)</td>
              </tr>
              <tr>
                <td><code>max_amount</code></td>
                <td>float</td>
                <td>Нет</td>
                <td>Новая максимальная сумма (максимум 2 знака после точки, должна быть больше min_amount)</td>
              </tr>
              <tr>
                <td><code>webhook_url</code></td>
                <td>string</td>
                <td>Нет</td>
                <td>Новый webhook URL</td>
              </tr>
            </tbody>
          </table>
          
          <h3>Пример запроса</h3>
          <pre><code class="python">import requests

response = requests.put(
    '<?php echo htmlspecialchars($api_base); ?>/cashier/1',
    json={
        'user_id': 1,
        'api_token': 'ваш_api_токен',
        'name': 'Обновленное название',
        'min_amount': 0.10,
        'max_amount': 5000.00
    }
)

data = response.json()
print(data)
</code></pre>
          
          <h3>Формат ответа</h3>
          <pre><code class="json">{
  "status": "ok",
  "message": "Cashier updated successfully"
}
</code></pre>
        </section>
		
		<hr class="divider">
		
		<!-- Withdraw API
		============================ -->
        <section id="idocs_withdraw_api">
          <h2>Withdraw API</h2>
          <p class="lead mb-5">API для вывода средств с касс</p>
          
          <div class="text-center mb-4">
            <img src="./scripts/img/Withdrawal.svg" alt="Вывод средств" class="img-fluid">
          </div>
        </section>
		  
        <!-- Withdraw
		  ============================ -->
        <section id="idocs_withdraw">
          <h2>POST /withdraw</h2>
          <p class="lead">Вывод средств с кассы</p>
          
          <h3>Описание</h3>
          <p>Выполняет вывод средств с указанной кассы на указанный адрес кошелька. Поддерживает вывод TON и Jetton токенов.</p>
          
          <h3>URL</h3>
          <pre><code class="bash"><?php echo htmlspecialchars($withdraw_api); ?>/withdraw</code></pre>
          
          <h3>Метод</h3>
          <p><code>POST</code></p>
          
          <h3>Аутентификация</h3>
          <p>Требуется. API токен проверяется автоматически на основе кассы.</p>
          
          <h3>Параметры запроса</h3>
			<table class="table table-bordered">
  <thead>
    <tr>
                <th>Параметр</th>
                <th>Тип</th>
                <th>Обязательный</th>
                <th>Описание</th>
    </tr>
  </thead>
  <tbody>
    <tr>
                <td><code>cashier_id</code></td>
                <td>integer</td>
                <td>Да</td>
                <td>ID кассы, с которой выполняется вывод</td>
    </tr>
	<tr>
                <td><code>amount</code></td>
                <td>float</td>
                <td>Да</td>
                <td>Сумма для вывода (максимум 2 знака после точки, минимум 0.01)</td>
              </tr>
              <tr>
                <td><code>wallet</code></td>
                <td>string</td>
                <td>Да</td>
                <td>Адрес кошелька получателя (в любом формате)</td>
              </tr>
              <tr>
                <td><code>api_token</code></td>
                <td>string</td>
                <td>Да</td>
                <td>API токен пользователя (владельца кассы)</td>
    </tr>
  </tbody>
</table>
          
          <h3>Валидация</h3>
          <ul>
            <li>Баланс кассы должен быть достаточным для вывода запрошенной суммы</li>
            <li>Сумма должна быть не менее 0.01</li>
            <li>Сумма должна иметь не более 2 знаков после точки</li>
            <li>Касса должна существовать и принадлежать пользователю</li>
            <li>При выводе TON: комиссия блокчейна вычитается из суммы перевода (пользователь получит сумму минус комиссия)</li>
            <li>При выводе JETTON: требуется наличие активной TON кассы с достаточным балансом для оплаты комиссии блокчейна</li>
          </ul>
          
          <h3>Пример запроса</h3>
          <pre><code class="python">import requests

response = requests.post(
    '<?php echo htmlspecialchars($withdraw_api); ?>/withdraw',
    json={
        'cashier_id': 1,
        'amount': 1.00,
        'wallet': '...',
        'api_token': 'ваш_api_токен'
    }
)

data = response.json()
print(data)
</code></pre>
          
          <h3>Формат ответа (успех)</h3>
          <pre><code class="json">{
  "status": "ok",
  "message": "Withdrawal successful. Requested: 1.00 TON, blockchain fee: ~0.007 TON, you will receive: ~0.993 TON",
  "tx_hash": "0x1234...",
  "request_id": "abc123...",
  "amount": 1.00,
  "currency": "TON",
  "blockchain_fee": 0.007,
  "requested_amount": 1.00,
  "actual_amount": 0.993
}
</code></pre>
          
          <h3>Статусы вывода</h3>
          <ul>
            <li><code>pending</code> - Вывод принят в обработку, транзакция ожидает подтверждения в блокчейне</li>
            <li><code>success</code> - Вывод успешно выполнен, транзакция подтверждена в блокчейне</li>
            <li><code>failed</code> - Вывод не выполнен, транзакция отклонена или не найдена в блокчейне</li>
          </ul>
            
          <h3>Обработка ошибок</h3>
			<table class="table table-bordered">
  <thead>
    <tr>
                <th>HTTP код</th>
                <th>Описание</th>
    </tr>
  </thead>
  <tbody>
    <tr>
                <td>400</td>
                <td>Недостаточно средств, неверная сумма, неверные параметры</td>
    </tr>
	<tr>
                <td>401</td>
                <td>Неверный API токен</td>
    </tr>
	<tr>
                <td>404</td>
                <td>Касса не найдена</td>
    </tr>
	<tr>
                <td>500</td>
                <td>Ошибка при выполнении перевода</td>
    </tr>
  </tbody>
</table>
          
          <h3>Примечания</h3>
          <ul>
            <li>Вывод выполняется с баланса конкретной кассы, а не с общего баланса пользователя</li>
            <li>Валюта вывода определяется автоматически на основе валюты кассы</li>
            <li>Для Jetton касс требуется, чтобы у кассы был установлен <code>jetton_address</code></li>
            <li>Транзакция сохраняется в базе данных со статусом <code>pending</code> и может быть отслежена</li>
            <li><strong>Комиссия блокчейна:</strong>
              <ul>
                <li>При выводе TON: комиссия вычитается из суммы перевода (пользователь получает запрошенную сумму минус комиссия)</li>
                <li>При выводе JETTON: комиссия списывается с TON баланса пользователя (требуется активная TON касса)</li>
                <li>Комиссия рассчитывается динамически через API блокчейна (обычно 0.005-0.007 TON для TON, 0.05-0.1+ TON для JETTON)</li>
              </ul>
            </li>
            <li>С кассы списывается ровно запрошенная сумма, комиссию платит пользователь</li>
            <li>После создания запроса на вывод транзакция получает статус <code>pending</code>, который обновляется на <code>success</code> или <code>failed</code> после проверки в блокчейне</li>
          </ul>
          </section>
          
		  <hr class="divider">
		  		  
        <!-- Frontend Integration
		  ============================ -->
        <section id="idocs_frontend">
          <h2>Frontend Integration</h2>
          <p class="lead mb-5">Интеграция <?php echo htmlspecialchars($site_name); ?> в ваше приложение</p>
        </section>
        
        <!-- Payment URL
		============================ -->
        <section id="idocs_payment_url">
          <h2>Создание ссылок на оплату</h2>
          <p class="lead">Самый простой способ приема платежей - создание ссылки на оплату</p>
          
          <h3>Быстрый старт</h3>
          <p>Самый простой способ принять платеж - создать ссылку и отправить её пользователю. Пользователь перейдет по ссылке, увидит QR-код и адрес для оплаты, отправит средства, и вы получите webhook уведомление.</p>
          
          <h3>Формат ссылки</h3>
          <pre><code class="bash"><?php echo htmlspecialchars($site_url); ?>/payment.php?cashier_id={id}&amount={сумма}&wallet={адрес}&currency={валюта}&payload={данные}&return_url={url}</code></pre>
          
          <h3>Обязательные параметры</h3>
			<table class="table table-bordered">
  <thead>
    <tr>
                <th>Параметр</th>
                <th>Описание</th>
                <th>Пример</th>
    </tr>
  </thead>
  <tbody>
    <tr>
                <td><code>cashier_id</code></td>
                <td>ID вашей платежной кассы</td>
                <td><code>1</code></td>
    </tr>
    <tr>
                <td><code>amount</code></td>
                <td>Сумма платежа (максимум 2 знака после точки)</td>
                <td><code>10.50</code></td>
    </tr>
	<tr>
                <td><code>wallet</code></td>
                <td>Адрес кошелька получателя (ваш кошелек)</td>
                <td><code>...</code></td>
    </tr>
            </tbody>
          </table>
          
          <h3>Опциональные параметры</h3>
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>Параметр</th>
                <th>Описание</th>
                <th>Пример</th>
    </tr>
            </thead>
            <tbody>
	<tr>
                <td><code>currency</code></td>
                <td>Валюта: <code>ton</code> или <code>jetton</code> (если не указана, берется из кассы)</td>
                <td><code>ton</code></td>
    </tr>
              <tr>
                <td><code>payload</code></td>
                <td>Дополнительные данные (будут отправлены в webhook)</td>
                <td><code>order_id=12345</code></td>
              </tr>
              <tr>
                <td><code>return_url</code></td>
                <td>URL для перенаправления после успешной оплаты</td>
                <td><code>https://example.com/success</code></td>
              </tr>
  </tbody>
</table>
          
          <h3>Примеры ссылок</h3>
          
          <h4>Простая ссылка для оплаты TON</h4>
          <pre><code class="bash"><?php echo htmlspecialchars($site_url); ?>/payment.php?cashier_id=1&amount=10.50&wallet=...</code></pre>
          
          <h4>Ссылка с дополнительными данными</h4>
          <pre><code class="bash"><?php echo htmlspecialchars($site_url); ?>/payment.php?cashier_id=1&amount=10.50&wallet=...&payload=order_id=12345&user_id=789</code></pre>
          
          <h4>Ссылка для оплаты Jetton</h4>
          <pre><code class="bash"><?php echo htmlspecialchars($site_url); ?>/payment.php?cashier_id=2&amount=100.00&wallet=...&currency=jetton</code></pre>
          
          <h4>Ссылка с return_url для перенаправления после оплаты</h4>
          <pre><code class="bash"><?php echo htmlspecialchars($site_url); ?>/payment.php?cashier_id=1&amount=10.50&wallet=...&return_url=https://example.com/success</code></pre>
          
          <h3>Создание ссылки в коде</h3>
          
          <h4>PHP</h4>
          <pre><code class="php">&lt;?php
$cashier_id = 1;
$amount = 10.50;
$wallet = "...";
$order_id = 12345;

// Создание ссылки
$payment_url = "<?php echo htmlspecialchars($site_url); ?>/payment.php?" . http_build_query([
    'cashier_id' => $cashier_id,
    'amount' => $amount,
    'wallet' => $wallet,
    'payload' => "order_id={$order_id}",
    'return_url' => 'https://example.com/success'  // Опционально
]);

echo "&lt;a href='{$payment_url}'&gt;Оплатить {$amount} TON&lt;/a&gt;";
?&gt;</code></pre>
          
          <h4>Python</h4>
          <pre><code class="python">from urllib.parse import urlencode

cashier_id = 1
amount = 10.50
wallet = "..."
order_id = 12345

# Создание ссылки
params = {
    'cashier_id': cashier_id,
    'amount': amount,
    'wallet': wallet,
    'payload': f'order_id={order_id}',
    'return_url': 'https://example.com/success'  # Опционально
}

payment_url = f"<?php echo htmlspecialchars($site_url); ?>/payment.php?{urlencode(params)}"
print(f"<a href='{payment_url}'>Оплатить {amount} TON</a>")
</code></pre>
          
          <h4>JavaScript</h4>
          <pre><code class="javascript">const cashierId = 1;
const amount = 10.50;
const wallet = "...";
const orderId = 12345;

// Создание ссылки
const params = new URLSearchParams({
    cashier_id: cashierId,
    amount: amount,
    wallet: wallet,
    payload: `order_id=${orderId}`,
    return_url: 'https://example.com/success'  // Опционально
});

const paymentUrl = `<?php echo htmlspecialchars($site_url); ?>/payment.php?${params.toString()}`;
console.log(`<a href="${paymentUrl}">Оплатить ${amount} TON</a>`);
</code></pre>
          
          <h3>Что происходит после перехода по ссылке</h3>
          <ol>
            <li>Пользователь переходит по ссылке</li>
            <li>Система создает платеж (или восстанавливает существующий по UUID)</li>
            <li>Происходит автоматический редирект на URL только с <code>transaction_uuid</code></li>
            <li>Пользователь видит страницу оплаты с QR-кодом и адресом кошелька</li>
            <li>Пользователь отправляет средства на указанный адрес</li>
            <li>Система автоматически отслеживает транзакцию</li>
            <li>При подтверждении отправляется webhook уведомление на ваш сервер</li>
          </ol>
          
          <h3>Редирект на чистый URL</h3>
          <p>После создания платежа происходит автоматический редирект на URL только с <code>transaction_uuid</code>:</p>
          <pre><code class="bash"><?php echo htmlspecialchars($site_url); ?>/payment.php?transaction_uuid=550e8400-e29b-41d4-a716-446655440000</code></pre>
          <p>Это позволяет:</p>
          <ul>
            <li>Сохранить ссылку для повторного использования</li>
            <li>Поделиться ссылкой с другими пользователями</li>
            <li>Восстановить платеж позже, используя тот же UUID</li>
          </ul>
          
          <h3>Восстановление платежа</h3>
          <p>Если пользователь перейдет по ссылке с теми же параметрами (<code>cashier_id</code>, <code>amount</code>, <code>wallet</code>), система восстановит существующий платеж по UUID:</p>
          <pre><code class="bash"># Первый раз - создается новый платеж
<?php echo htmlspecialchars($site_url); ?>/payment.php?cashier_id=1&amount=10.50&wallet=UQC...

# Редирект на UUID
<?php echo htmlspecialchars($site_url); ?>/payment.php?transaction_uuid=550e8400-...

# Повторный переход с теми же параметрами - восстановление платежа
<?php echo htmlspecialchars($site_url); ?>/payment.php?cashier_id=1&amount=10.50&wallet=UQC...
</code></pre>
          
          <h3>Готовые примеры для копирования</h3>
          
          <h4>HTML кнопка</h4>
          <pre><code class="html">&lt;!-- Простая кнопка оплаты --&gt;
&lt;a href="<?php echo htmlspecialchars($site_url); ?>/payment.php?cashier_id=1&amount=10.50&wallet=..." 
   class="btn btn-primary"&gt;
  Оплатить 10.50 TON
&lt;/a&gt;</code></pre>
          
          <h4>HTML форма</h4>
          <pre><code class="html">&lt;form action="<?php echo htmlspecialchars($site_url); ?>/payment.php" method="GET"&gt;
  &lt;input type="hidden" name="cashier_id" value="1"&gt;
  &lt;input type="hidden" name="amount" value="10.50"&gt;
  &lt;input type="hidden" name="wallet" value="..."&gt;
  &lt;input type="hidden" name="payload" value="order_id=12345"&gt;
  &lt;button type="submit" class="btn btn-primary"&gt;Оплатить 10.50 TON&lt;/button&gt;
&lt;/form&gt;</code></pre>
          
          <p class="alert alert-success"><span class="badge badge-success text-uppercase mr-2">Совет</span>Используйте функцию <code>http_build_query()</code> в PHP или <code>URLSearchParams</code> в JavaScript для безопасного создания URL с параметрами.</p>
          </section>
		  
		  <hr class="divider">
		  
        <!-- Webhooks
		  ============================ -->
        <section id="idocs_webhooks">
          <h2>Webhook уведомления</h2>
          <p class="lead">Автоматические уведомления о статусе платежей</p>
          
          <h3>Описание</h3>
          <p>При изменении статуса платежа система отправляет POST запрос на <code>webhook_url</code>, указанный в настройках кассы.</p>
          
          <h3>Настройка webhook</h3>
          <p>Укажите <code>webhook_url</code> при создании или обновлении кассы:</p>
          <pre><code class="python"># При создании кассы
{
  "webhook_url": "https://example.com/webhook"
}

# Или при обновлении
PUT /cashier/1
{
  "webhook_url": "https://example.com/webhook"
}
</code></pre>
			            
          <h3>Формат webhook запроса</h3>
          <p>Система отправляет POST запрос с JSON телом:</p>
          <pre><code class="json">{
  "payment_id": 123,
  "status": "success",
  "currency": "ton",
  "payload": "order_id=12345"
}
</code></pre>
          
          <h3>Параметры webhook</h3>
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>Параметр</th>
                <th>Тип</th>
                <th>Описание</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><code>payment_id</code></td>
                <td>integer</td>
                <td>ID платежа</td>
              </tr>
              <tr>
                <td><code>status</code></td>
                <td>string</td>
                <td>Статус платежа: <code>nohash</code>, <code>pending</code>, <code>success</code>, <code>error</code></td>
              </tr>
              <tr>
                <td><code>currency</code></td>
                <td>string</td>
                <td>Валюта: <code>ton</code> или <code>jetton</code></td>
              </tr>
              <tr>
                <td><code>payload</code></td>
                <td>string</td>
                <td>Дополнительные данные, переданные при создании платежа (если были указаны)</td>
              </tr>
            </tbody>
          </table>
          
          <h3>Безопасность webhook (HMAC подпись)</h3>
          <p>Для защиты от подделки webhook запросов система поддерживает HMAC подпись. Если при создании кассы был указан <code>webhook_secret</code>, все webhook запросы будут содержать заголовок <code>X-Webhook-Signature</code> с подписью.</p>
          
          <h4>Формат подписи</h4>
          <p>Заголовок имеет формат: <code>X-Webhook-Signature: sha256={signature}</code></p>
          
          <h4>Алгоритм проверки подписи</h4>
          <ol>
            <li>Получите JSON тело запроса</li>
            <li>Отсортируйте ключи JSON объекта в алфавитном порядке</li>
            <li>Преобразуйте JSON в строку без пробелов: <code>{"payment_id":123,"status":"success","currency":"ton"}</code></li>
            <li>Вычислите HMAC-SHA256 используя ваш <code>webhook_secret</code> как ключ</li>
            <li>Сравните полученную подпись с заголовком <code>X-Webhook-Signature</code></li>
          </ol>
          
          <h4>Пример проверки подписи (Python)</h4>
          <pre><code class="python">import hmac
import hashlib
import json

def verify_webhook_signature(request_body, signature_header, webhook_secret):
    # Парсим JSON
    payload = json.loads(request_body)
    
    # Сортируем ключи и создаем строку без пробелов
    sorted_payload = json.dumps(payload, sort_keys=True, separators=(',', ':'))
    
    # Вычисляем HMAC-SHA256
    expected_signature = hmac.new(
        webhook_secret.encode('utf-8'),
        sorted_payload.encode('utf-8'),
        hashlib.sha256
    ).hexdigest()
    
    # Извлекаем подпись из заголовка (формат: sha256=...)
    received_signature = signature_header.replace('sha256=', '')
    
    # Сравниваем подписи (используем constant-time сравнение)
    return hmac.compare_digest(expected_signature, received_signature)

# Пример использования
webhook_secret = "ваш_webhook_secret"
signature_header = request.headers.get('X-Webhook-Signature', '')
request_body = request.get_data(as_text=True)

if verify_webhook_signature(request_body, signature_header, webhook_secret):
    # Webhook подлинный
    process_webhook(json.loads(request_body))
else:
    # Webhook подделан
    return {'error': 'Invalid signature'}, 401
</code></pre>
          
          <h4>Пример проверки подписи (PHP)</h4>
          <pre><code class="php">&lt;?php
function verifyWebhookSignature($requestBody, $signatureHeader, $webhookSecret) {
    // Парсим JSON
    $payload = json_decode($requestBody, true);
    
    // Сортируем ключи и создаем строку без пробелов
    ksort($payload);
    $sortedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
    
    // Вычисляем HMAC-SHA256
    $expectedSignature = hash_hmac('sha256', $sortedPayload, $webhookSecret);
    
    // Извлекаем подпись из заголовка (формат: sha256=...)
    $receivedSignature = str_replace('sha256=', '', $signatureHeader);
    
    // Сравниваем подписи (используем constant-time сравнение)
    return hash_equals($expectedSignature, $receivedSignature);
}

// Пример использования
$webhookSecret = "ваш_webhook_secret";
$signatureHeader = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$requestBody = file_get_contents('php://input');

if (verifyWebhookSignature($requestBody, $signatureHeader, $webhookSecret)) {
    // Webhook подлинный
    $data = json_decode($requestBody, true);
    processWebhook($data);
} else {
    // Webhook подделан
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit();
}
?&gt;</code></pre>
          
          <h4>Пример проверки подписи (JavaScript/Node.js)</h4>
          <pre><code class="javascript">const crypto = require('crypto');

function verifyWebhookSignature(requestBody, signatureHeader, webhookSecret) {
    // Парсим JSON
    const payload = JSON.parse(requestBody);
    
    // Сортируем ключи и создаем строку без пробелов
    const sortedPayload = JSON.stringify(payload, Object.keys(payload).sort());
    
    // Вычисляем HMAC-SHA256
    const expectedSignature = crypto
        .createHmac('sha256', webhookSecret)
        .update(sortedPayload)
        .digest('hex');
    
    // Извлекаем подпись из заголовка (формат: sha256=...)
    const receivedSignature = signatureHeader.replace('sha256=', '');
    
    // Сравниваем подписи (используем constant-time сравнение)
    return crypto.timingSafeEqual(
        Buffer.from(expectedSignature),
        Buffer.from(receivedSignature)
    );
}

// Пример использования (Express.js)
app.post('/webhook', (req, res) => {
    const webhookSecret = "ваш_webhook_secret";
    const signatureHeader = req.headers['x-webhook-signature'] || '';
    const requestBody = JSON.stringify(req.body);
    
    if (verifyWebhookSignature(requestBody, signatureHeader, webhookSecret)) {
        // Webhook подлинный
        processWebhook(req.body);
        res.json({ status: 'ok' });
    } else {
        // Webhook подделан
        res.status(401).json({ error: 'Invalid signature' });
    }
});
</code></pre>
          
          <p class="alert alert-warning"><span class="badge badge-danger text-uppercase mr-2">Важно</span>Всегда проверяйте подпись webhook перед обработкой данных. Никогда не обрабатывайте webhook без проверки подписи, даже если запрос приходит с правильного IP адреса.</p>
          
          <h3>Возможные статусы</h3>
          <ul>
            <li><code>nohash</code> - Платеж создан, ожидание оплаты</li>
            <li><code>pending</code> - Транзакция найдена в блокчейне, ожидание подтверждения</li>
            <li><code>success</code> - Платеж успешно выполнен</li>
            <li><code>error</code> - Ошибка при обработке платежа</li>
            </ul>
          
          <h3>Пример обработки webhook (PHP)</h3>
          <pre><code class="php">&lt;?php
// webhook.php
require_once('verify_signature.php'); // Функция проверки подписи

$webhook_secret = "ваш_webhook_secret"; // Получите из настроек кассы
$signature_header = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$request_body = file_get_contents('php://input');

// Проверяем подпись
if (!verifyWebhookSignature($request_body, $signature_header, $webhook_secret)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit();
}

$data = json_decode($request_body, true);

$payment_id = $data['payment_id'];
$status = $data['status'];
$currency = $data['currency'];
$payload = $data['payload'] ?? null;

if ($status === 'success') {
    // Платеж успешно выполнен
    // Обновить статус заказа, начислить товар и т.д.
    echo "Payment {$payment_id} completed successfully";
} else {
    // Обработка других статусов
    echo "Payment {$payment_id} status: {$status}";
}
?&gt;</code></pre>
          
          <h3>Пример обработки webhook (Python)</h3>
          <pre><code class="python">from flask import Flask, request
import hmac
import hashlib
import json

app = Flask(__name__)

def verify_webhook_signature(request_body, signature_header, webhook_secret):
    payload = json.loads(request_body)
    sorted_payload = json.dumps(payload, sort_keys=True, separators=(',', ':'))
    expected_signature = hmac.new(
        webhook_secret.encode('utf-8'),
        sorted_payload.encode('utf-8'),
        hashlib.sha256
    ).hexdigest()
    received_signature = signature_header.replace('sha256=', '')
    return hmac.compare_digest(expected_signature, received_signature)

@app.route('/webhook', methods=['POST'])
def webhook():
    webhook_secret = "ваш_webhook_secret"  # Получите из настроек кассы
    signature_header = request.headers.get('X-Webhook-Signature', '')
    request_body = request.get_data(as_text=True)
    
    # Проверяем подпись
    if not verify_webhook_signature(request_body, signature_header, webhook_secret):
        return {'error': 'Invalid signature'}, 401
    
    data = request.json
    
    payment_id = data['payment_id']
    status = data['status']
    currency = data['currency']
    payload = data.get('payload')
    
    if status == 'success':
        # Платеж успешно выполнен
        print(f"Payment {payment_id} completed successfully")
        # Обновить статус заказа, начислить товар и т.д.
    
    return {'status': 'ok'}, 200
</code></pre>
          
          <h3>Пример обработки webhook (JavaScript/Node.js)</h3>
          <pre><code class="javascript">const express = require('express');
const crypto = require('crypto');
const app = express();

app.use(express.json());

function verifyWebhookSignature(requestBody, signatureHeader, webhookSecret) {
    const payload = JSON.parse(requestBody);
    const sortedPayload = JSON.stringify(payload, Object.keys(payload).sort());
    const expectedSignature = crypto
        .createHmac('sha256', webhookSecret)
        .update(sortedPayload)
        .digest('hex');
    const receivedSignature = signatureHeader.replace('sha256=', '');
    return crypto.timingSafeEqual(
        Buffer.from(expectedSignature),
        Buffer.from(receivedSignature)
    );
}

app.post('/webhook', (req, res) => {
    const webhookSecret = "ваш_webhook_secret"; // Получите из настроек кассы
    const signatureHeader = req.headers['x-webhook-signature'] || '';
    const requestBody = JSON.stringify(req.body);
    
    // Проверяем подпись
    if (!verifyWebhookSignature(requestBody, signatureHeader, webhookSecret)) {
        return res.status(401).json({ error: 'Invalid signature' });
    }
    
    const { payment_id, status, currency, payload } = req.body;
    
    if (status === 'success') {
        // Платеж успешно выполнен
        console.log(`Payment ${payment_id} completed successfully`);
        // Обновить статус заказа, начислить товар и т.д.
    }
    
    res.json({ status: 'ok' });
});

app.listen(3000);
</code></pre>
          
          <p class="alert alert-info"><span class="badge badge-info text-uppercase mr-2">Важно</span>Ваш webhook endpoint должен отвечать статусом 200 в течение 10 секунд. Если ответ не получен, система может повторить запрос.</p>
          
          <p class="alert alert-warning"><span class="badge badge-danger text-uppercase mr-2">Безопасность</span>Если вы используете <code>webhook_secret</code>, всегда проверяйте подпись перед обработкой данных. Не обрабатывайте webhook без проверки подписи.</p>
        </section>
        
		<hr class="divider">
        
        <!-- Payment Status Check
		============================ -->
        <section id="idocs_payment_status_check">
          <h2>Проверка статуса платежа</h2>
          <p class="lead">Проверка статуса платежа на странице оплаты</p>
          
          <h3>Описание</h3>
          <p>На странице оплаты автоматически выполняется проверка статуса платежа каждые 30 секунд. Также можно проверить статус вручную через API.</p>
          
          <h3>Автоматическая проверка</h3>
          <p>На странице <code>payment.php</code> статус проверяется автоматически. Платеж имеет таймаут 20 минут.</p>
          
          <h3>Ручная проверка через API</h3>
          <pre><code class="python">import requests

# Проверка статуса по ID и валюте
response = requests.get(
    '<?php echo htmlspecialchars($api_base); ?>/payment_status/ton/123'
)

data = response.json()
print(f"Status: {data['payment_status']}")
</code></pre>
          
          <h3>Проверка по UUID</h3>
          <pre><code class="python">import requests

# Получение платежа по UUID
response = requests.get(
    '<?php echo htmlspecialchars($api_base); ?>/payment_by_uuid/550e8400-e29b-41d4-a716-446655440000'
)

data = response.json()
print(f"Status: {data['payment_status']}")
</code></pre>
        </section>
        
		<hr class="divider">
		
        <!-- Examples
		============================ -->
        <section id="idocs_examples">
          <h2>Примеры интеграции</h2>
          <p class="lead mb-5">Готовые примеры кода для различных языков программирования</p>
        </section>
        
        <!-- Example Python
		============================ -->
        <section id="idocs_example_python">
          <h2>Пример на Python</h2>
          
          <h3>Полная интеграция</h3>
          <pre><code class="python">import requests
import time

API_BASE = "<?php echo htmlspecialchars($api_base); ?>"
WITHDRAW_API = "<?php echo htmlspecialchars($withdraw_api); ?>"

# Ваши данные
USER_ID = 1
API_TOKEN = "ваш_api_токен"
CASHIER_ID = 1

# 1. Создание платежа
def create_payment(amount, wallet, currency="ton"):
    response = requests.post(
        f"{API_BASE}/create_payment",
        json={
            "cashier_id": CASHIER_ID,
            "amount": amount,
            "wallet": wallet,
            "currency": currency,
            "payload": f"order_id=12345"
        }
    )
    return response.json()

# 2. Проверка статуса платежа
def check_payment_status(payment_id, currency="ton"):
    response = requests.get(
        f"{API_BASE}/payment_status/{currency}/{payment_id}"
    )
    return response.json()

# 3. Получение списка касс
def get_cashiers():
    response = requests.get(
        f"{API_BASE}/cashiers/{USER_ID}"
    )
    return response.json()

# 4. Вывод средств
def withdraw(cashier_id, amount, wallet):
    response = requests.post(
        f"{WITHDRAW_API}/withdraw",
        json={
            "cashier_id": cashier_id,
            "amount": amount,
            "wallet": wallet,
            "api_token": API_TOKEN
        }
    )
    return response.json()

# Пример использования
if __name__ == "__main__":
    # Создание платежа
    payment = create_payment(
        amount=0.01,
        wallet="..."
    )
    print(f"Payment created: {payment}")
    
    # Проверка статуса
    payment_id = payment['payment_id']
    status = check_payment_status(payment_id)
    print(f"Payment status: {status['payment_status']}")
</code></pre>
        </section>
		
		<hr class="divider">
		
        <!-- Example PHP
		============================ -->
        <section id="idocs_example_php">
          <h2>Пример на PHP</h2>
          
          <h3>Полная интеграция</h3>
          <pre><code class="php">&lt;?php
$api_base = "<?php echo htmlspecialchars($api_base); ?>";
$withdraw_api = "<?php echo htmlspecialchars($withdraw_api); ?>";
$user_id = 1;
$api_token = "ваш_api_токен";
$cashier_id = 1;

// Функция для создания платежа
function createPayment($amount, $wallet, $currency = "ton") {
    global $api_base, $cashier_id;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "$api_base/create_payment",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            "cashier_id" => $cashier_id,
            "amount" => $amount,
            "wallet" => $wallet,
            "currency" => $currency,
            "payload" => "order_id=12345"
        ]),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json"
        ],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Функция для проверки статуса
function checkPaymentStatus($payment_id, $currency = "ton") {
    global $api_base;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "$api_base/payment_status/$currency/$payment_id",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Пример использования
$payment = createPayment(
    0.01,
    "..."
);

echo "Payment created: " . json_encode($payment) . "\n";

$status = checkPaymentStatus($payment['payment_id']);
echo "Payment status: " . $status['payment_status'] . "\n";
?&gt;</code></pre>
        </section>
		
		<hr class="divider">
		
        <!-- Example JavaScript
		============================ -->
        <section id="idocs_example_javascript">
          <h2>Пример на JavaScript</h2>
          
          <h3>Полная интеграция</h3>
          <pre><code class="javascript">const API_BASE = "<?php echo htmlspecialchars($api_base); ?>";
const WITHDRAW_API = "<?php echo htmlspecialchars($withdraw_api); ?>";
const USER_ID = 1;
const API_TOKEN = "ваш_api_токен";
const CASHIER_ID = 1;

// Создание платежа
async function createPayment(amount, wallet, currency = "ton") {
    const response = await fetch(`${API_BASE}/create_payment`, {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify({
            cashier_id: CASHIER_ID,
            amount: amount,
            wallet: wallet,
            currency: currency,
            payload: "order_id=12345"
        })
    });
    
    return await response.json();
}

// Проверка статуса платежа
async function checkPaymentStatus(paymentId, currency = "ton") {
    const response = await fetch(
        `${API_BASE}/payment_status/${currency}/${paymentId}`
    );
    
    return await response.json();
}

// Получение списка касс
async function getCashiers() {
    const response = await fetch(`${API_BASE}/cashiers/${USER_ID}`);
    return await response.json();
}

// Вывод средств
async function withdraw(cashierId, amount, wallet) {
    const response = await fetch(`${WITHDRAW_API}/withdraw`, {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify({
            cashier_id: cashierId,
            amount: amount,
            wallet: wallet,
            api_token: API_TOKEN
        })
    });
    
    return await response.json();
}

// Пример использования
(async () => {
    const payment = await createPayment(
        0.01,
        "..."
    );
    console.log("Payment created:", payment);
    
    const status = await checkPaymentStatus(payment.payment_id);
    console.log("Payment status:", status.payment_status);
})();
</code></pre>
        </section>
		  
		  <hr class="divider">
		  
        <!-- Error Handling
		============================ -->
        <section id="idocs_errors">
          <h2>Обработка ошибок</h2>
          <p class="lead">HTTP коды статуса и формат ошибок</p>
          
          <h3>HTTP коды статуса</h3>
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>Код</th>
                <th>Описание</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>200</td>
                <td>Успешный запрос</td>
              </tr>
              <tr>
                <td>400</td>
                <td>Неверные параметры запроса (неверный формат суммы, недостаточно средств и т.д.)</td>
              </tr>
              <tr>
                <td>401</td>
                <td>Не авторизован (неверный API токен)</td>
              </tr>
              <tr>
                <td>403</td>
                <td>Доступ запрещен (касса принадлежит другому пользователю)</td>
              </tr>
              <tr>
                <td>404</td>
                <td>Ресурс не найден (касса, платеж и т.д.)</td>
              </tr>
              <tr>
                <td>500</td>
                <td>Внутренняя ошибка сервера</td>
              </tr>
            </tbody>
          </table>
          
          <h3>Формат ответа с ошибкой</h3>
          <pre><code class="json">{
  "detail": "Описание ошибки"
}
</code></pre>
          
          <h3>Примеры ошибок</h3>
          <ul>
            <li><code>{"detail": "Amount is less than minimum: 0.01"}</code> - Сумма меньше минимальной</li>
            <li><code>{"detail": "Insufficient balance. Available: 0.50, Requested: 1.00"}</code> - Недостаточно средств</li>
            <li><code>{"detail": "Invalid API token"}</code> - Неверный API токен</li>
            <li><code>{"detail": "Cashier not found"}</code> - Касса не найдена</li>
            <li><code>{"detail": "Amount must have no more than 2 decimal places"}</code> - Неверный формат суммы</li>
          </ul>
        </section>        
        
      </div>
    </div>
	
  </div>
  <!-- Content end --> 
  
  <!-- Footer
  ============================ -->
  <footer id="footer" class="section bg-dark footer-text-light">
    <div class="container">
      <p class="text-center mb-0">
        &copy; <span id="currentYear"></span> <?php echo htmlspecialchars($site_name); ?>. Все права защищены. Документация API платёжной системы TON.
      </p>
      <p class="text-center mt-2 mb-0 small opacity-75">
        <a href="https://whaile.ru/" target="_blank" rel="noopener" class="text-decoration-none">by _whaile_</a>
      </p>
    </div>
  </footer>
  <!-- Footer end -->
  
</div>
<!-- Document Wrapper end --> 

<!-- Back To Top --> 
<a id="back-to-top" data-toggle="tooltip" title="Back to Top" href="javascript:void(0)"><i class="fa fa-chevron-up"></i></a> 

<!-- JavaScript
============================ -->
<script src="scripts/assets/vendor/jquery/jquery.min.js"></script>
<script src="scripts/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<!-- Highlight JS -->
<script src="scripts/assets/vendor/highlight.js/highlight.min.js"></script>
<!-- Easing --> 
<script src="scripts/assets/vendor/jquery.easing/jquery.easing.min.js"></script>
<!-- Magnific Popup --> 
<script src="scripts/assets/vendor/magnific-popup/jquery.magnific-popup.min.js"></script>
<!-- Custom Script -->
<script src="scripts/assets/js/theme.js"></script>


<style>
    /* Общие переходы */
    body, .navbar, .card, .section, pre, code, footmax-widther, .sidebar, .idocs-navigation {
        transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
    }

    /* Базовые цвета тёмной темы */
    .dark-theme {
        background-color: #0e0e10 !important;
        color: #d4d4d4 !important;
    }

    .dark-theme .idocs-content {
        background-color: #1a1a1c !important;
    }

    .dark-theme .alert-info {
        color: #0c5460 !important;
    }

    .dark-theme .alert-info a, a:focus {
        color: #0366d6 !important;
    }

    /* Заголовки */
    .dark-theme h1,
    .dark-theme h2,
    .dark-theme h3,
    .dark-theme h4,
    .dark-theme h5,
    .dark-theme h6 {
        color: #e6e6e6 !important;
    }

    /* Текст и параграфы */
    .dark-theme p,
    .dark-theme li,
    .dark-theme span,
    .dark-theme strong {
        color: #d4d4d4 !important;
    }

    /* Общее правило для всех ссылок в тёмной теме */
    .dark-theme a {
        color: #e0e0e0 !important;
        text-decoration: underline dotted #555 !important;
    }

    /* Исключения — переопределяем стиль */
    .dark-theme .accordion:not(.accordion-alternate) .card-header a.collapsed,
    .dark-theme .alert-info a,
    .dark-theme a:focus {
        color: inherit !important;
        text-decoration: none !important;
    }

    .dark-theme a:hover {
        color: #ffffff !important;
        text-decoration: underline solid #777 !important;
    }

    /* Навигация */
    .dark-theme .navbar {
        background-color: #141416 !important;
        border-bottom: 1px solid #2a2a2c !important;
    }

    /* Боковая панель */
    .dark-theme .idocs-navigation,
    .dark-theme .sidebar {
        background-color: #141416 !important;
        border-right: 1px solid #2a2a2c !important;
    }

    /* Карточки, контейнеры, секции */
    .dark-theme .card,
    .dark-theme .section,
    .dark-theme .content,
    .dark-theme .bg-light,
    .dark-theme .bg-white {
        background-color: #1a1a1c !important;
        color: #d4d4d4 !important;
        border-color: #2a2a2c !important;
    }

    /* Прелоадер, хедер, футер */
    .dark-theme header,
    .dark-theme footer {
        background-color: #141416 !important;
        color: #b5b5b5 !important;
        border-color: #2a2a2c !important;
    }

    /* Кнопки */
    .dark-theme .btn {
        background-color: #1f1f21 !important;
        color: #d4d4d4 !important;
        border: 1px solid #2a2a2c !important;
    }
    .dark-theme .btn:hover {
        background-color: #2a2a2c !important;
    }

    /* ====== Dark Theme Code Highlighting ====== */
    .dark-theme pre,
    .dark-theme code {
        background-color: #1e1e1e !important; /* мягкий фон */
        color: #d4d4d4 !important; /* базовый цвет текста */
        border: none !important; /* без рамки */
        border-radius: 6px;
    }

    /* Цвета синтаксиса — стиль в духе VSCode One Dark */
    .dark-theme .hljs-keyword,
    .dark-theme .hljs-selector-tag,
    .dark-theme .hljs-literal,
    .dark-theme .hljs-section,
    .dark-theme .hljs-link {
        color: #c586c0 !important; /* розово-фиолетовый */
    }

    .dark-theme .hljs-function,
    .dark-theme .hljs-title,
    .dark-theme .hljs-name {
        color: #dcdcaa !important; /* жёлтый */
    }

    .dark-theme .hljs-string,
    .dark-theme .hljs-attr,
    .dark-theme .hljs-template-variable,
    .dark-theme .hljs-type {
        color: #ce9178 !important; /* красновато-оранжевый */
    }

    .dark-theme .hljs-number,
    .dark-theme .hljs-symbol,
    .dark-theme .hljs-bullet {
        color: #b5cea8 !important; /* зелёный */
    }

    .dark-theme .hljs-comment,
    .dark-theme .hljs-quote {
        color: #6a9955 !important; /* зелёно-серый, приглушённый */
        font-style: italic !important;
    }

    .dark-theme .hljs-meta {
        color: #9cdcfe !important; /* голубой */
    }

    .dark-theme .hljs-variable,
    .dark-theme .hljs-params,
    .dark-theme .hljs-class .hljs-title {
        color: #4ec9b0 !important; /* бирюзовый */
    }

    .dark-theme .hljs-built_in,
    .dark-theme .hljs-builtin-name {
        color: #569cd6 !important; /* синий */
    }

    /* Таблицы */
    .dark-theme table {
        background-color: #1a1a1c !important;
        color: #d4d4d4 !important;
    }
    .dark-theme th, .dark-theme td {
        border-color: #2a2a2c !important;
    }

    /* Маркировки, выделения */
    .dark-theme mark,
    .dark-theme .highlight {
        background-color: #2a2a2c !important;
        color: #f0f0f0 !important;
    }

    /* Scrollbar */
    .dark-theme ::-webkit-scrollbar {
        width: 10px;
    }
    .dark-theme ::-webkit-scrollbar-thumb {
        background-color: #2a2a2c;
        border-radius: 6px;
    }
    .dark-theme ::-webkit-scrollbar-thumb:hover {
        background-color: #3a3a3c;
    }

    /* Исправление горизонтального скролла */
    body {
        overflow-x: hidden;
    }
    .idocs-content {
        overflow-x: hidden;
        word-wrap: break-word;
    }
    .idocs-content pre {
        overflow-x: auto;
        max-width: 100%;
        word-wrap: normal;
    }
    .idocs-content table {
        max-width: 100%;
        display: block;
        overflow-x: auto;
        white-space: nowrap;
    }
    .idocs-content .table-responsive {
        display: block;
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    /* Формы и инпуты */
    .dark-theme input,
    .dark-theme textarea,
    .dark-theme select {
        background-color: #1c1c1f !important;
        color: #d4d4d4 !important;
        border: 1px solid #2a2a2c !important;
    }
    .dark-theme input:focus,
    .dark-theme textarea:focus,
    .dark-theme select:focus {
        background-color: #202023 !important;
        border-color: #3a3a3c !important;
        outline: none;
    }

    /* Тени (приглушённые) */
    .dark-theme .shadow,
    .dark-theme .card {
        box-shadow: none !important;
    }

    /* Акценты — просто ярче текста */
    .dark-theme em,
    .dark-theme strong {
        color: #eaeaea !important;
    }
</style>

</body>
</html>
