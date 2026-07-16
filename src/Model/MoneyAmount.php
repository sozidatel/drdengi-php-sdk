<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Exception\InvalidArgumentException;

final readonly class MoneyAmount implements \JsonSerializable
{
    public function __construct(
        public int $minorUnits,
        public int $scale = 2,
        public ?string $currencyId = null,
    ) {
        self::assertScale($scale);

        if ($currencyId !== null && trim($currencyId) === '') {
            throw new InvalidArgumentException('Currency ID must not be empty.');
        }
    }

    public static function fromDecimalString(string $amount, int $scale = 2, int|string|null $currencyId = null): self
    {
        $amount = trim($amount);
        self::assertScale($scale);

        if (!preg_match('/^([+-])?(\d+)(?:[.,](\d+))?$/', $amount, $matches)) {
            throw new InvalidArgumentException(sprintf('Invalid decimal money amount "%s".', $amount));
        }

        $negative = $matches[1] === '-';
        $fraction = $matches[3] ?? '';
        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException(sprintf(
                'Decimal money amount "%s" has more than %d fractional digits.',
                $amount,
                $scale,
            ));
        }

        $minorUnitDigits = $matches[2] . str_pad($fraction, $scale, '0');
        $minorUnits = self::minorUnitsFromDigits(
            $minorUnitDigits,
            $negative,
            sprintf(
                'Decimal money amount "%s" is outside the supported integer minor-unit range at scale %d.',
                $amount,
                $scale,
            ),
        );

        return new self(
            $minorUnits,
            $scale,
            $currencyId === null ? null : (string)$currencyId,
        );
    }

    /**
     * Creates an amount by rounding a binary float to the nearest minor unit.
     *
     * @deprecated Binary floats cannot represent most decimal monetary values exactly.
     *             Use fromDecimalString() for deterministic monetary input.
     */
    public static function fromFloat(float $amount, int $scale = 2, int|string|null $currencyId = null): self
    {
        self::assertScale($scale);
        if (!is_finite($amount)) {
            throw new InvalidArgumentException('Float money amount must be finite.');
        }

        $scaled = $amount * (10.0 ** $scale);
        if (!is_finite($scaled)) {
            throw new InvalidArgumentException('Float money amount is outside the supported integer minor-unit range.');
        }

        $rounded = number_format(round($scaled, 0, PHP_ROUND_HALF_UP), 0, '.', '');
        $negative = str_starts_with($rounded, '-');
        $minorUnits = self::minorUnitsFromDigits(
            $negative ? substr($rounded, 1) : $rounded,
            $negative,
            'Float money amount is outside the supported integer minor-unit range.',
        );

        return new self(
            $minorUnits,
            $scale,
            $currencyId === null ? null : (string)$currencyId,
        );
    }

    public static function fromMinorUnits(int $minorUnits, int $scale = 2, int|string|null $currencyId = null): self
    {
        return new self($minorUnits, $scale, $currencyId === null ? null : (string)$currencyId);
    }

    public function absolute(): self
    {
        if ($this->minorUnits === PHP_INT_MIN) {
            throw new InvalidArgumentException(
                'Cannot get the absolute value of PHP_INT_MIN because it exceeds the supported integer minor-unit range.',
            );
        }

        return new self(abs($this->minorUnits), $this->scale, $this->currencyId);
    }

    public function negate(): self
    {
        if ($this->minorUnits === PHP_INT_MIN) {
            throw new InvalidArgumentException(
                'Cannot negate PHP_INT_MIN because its positive value exceeds the supported integer minor-unit range.',
            );
        }

        return new self(-$this->minorUnits, $this->scale, $this->currencyId);
    }

    /**
     * Reinterprets the unchanged minor-unit count using another scale.
     *
     * @deprecated Use rescale() to preserve the decimal amount, or reinterpretScale()
     *             when changing the meaning of the existing minor units is intentional.
     */
    public function withScale(int $scale): self
    {
        return $this->reinterpretScale($scale);
    }

    /**
     * Reinterprets the unchanged minor-unit count using another scale.
     *
     * This changes the decimal amount. For example, 123 minor units become 1.23
     * at scale 2 and 12.3 at scale 1.
     */
    public function reinterpretScale(int $scale): self
    {
        return new self($this->minorUnits, $scale, $this->currencyId);
    }

    /**
     * Changes the scale while preserving the decimal amount exactly.
     *
     * Reducing the scale is rejected when it would discard non-zero digits.
     */
    public function rescale(int $scale): self
    {
        self::assertScale($scale);
        if ($scale === $this->scale) {
            return $this;
        }

        $decimal = $this->toDecimalString();
        if ($scale < $this->scale) {
            $discardedDigits = substr($decimal, -($this->scale - $scale));
            if (trim($discardedDigits, '0') !== '') {
                throw new InvalidArgumentException(sprintf(
                    'Cannot rescale money amount %s from scale %d to %d without losing precision.',
                    $decimal,
                    $this->scale,
                    $scale,
                ));
            }

            $decimal = substr($decimal, 0, -($this->scale - $scale));
            $decimal = rtrim($decimal, '.');
        }

        return self::fromDecimalString($decimal, $scale, $this->currencyId);
    }

    public function toDecimalString(): string
    {
        $minorUnits = (string)$this->minorUnits;
        $negative = str_starts_with($minorUnits, '-');
        $digits = $negative ? substr($minorUnits, 1) : $minorUnits;
        $sign = $negative ? '-' : '';

        if ($this->scale === 0) {
            return $sign . $digits;
        }

        if (strlen($digits) <= $this->scale) {
            return sprintf(
                '%s0.%s',
                $sign,
                str_pad($digits, $this->scale, '0', STR_PAD_LEFT),
            );
        }

        return sprintf(
            '%s%s.%s',
            $sign,
            substr($digits, 0, -$this->scale),
            substr($digits, -$this->scale),
        );
    }

    /**
     * @return array{minorUnits: int, scale: int, decimal: string, currencyId?: string}
     */
    public function jsonSerialize(): array
    {
        $result = [
            'minorUnits' => $this->minorUnits,
            'scale' => $this->scale,
            'decimal' => $this->toDecimalString(),
        ];

        if ($this->currencyId !== null) {
            $result['currencyId'] = $this->currencyId;
        }

        return $result;
    }

    private static function assertScale(int $scale): void
    {
        if ($scale < 0 || $scale > 18) {
            throw new InvalidArgumentException('Money scale must be between 0 and 18 decimal places.');
        }
    }

    private static function minorUnitsFromDigits(string $digits, bool $negative, string $overflowMessage): int
    {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return 0;
        }

        $limit = $negative ? substr((string)PHP_INT_MIN, 1) : (string)PHP_INT_MAX;
        if (strlen($digits) > strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)
        ) {
            throw new InvalidArgumentException($overflowMessage);
        }

        if ($negative && $digits === substr((string)PHP_INT_MIN, 1)) {
            return PHP_INT_MIN;
        }

        $minorUnits = (int)$digits;

        return $negative ? -$minorUnits : $minorUnits;
    }
}
