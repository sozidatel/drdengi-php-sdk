<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Exception\InvalidArgumentException;

final class ReportQuery
{
    private RecordQuery $records;
    private ReportAveraging $averaging = ReportAveraging::None;

    public function __construct()
    {
        $this->records = (new RecordQuery())->thisMonth();
    }

    public static function forDateRange(\DateTimeInterface $from, \DateTimeInterface $to): self
    {
        return (new self())->dateRange($from, $to);
    }

    public function dateRange(\DateTimeInterface $from, \DateTimeInterface $to): self
    {
        $this->records->dateRange($from, $to);

        return $this;
    }

    public function today(): self
    {
        $this->records->today();

        return $this;
    }

    public function thisMonth(): self
    {
        $this->records->thisMonth();

        return $this;
    }

    public function lastMonth(): self
    {
        $this->records->lastMonth();

        return $this;
    }

    public function thisQuarter(): self
    {
        $this->records->thisQuarter();

        return $this;
    }

    public function thisYear(): self
    {
        $this->records->thisYear();

        return $this;
    }

    public function lastYear(): self
    {
        $this->records->lastYear();

        return $this;
    }

    public function allTime(): self
    {
        $this->records->allTime();

        return $this;
    }

    public function last20(): self
    {
        $this->records->last20();

        return $this;
    }

    public function relativeTo(\DateTimeInterface $date): self
    {
        $this->records->relativeTo($date);

        return $this;
    }

    public function includeDebts(bool $enabled = true): self
    {
        $this->records->includeDebts($enabled);

        return $this;
    }

    public function includePlanned(bool $enabled = true): self
    {
        $this->records->includePlanned($enabled);

        return $this;
    }

    public function forUser(int|string $userId): self
    {
        $this->records->forUser($userId);

        return $this;
    }

    public function forAllUsers(): self
    {
        $this->records->forAllUsers();

        return $this;
    }

    /** @param list<int|string> $ids */
    public function onlyPlaces(array $ids): self
    {
        $this->records->onlyPlaces($ids);

        return $this;
    }

    /** @param list<int|string> $ids */
    public function exceptPlaces(array $ids): self
    {
        $this->records->exceptPlaces($ids);

        return $this;
    }

    public function allPlaces(): self
    {
        $this->records->allPlaces();

        return $this;
    }

    /** @param list<int|string> $ids */
    public function onlyTags(array $ids): self
    {
        $this->records->onlyTags($ids);

        return $this;
    }

    /** @param list<int|string> $ids */
    public function exceptTags(array $ids): self
    {
        $this->records->exceptTags($ids);

        return $this;
    }

    public function allTags(): self
    {
        $this->records->allTags();

        return $this;
    }

    /** @param list<int|string> $ids */
    public function onlyCategories(array $ids): self
    {
        $this->records->onlyCategories($ids);

        return $this;
    }

    /** @param list<int|string> $ids */
    public function exceptCategories(array $ids): self
    {
        $this->records->exceptCategories($ids);

        return $this;
    }

    public function allCategories(): self
    {
        $this->records->allCategories();

        return $this;
    }

    public function originalCurrency(): self
    {
        $this->records->originalCurrency();

        return $this;
    }

    public function convertedToCurrency(int|string $currencyId): self
    {
        $this->records->convertedToCurrency($currencyId);

        return $this;
    }

    public function averageBy(ReportAveraging $averaging): self
    {
        $this->averaging = $averaging;

        return $this;
    }

    public function withoutAveraging(): self
    {
        return $this->averageBy(ReportAveraging::None);
    }

    public function averageDaily(): self
    {
        return $this->averageBy(ReportAveraging::Daily);
    }

    public function averageWeekly(): self
    {
        return $this->averageBy(ReportAveraging::Weekly);
    }

    public function averageMonthly(): self
    {
        return $this->averageBy(ReportAveraging::Monthly);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSoapParams(
        OperationType $operationType,
        ?\DateTimeZone $timezone = null,
    ): array {
        $grouping = match ($operationType) {
            OperationType::Income => 2,
            OperationType::Expense => 3,
            default => throw new InvalidArgumentException(
                'Reports can only group OperationType::Income or OperationType::Expense.',
            ),
        };

        $params = $this->records
            ->operationType($operationType)
            ->toSoapParams($timezone);
        $params['r_how'] = $grouping;
        $params['r_middle'] = $this->averaging->value;

        return $params;
    }
}
