<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Support\DrebedengiNormalizer;
use Soz\Drebedengi\Support\DrebedengiDateTime;
use Soz\Drebedengi\Exception\InvalidArgumentException;

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
        $operationType = OperationType::from((int)($raw['operation_type'] ?? OperationType::Expense->value));
        $linkedRecordId = DrebedengiNormalizer::nullableId($raw['id2'] ?? null);
        $currencyId = DrebedengiNormalizer::string($raw['currency_id'] ?? '');

        if ($currency !== null && $currency->id !== $currencyId) {
            throw new InvalidArgumentException(sprintf(
                'Record currency ID "%s" does not match supplied currency "%s".',
                $currencyId,
                $currency->id,
            ));
        }

        $minorUnits = (int)($raw['sum'] ?? $raw['difference'] ?? 0);

        return new self(
            id: DrebedengiNormalizer::string($raw['id'] ?? $raw['server_id'] ?? ''),
            placeId: DrebedengiNormalizer::string($raw['place_id'] ?? $raw['budget_account_id'] ?? ''),
            budgetObjectId: DrebedengiNormalizer::string($raw['budget_object_id'] ?? ''),
            sum: $currency?->amountFromMinorUnits($minorUnits) ?? MoneyAmount::fromMinorUnits($minorUnits),
            operationDate: DrebedengiDateTime::parseDateTime((string)($raw['operation_date'] ?? 'now'), $timezone),
            comment: (string)($raw['comment'] ?? ''),
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
        );
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
        );
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
