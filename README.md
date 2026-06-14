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

$client = DrebedengiClient::fromCredentials(
    new Credentials(
        apiId: getenv('DREB_API_ID'),
        login: getenv('DREB_LOGIN'),
        password: getenv('DREB_PASSWORD'),
    ),
    new Endpoint(getenv('DREB_BASE_URI') ?: Endpoint::DEFAULT_BASE_URI),
    new ClientOptions(new DateTimeZone('Europe/Podgorica')),
);
```

`Endpoint` нужен не только для `drebedengi.me`, но и для кастомных доменов индивидуальных установок. SDK переопределяет SOAP `location`, потому что официальный WSDL указывает `http://www.drebedengi.ru/soap/`.

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

$records = $client->records()->list(
    RecordQuery::forDateRange(
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2026-01-31'),
    )->onlyPlaces(['11416426'])
);
```

По умолчанию `RecordQuery` использует `r_currency=0`, то есть оригинальную валюту операции.

## Создание операций

Суммы в SDK представлены как integer minor units, то есть в мельчайших единицах конкретной валюты. Для обычных валют это исторически 2 знака после запятой, а для криптовалют точность может быть выше.

```php
use Soz\Drebedengi\Model\MoneyAmount;

$client->records()->createExpense(
    placeId: 'PLACE_ID',
    categoryId: 'CATEGORY_ID',
    amount: MoneyAmount::fromDecimalString('12.34'),
    currencyId: 'CURRENCY_ID',
    date: new DateTimeImmutable(),
    comment: 'Обед',
);

$client->records()->createIncome(
    placeId: 'PLACE_ID',
    sourceId: 'SOURCE_ID',
    amount: MoneyAmount::fromDecimalString('100.00'),
    currencyId: 'CURRENCY_ID',
    date: new DateTimeImmutable(),
    comment: 'Возврат',
);

$client->records()->createTransfer(
    fromPlaceId: 'FROM_PLACE_ID',
    toPlaceId: 'TO_PLACE_ID',
    amount: MoneyAmount::fromDecimalString('50.00'),
    currencyId: 'CURRENCY_ID',
    date: new DateTimeImmutable(),
    comment: 'Перенос между счетами',
);
```

Для валют с нестандартной точностью передай scale явно или возьми его из `Currency`:

```php
$btc = $client->currencies()->list()[0]; // пример; в реальном коде найди BTC по code/name

$amount = MoneyAmount::fromDecimalString('0.00001234', $btc->decimalPlaces);
```

`createTransfer()` и `createExchange()` сами создают парные записи и связывают их через `client_move_id` / `client_change_id`.

## Обновление и удаление

```php
$record = $client->records()->byIds(['RECORD_ID'])[0];
$client->records()->update($record);

$client->records()->delete('RECORD_ID', $record->operationType);
```

DTO сохраняют исходный SOAP-массив в поле `raw`, чтобы можно было разбирать неизвестные legacy-поля без потери данных.

## Синхронизация по revision

```php
$current = $client->sync()->currentRevision();
$changes = $client->sync()->changesSince($lastSavedRevision);
```

Потребитель SDK должен сохранять последнюю успешно обработанную revision сам. Для cron-синхронизаций важно сохранять progress инкрементально после каждой обработанной revision, а не только в конце пачки.

## Прямой доступ к SOAP

Не все методы WSDL покрыты доменным API v1. Для редких методов есть прямой вызов с автоматическим добавлением credentials:

```php
$result = $client->raw()->call('getAccessStatus');
$accums = $client->raw()->call('getAccumList', [[]]);
```

## Live-тесты

Скопируй `.env.example` в `.env` и заполни тестовый аккаунт:

```dotenv
DREB_TEST_BASE_URI=https://www.drebedengi.me
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
