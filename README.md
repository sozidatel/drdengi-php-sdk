# drdengi-php-sdk

PHP SDK для SOAP API Дребеденег.

SDK намеренно не генерирует PHP-классы из WSDL: в WSDL почти все полезные ответы описаны как `anyType`, поэтому публичный слой здесь доменный и типизированный, а legacy-массивы остаются на границе транспорта.

## Установка

```bash
composer require sozidatel/drdengi-php-sdk
```

Для локальной разработки в этом репозитории:

```bash
composer install
composer test:unit
```

## Настройка

```php
use Soz\Drebedengi\Credentials;
use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\DrebedengiClient;
use Soz\Drebedengi\Endpoint;

$credentials = new Credentials(
    apiId: getenv('DREB_API_ID'),
    login: getenv('DREB_LOGIN'),
    password: getenv('DREB_PASSWORD'),
);

$client = DrebedengiClient::fromCredentials(
    $credentials,
    options: new ClientOptions(new DateTimeZone('Europe/Podgorica')),
);
```

По умолчанию SDK обращается только к основному SaaS-серверу `Endpoint::RU_BASE_URI`. Сервер можно передать как base URL или как объект `Endpoint`:

```php
// Официальное ME-зеркало.
$meClient = DrebedengiClient::fromCredentials($credentials, Endpoint::ME_BASE_URI);

// Self-hosted установка с путём. SDK добавит /soap/dd.wsdl и /soap/.
$selfHostedClient = DrebedengiClient::fromCredentials(
    $credentials,
    'https://money.example.com/drebedengi',
);
```

SDK не добавляет скрытые fallback-серверы. Чтобы включить failover, передайте два или больше base URL в нужном порядке:

```php
$client = DrebedengiClient::fromCredentials(
    $credentials,
    [Endpoint::RU_BASE_URI, Endpoint::ME_BASE_URI],
);
```

Первый ответивший endpoint запоминается на время жизни клиента. Read-only вызов можно повторить на следующем endpoint после инфраструктурного сбоя. Перед первой операцией записи SDK выбирает endpoint безопасным `getAccessStatus`, а саму запись отправляет только один раз: неоднозначный сбой после отправки не приводит к автоматическому повтору. Credentials передаются каждому endpoint из явно заданного списка, поэтому добавляйте только доверенные серверы.

SDK переопределяет SOAP `location`, потому что официальный WSDL может указывать другой адрес сервиса.

Дребеденьги передают даты операций как `YYYY-MM-DD HH:MM:SS` без timezone. SDK не нашел timezone в SOAP-методах аккаунта, поэтому timezone аккаунта нужно задавать явно через `ClientOptions`. Если не задать, будет использована `date_default_timezone_get()`.

## Чтение данных

```php
$places = $client->places()->list();
$categories = $client->categories()->list();
$sources = $client->sources()->list();
$currencies = $client->currencies()->list();
$tags = $client->tags()->list();
$balance = $client->balance()->list();
```

Остатки на выбранную дату и дополнительные опции:

```php
use Soz\Drebedengi\Model\BalanceQuery;

$balance = $client->balance()->list(
    BalanceQuery::at(new DateTimeImmutable('2026-07-14'))
        ->includeHidden()
        ->includeZero()
        ->subtractAccumulations()
        ->subtractDebts(),
);
```

`restDate` форматируется в timezone из `ClientOptions`. `subtractAccumulations()` вычитает
зарезервированные накопления, `subtractDebts()` — долги, а `includeHidden()` и `includeZero()`
добавляют скрытые и нулевые счета. Legacy-массив параметров для `balance()->list()` пока
поддерживается для обратной совместимости.

Места хранения:

```php
$places = $client->places()->list();      // счета и папки, отсортированы по sort
$accounts = $client->places()->accounts(); // только type=4, можно использовать в операциях
$folders = $client->places()->folders();   // только type=9
$placeTree = $client->places()->tree(includeHidden: false);

foreach ($placeTree as $node) {
    echo $node->place->name;
    echo $node->place->canHaveTransactions() ? " можно использовать в операциях\n" : " только папка\n";
}
```

`type=4` — реальный счет, `type=9` — папка для дерева. Отрицательные `parent_id`, например `-3`, сохраняются как `systemParentId`, а не как обычная папка. Для `-3` счет остается видимым и принимает операции, но в UI попадает в группу `Скрытые суммы`, а его баланс исключается из `Итого`; это можно проверить через `$place->isExcludedFromTotal()`.

Плоский список категорий уже отсортирован по `sort`. Для дерева:

```php
$tree = $client->categories()->tree(includeHidden: false);

foreach ($tree as $node) {
    echo $node->category->name . "\n";
    foreach ($node->children as $child) {
        echo "  " . $child->category->name . "\n";
    }
}
```

Для `<select>`:

```php
foreach ($client->categories()->options(includeHidden: false) as $option) {
    echo "<option value=\"{$option->id}\">{$option->label}</option>";
}
```

Создание категории расходов:

```php
$category = $client->categories()->create(
    name: 'Кафе',
    parentId: null, // null означает корневую категорию
);

$categoryId = $category->id;
```

Источники доходов поддерживают тот же интерфейс:

```php
$sources = $client->sources()->list(); // плоский список, отсортирован по sort
$sourceTree = $client->sources()->tree(includeHidden: false);
$sourceOptions = $client->sources()->options(includeHidden: false);
```

Аккаунт и подписка:

```php
$userId = $client->account()->userId();
$hasAccess = $client->account()->hasAccess();
$expireDate = $client->account()->expireDate();
```

Операции:

```php
use Soz\Drebedengi\Model\RecordQuery;
use Soz\Drebedengi\Model\OperationType;

$records = $client->records()->list(
    RecordQuery::forDateRange(
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2026-01-31'),
    )
        ->operationType(OperationType::Expense)
        ->onlyPlaces(['11416426'])
        ->onlyCategories(['CATEGORY_ID'])
        ->withBalanceAfter()
);

foreach ($records as $record) {
    echo $record->balanceAfter?->toDecimalString();
}
```

Периоды и остальные фильтры detail-журнала:

```php
$records = $client->records()->list(
    (new RecordQuery())
        ->thisMonth() // также today(), lastMonth(), thisQuarter(), thisYear(), lastYear(), allTime(), last20()
        ->relativeTo(new DateTimeImmutable('2026-07-14'))
        ->operationType(OperationType::Expense)
        ->includePlanned()
        ->includeDebts(false)
        ->forUser('USER_ID')
        ->exceptPlaces(['PLACE_ID'])
        ->onlyTags(['TAG_ID'])
        ->exceptCategories(['CATEGORY_ID']),
);
```

`relativeTo()` задаёт опорную дату для именованного периода и форматируется в timezone аккаунта.
`forAllUsers()` возвращает query к семейной выборке. Для счетов, тегов и категорий доступны
`only...()`, `except...()` и возврат к полной выборке через `all...()`.

При `includePlanned()` в `Record` заполняются `planned`, `plannedRepeatId`, `plannedPeriodId`
и `plannedInitialDate`. Плановые операции нельзя сочетать с `withBalanceAfter()`: SOAP не отдаёт
готовый прогнозный остаток, а вычислять его из фактического остатка было бы неоднозначно.

Фильтры по категориям и тегам допустимы только для расходов или доходов, поэтому
перед ними нужно явно выбрать `OperationType::Expense` или `OperationType::Income`.
Несовместимое сочетание SDK отклоняет до SOAP-вызова.

По умолчанию `RecordQuery` использует `r_currency=0`, то есть оригинальную валюту операции.
Обычное чтение использует безопасный detail report (`is_report=true`, `r_how=1`). В legacy SOAP
режиме `is_report=false` сервер считает запрос первоначальной синхронизацией и сбрасывает
служебные соответствия `client_id` / `server_id`, поэтому SDK не использует его для журнала.

`withBalanceAfter()` добавляет в каждый `Record` поле `balanceAfter` с остатком на счёте сразу
после операции. Расчёт доступен только для оригинальной валюты и может выполнить дополнительный
`getRecordList`, если исходный запрос содержит фильтры, а также один `getBalance`.

## Создание операций

Суммы в SDK представлены как integer minor units, то есть в мельчайших единицах конкретной валюты. Для обычных валют это исторически 2 знака после запятой, а для криптовалют точность может быть выше.

```php
use Soz\Drebedengi\Model\ExpenseGroupItem;

$currency = $client->currencies()->require('CURRENCY_ID');

$client->records()->createExpense(
    placeId: 'PLACE_ID',
    categoryId: 'CATEGORY_ID',
    amount: $currency->amount('12.34'),
    currencyId: $currency->id,
    date: new DateTimeImmutable(),
    comment: 'Обед',
);

$client->records()->createIncome(
    placeId: 'PLACE_ID',
    sourceId: 'SOURCE_ID',
    amount: $currency->amount('100.00'),
    currencyId: $currency->id,
    date: new DateTimeImmutable(),
    comment: 'Возврат',
);

$client->records()->createTransfer(
    fromPlaceId: 'FROM_PLACE_ID',
    toPlaceId: 'TO_PLACE_ID',
    amount: $currency->amount('50.00'),
    currencyId: $currency->id,
    date: new DateTimeImmutable(),
    comment: 'Перенос между счетами',
);
```

Группа расходов, например строки одного чека:

```php
$client->records()->createExpenseGroup(
    placeId: 'PLACE_ID',
    items: [
        new ExpenseGroupItem('CATEGORY_ID_1', $currency->amount('12.34'), 'Кофе'),
        ['categoryId' => 'CATEGORY_ID_2', 'amount' => $currency->amount('56.78'), 'comment' => 'Продукты'],
    ],
    currencyId: $currency->id,
    date: new DateTimeImmutable(),
);
```

Для нескольких строк SDK отправляет один `setRecordList` с общим `group_id`, который связывает все позиции чека в одну группу.

`Currency::amount()` — рекомендуемый способ создавать суммы: он сам применяет точность из
`ratio` и связывает `MoneyAmount` с ID валюты. Валюту можно найти без ручного перебора:

```php
$btc = $client->currencies()->requireByCode('BTC');

$amount = $btc->amount('0.00001234');
```

Прежний `MoneyAmount::fromDecimalString()` остаётся совместимым для валют с двумя знаками.
Перед записью SDK загружает каталог валют и проверяет scale, а у суммы от `Currency::amount()` —
ещё и совпадение `currencyId`. Поэтому потенциально неверная сумма отклоняется до SOAP-вызова.
Отдельный аргумент `currencyId` в методах `create*()` пока сохранён ради обратной совместимости;
передавай в него ID той же `Currency`, которая создала сумму. В следующей major-версии этот
дубль можно будет убрать в пользу обязательной currency-bound суммы.

При чтении через `records()` и `balance()` суммы уже имеют правильный `scale` и связанный
`currencyId`; вручную применять `withScale()` больше не нужно. Каталог валют лениво кэшируется
в пределах экземпляра `DrebedengiClient`; для принудительного обновления есть
`$client->currencies()->refresh()`.

`createTransfer()` и `createExchange()` сами создают парные записи и связывают их через `client_move_id` / `client_change_id`.
Перевод на тот же самый счёт SDK отклоняет до SOAP-вызова.

## Обновление и удаление

```php
$record = $client->records()->byIds(['RECORD_ID'])[0];
$client->records()->update($record);

$client->records()->delete('RECORD_ID', $record->operationType);
```

DTO сохраняют исходный SOAP-массив в поле `raw`, чтобы можно было разбирать неизвестные legacy-поля без потери данных.
Методы `delete()` принимают только положительные целочисленные server ID и проверяют их до SOAP-вызова.

## Синхронизация по revision

```php
$current = $client->sync()->currentRevision();
$changes = $client->sync()->changesSince($lastSavedRevision);
```

Потребитель SDK должен сохранять последнюю успешно обработанную revision сам. Для cron-синхронизаций важно сохранять progress инкрементально после каждой обработанной revision, а не только в конце пачки.

Для настоящей первоначальной синхронизации можно получить полный набор записей в legacy
sync/export-формате:

```php
$initialRecords = $client->sync()->initialRecords();
```

Этот вызов намеренно использует `is_report=false`. Сервер Дребеденег очищает при нём служебную
таблицу дедупликации `client_id` / `server_id` для текущего API ID. Не используй
`initialRecords()` для обычного чтения журнала или проверки результата записи.

## Прямой доступ к SOAP

Не все методы WSDL покрыты доменным API v1. Для редких методов есть прямой вызов с автоматическим добавлением credentials:

```php
$result = $client->raw()->call('getAccessStatus');
$accums = $client->raw()->call('getAccumList', [[]]);
```

## Live-тесты

Скопируй `.env.example` в `.env` и заполни тестовый аккаунт:

```dotenv
DREB_TEST_BASE_URI=https://www.drebedengi.ru
DREB_TEST_API_ID=
DREB_TEST_LOGIN=
DREB_TEST_PASSWORD=
DREB_TEST_TIMEZONE=UTC
DREB_RUN_LIVE_TESTS=1
DREB_BROWSER_CHECKS=0
```

Запуск:

```bash
composer test:integration
```

Интеграционные тесты создают только записи с уникальным тестовым комментарием и удаляют их через `deleteObject`. `deleteAll` в автоматических тестах не используется.

Если аккаунт отвечает `No payment` на `setRecordList`, тест записи будет пропущен. Для полной проверки создания/удаления операций нужен тестовый аккаунт с активным доступом к API-записи.

## Ограничения v1

- Основной SDK работает только с SOAP API.
- Методы покупок, чеков, регистрации и подписок доступны через `raw()`, но не типизированы.
