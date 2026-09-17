# HTTP-транспорт и таймауты

При включённом `ClientOptions::readTimeout` SDK использует native `SoapClient`
для WSDL и SOAP XML, а cURL — для отправки SOAP HTTP-запросов. Требуется
`ext-curl`. По умолчанию `connectTimeout` равен 10 секундам, `readTimeout` —
30 секундам.

## Значение лимитов

- `readTimeout` — общий лимит одного SOAP HTTP-запроса: подключение, отправка,
  ожидание заголовков и получение всего тела ответа. Это не таймаут простоя
  между порциями данных. Положительные дробные секунды округляются вверх до
  миллисекунды.
- `connectTimeout` дополнительно ограничивает установление соединения.
  Raw `connection_timeout` имеет приоритет над типизированным значением.
  Если отдельный лимит не задан или raw-значение равно `0`, используется
  положительный `default_socket_timeout`; при неограниченном глобальном
  значении подключение ограничивает только общий лимит запроса.
- Явный `stream_context.http.timeout` имеет приоритет над `readTimeout`,
  в том числе при `readTimeout: null`. Требуется конечное положительное число.
- Контекст только с заголовками или TLS-настройками получает типизированный
  timeout в отдельной копии: исходный ресурс вызывающего кода не изменяется.
- WSDL загружается native `SoapClient` с HTTP stream timeout. Это отдельная
  стадия; общий бюджет всего дерева WSDL/imports не устанавливается.
- Получение справочников, проверка доступности сервера и каждый запрос при
  failover имеют свои лимиты. Единого бюджета для всего вызова фасада SDK нет.

SDK не меняет глобальный `default_socket_timeout`. Для чтения транспортная
ошибка допускает переход к следующему endpoint. После отправки записи timeout
означает неопределённый результат (`AmbiguousMutationException`); автоматического
повтора записи нет. Сбой загрузки WSDL происходит до отправки операции и
остаётся безопасным для повторной попытки.

## Поддерживаемые настройки cURL-режима

| Настройки | Поведение |
| --- | --- |
| `soap_version`, `encoding`, `classmap`, `typemap`, `features`, `cache_wsdl` | Обрабатываются native SOAP-кодеком |
| `connection_timeout`, `user_agent` | Подключение и User-Agent HTTP-запроса |
| `login`, `password`, `authentication` | HTTP Basic или Digest |
| `proxy_host`, `proxy_port`, `proxy_login`, `proxy_password` | Явный HTTP proxy, Basic proxy authentication; переменные окружения proxy не применяются к SOAP POST |
| `compression` | SOAP-флаги gzip/deflate и приёма сжатого ответа; сжатие запроса требует zlib |
| `trace` | `__getLastRequest*()` и `__getLastResponse*()`; тела доступны без сжатия |
| `keep_alive` | Управляет заголовком Connection; каждый вызов всё равно открывает новое соединение |
| `http.timeout`, `http.header`, `http.user_agent`, `http.content_type` в stream context | Таймаут и HTTP-заголовки; raw `user_agent` в SOAP options имеет приоритет над context |
| `http.protocol_version`, `http.max_redirects` в stream context | Только HTTP 1.0/1.1 и `max_redirects: 0` |
| `ssl.verify_peer`, `ssl.verify_peer_name`, `ssl.cafile`, `ssl.capath` | Проверка сертификата и имени сервера; обе проверки включены по умолчанию, используется хранилище доверия cURL |
| `ssl.local_cert`, `ssl.local_pk`, `ssl.passphrase` | Клиентский сертификат и ключ; одноимённые SOAP options имеют приоритет |
| `ssl.allow_self_signed` | Принимается только `false`; для собственного CA задайте `cafile`/`capath` |

Cookies из ответов сохраняются на экземпляр клиента; доступны `__setCookie()`
и `__getCookies()`. SOAP 1.1/1.2 и SOAP Fault в HTTP 500 обрабатываются штатным
SOAP-кодеком. Chunked и gzip/deflate-ответы декодируются HTTP-транспортом.

SDK сохраняет за собой `location` и `exceptions`. Контекстные заголовки Host,
Connection, User-Agent, Content-Length, Content-Type, SOAPAction и
Transfer-Encoding формирует транспорт. При заданном `login` или `proxy_login`
также не применяются соответствующие пользовательские заголовки авторизации.

Redirects не выполняются. Соединения между SOAP-вызовами не переиспользуются:
это исключает скрытый повтор POST при обнаружении устаревшего соединения cURL.
Для one-way SOAP-метода транспорт возвращает `null`, но ждёт HTTP-ответ в пределах
того же timeout; текущий API SDK такие методы не использует.

Другие параметры stream context, notification callbacks и `ssl_method`
отклоняются с `InvalidArgumentException` до загрузки WSDL. Неподдерживаемые
настройки не игнорируются и не включают незаметный возврат к native HTTP.

## Прежний native HTTP

`ClientOptions(readTimeout: null)` **при отсутствии raw `http.timeout`**
выбирает обычный native `SoapClient`. Это явный режим для нестандартных SOAP
options и stream context. В нём ожидание SOAP-ответа зависит от
`default_socket_timeout` окружения, а индивидуального лимита cURL нет.
`ext-curl` остаётся зависимостью пакета независимо от выбранного режима.
