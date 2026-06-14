<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Model\DeleteObjectType;
use Soz\Drebedengi\Model\MoneyAmount;
use Soz\Drebedengi\Model\OperationType;
use Soz\Drebedengi\Model\Record;
use Soz\Drebedengi\Model\RecordQuery;
use Soz\Drebedengi\Support\DrebedengiNormalizer;
use Soz\Drebedengi\Support\DrebedengiDateTime;
use Soz\Drebedengi\Transport\TransportInterface;

final readonly class RecordService
{
    public function __construct(
        private TransportInterface $transport,
        private ClientOptions $options = new ClientOptions(),
    )
    {
    }

    /**
     * @return list<Record>
     */
    public function list(?RecordQuery $query = null): array
    {
        $query ??= (new RecordQuery())->last20();

        return array_map(fn (array $item): Record => Record::fromSoap($item, $this->options->timezone), DrebedengiNormalizer::listOfArrays(
            $this->transport->call('getRecordList', [$query->toSoapParams($this->options->timezone), []]),
        ));
    }

    /**
     * @param list<int|string> $ids
     * @return list<Record>
     */
    public function byIds(array $ids): array
    {
        return array_map(fn (array $item): Record => Record::fromSoap($item, $this->options->timezone), DrebedengiNormalizer::listOfArrays(
            $this->transport->call('getRecordList', [[], $this->normalizeIds($ids)]),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function createExpense(
        int|string $placeId,
        int|string $categoryId,
        MoneyAmount $amount,
        int|string $currencyId,
        \DateTimeInterface $date,
        string $comment = '',
    ): array {
        return $this->savePayloads([[
            'client_id' => $this->clientId(),
            'place_id' => (string)$placeId,
            'budget_object_id' => (string)$categoryId,
            'sum' => -abs($amount->minorUnits),
            'operation_date' => DrebedengiDateTime::formatDateTime($date, $this->options->timezone),
            'comment' => $comment,
            'currency_id' => (string)$currencyId,
            'is_duty' => false,
            'operation_type' => OperationType::Expense->value,
        ]]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function createIncome(
        int|string $placeId,
        int|string $sourceId,
        MoneyAmount $amount,
        int|string $currencyId,
        \DateTimeInterface $date,
        string $comment = '',
    ): array {
        return $this->savePayloads([[
            'client_id' => $this->clientId(),
            'place_id' => (string)$placeId,
            'budget_object_id' => (string)$sourceId,
            'sum' => abs($amount->minorUnits),
            'operation_date' => DrebedengiDateTime::formatDateTime($date, $this->options->timezone),
            'comment' => $comment,
            'currency_id' => (string)$currencyId,
            'is_duty' => false,
            'operation_type' => OperationType::Income->value,
        ]]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function createTransfer(
        int|string $fromPlaceId,
        int|string $toPlaceId,
        MoneyAmount $amount,
        int|string $currencyId,
        \DateTimeInterface $date,
        string $comment = '',
    ): array {
        $fromClientId = $this->clientId();
        $toClientId = $this->clientId();
        if ($fromClientId === $toClientId) {
            $toClientId++;
        }

        return $this->savePayloads([
            [
                'client_id' => $fromClientId,
                'client_move_id' => $toClientId,
                'place_id' => (string)$fromPlaceId,
                'budget_object_id' => (string)$toPlaceId,
                'sum' => -abs($amount->minorUnits),
                'operation_date' => DrebedengiDateTime::formatDateTime($date, $this->options->timezone),
                'comment' => $comment,
                'currency_id' => (string)$currencyId,
                'is_duty' => false,
                'operation_type' => OperationType::Transfer->value,
            ],
            [
                'client_id' => $toClientId,
                'client_move_id' => $fromClientId,
                'place_id' => (string)$toPlaceId,
                'budget_object_id' => (string)$fromPlaceId,
                'sum' => abs($amount->minorUnits),
                'operation_date' => DrebedengiDateTime::formatDateTime($date, $this->options->timezone),
                'comment' => $comment,
                'currency_id' => (string)$currencyId,
                'is_duty' => false,
                'operation_type' => OperationType::Transfer->value,
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function createExchange(
        int|string $placeId,
        MoneyAmount $soldAmount,
        int|string $soldCurrencyId,
        MoneyAmount $boughtAmount,
        int|string $boughtCurrencyId,
        \DateTimeInterface $date,
        string $comment = '',
    ): array {
        if ((string)$soldCurrencyId === (string)$boughtCurrencyId) {
            throw new InvalidArgumentException('Currency exchange requires two different currency ids.');
        }

        $soldClientId = $this->clientId();
        $boughtClientId = $this->clientId();
        if ($soldClientId === $boughtClientId) {
            $boughtClientId++;
        }

        return $this->savePayloads([
            [
                'client_id' => $soldClientId,
                'client_change_id' => $boughtClientId,
                'place_id' => (string)$placeId,
                'budget_object_id' => (string)$placeId,
                'sum' => -abs($soldAmount->minorUnits),
                'operation_date' => DrebedengiDateTime::formatDateTime($date, $this->options->timezone),
                'comment' => $comment,
                'currency_id' => (string)$soldCurrencyId,
                'is_duty' => false,
                'operation_type' => OperationType::Exchange->value,
            ],
            [
                'client_id' => $boughtClientId,
                'client_change_id' => $soldClientId,
                'place_id' => (string)$placeId,
                'budget_object_id' => (string)$placeId,
                'sum' => abs($boughtAmount->minorUnits),
                'operation_date' => DrebedengiDateTime::formatDateTime($date, $this->options->timezone),
                'comment' => $comment,
                'currency_id' => (string)$boughtCurrencyId,
                'is_duty' => false,
                'operation_type' => OperationType::Exchange->value,
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function update(Record $record): array
    {
        if ($record->id === '') {
            throw new InvalidArgumentException('Cannot update a Drebedengi record without server id.');
        }

        return $this->savePayloads([$record->toUpdatePayload($this->options->timezone)]);
    }

    public function delete(string|int $id, OperationType $type): bool
    {
        $deleteType = match ($type) {
            OperationType::Income => DeleteObjectType::Income,
            OperationType::Expense => DeleteObjectType::Expense,
            OperationType::Transfer => DeleteObjectType::Transfer,
            OperationType::Exchange => DeleteObjectType::Exchange,
            OperationType::All => throw new InvalidArgumentException('Cannot delete a record with OperationType::All.'),
        };

        return (int)$this->transport->call('deleteObject', [(int)$id, $deleteType->value]) === 1;
    }

    /**
     * @param list<array<string, mixed>> $payloads
     * @return list<array<string, mixed>>
     */
    public function savePayloads(array $payloads): array
    {
        if ($payloads === []) {
            return [];
        }

        return DrebedengiNormalizer::listOfArrays($this->transport->call('setRecordList', [$payloads]));
    }

    private function clientId(): int
    {
        return random_int(1, 999_999_999);
    }

    /**
     * @param list<int|string> $ids
     * @return list<string>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_map(static fn (int|string $id): string => (string)$id, $ids));
    }
}
