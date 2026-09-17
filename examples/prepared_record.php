<?php

declare(strict_types=1);

use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Model\Currency;
use Soz\Drebedengi\Model\PreparedRecordWrite;
use Soz\Drebedengi\Model\RecordWriteToken;
use Soz\Drebedengi\Service\RecordService;
use Soz\Drebedengi\Support\CurrencyCatalog;
use Soz\Drebedengi\Transport\TransportInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

// An offline example: no credentials, network calls, or financial writes.
$transport = new class implements TransportInterface {
    public mixed $submitted = null;

    public function call(string $method, array $arguments = []): mixed
    {
        if ($method !== 'setRecordList') {
            throw new LogicException('The offline example must not fetch remote data.');
        }
        $this->submitted = $arguments[0] ?? null;

        return [['client_id' => 111, 'server_id' => '9001']];
    }
};
$currency = Currency::fromSoap(['id' => '3', 'name' => 'Euro', 'code' => 'EUR', 'ratio' => '1']);
$records = new RecordService(
    $transport,
    new ClientOptions(timezone: new DateTimeZone('Europe/Podgorica')),
    new CurrencyCatalog($transport, [$currency]),
);

$prepared = $records->prepareExpense(
    placeId: '1',
    categoryId: '2',
    amount: $currency->amount('10.00'),
    currencyId: $currency->id,
    date: new DateTimeImmutable('2026-09-18 12:00:00', new DateTimeZone('Europe/Podgorica')),
    comment: 'Pocket money example',
    writeToken: new RecordWriteToken([111], 222),
);

printf("Preview: %s EUR, %s\n", $prepared->amounts[0]->toDecimalString(), $prepared->payloads[0]['operation_date']);
$savedJson = $prepared->toJson(); // Persist this before a real attempt.
echo $savedJson, PHP_EOL;

// Simulate restoring a saved request in another process, without its catalog.
$restored = PreparedRecordWrite::fromJson($savedJson);
$sender = new RecordService($transport);
$result = $sender->submit($restored);

if ($transport->submitted !== $prepared->payloads) {
    throw new LogicException('The restored request differs from the reviewed request.');
}
printf("Simulated submission: unchanged payload, fixture record ID %s\n", $result->firstServerId());
