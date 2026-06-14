<?php

declare(strict_types=1);

use Soz\Drebedengi\Credentials;
use Soz\Drebedengi\DrebedengiClient;
use Soz\Drebedengi\Endpoint;
use Soz\Drebedengi\Model\MoneyAmount;

require dirname(__DIR__) . '/vendor/autoload.php';

$client = DrebedengiClient::fromCredentials(
    new Credentials(
        (string)getenv('DREB_API_ID'),
        (string)getenv('DREB_LOGIN'),
        (string)getenv('DREB_PASSWORD'),
    ),
    new Endpoint((string)(getenv('DREB_BASE_URI') ?: Endpoint::DEFAULT_BASE_URI)),
);

$created = $client->records()->createExpense(
    placeId: (string)getenv('DREB_PLACE_ID'),
    categoryId: (string)getenv('DREB_CATEGORY_ID'),
    amount: MoneyAmount::fromDecimalString('12.34'),
    currencyId: (string)getenv('DREB_CURRENCY_ID'),
    date: new DateTimeImmutable(),
    comment: 'Created by drdengi-php-sdk example',
);

print_r($created);
