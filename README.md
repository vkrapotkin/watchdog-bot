# Watchdog Bot for Laravel

Composer-пакет для мониторинга **одного сайта на его собственном сервере**. У каждого сайта свои Telegram-бот, получатели и файлы состояния. PHP 8.2+, ext-curl, ext-json. Интеграция с Laravel 11–13; локальная проверка интеграции выполняется на Laravel 13.

## Установка

После публикации версии в Packagist:

```sh
composer require vkrapotkin/watchdog-bot:^0.1
```

Laravel автоматически обнаруживает провайдер. В `.env` сайта задайте:

```dotenv
WATCHDOG_SITE_ID=ftsso
WATCHDOG_SITE_NAME=FTSSO
WATCHDOG_URL=https://ftsso.ru/
WATCHDOG_EXPECTED_TEXT=ФТССО
WATCHDOG_TELEGRAM_BOT_USERNAME=ftsso_monitor_bot
WATCHDOG_TELEGRAM_BOT_TOKEN=replace_with_private_token
WATCHDOG_TELEGRAM_CHAT_IDS=123456789,-1001234567890
WATCHDOG_TELEGRAM_PROXY=
```

Username справочный, API использует токен. Получатели — ID чатов строками, разделённые запятой. Секреты не добавляются в Git.

```sh
# Обновите кэш настроек штатной процедурой релиза, если он включён.
php artisan config:cache
php artisan watchdog:configure
php vendor/vkrapotkin/watchdog-bot/bin/watchdog --config=/var/www/ftsso/storage/app/watchdog/config.json --check
```

`watchdog:configure` экспортирует настройки в `storage/app/watchdog/config.json`. После изменения `.env` повторите экспорт с актуальным Laravel config cache. Запускайте экспорт от пользователя будущего таймера. Каталог должен быть вне public, с правами 0700, JSON — 0600 (на Windows используйте ACL). В нём есть копия токена; убедитесь, что Git и публичные архивы не включают этот каталог. В стандартном Laravel storage/app исключён из Git.

## Независимость от приложения

Планировщик запускает самостоятельный `bin/watchdog`. Он не загружает Laravel, Composer-autoload приложения, `.env` или БД сайта. Читает только собственный экспортированный JSON, состояние хранит в соседнем каталоге `state`. Поэтому отказ Firebird или ошибка загрузки Laravel не мешают проверке.

Модуль находится в vendor каждого сайта. На одном сервере действует свой таймер и своя очередь; общего сервера мониторинга нет. Полное отключение хоста или интернета также выключает возможность уведомлять — это ограничение локального размещения.

## Проверка и сообщения

Каждые 10 минут GET к публичному HTTPS URL должен вернуть прямой 200 и необязательный текст-маркер. Редирект, HTTP/TLS/сетевая ошибка, отсутствие маркера и ответ больше 1 MiB считаются сбоем. cURL ограничивает полное время запроса 15 секундами. При ошибке повтор через 30 секунд; только подтверждённый сбой создаёт уведомления.

При восстановлении приходит отдельное сообщение с длительностью с момента обнаружения. Начальный успешный запуск и неизменное состояние молчат. Перезапусков служб и команд управления сервером нет. Обычная задержка обнаружения — до 10 минут плюс повтор и длительность запросов.

Очередь и состояние записываются атомарно в JSON. Каждая успешная доставка сохраняется отдельно; неуспешная повторяется при следующем запуске. Порядок падения/восстановления сохраняется для каждого чата. Удалённые из настроек получатели больше не получают очередь. Повреждённое состояние не сбрасывается молча. Храните каталог между релизами.

Если Telegram принял сообщение, а ответ потерялся или процесс остановился до сохранения подтверждения, возможен дубль. При длительном отказе доставки очередь накапливается — следите за журналом и местом на диске.

## Бот для каждого нового проекта

1. В [BotFather](https://t.me/BotFather) выполните `/newbot`, выберите название и уникальное имя с окончанием `bot`.
2. Сохраните токен только в настройках этого сайта.
3. Каждый личный получатель открывает бота и отправляет `/start`. Для группы добавьте бота, разрешите ему писать и отправьте команду с упоминанием бота.
4. Получите `message.chat.id` через [getUpdates](https://core.telegram.org/bots/api#getupdates) на доверенном компьютере, не публикуя токен или полные ответы с сообщениями.
5. Внесите ID в настройки, выполните экспорт и явную тестовую отправку:

```sh
php vendor/vkrapotkin/watchdog-bot/bin/watchdog --config=/var/www/ftsso/storage/app/watchdog/config.json --test-message
```

Подтвердите получение у каждого адресата. Тест не меняет состояние мониторинга. Подтверждение [sendMessage](https://core.telegram.org/bots/api#sendmessage) означает принятие API, а не прочтение сообщения. Webhook и постоянный процесс бота не нужны; получателей задаёт владелец сайта.

## Таймер на сервере сайта

Шаблоны `deploy/watchdog-bot@.service` и `.timer` предполагают `/var/www/%i`, PHP `/usr/bin/php` и пользователя www-data. Проверьте пути, версию PHP и владельца экспортированных файлов. `%i=ftsso` означает `/var/www/ftsso`. Скопируйте шаблоны в `/etc/systemd/system/`:

```sh
systemd-analyze verify /etc/systemd/system/watchdog-bot@.service /etc/systemd/system/watchdog-bot@.timer
systemctl daemon-reload
systemctl enable --now watchdog-bot@ftsso.timer
systemctl start watchdog-bot@ftsso.service
systemctl list-timers 'watchdog-bot*'
journalctl -u watchdog-bot@ftsso.service
```

Запуск в минуты 00/10/20/30/40/50, после включения сервера догоняется один пропущенный запуск. Файловая блокировка предотвращает перекрытие ручных и плановых проверок. Лимит службы 5 минут; остаток большой очереди обрабатывается позже. Не запускайте одновременно cron и таймер.

Отключение: `systemctl disable --now watchdog-bot@ftsso.timer`. Уже выполняемый цикл при необходимости остановите через `systemctl stop watchdog-bot@ftsso.service`. Файлы сохраняются.

Без флагов CLI выполняет один цикл. Код 0 — цикл обработан (сайт может быть недоступен), 2 — ошибка монитора или недоставленные сообщения. Вывод JSON показывает состояние и размер очереди. `--check` только проверяет URL: 0 — здоров, 1 — сбой, 2 — ошибка настроек; без отправки и изменения состояния.

## Разработка и выпуск

### VPN только для Telegram (начиная с v0.1.1)

В `.env` сайта задайте `WATCHDOG_TELEGRAM_PROXY=socks5h://127.0.0.1:10880`, затем обновите Laravel config cache (если используется) и выполните `watchdog:configure`. Только `sendMessage` использует этот прокси. Проверка сайта явно выполняется напрямую, даже если в окружении заданы HTTP_PROXY/HTTPS_PROXY/ALL_PROXY. При ошибке прокси скрытого переключения на прямую отправку нет: сообщение остаётся в очереди.

Пример для Xray + VLESS/REALITY из экспорта Happ:

- Бинарник: `/opt/watchdog-xray/xray` (официальный релиз XTLS/Xray-core, проверяйте SHA-256 архива).
- Активная закрытая конфигурация: `/etc/watchdog-xray/config.json`, root:watchdog-xray, 0640; каталог 0750.
- Служба: `/etc/systemd/system/watchdog-xray.service`; шаблон находится в `deploy/` пакета. Требуется отдельный системный пользователь watchdog-xray без shell и домашнего каталога.
- SOCKS слушает только `127.0.0.1:10880`; импортёр разрешает только TCP к `api.telegram.org:443`. Остальные назначения блокируются. Системные маршруты, SSH, nginx и глобальные proxy-переменные не меняются.

Экспорт Happ содержит секреты. Храните его вне public/Git, например `/root/happ-export.json` с правами 0600. Подготовьте **новый** файл, выбрав номер подходящего TCP/REALITY-узла (нумерация с нуля):

```sh
cd /var/www/ftsso
php vendor/vkrapotkin/watchdog-bot/bin/import-happ --source=/root/happ-export.json --output=/etc/watchdog-xray/config.next.json --node-index=0
/opt/watchdog-xray/xray run -test -config /etc/watchdog-xray/config.next.json
chown root:watchdog-xray /etc/watchdog-xray/config.next.json
chmod 0640 /etc/watchdog-xray/config.next.json
# Перед заменой сохраните текущий config.json в уникальный закрытый backup-файл.
mv /etc/watchdog-xray/config.next.json /etc/watchdog-xray/config.json
systemctl restart watchdog-xray
curl --proxy socks5h://127.0.0.1:10880 --connect-timeout 10 --max-time 20 -I https://api.telegram.org
sudo -u www-data php vendor/vkrapotkin/watchdog-bot/bin/watchdog --config=/var/www/ftsso/storage/app/watchdog/config.json --test-message
```

Если проверка неудачна, верните сохранённый config.json и перезапустите только watchdog-xray. Для просмотра используйте `sudoedit /etc/watchdog-xray/config.json` (файл содержит ключи, не публикуйте вывод). Статус: `systemctl status watchdog-xray`, `journalctl -u watchdog-xray`. Xray access/error-логи в этом шаблоне отключены, чтобы не сохранять адреса и данные подключения; системный журнал показывает жизненный цикл службы.

**Подписка и обновление серверов:** экспорт Happ — статический снимок. Интервал обновления подписки в Happ не обновляет этот файл на сервере и не доказывает, что ключи меняются каждый час. В этой версии импорт вручную, автоматического загрузчика подписки нет. Для почасового обновления нужна отдельная ссылка подписки и её формат; её следует хранить как секрет, валидировать новый конфиг до применения и сохранять последний рабочий вариант при сбое загрузки. До настройки такого обновления повторяйте экспорт/импорт, если VPN-провайдер меняет сервер или ключи.

### Проверено при внедрении в FTSSO (13 сентября 2026)

Версия v0.1.0 опубликована в Packagist и установлена обычным Composer install на Linux-сервер FTSSO (PHP 8.5, Laravel 13). Закрытый JSON экспортирован от www-data; каталог 0700, конфиг 0600. Systemd oneshot вернул `Result=success`, `ExecMainStatus=0`, HTTP 200, pending=0. Локально пройдены 9 тестов ядра и 12 тестов приложения (90 assertions) на копии production-БД.

На первом этапе api.telegram.org оказался недоступен: IPv4 — тайм-аут подключения, IPv6 — ошибка соединения. Версия v0.1.1 решила отправку через отдельный Xray-прокси: реальный `--test-message` на production получил подтверждение Telegram API, таймер включён. Xray v26.3.27 слушает только 127.0.0.1:10880 и принимает только Telegram HTTPS; посторонний адрес через него заблокирован. PHP-проверка сайта вернула HTTP 200 даже при намеренно неработающих глобальных proxy-переменных. Работает статический снимок Happ, автоматическое обновление подписки ещё не настроено. Успешная HTTP-проверка сайта сама по себе не доказывает работу уведомлений.

### Выпуск версий

```sh
composer validate --strict
php tests/run.php
php bin/watchdog --config=config.example.json --check
```

Тесты ядра не требуют vendor или Laravel, отправитель Telegram подменён. Реальную доставку и работу systemd проверяйте при внедрении на управляемом тестовом endpoint, не останавливая боевой сайт.

Публикация: публичный GitHub → проверенный коммит → тег v0.1.0 → URL репозитория в [Packagist Submit](https://packagist.org/packages/submit). Версия определяется тегом. Подключите интеграцию обновлений Packagist для будущих тегов. Пока версия не появилась в Packagist, обычная команда composer require недоступна.

Локально можно подключить Composer path repository с версией 0.1.0 в options. Не переносите Windows path repository на сервер. После публикации обновите lock сайта из Packagist.
