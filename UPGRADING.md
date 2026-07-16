# Обновление SDK

## С 0.6.0 на 0.7.0

Релиз добавляет полный CRUD финансовых справочников и выравнивает их публичный
API. Для категорий, счетов, источников, тегов и валют теперь доступны
`byIds()`, `find()` и `require()`.

Существующие `categories()->byIds()` и `places()->byIds()` теперь, как и
остальные справочники, принимают только положительные integer ID, удаляют
повторы и не вызывают SOAP для пустого списка. Код, который передавал нули,
отрицательные или произвольные строки, теперь получит
`InvalidArgumentException`.

Главное несовместимое изменение: `categories()->update()` и
`places()->update()` возвращают типизированный `Category` / `Place`, а не сырой
список SOAP-строк.

```php
$category = $client->categories()->update($id, ['name' => 'Новое имя']);
$categoryId = $category->id;

// Если нужен именно legacy-ответ:
$raw = $client->categories()->savePayloads([/* полный SOAP payload */]);
```

`SourceService`, `TagService` и `CurrencyService` получили create/update/delete;
их create/update также возвращают перечитанный DTO. Низкоуровневый
`savePayloads()` остаётся массивом, но намеренно обходит типизированные guards и
read-back. После raw currency write при необходимости вызови
`currencies()->refresh()` вручную.

Справочные update-методы теперь принимают для `is_hidden`, `is_family`,
`is_autoupdate` и других boolean-полей обычного пользовательского patch только
настоящий PHP `bool`. SOAP-токены вроде `'t'`, `'f'`, `'1'` и `'0'` в частичном
patch больше не нормализуются:

```php
$client->categories()->update($id, ['is_hidden' => false]);
```

Исключение сохранено только для обратной совместимости: полный legacy raw
payload категории или счёта со всеми server-managed полями по-прежнему может
содержать точные SOAP-токены. Для нового кода используй только `bool`.

Все типизированные update/delete, а также `currencies()->setDefault()`, требуют
полного доступа к аккаунту. Удаление категорий, счетов и источников делает
дополнительный точечный read. Это необходимо, потому что SOAP использует общий
`deleteObject($id, 'object')`: без проверки ID категории мог фактически удалить
источник или счёт. Отсутствующий ID теперь возвращает `false` без mutation.

Удаление родительской категории сохраняет legacy cascade-семантику и удаляет
поддерево. Источники используют тот же иерархический
`deleteObject(..., 'object')`; перед удалением родителя проверь `tree()`, если
каскад не задуман. Типизированный API намеренно не делает delete leaf-only.

`places()->delete()` намеренно удаляет только обычный пустой счёт. Папки,
кредитные карты, долговые, purse-owned и auto-hide счета отклоняются. Перед
удалением SDK читает весь журнал, включая плановые операции, с фильтром по
счёту. Это снижает риск legacy API, который удаляет строку начального остатка
до попытки удалить сам счёт и не использует транзакцию. Между preflight и
`deleteObject()` всё равно остаётся server-side race, поэтому не запускай
параллельную запись в удаляемый счёт.

Для контролируемого повтора создания используй `ReferenceWriteToken`:

```php
$token = ReferenceWriteToken::generate();

$category = $client->categories()->create(
    name: 'Кафе',
    writeToken: $token,
);
```

Токен действует только для немедленного повтора совершенно того же payload до
следующей успешной записи справочника того же типа. При неоднозначном failover
повтор допустим только через один endpoint из
`AmbiguousMutationException::$endpoint`.

Валютные mutations намеренно ограничены:

- create создаёт стандартную non-default валюту с `ratio=1`;
- update и `setDefault()` отклоняют crypto/investing валюты, потому что
  `setCurrencyList` сбрасывает `ratio` и `is_investing`;
- default-валюту нельзя удалить, сначала назначь другую через `setDefault()`;
- непосредственно перед типизированной mutation старый каталог инвалидируется,
  а после успешного ответа перечитывается; потерянный или некорректный ответ не
  оставит доступным потенциально устаревший снимок.

DTO `Category` и `Source` получили `description`, а `Tag` — `userId`. Новые
nullable-параметры добавлены в конец конструкторов, поэтому прежние позиционные
вызовы остаются валидными. Эти поля также добавлены в `jsonSerialize()`, поэтому
код, который сравнивает точную JSON-форму DTO, должен учесть новые nullable
ключи.

`tags()->update()` и `tags()->delete()` работают только с тегами текущего
пользователя. `getTagList` может показывать теги других участников семьи, но
legacy `setTagList` при update переписал бы владельца на текущего пользователя,
а delete удалил бы чужой видимый семейный тег. SDK отклоняет такой write до
mutation; если сервер не вернул `user_id`, типизированная запись также считается
небезопасной.

Удаление тега может менять комментарии связанных операций: legacy-сервер
удаляет первое вхождение `[TagName]` (не более чем в 1000 строках за проход).
Не удаляй используемый тег, если это преобразование нежелательно.

## С 0.5.1 на 0.6.0

Релиз улучшает записывающий и транспортный API. Основное несовместимое
изменение: методы создания и обновления операций теперь возвращают объект
`WriteResult`, а не PHP-массив.

```php
$result = $client->records()->createExpense(/* ... */);

$serverId = $result->firstServerId();
$serverIds = $result->serverIds;
$rawRows = $result->raw;
```

`WriteResult` поддерживает `foreach`, `count()` и чтение `$result[0]`, поэтому
простой перебор прежнего результата продолжает работать. Код с параметром или
return type `array`, `is_array()`, `empty()`, boolean-проверкой результата,
array-функциями или записью в offset нужно перевести на `$result->raw`.
`json_encode($result)` теперь выдаёт типизированный объект с ID и `raw`, а не
только прежний список; для прежней JSON-формы также кодируй `$result->raw`.
Низкоуровневый `records()->savePayloads()` по-прежнему возвращает сырой список.

Для изменения отдельных полей операции используй `RecordPatch`:

```php
$record = $client->records()->byIds([$id])[0];

$client->records()->update($record, new RecordPatch(
    amount: $currency->amount('25.00'),
    comment: 'Исправленный комментарий',
));
```

Одиночный `update()` поддерживает расходы и доходы. Перемещения и обмены
состоят из двух связанных строк, а legacy `setRecordList` требует обе половины
в одной пачке, поэтому SDK теперь отклоняет их до SOAP-вызова.

Для контролируемого повтора можно заранее создать `RecordWriteToken` и передать
его в тот же `create*()` повторно. Это не вечный idempotency key: сервер хранит
только соответствия последнего успешного `setRecordList` для данного API ID.
Токен сохраняет только ID, поэтому все остальные аргументы, включая дату,
суммы и комментарий, тоже должны быть теми же. Запрос нужно повторять сразу, до
другой успешной записи и до `sync()->initialRecords()`. При failover не
используй прежний failover-клиент: повтор допустим только через клиент,
закреплённый за endpoint из `AmbiguousMutationException`.

Транспортные ошибки стали структурированными:

- `SoapFaultException` означает, что сервер ответил валидным SOAP fault;
- `EndpointUnavailableException` с `retrySafe=true` означает, что тот же вызов
  можно повторить без риска дублирования записи;
- `AmbiguousMutationException` наследует `EndpointUnavailableException`, но
  всегда имеет `retrySafe=false`: запрос мог быть применён сервером.

У всех транспортных исключений доступны `method`, `endpoint`, `retrySafe` и
`faultCode`. Исходный `SoapFault` намеренно не сохраняется в `previous`, потому
что его trace может содержать credentials.

`ClientOptions` получил явные значения по умолчанию:

```php
new ClientOptions(
    timezone: new DateTimeZone('Europe/Podgorica'),
    connectTimeout: 10,
    readTimeout: 30.0,
    wsdlCache: WsdlCache::Memory,
);
```

Передай `null` вместо timeout, чтобы не добавлять соответствующую настройку
SOAP. Legacy/raw `soapOptions` остаются совместимыми и имеют приоритет над
типизированными значениями.

Чтобы максимально воспроизвести транспортные defaults 0.5.1:

```php
new ClientOptions(
    timezone: $timezone,
    connectTimeout: null,
    readTimeout: null,
    wsdlCache: WsdlCache::None,
);
```

`MoneyAmount::fromFloat()` и `MoneyAmount::withScale()` объявлены устаревшими.
Используй decimal string / `Currency::amount()`, `rescale()` для сохранения
денежного значения либо `reinterpretScale()` для намеренного изменения смысла
minor units. Переполнение теперь приводит к `InvalidArgumentException`.

## С 0.5.0 на 0.5.1

Обновление обратно совместимо для корректных ответов API и корректных входных
данных. При этом несколько ошибок, которые раньше могли быть незаметно
нормализованы PHP, теперь становятся явными исключениями SDK.

- Некорректная дата, revision, идентификатор пользователя, boolean token или
  другой ожидаемый скаляр в SOAP-ответе приводит к
  `UnexpectedResponseException`.
- `categories()->update()` и `places()->update()` выполняют дополнительный
  `getRightAccess` и чтение объекта перед записью. Так SDK отклоняет limited
  access, при котором сервер маскирует обязательные поля, и собирает полный
  legacy payload. Для категорий изменяются `name`, `parent_id`, `is_hidden`,
  `sort`, `description`; для счетов — те же поля и `icon_id`. Полный `raw`-payload
  прежнего кода остаётся допустимым, но server-managed поля игнорируются;
  неизвестный `client_id` и другие неизвестные ключи отклоняются, а `server_id`
  всегда определяется аргументом метода.
- `places()->update()` отклоняет папки, credit-card и системные долговые счета:
  legacy `setPlaceList` не умеет безопасно сохранить их состояние. Раньше SDK
  отправлял неполный payload, который мог повредить объект.
- При read-modify-write SDK снимает один слой HTML-экранирования, добавленный
  `getCategoryList` / `getPlaceList`; явно переданный новый текст остаётся без
  преобразования.
- Некорректный ответ `deleteObject` теперь приводит к
  `UnexpectedResponseException`, а не незаметному `false`.
- При legacy-вызове `fromCredentials($credentials, $endpoint, $soapOptions,
  $explicitSoapOptions)` оба набора SOAP options теперь объединяются. Значения
  из четвёртого аргумента имеют приоритет.
- Failover считает `getRecordList` read-only только при явном `is_report=true` в
  первом аргументе. Неизвестная форма raw-вызова и `is_report=false` выполняются
  на одном endpoint без автоматического повтора; это защищает
  `sync()->initialRecords()` от двойного state-changing вызова.
- После отказа всех endpoint SDK больше не использует устаревший sticky endpoint
  для следующей записи, а сначала выполняет новый read-only probe.

### Live-тесты

Старый `DREB_RUN_LIVE_TESTS=1` временно продолжает включать только read-only
набор. Для новых конфигураций используй отдельные флаги:

```dotenv
DREB_RUN_LIVE_READ_TESTS=1
DREB_RUN_LIVE_WRITE_TESTS=0
```

Записывающие тесты запускаются только при явном
`DREB_RUN_LIVE_WRITE_TESTS=1`. Они создают и затем удаляют тестовые данные.

## Кандидаты на изменение перед 1.0

До 1.0 могут быть объявлены устаревшими перегрузки, усложняющие публичные типы:
legacy-массив в `balance()->list()`, SOAP options в третьем аргументе
`fromCredentials()` и низкоуровневые payload-массивы методов записи. Такие
изменения сначала попадут в этот файл и `CHANGELOG.md`; в ветке 0.x они не будут
удаляться без отдельного релиза с ясной инструкцией миграции.
