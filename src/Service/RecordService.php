<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Model\BalanceItem;
use Soz\Drebedengi\Model\Currency;
use Soz\Drebedengi\Model\DeleteObjectType;
use Soz\Drebedengi\Model\ExpenseGroupItem;
use Soz\Drebedengi\Model\MoneyAmount;
use Soz\Drebedengi\Model\OperationType;
use Soz\Drebedengi\Model\Record;
use Soz\Drebedengi\Model\RecordQuery;
use Soz\Drebedengi\Support\CurrencyCatalog;
use Soz\Drebedengi\Support\DrebedengiNormalizer;
use Soz\Drebedengi\Support\DrebedengiDateTime;
use Soz\Drebedengi\Transport\TransportInterface;

final readonly class RecordService
{
    private CurrencyCatalog $currencies;

    public function __construct(
        private TransportInterface $transport,
        private ClientOptions $options = new ClientOptions(),
        ?CurrencyCatalog $currencies = null,
    ) {
        $this->currencies = $currencies ?? new CurrencyCatalog($transport);
    }

    /**
     * @return list<Record>
     */
    public function list(?RecordQuery $query = null): array
    {
        $query ??= (new RecordQuery())->last20();
        $params = $query->toSoapParams($this->options->timezone);

        if ($query->shouldIncludeBalanceAfter() && (string)$params['r_currency'] !== '0') {
            throw new InvalidArgumentException('Balance after a record is only available in the original currency.');
        }

        $rows = DrebedengiNormalizer::listOfArrays(
            $this->transport->call('getRecordList', [$params, []]),
        );
        $records = array_map(
            fn (array $item): Record => $this->recordFromSoap($item),
            $rows,
        );

        if (!$query->shouldIncludeBalanceAfter() || $records === []) {
            return $records;
        }

        return $this->addBalanceAfter($records, $rows, $params);
    }

    /**
     * @param list<int|string> $ids
     * @return list<Record>
     */
    public function byIds(array $ids): array
    {
        return array_map(fn (array $item): Record => $this->recordFromSoap($item), DrebedengiNormalizer::listOfArrays(
            $this->transport->call('getRecordList', [['is_report' => true], $this->normalizeIds($ids)]),
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
        return $this->savePayloads([
            $this->expensePayload($placeId, $categoryId, $amount, $currencyId, $date, $comment),
        ]);
    }

    /**
     * @param list<ExpenseGroupItem|array{categoryId: int|string, amount: MoneyAmount, comment?: string}> $items
     * @return list<array<string, mixed>>
     */
    public function createExpenseGroup(
        int|string $placeId,
        array $items,
        int|string $currencyId,
        \DateTimeInterface $date,
    ): array {
        $items = array_map($this->normalizeExpenseGroupItem(...), $items);
        if ($items === []) {
            return [];
        }

        if (count($items) === 1) {
            $item = $items[0];

            return $this->createExpense(
                placeId: $placeId,
                categoryId: $item->categoryId,
                amount: $item->amount,
                currencyId: $currencyId,
                date: $date,
                comment: $item->comment,
            );
        }

        $groupId = (string)$this->clientId();
        $payloads = [];
        foreach ($items as $item) {
            $payload = $this->expensePayload($placeId, $item->categoryId, $item->amount, $currencyId, $date, $item->comment);
            $payload['group_id'] = $groupId;

            $payloads[] = $payload;
        }

        return $this->savePayloads($payloads);
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
        $this->assertAmountMatchesCurrency($amount, $currencyId);

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
        $fromPlaceId = DrebedengiNormalizer::positiveIntegerId($fromPlaceId, 'Transfer source place ID');
        $toPlaceId = DrebedengiNormalizer::positiveIntegerId($toPlaceId, 'Transfer destination place ID');
        if ($fromPlaceId === $toPlaceId) {
            throw new InvalidArgumentException('Transfer source and destination place IDs must be different.');
        }

        $this->assertAmountMatchesCurrency($amount, $currencyId);

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

        $this->assertAmountMatchesCurrency($soldAmount, $soldCurrencyId);
        $this->assertAmountMatchesCurrency($boughtAmount, $boughtCurrencyId);

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

        $this->assertAmountMatchesCurrency($record->sum, $record->currencyId);

        return $this->savePayloads([$record->toUpdatePayload($this->options->timezone)]);
    }

    public function delete(string|int $id, OperationType $type): bool
    {
        $id = DrebedengiNormalizer::positiveIntegerId($id, 'Record ID');
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
     * @param list<Record> $records
     * @param list<array<string, mixed>> $queriedRows
     * @param array<string, mixed> $params
     * @return list<Record>
     */
    private function addBalanceAfter(array $records, array $queriedRows, array $params): array
    {
        [$from, $to] = $this->balanceDateRange($records, $params);
        $allRows = $this->canReuseRowsForBalance($params)
            ? $queriedRows
            : DrebedengiNormalizer::listOfArrays($this->transport->call('getRecordList', [[
                'is_report' => true,
                'is_show_duty' => true,
                'r_period' => 0,
                'period_from' => $from,
                'period_to' => $to,
                'r_how' => 1,
                'r_what' => OperationType::All->value,
                'r_currency' => 0,
                'r_is_place' => 0,
                'r_is_tag' => 0,
                'r_is_category' => 0,
            ], []]));

        $balances = [];
        foreach (DrebedengiNormalizer::listOfArrays($this->transport->call('getBalance', [[
            'restDate' => $to,
            'is_with_accum' => false,
            'is_with_duty' => false,
        ]])) as $balance) {
            $item = BalanceItem::fromSoap($balance);
            $this->currencyFromResponse($item->currencyId, 'Balance');
            $balances[$item->placeId . ':' . $item->currencyId] = $item->sum->minorUnits;
        }

        $balanceAfterById = [];
        // Drebedengi returns records newest first, so unwind them from the end-of-day balance.
        foreach ($allRows as $row) {
            $record = $this->recordFromSoap($row);
            $key = $record->placeId . ':' . $record->currencyId;
            if (!array_key_exists($key, $balances)) {
                throw new UnexpectedResponseException(sprintf(
                    'Balance response does not contain place ID "%s" in currency ID "%s".',
                    $record->placeId,
                    $record->currencyId,
                ));
            }

            $balanceAfterById[$record->id] = $balances[$key];
            $balances[$key] -= $record->sum->minorUnits;
        }

        return array_map(
            fn (Record $record): Record => array_key_exists($record->id, $balanceAfterById)
                ? $record->withBalanceAfter(
                    $this->currencyFromResponse($record->currencyId)->amountFromMinorUnits($balanceAfterById[$record->id]),
                )
                : $record,
            $records,
        );
    }

    /**
     * @param list<Record> $records
     * @param array<string, mixed> $params
     * @return array{string, string}
     */
    private function balanceDateRange(array $records, array $params): array
    {
        if ((int)$params['r_period'] === 0) {
            return [(string)$params['period_from'], (string)$params['period_to']];
        }

        $dates = array_map(
            fn (Record $record): string => DrebedengiDateTime::formatDate($record->operationDate, $this->options->timezone),
            $records,
        );

        return [min($dates), max($dates)];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function canReuseRowsForBalance(array $params): bool
    {
        return (int)$params['r_period'] === 0
            && (int)$params['r_what'] === OperationType::All->value
            && (string)$params['r_currency'] === '0'
            && (int)$params['r_is_place'] === 0
            && (int)$params['r_is_tag'] === 0
            && (int)$params['r_is_category'] === 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function expensePayload(
        int|string $placeId,
        int|string $categoryId,
        MoneyAmount $amount,
        int|string $currencyId,
        \DateTimeInterface $date,
        string $comment = '',
    ): array {
        $this->assertAmountMatchesCurrency($amount, $currencyId);

        return [
            'client_id' => $this->clientId(),
            'place_id' => (string)$placeId,
            'budget_object_id' => (string)$categoryId,
            'sum' => -abs($amount->minorUnits),
            'operation_date' => DrebedengiDateTime::formatDateTime($date, $this->options->timezone),
            'comment' => $comment,
            'currency_id' => (string)$currencyId,
            'is_duty' => false,
            'operation_type' => OperationType::Expense->value,
        ];
    }

    /**
     * @param ExpenseGroupItem|array{categoryId: int|string, amount: MoneyAmount, comment?: string} $item
     */
    private function normalizeExpenseGroupItem(ExpenseGroupItem|array $item): ExpenseGroupItem
    {
        if ($item instanceof ExpenseGroupItem) {
            return $item;
        }

        if (!array_key_exists('categoryId', $item) || !array_key_exists('amount', $item)) {
            throw new InvalidArgumentException('Expense group item must contain categoryId and amount.');
        }

        if (!$item['amount'] instanceof MoneyAmount) {
            throw new InvalidArgumentException('Expense group item amount must be an instance of MoneyAmount.');
        }

        return new ExpenseGroupItem(
            categoryId: $item['categoryId'],
            amount: $item['amount'],
            comment: (string)($item['comment'] ?? ''),
        );
    }

    /** @param array<string, mixed> $raw */
    private function recordFromSoap(array $raw): Record
    {
        $currencyId = DrebedengiNormalizer::requiredString($raw, 'currency_id', 'record');

        return Record::fromSoap(
            $raw,
            $this->options->timezone,
            $this->currencyFromResponse($currencyId),
        );
    }

    private function currencyFromResponse(string $currencyId, string $context = 'Record'): Currency
    {
        return $this->currencies->find($currencyId)
            ?? throw new UnexpectedResponseException(sprintf(
                '%s response refers to unknown currency ID "%s".',
                $context,
                $currencyId,
            ));
    }

    private function assertAmountMatchesCurrency(MoneyAmount $amount, int|string $currencyId): void
    {
        $currency = $this->currencies->require($currencyId);

        if ($amount->currencyId !== null && $amount->currencyId !== $currency->id) {
            throw new InvalidArgumentException(sprintf(
                'Money amount is bound to currency ID "%s", but currency ID "%s" was requested.',
                $amount->currencyId,
                $currency->id,
            ));
        }

        if ($amount->scale !== $currency->decimalPlaces) {
            $label = $currency->code ?? ($currency->name !== '' ? $currency->name : $currency->id);

            throw new InvalidArgumentException(sprintf(
                'Money amount scale %d does not match currency %s (ID %s) scale %d. '
                    . 'Create the amount with Currency::amount().',
                $amount->scale,
                $label,
                $currency->id,
                $currency->decimalPlaces,
            ));
        }
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
