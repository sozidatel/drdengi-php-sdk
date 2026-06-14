<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Support\DrebedengiDateTime;

final class RecordQuery
{
    private bool $isReport = false;
    private bool $showDuty = true;
    private int $period = 0;
    private ?\DateTimeInterface $from = null;
    private ?\DateTimeInterface $to = null;
    private int $how = 1;
    private OperationType $what = OperationType::All;
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

    public static function forDateRange(\DateTimeInterface $from, \DateTimeInterface $to): self
    {
        return (new self())->dateRange($from, $to);
    }

    public function dateRange(\DateTimeInterface $from, \DateTimeInterface $to): self
    {
        if ($from > $to) {
            throw new InvalidArgumentException('RecordQuery "from" date must be before or equal to "to" date.');
        }

        $this->period = 0;
        $this->from = $from;
        $this->to = $to;

        return $this;
    }

    public function last20(): self
    {
        $this->period = 8;
        $this->from = null;
        $this->to = null;

        return $this;
    }

    public function operationType(OperationType $type): self
    {
        $this->what = $type;

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

    /**
     * @return array<string, mixed>
     */
    public function toSoapParams(?\DateTimeZone $timezone = null): array
    {
        $timezone ??= new \DateTimeZone(date_default_timezone_get());

        $params = [
            'is_report' => $this->isReport,
            'is_show_duty' => $this->showDuty,
            'r_period' => $this->period,
            'r_how' => $this->how,
            'r_what' => $this->what->value,
            'r_currency' => $this->currencyId,
            'r_is_place' => $this->placeFilter,
            'r_is_tag' => $this->tagFilter,
            'r_is_category' => $this->categoryFilter,
        ];

        if ($this->period === 0) {
            if (!$this->from || !$this->to) {
                throw new InvalidArgumentException('RecordQuery date range is required when r_period=0.');
            }
            $params['period_from'] = DrebedengiDateTime::formatDate($this->from, $timezone);
            $params['period_to'] = DrebedengiDateTime::formatDate($this->to, $timezone);
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

        return array_values(array_unique($result));
    }
}
