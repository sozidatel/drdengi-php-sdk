<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Support\DrebedengiNormalizer;
use Soz\Drebedengi\Support\DrebedengiDateTime;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;

final readonly class Record implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $id,
        public string $placeId,
        public string $budgetObjectId,
        public MoneyAmount $sum,
        public \DateTimeImmutable $operationDate,
        public string $comment,
        public string $currencyId,
        public bool $duty,
        public OperationType $operationType,
        public ?string $serverMoveId,
        public ?string $serverChangeId,
        public ?string $groupId,
        public ?string $userId,
        public array $raw,
        public ?MoneyAmount $balanceAfter = null,
        public bool $planned = false,
        public ?string $plannedRepeatId = null,
        public ?string $plannedPeriodId = null,
        public ?\DateTimeImmutable $plannedInitialDate = null,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromSoap(
        array $raw,
        ?\DateTimeZone $timezone = null,
        ?Currency $currency = null,
    ): self
    {
        $timezone ??= new \DateTimeZone(date_default_timezone_get());
        $id = self::requiredString($raw, ['id', 'server_id'], 'record ID');
        $placeId = self::requiredString($raw, ['place_id', 'budget_account_id'], 'record place ID');
        $budgetObjectId = self::requiredString($raw, ['budget_object_id'], 'record budget object ID');
        $currencyId = self::requiredString($raw, ['currency_id'], 'record currency ID');
        $operationDate = self::requiredString($raw, ['operation_date'], 'record operation date');
        $sum = self::requiredInteger($raw, ['sum', 'difference'], 'record sum');
        $operationTypeValue = self::requiredInteger($raw, ['operation_type'], 'record operation type');
        $operationType = OperationType::tryFrom($operationTypeValue)
            ?? throw new UnexpectedResponseException(sprintf(
                'Drebedengi record response contains unknown operation type %d.',
                $operationTypeValue,
            ));
        $linkedRecordId = DrebedengiNormalizer::nullableId($raw['id2'] ?? null);

        if ($currency !== null && $currency->id !== $currencyId) {
            throw new InvalidArgumentException(sprintf(
                'Record currency ID "%s" does not match supplied currency "%s".',
                $currencyId,
                $currency->id,
            ));
        }

        return new self(
            id: $id,
            placeId: $placeId,
            budgetObjectId: $budgetObjectId,
            sum: $currency?->amountFromMinorUnits($sum) ?? MoneyAmount::fromMinorUnits($sum),
            operationDate: DrebedengiDateTime::parseDateTime($operationDate, $timezone),
            comment: DrebedengiNormalizer::text($raw['comment'] ?? ''),
            currencyId: $currencyId,
            duty: DrebedengiNormalizer::bool($raw['is_duty'] ?? false),
            operationType: $operationType,
            serverMoveId: DrebedengiNormalizer::nullableId(
                $raw['server_move_id'] ?? ($operationType === OperationType::Transfer ? $linkedRecordId : null),
            ),
            serverChangeId: DrebedengiNormalizer::nullableId(
                $raw['server_change_id'] ?? ($operationType === OperationType::Exchange ? $linkedRecordId : null),
            ),
            groupId: DrebedengiNormalizer::nullableId($raw['group_id'] ?? null),
            userId: DrebedengiNormalizer::nullableId($raw['user_nuid'] ?? null),
            raw: $raw,
            planned: DrebedengiNormalizer::bool($raw['is_planned'] ?? false),
            plannedRepeatId: DrebedengiNormalizer::nullableId($raw['repeat_id'] ?? null),
            plannedPeriodId: DrebedengiNormalizer::nullableId($raw['period_id'] ?? null),
            plannedInitialDate: self::optionalDateTime($raw['init_date'] ?? null, $timezone),
        );
    }

    private static function optionalDateTime(mixed $value, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $value = DrebedengiNormalizer::string($value);
        if ($value === '') {
            return null;
        }

        return DrebedengiDateTime::parseDateTime($value, $timezone);
    }

    /**
     * @param array<string, mixed> $raw
     * @param non-empty-list<string> $fields
     */
    private static function requiredString(array $raw, array $fields, string $label): string
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $raw) || !is_scalar($raw[$field])) {
                continue;
            }

            $value = trim((string)$raw[$field]);
            if ($value !== '') {
                return $value;
            }
        }

        throw new UnexpectedResponseException(sprintf(
            'Drebedengi record response is missing %s (%s).',
            $label,
            implode(' or ', $fields),
        ));
    }

    /**
     * @param array<string, mixed> $raw
     * @param non-empty-list<string> $fields
     */
    private static function requiredInteger(array $raw, array $fields, string $label): int
    {
        $value = self::requiredString($raw, $fields, $label);
        if (!preg_match('/^-?\d+$/', $value)) {
            throw new UnexpectedResponseException(sprintf(
                'Drebedengi record response contains non-integer %s.',
                $label,
            ));
        }

        return (int)$value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toUpdatePayload(?\DateTimeZone $timezone = null): array
    {
        $timezone ??= new \DateTimeZone(date_default_timezone_get());

        $payload = [
            'server_id' => $this->id,
            'place_id' => $this->placeId,
            'budget_object_id' => $this->budgetObjectId,
            'sum' => $this->sum->minorUnits,
            'operation_date' => DrebedengiDateTime::formatDateTime($this->operationDate, $timezone),
            'comment' => $this->comment,
            'currency_id' => $this->currencyId,
            'is_duty' => $this->duty,
            'operation_type' => $this->operationType->value,
        ];

        foreach ([
            'server_move_id' => $this->serverMoveId,
            'server_change_id' => $this->serverChangeId,
            'group_id' => $this->groupId,
            'user_nuid' => $this->userId,
        ] as $field => $value) {
            if ($value !== null) {
                $payload[$field] = $value;
            }
        }

        return $payload;
    }

    public function withBalanceAfter(MoneyAmount $balanceAfter): self
    {
        return new self(
            id: $this->id,
            placeId: $this->placeId,
            budgetObjectId: $this->budgetObjectId,
            sum: $this->sum,
            operationDate: $this->operationDate,
            comment: $this->comment,
            currencyId: $this->currencyId,
            duty: $this->duty,
            operationType: $this->operationType,
            serverMoveId: $this->serverMoveId,
            serverChangeId: $this->serverChangeId,
            groupId: $this->groupId,
            userId: $this->userId,
            raw: $this->raw,
            balanceAfter: $balanceAfter,
            planned: $this->planned,
            plannedRepeatId: $this->plannedRepeatId,
            plannedPeriodId: $this->plannedPeriodId,
            plannedInitialDate: $this->plannedInitialDate,
        );
    }

    /**
     * @return array{
     *     id: string,
     *     placeId: string,
     *     budgetObjectId: string,
     *     sum: MoneyAmount,
     *     operationDate: \DateTimeImmutable,
     *     comment: string,
     *     currencyId: string,
     *     duty: bool,
     *     operationType: OperationType,
     *     serverMoveId: string|null,
     *     serverChangeId: string|null,
     *     groupId: string|null,
     *     userId: string|null,
     *     raw: array<string, mixed>,
     *     balanceAfter: MoneyAmount|null,
     *     planned: bool,
     *     plannedRepeatId: string|null,
     *     plannedPeriodId: string|null,
     *     plannedInitialDate: \DateTimeImmutable|null
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'placeId' => $this->placeId,
            'budgetObjectId' => $this->budgetObjectId,
            'sum' => $this->sum,
            'operationDate' => $this->operationDate,
            'comment' => $this->comment,
            'currencyId' => $this->currencyId,
            'duty' => $this->duty,
            'operationType' => $this->operationType,
            'serverMoveId' => $this->serverMoveId,
            'serverChangeId' => $this->serverChangeId,
            'groupId' => $this->groupId,
            'userId' => $this->userId,
            'raw' => $this->raw,
            'balanceAfter' => $this->balanceAfter,
            'planned' => $this->planned,
            'plannedRepeatId' => $this->plannedRepeatId,
            'plannedPeriodId' => $this->plannedPeriodId,
            'plannedInitialDate' => $this->plannedInitialDate,
        ];
    }
}
