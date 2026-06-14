<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Support\DrebedengiNormalizer;
use Soz\Drebedengi\Support\DrebedengiDateTime;

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
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromSoap(array $raw, ?\DateTimeZone $timezone = null): self
    {
        $timezone ??= new \DateTimeZone(date_default_timezone_get());

        return new self(
            id: DrebedengiNormalizer::string($raw['id'] ?? $raw['server_id'] ?? ''),
            placeId: DrebedengiNormalizer::string($raw['place_id'] ?? ''),
            budgetObjectId: DrebedengiNormalizer::string($raw['budget_object_id'] ?? ''),
            sum: MoneyAmount::fromMinorUnits((int)($raw['sum'] ?? 0)),
            operationDate: DrebedengiDateTime::parseDateTime((string)($raw['operation_date'] ?? 'now'), $timezone),
            comment: (string)($raw['comment'] ?? ''),
            currencyId: DrebedengiNormalizer::string($raw['currency_id'] ?? ''),
            duty: DrebedengiNormalizer::bool($raw['is_duty'] ?? false),
            operationType: OperationType::from((int)($raw['operation_type'] ?? OperationType::Expense->value)),
            serverMoveId: DrebedengiNormalizer::nullableId($raw['server_move_id'] ?? null),
            serverChangeId: DrebedengiNormalizer::nullableId($raw['server_change_id'] ?? null),
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

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
