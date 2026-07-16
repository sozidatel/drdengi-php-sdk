<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Support\DrebedengiNormalizer;

/**
 * Mutable fields accepted by RecordService::update().
 *
 * Amount is treated as an absolute value while the original record direction
 * is preserved.
 */
final readonly class RecordPatch
{
    public ?string $placeId;

    public ?string $budgetObjectId;

    public ?\DateTimeImmutable $operationDate;

    public ?string $currencyId;

    public function __construct(
        int|string|null $placeId = null,
        int|string|null $budgetObjectId = null,
        public ?MoneyAmount $amount = null,
        ?\DateTimeInterface $operationDate = null,
        public ?string $comment = null,
        int|string|null $currencyId = null,
        public ?bool $duty = null,
    ) {
        if (
            $placeId === null
            && $budgetObjectId === null
            && $amount === null
            && $operationDate === null
            && $comment === null
            && $currencyId === null
            && $duty === null
        ) {
            throw new InvalidArgumentException('Record patch must change at least one field.');
        }

        $this->placeId = $placeId === null
            ? null
            : (string)DrebedengiNormalizer::positiveIntegerId($placeId, 'Record place ID');
        $this->budgetObjectId = $budgetObjectId === null
            ? null
            : (string)DrebedengiNormalizer::positiveIntegerId($budgetObjectId, 'Record budget object ID');
        $this->operationDate = $operationDate === null
            ? null
            : \DateTimeImmutable::createFromInterface($operationDate);
        $this->currencyId = $currencyId === null
            ? null
            : (string)DrebedengiNormalizer::positiveIntegerId($currencyId, 'Record currency ID');
    }

    public function applyTo(Record $record): Record
    {
        $sum = $record->sum;
        if ($this->amount !== null) {
            $sum = $this->amount->absolute();
            $sum = match ($record->operationType) {
                OperationType::Expense => $sum->negate(),
                OperationType::Income => $sum,
                OperationType::Transfer, OperationType::Exchange => $record->sum->minorUnits < 0
                    ? $sum->negate()
                    : $sum,
                OperationType::All => throw new InvalidArgumentException(
                    'Cannot apply an amount patch to a record with OperationType::All.',
                ),
            };
        }

        $balanceAfterIsStale = $this->placeId !== null
            || $this->amount !== null
            || $this->operationDate !== null
            || $this->currencyId !== null
            || $this->duty !== null;

        return new Record(
            id: $record->id,
            placeId: $this->placeId ?? $record->placeId,
            budgetObjectId: $this->budgetObjectId ?? $record->budgetObjectId,
            sum: $sum,
            operationDate: $this->operationDate ?? $record->operationDate,
            comment: $this->comment ?? $record->comment,
            currencyId: $this->currencyId ?? $record->currencyId,
            duty: $this->duty ?? $record->duty,
            operationType: $record->operationType,
            serverMoveId: $record->serverMoveId,
            serverChangeId: $record->serverChangeId,
            groupId: $record->groupId,
            userId: $record->userId,
            raw: $record->raw,
            balanceAfter: $balanceAfterIsStale ? null : $record->balanceAfter,
            planned: $record->planned,
            plannedRepeatId: $record->plannedRepeatId,
            plannedPeriodId: $record->plannedPeriodId,
            plannedInitialDate: $record->plannedInitialDate,
        );
    }
}
