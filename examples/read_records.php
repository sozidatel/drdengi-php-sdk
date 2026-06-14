<?php

declare(strict_types=1);

use Soz\Drebedengi\Credentials;
use Soz\Drebedengi\DrebedengiClient;
use Soz\Drebedengi\Endpoint;
use Soz\Drebedengi\Model\RecordQuery;

require dirname(__DIR__) . '/vendor/autoload.php';

$client = DrebedengiClient::fromCredentials(
    new Credentials(
        (string)getenv('DREB_API_ID'),
        (string)getenv('DREB_LOGIN'),
        (string)getenv('DREB_PASSWORD'),
    ),
    new Endpoint((string)(getenv('DREB_BASE_URI') ?: Endpoint::DEFAULT_BASE_URI)),
);

$records = $client->records()->list(
    RecordQuery::forDateRange(
        new DateTimeImmutable('first day of this month'),
        new DateTimeImmutable('today'),
    ),
);

foreach ($records as $record) {
    printf(
        "%s %s %s\n",
        $record->operationDate->format('Y-m-d H:i:s'),
        $record->sum->toDecimalString(),
        $record->comment,
    );
}
