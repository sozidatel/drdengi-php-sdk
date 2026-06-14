<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Exception\InvalidArgumentException;

final readonly class MoneyAmount implements \JsonSerializable
{
    public function __construct(
        public int $minorUnits,
        public int $scale = 2,
    ) {
        if ($scale < 0 || $scale > 18) {
            throw new InvalidArgumentException('Money scale must be between 0 and 18 decimal places.');
        }
    }

    public static function fromDecimalString(string $amount, int $scale = 2): self
    {
        $amount = trim($amount);
        if ($scale < 0 || $scale > 18) {
            throw new InvalidArgumentException('Money scale must be between 0 and 18 decimal places.');
        }

        if (!preg_match('/^([+-])?(\d+)(?:[.,](\d+))?$/', $amount, $matches)) {
            throw new InvalidArgumentException(sprintf('Invalid decimal money amount "%s".', $amount));
        }

        $sign = ($matches[1] ?? '') === '-' ? -1 : 1;
        $major = (int)$matches[2];
        $fraction = $matches[3] ?? '';
        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException(sprintf(
                'Decimal money amount "%s" has more than %d fractional digits.',
                $amount,
                $scale,
            ));
        }

        $multiplier = 10 ** $scale;
        $minor = $scale === 0 ? 0 : (int)str_pad($fraction, $scale, '0');

        return new self($sign * (($major * $multiplier) + $minor), $scale);
    }

    public static function fromFloat(float $amount, int $scale = 2): self
    {
        return new self((int)round($amount * (10 ** $scale)), $scale);
    }

    public static function fromMinorUnits(int $minorUnits, int $scale = 2): self
    {
        return new self($minorUnits, $scale);
    }

    public function absolute(): self
    {
        return new self(abs($this->minorUnits), $this->scale);
    }

    public function negate(): self
    {
        return new self($this->minorUnits * -1, $this->scale);
    }

    public function withScale(int $scale): self
    {
        return new self($this->minorUnits, $scale);
    }

    public function toDecimalString(): string
    {
        $sign = $this->minorUnits < 0 ? '-' : '';
        $absolute = abs($this->minorUnits);
        $multiplier = 10 ** $this->scale;

        if ($this->scale === 0) {
            return $sign . (string)$absolute;
        }

        return sprintf(
            '%s%d.%s',
            $sign,
            intdiv($absolute, $multiplier),
            str_pad((string)($absolute % $multiplier), $this->scale, '0', STR_PAD_LEFT),
        );
    }

    /**
     * @return array{minorUnits: int, scale: int, decimal: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'minorUnits' => $this->minorUnits,
            'scale' => $this->scale,
            'decimal' => $this->toDecimalString(),
        ];
    }
}
