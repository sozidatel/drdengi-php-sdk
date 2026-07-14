<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Support\DrebedengiDateTime;
use Soz\Drebedengi\Support\DrebedengiNormalizer;

final class RecordQuery
{
    private const PERIOD_CUSTOM = 0;
    private const PERIOD_THIS_MONTH = 1;
    private const PERIOD_LAST_MONTH = 2;
    private const PERIOD_THIS_QUARTER = 3;
    private const PERIOD_THIS_YEAR = 4;
    private const PERIOD_LAST_YEAR = 5;
    private const PERIOD_ALL_TIME = 6;
    private const PERIOD_TODAY = 7;
    private const PERIOD_LAST_20 = 8;

    private bool $showDuty = true;
    private bool $withPlanned = false;
    private int $period = self::PERIOD_CUSTOM;
    private ?\DateTimeInterface $from = null;
    private ?\DateTimeInterface $to = null;
    private ?\DateTimeInterface $relativeTo = null;
    private int $how = 1;
    private OperationType $what = OperationType::All;
    private int $userId = 0;
    private int|string $currencyId = 0;
    private int $placeFilter = 0;
    /** @var list<string> */
    private array $placeIds = [];
    private int $tagFilter = 0;
    /** @var list<string> */
    private array $tagIds = [];
    private int $categoryFilter = 0;
    /** @var list<string> */
    private array $categoryIds = [];
    private bool $withBalanceAfter = false;

    public static function forDateRange(\DateTimeInterface $from, \DateTimeInterface $to): self
    {
        return (new self())->dateRange($from, $to);
    }

    public function dateRange(\DateTimeInterface $from, \DateTimeInterface $to): self
    {
        if ($from > $to) {
            throw new InvalidArgumentException('RecordQuery "from" date must be before or equal to "to" date.');
        }

        $this->period = self::PERIOD_CUSTOM;
        $this->from = \DateTimeImmutable::createFromInterface($from);
        $this->to = \DateTimeImmutable::createFromInterface($to);
        $this->relativeTo = null;

        return $this;
    }

    public function today(): self
    {
        return $this->usePeriod(self::PERIOD_TODAY);
    }

    public function thisMonth(): self
    {
        return $this->usePeriod(self::PERIOD_THIS_MONTH);
    }

    public function lastMonth(): self
    {
        return $this->usePeriod(self::PERIOD_LAST_MONTH);
    }

    public function thisQuarter(): self
    {
        return $this->usePeriod(self::PERIOD_THIS_QUARTER);
    }

    public function thisYear(): self
    {
        return $this->usePeriod(self::PERIOD_THIS_YEAR);
    }

    public function lastYear(): self
    {
        return $this->usePeriod(self::PERIOD_LAST_YEAR);
    }

    public function last20(): self
    {
        return $this->usePeriod(self::PERIOD_LAST_20);
    }

    public function allTime(): self
    {
        return $this->usePeriod(self::PERIOD_ALL_TIME);
    }

    /**
     * Sets the date relative to which a named period such as today() is resolved.
     */
    public function relativeTo(\DateTimeInterface $date): self
    {
        $this->relativeTo = \DateTimeImmutable::createFromInterface($date);

        return $this;
    }

    public function operationType(OperationType $type): self
    {
        $this->what = $type;

        return $this;
    }

    public function includeDebts(bool $enabled = true): self
    {
        $this->showDuty = $enabled;

        return $this;
    }

    public function includePlanned(bool $enabled = true): self
    {
        $this->withPlanned = $enabled;

        return $this;
    }

    public function forUser(int|string $userId): self
    {
        $this->userId = DrebedengiNormalizer::positiveIntegerId($userId, 'Record query user ID');

        return $this;
    }

    public function forAllUsers(): self
    {
        $this->userId = 0;

        return $this;
    }

    /**
     * @param list<int|string> $ids
     */
    public function onlyPlaces(array $ids): self
    {
        $this->placeFilter = 1;
        $this->placeIds = $this->normalizeIds($ids);

        return $this;
    }

    /**
     * @param list<int|string> $ids
     */
    public function exceptPlaces(array $ids): self
    {
        $this->placeFilter = 2;
        $this->placeIds = $this->normalizeIds($ids);

        return $this;
    }

    public function allPlaces(): self
    {
        $this->placeFilter = 0;
        $this->placeIds = [];

        return $this;
    }

    /**
     * @param list<int|string> $ids
     */
    public function onlyCategories(array $ids): self
    {
        $this->categoryFilter = 1;
        $this->categoryIds = $this->normalizeIds($ids);

        return $this;
    }

    /**
     * @param list<int|string> $ids
     */
    public function exceptCategories(array $ids): self
    {
        $this->categoryFilter = 2;
        $this->categoryIds = $this->normalizeIds($ids);

        return $this;
    }

    public function allCategories(): self
    {
        $this->categoryFilter = 0;
        $this->categoryIds = [];

        return $this;
    }

    /**
     * @param list<int|string> $ids
     */
    public function onlyTags(array $ids): self
    {
        $this->tagFilter = 1;
        $this->tagIds = $this->normalizeIds($ids);

        return $this;
    }

    /**
     * @param list<int|string> $ids
     */
    public function exceptTags(array $ids): self
    {
        $this->tagFilter = 2;
        $this->tagIds = $this->normalizeIds($ids);

        return $this;
    }

    public function allTags(): self
    {
        $this->tagFilter = 0;
        $this->tagIds = [];

        return $this;
    }

    public function originalCurrency(): self
    {
        $this->currencyId = 0;

        return $this;
    }

    public function convertedToCurrency(int|string $currencyId): self
    {
        $currencyId = (string)$currencyId;
        if ($currencyId === '' || $currencyId === '0') {
            throw new InvalidArgumentException('Use originalCurrency() for Drebedengi r_currency=0.');
        }

        $this->currencyId = $currencyId;

        return $this;
    }

    public function withBalanceAfter(bool $enabled = true): self
    {
        $this->withBalanceAfter = $enabled;

        return $this;
    }

    public function shouldIncludeBalanceAfter(): bool
    {
        return $this->withBalanceAfter;
    }

    public function shouldIncludePlanned(): bool
    {
        return $this->withPlanned;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSoapParams(?\DateTimeZone $timezone = null): array
    {
        $timezone ??= new \DateTimeZone(date_default_timezone_get());

        if (($this->tagFilter !== 0 || $this->categoryFilter !== 0)
            && $this->what !== OperationType::Expense
            && $this->what !== OperationType::Income) {
            throw new InvalidArgumentException(
                'RecordQuery tag and category filters require OperationType::Expense or OperationType::Income.',
            );
        }

        $params = [
            // Detail report mode is a side-effect-free ledger read. The legacy
            // is_report=false mode is reserved for SyncService::initialRecords().
            'is_report' => true,
            'is_show_duty' => $this->showDuty,
            'is_with_planned' => $this->withPlanned,
            'r_period' => $this->period,
            'r_how' => $this->how,
            'r_what' => $this->what->value,
            'r_who' => $this->userId,
            'r_currency' => $this->currencyId,
            'r_is_place' => $this->placeFilter,
            'r_is_tag' => $this->tagFilter,
            'r_is_category' => $this->categoryFilter,
        ];

        if ($this->period === self::PERIOD_CUSTOM) {
            if (!$this->from || !$this->to) {
                throw new InvalidArgumentException('RecordQuery date range is required when r_period=0.');
            }
            $params['period_from'] = DrebedengiDateTime::formatDate($this->from, $timezone);
            $params['period_to'] = DrebedengiDateTime::formatDate($this->to, $timezone);
        } elseif ($this->relativeTo !== null) {
            $params['relative_date'] = DrebedengiDateTime::formatDate($this->relativeTo, $timezone);
        }

        if ($this->placeFilter !== 0) {
            $params['r_place'] = $this->placeIds;
        }
        if ($this->tagFilter !== 0) {
            $params['r_tag'] = $this->tagIds;
        }
        if ($this->categoryFilter !== 0) {
            $params['r_category'] = $this->categoryIds;
        }

        return $params;
    }

    private function usePeriod(int $period): self
    {
        $this->period = $period;
        $this->from = null;
        $this->to = null;

        return $this;
    }

    /**
     * @param list<int|string> $ids
     * @return list<string>
     */
    private function normalizeIds(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            $id = trim((string)$id);
            if ($id !== '') {
                $result[] = $id;
            }
        }

        if ($result === []) {
            throw new InvalidArgumentException('RecordQuery filter requires at least one non-empty ID.');
        }

        return array_values(array_unique($result));
    }
}
