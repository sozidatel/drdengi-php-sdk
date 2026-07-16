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
use Soz\Drebedengi\Model\RecordPatch;
use Soz\Drebedengi\Model\RecordQuery;
use Soz\Drebedengi\Model\RecordWriteToken;
use Soz\Drebedengi\Model\WriteResult;
use Soz\Drebedengi\Support\CurrencyCatalog;
use Soz\Drebedengi\Support\DrebedengiNormalizer;
use Soz\Drebedengi\Support\DrebedengiDateTime;
use Soz\Drebedengi\Transport\TransportInterface;

/** @phpstan-import-type SoapParams from RecordQuery */
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
        $query ??= new RecordQuery();
        $params = $query->toSoapParams($this->options->timezone);

        if ($params['r_currency'] !== 0 && $params['r_currency'] !== '0') {
            throw new InvalidArgumentException(
                'RecordService only supports records in their original currency. '
                . 'Use ReportQuery for converted financial amounts.',
            );
        }
        if ($query->shouldIncludeBalanceAfter() && $query->shouldIncludePlanned()) {
            throw new InvalidArgumentException('Balance after a record cannot be combined with planned records.');
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
        if ($ids === []) {
            return [];
        }

        return array_map(fn (array $item): Record => $this->recordFromSoap($item), DrebedengiNormalizer::listOfArrays(
            $this->transport->call('getRecordList', [['is_report' => true], $this->normalizeIds($ids)]),
        ));
    }

    public function createExpense(
        int|string $placeId,
        int|string $categoryId,
        MoneyAmount $amount,
        int|string $currencyId,
        \DateTimeInterface $date,
        string $comment = '',
        ?RecordWriteToken $writeToken = null,
    ): WriteResult {
        $writeToken = $this->writeToken($writeToken, 1, 'Creating an expense');

        return $this->writePayloads([
            $this->expensePayload(
                $placeId,
                $categoryId,
                $amount,
                $currencyId,
                $date,
                $comment,
                $writeToken->clientId(),
            ),
        ]);
    }

    /**
     * @param list<ExpenseGroupItem|array{categoryId: int|string, amount: MoneyAmount, comment?: string}> $items
     */
    public function createExpenseGroup(
        int|string $placeId,
        array $items,
        int|string $currencyId,
        \DateTimeInterface $date,
        ?RecordWriteToken $writeToken = null,
    ): WriteResult {
        $items = array_map($this->normalizeExpenseGroupItem(...), $items);
        if ($items === []) {
            return WriteResult::empty();
        }

        $writeToken = $this->writeToken($writeToken, count($items), 'Creating an expense group');
        if (count($items) === 1) {
            $item = $items[0];

            return $this->createExpense(
                placeId: $placeId,
                categoryId: $item->categoryId,
                amount: $item->amount,
                currencyId: $currencyId,
                date: $date,
                comment: $item->comment,
                writeToken: $writeToken,
            );
        }

        $payloads = [];
        foreach ($items as $index => $item) {
            $payload = $this->expensePayload(
                $placeId,
                $item->categoryId,
                $item->amount,
                $currencyId,
                $date,
                $item->comment,
                $writeToken->clientId($index),
            );
            $payload['group_id'] = (string)$writeToken->groupId;

            $payloads[] = $payload;
        }

        return $this->writePayloads($payloads);
    }

    public function createIncome(
        int|string $placeId,
        int|string $sourceId,
        MoneyAmount $amount,
        int|string $currencyId,
        \DateTimeInterface $date,
        string $comment = '',
        ?RecordWriteToken $writeToken = null,
    ): WriteResult {
        $this->assertAmountMatchesCurrency($amount, $currencyId);
        $writeToken = $this->writeToken($writeToken, 1, 'Creating an income');
        $minorUnits = $amount->absolute()->minorUnits;

        return $this->writePayloads([[
            'client_id' => $writeToken->clientId(),
            'place_id' => (string)$placeId,
            'budget_object_id' => (string)$sourceId,
            'sum' => $minorUnits,
            'operation_date' => DrebedengiDateTime::formatDateTime($date, $this->options->timezone),
            'comment' => $comment,
            'currency_id' => (string)$currencyId,
            'is_duty' => false,
            'operation_type' => OperationType::Income->value,
        ]]);
    }

    public function createTransfer(
        int|string $fromPlaceId,
        int|string $toPlaceId,
        MoneyAmount $amount,
        int|string $currencyId,
        \DateTimeInterface $date,
        string $comment = '',
        ?RecordWriteToken $writeToken = null,
    ): WriteResult {
        $fromPlaceId = DrebedengiNormalizer::positiveIntegerId($fromPlaceId, 'Transfer source place ID');
        $toPlaceId = DrebedengiNormalizer::positiveIntegerId($toPlaceId, 'Transfer destination place ID');
        if ($fromPlaceId === $toPlaceId) {
            throw new InvalidArgumentException('Transfer source and destination place IDs must be different.');
        }

        $this->assertAmountMatchesCurrency($amount, $currencyId);
        $minorUnits = $amount->absolute()->minorUnits;

        $writeToken = $this->writeToken($writeToken, 2, 'Creating a transfer');
        $fromClientId = $writeToken->clientId(0);
        $toClientId = $writeToken->clientId(1);

        return $this->writePayloads([
            [
                'client_id' => $fromClientId,
                'client_move_id' => $toClientId,
                'place_id' => (string)$fromPlaceId,
                'budget_object_id' => (string)$toPlaceId,
                'sum' => -$minorUnits,
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
                'sum' => $minorUnits,
                'operation_date' => DrebedengiDateTime::formatDateTime($date, $this->options->timezone),
                'comment' => $comment,
                'currency_id' => (string)$currencyId,
                'is_duty' => false,
                'operation_type' => OperationType::Transfer->value,
            ],
        ]);
    }

    public function createExchange(
        int|string $placeId,
        MoneyAmount $soldAmount,
        int|string $soldCurrencyId,
        MoneyAmount $boughtAmount,
        int|string $boughtCurrencyId,
        \DateTimeInterface $date,
        string $comment = '',
        ?RecordWriteToken $writeToken = null,
    ): WriteResult {
        if ((string)$soldCurrencyId === (string)$boughtCurrencyId) {
            throw new InvalidArgumentException('Currency exchange requires two different currency ids.');
        }

        $this->assertAmountMatchesCurrency($soldAmount, $soldCurrencyId);
        $this->assertAmountMatchesCurrency($boughtAmount, $boughtCurrencyId);
        $soldMinorUnits = $soldAmount->absolute()->minorUnits;
        $boughtMinorUnits = $boughtAmount->absolute()->minorUnits;

        $writeToken = $this->writeToken($writeToken, 2, 'Creating a currency exchange');
        $soldClientId = $writeToken->clientId(0);
        $boughtClientId = $writeToken->clientId(1);

        return $this->writePayloads([
            [
                'client_id' => $soldClientId,
                'client_change_id' => $boughtClientId,
                'place_id' => (string)$placeId,
                'budget_object_id' => (string)$placeId,
                'sum' => -$soldMinorUnits,
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
                'sum' => $boughtMinorUnits,
                'operation_date' => DrebedengiDateTime::formatDateTime($date, $this->options->timezone),
                'comment' => $comment,
                'currency_id' => (string)$boughtCurrencyId,
                'is_duty' => false,
                'operation_type' => OperationType::Exchange->value,
            ],
        ]);
    }

    public function update(Record $record, ?RecordPatch $patch = null): WriteResult
    {
        if ($record->id === '') {
            throw new InvalidArgumentException('Cannot update a Drebedengi record without server id.');
        }
        if ($record->planned) {
            throw new InvalidArgumentException('Cannot update a planned record through RecordService::update().');
        }
        if ($record->operationType === OperationType::Transfer || $record->operationType === OperationType::Exchange) {
            throw new InvalidArgumentException(
                'Transfer and exchange records are paired and cannot be updated through single-record update().',
            );
        }
        if ($record->operationType === OperationType::All) {
            throw new InvalidArgumentException('Cannot update a record with OperationType::All.');
        }

        if ($patch !== null) {
            $record = $record->withPatch($patch);
        }
        $this->assertAmountMatchesCurrency($record->sum, $record->currencyId);

        return $this->writePayloads([$record->toUpdatePayload($this->options->timezone)]);
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

        $response = $this->transport->call('deleteObject', [$id, $deleteType->value]);
        if (!is_int($response) && !is_string($response)) {
            throw new UnexpectedResponseException(sprintf(
                'Drebedengi record delete response must be an integer, got %s.',
                get_debug_type($response),
            ));
        }

        $status = DrebedengiNormalizer::requiredInteger(
            ['result' => $response],
            'result',
            'record delete',
        );
        if ($status !== 0 && $status !== 1) {
            throw new UnexpectedResponseException('Drebedengi record delete response must be 0 or 1.');
        }

        return $status === 1;
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

    /**
     * @param list<array<string, mixed>> $payloads
     */
    private function writePayloads(array $payloads): WriteResult
    {
        return new WriteResult($this->savePayloads($payloads), $payloads);
    }

    private function writeToken(
        ?RecordWriteToken $writeToken,
        int $recordCount,
        string $operation,
    ): RecordWriteToken {
        $writeToken ??= RecordWriteToken::generate($recordCount);
        $writeToken->assertRecordCount($recordCount, $operation);

        return $writeToken;
    }

    /**
     * @param non-empty-list<Record> $records
     * @param list<array<string, mixed>> $queriedRows
     * @param SoapParams $params
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
                'r_who' => 0,
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
     * @param non-empty-list<Record> $records
     * @param SoapParams $params
     * @return array{string, string}
     */
    private function balanceDateRange(array $records, array $params): array
    {
        if ($params['r_period'] === 0) {
            if (!isset($params['period_from'], $params['period_to'])) {
                throw new \LogicException('Custom record query must contain period_from and period_to.');
            }

            return [$params['period_from'], $params['period_to']];
        }

        $dates = array_map(
            fn (Record $record): string => DrebedengiDateTime::formatDate($record->operationDate, $this->options->timezone),
            $records,
        );

        return [min($dates), max($dates)];
    }

    /**
     * @param SoapParams $params
     */
    private function canReuseRowsForBalance(array $params): bool
    {
        return $params['r_period'] === 0
            && $params['r_what'] === OperationType::All->value
            && $params['r_who'] === 0
            && ($params['r_currency'] === 0 || $params['r_currency'] === '0')
            && $params['r_is_place'] === 0
            && $params['r_is_tag'] === 0
            && $params['r_is_category'] === 0;
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
        string $comment,
        int $clientId,
    ): array {
        $this->assertAmountMatchesCurrency($amount, $currencyId);
        $minorUnits = $amount->absolute()->minorUnits;

        return [
            'client_id' => $clientId,
            'place_id' => (string)$placeId,
            'budget_object_id' => (string)$categoryId,
            'sum' => -$minorUnits,
            'operation_date' => DrebedengiDateTime::formatDateTime($date, $this->options->timezone),
            'comment' => $comment,
            'currency_id' => (string)$currencyId,
            'is_duty' => false,
            'operation_type' => OperationType::Expense->value,
        ];
    }

    private function normalizeExpenseGroupItem(mixed $item): ExpenseGroupItem
    {
        if ($item instanceof ExpenseGroupItem) {
            return $item;
        }

        if (!is_array($item)) {
            throw new InvalidArgumentException('Expense group item must be an array or ExpenseGroupItem.');
        }

        if (!array_key_exists('categoryId', $item) || !array_key_exists('amount', $item)) {
            throw new InvalidArgumentException('Expense group item must contain categoryId and amount.');
        }

        if (!is_int($item['categoryId']) && !is_string($item['categoryId'])) {
            throw new InvalidArgumentException('Expense group item categoryId must be an integer or string.');
        }

        if (!$item['amount'] instanceof MoneyAmount) {
            throw new InvalidArgumentException('Expense group item amount must be an instance of MoneyAmount.');
        }

        $comment = $item['comment'] ?? '';
        if (!is_string($comment)) {
            throw new InvalidArgumentException('Expense group item comment must be a string.');
        }

        return new ExpenseGroupItem(
            categoryId: $item['categoryId'],
            amount: $item['amount'],
            comment: $comment,
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
        return array_map(static fn (int|string $id): string => (string)$id, $ids);
    }
}
