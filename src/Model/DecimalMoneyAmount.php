<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Exception\InvalidArgumentException;

/**
 * A money amount whose minor-unit value may itself be fractional after
 * currency conversion or report averaging.
 */
final readonly class DecimalMoneyAmount implements \JsonSerializable
{
    public string $minorUnits;
    public int $scale;
    public ?string $currencyId;

    public function __construct(
        int|float|string $minorUnits,
        int $scale = 2,
        int|string|null $currencyId = null,
    ) {
        if ($scale < 0 || $scale > 18) {
            throw new InvalidArgumentException('Money scale must be between 0 and 18 decimal places.');
        }

        if ($currencyId !== null && trim((string)$currencyId) === '') {
            throw new InvalidArgumentException('Currency ID must not be empty.');
        }

        $this->minorUnits = self::normalize($minorUnits);
        $this->scale = $scale;
        $this->currencyId = $currencyId === null ? null : (string)$currencyId;
    }

    public function toDecimalString(): string
    {
        $negative = str_starts_with($this->minorUnits, '-');
        $unsigned = $negative ? substr($this->minorUnits, 1) : $this->minorUnits;
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');

        if ($this->scale === 0) {
            return ($negative ? '-' : '') . $unsigned;
        }

        $digits = $integer . $fraction;
        $decimalPosition = strlen($integer) - $this->scale;
        if ($decimalPosition <= 0) {
            $major = '0';
            $decimal = str_repeat('0', -$decimalPosition) . $digits;
        } elseif ($decimalPosition >= strlen($digits)) {
            $major = $digits . str_repeat('0', $decimalPosition - strlen($digits));
            $decimal = '';
        } else {
            $major = substr($digits, 0, $decimalPosition);
            $decimal = substr($digits, $decimalPosition);
        }

        $major = ltrim($major, '0');
        $major = $major === '' ? '0' : $major;
        $decimal = str_pad($decimal, $this->scale, '0');
        while (strlen($decimal) > $this->scale && str_ends_with($decimal, '0')) {
            $decimal = substr($decimal, 0, -1);
        }

        return sprintf('%s%s.%s', $negative ? '-' : '', $major, $decimal);
    }

    /**
     * @return array{minorUnits: string, scale: int, decimal: string, currencyId?: string}
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

    private static function normalize(int|float|string $value): string
    {
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('Decimal minor units must be finite.');
            }

            $value = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } else {
            $value = trim((string)$value);
        }

        if (!preg_match('/^([+-]?)(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $value, $matches)) {
            throw new InvalidArgumentException(sprintf('Invalid decimal minor units "%s".', $value));
        }

        $negative = $matches[1] === '-';
        $integer = $matches[2];
        $fraction = $matches[3] ?? '';
        $exponent = isset($matches[4]) ? (int)$matches[4] : 0;
        if (abs($exponent) > 1_000) {
            throw new InvalidArgumentException('Decimal minor-unit exponent is too large.');
        }
        $digits = $integer . $fraction;
        $decimalPosition = strlen($integer) + $exponent;

        if ($decimalPosition <= 0) {
            $integer = '0';
            $fraction = str_repeat('0', -$decimalPosition) . $digits;
        } elseif ($decimalPosition >= strlen($digits)) {
            $integer = $digits . str_repeat('0', $decimalPosition - strlen($digits));
            $fraction = '';
        } else {
            $integer = substr($digits, 0, $decimalPosition);
            $fraction = substr($digits, $decimalPosition);
        }

        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');
        $normalized = $integer . ($fraction === '' ? '' : '.' . $fraction);

        return $negative && $normalized !== '0' ? '-' . $normalized : $normalized;
    }
}
