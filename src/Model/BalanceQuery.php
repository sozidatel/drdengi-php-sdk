<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Support\DrebedengiDateTime;

final class BalanceQuery
{
    private ?\DateTimeImmutable $date = null;
    private bool $subtractAccumulations = false;
    private bool $subtractDebts = false;
    private bool $includeHidden = false;
    private bool $includeZero = false;

    public static function at(\DateTimeInterface $date): self
    {
        $query = new self();
        $query->date = \DateTimeImmutable::createFromInterface($date);

        return $query;
    }

    public function subtractAccumulations(bool $enabled = true): self
    {
        $this->subtractAccumulations = $enabled;

        return $this;
    }

    public function subtractDebts(bool $enabled = true): self
    {
        $this->subtractDebts = $enabled;

        return $this;
    }

    public function includeHidden(bool $enabled = true): self
    {
        $this->includeHidden = $enabled;

        return $this;
    }

    public function includeZero(bool $enabled = true): self
    {
        $this->includeZero = $enabled;

        return $this;
    }

    /**
     * @return array{
     *     restDate?: string,
     *     is_with_accum: bool,
     *     is_with_duty: bool,
     *     is_with_hidden: bool,
     *     is_with_null: bool
     * }
     */
    public function toSoapParams(?\DateTimeZone $timezone = null): array
    {
        $timezone ??= new \DateTimeZone(date_default_timezone_get());
        $params = [
            'is_with_accum' => $this->subtractAccumulations,
            'is_with_duty' => $this->subtractDebts,
            'is_with_hidden' => $this->includeHidden,
            'is_with_null' => $this->includeZero,
        ];

        if ($this->date !== null) {
            $params['restDate'] = DrebedengiDateTime::formatDate($this->date, $timezone);
        }

        return $params;
    }
}
