<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Model\MoneyAmount;

final class MoneyAmountTest extends TestCase
{
    #[DataProvider('amountProvider')]
    public function testParsesDecimalString(string $input, int $minorUnits, string $formatted): void
    {
        $amount = MoneyAmount::fromDecimalString($input);

        self::assertSame($minorUnits, $amount->minorUnits);
        self::assertSame(2, $amount->scale);
        self::assertSame($formatted, $amount->toDecimalString());
    }

    public function testParsesCryptoScale(): void
    {
        $amount = MoneyAmount::fromDecimalString('0.00001234', 8);

        self::assertSame(1234, $amount->minorUnits);
        self::assertSame(8, $amount->scale);
        self::assertSame('0.00001234', $amount->toDecimalString());
    }

    public function testRejectsTooManyFractionalDigitsForScale(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MoneyAmount::fromDecimalString('0.001', 2);
    }

    public function testParsesIntegerBoundariesWithoutOverflow(): void
    {
        $maximum = MoneyAmount::fromDecimalString((string)PHP_INT_MAX, 0);
        $minimum = MoneyAmount::fromDecimalString((string)PHP_INT_MIN, 0);

        self::assertSame(PHP_INT_MAX, $maximum->minorUnits);
        self::assertSame((string)PHP_INT_MAX, $maximum->toDecimalString());
        self::assertSame(PHP_INT_MIN, $minimum->minorUnits);
        self::assertSame((string)PHP_INT_MIN, $minimum->toDecimalString());
    }

    public function testRejectsDecimalAmountOutsideIntegerRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the supported integer minor-unit range');

        MoneyAmount::fromDecimalString(substr((string)PHP_INT_MIN, 1), 0);
    }

    public function testRejectsOverflowCausedByScale(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the supported integer minor-unit range');

        MoneyAmount::fromDecimalString('10', 18);
    }

    #[DataProvider('nonFiniteFloatProvider')]
    public function testRejectsNonFiniteFloat(float $amount): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be finite');

        MoneyAmount::fromFloat($amount);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function nonFiniteFloatProvider(): iterable
    {
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'not a number' => [NAN];
    }

    public function testRejectsFloatOutsideIntegerRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the supported integer minor-unit range');

        MoneyAmount::fromFloat(PHP_INT_MAX * 2.0, 0);
    }

    public function testFloatConversionValidatesScaleBeforeArithmetic(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('between 0 and 18');

        MoneyAmount::fromFloat(1.0, 19);
    }

    public function testFormatsMinimumIntegerAtMaximumScale(): void
    {
        $amount = MoneyAmount::fromMinorUnits(PHP_INT_MIN, 18);
        $digits = substr((string)PHP_INT_MIN, 1);
        $expected = strlen($digits) <= 18
            ? '-0.' . str_pad($digits, 18, '0', STR_PAD_LEFT)
            : sprintf('-%s.%s', substr($digits, 0, -18), substr($digits, -18));

        self::assertSame($expected, $amount->toDecimalString());
    }

    public function testRejectsAbsoluteValueOfMinimumInteger(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute value of PHP_INT_MIN');

        MoneyAmount::fromMinorUnits(PHP_INT_MIN)->absolute();
    }

    public function testRejectsNegationOfMinimumInteger(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot negate PHP_INT_MIN');

        MoneyAmount::fromMinorUnits(PHP_INT_MIN)->negate();
    }

    public function testRescalesWithoutChangingDecimalAmount(): void
    {
        $amount = MoneyAmount::fromDecimalString('12.30', 2, '18');

        $morePrecise = $amount->rescale(4);
        self::assertSame(123_000, $morePrecise->minorUnits);
        self::assertSame('12.3000', $morePrecise->toDecimalString());
        self::assertSame('18', $morePrecise->currencyId);

        $lessPrecise = $amount->rescale(1);
        self::assertSame(123, $lessPrecise->minorUnits);
        self::assertSame('12.3', $lessPrecise->toDecimalString());
        self::assertSame('18', $lessPrecise->currencyId);
    }

    public function testRejectsLossyRescale(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('without losing precision');

        MoneyAmount::fromDecimalString('12.34')->rescale(1);
    }

    public function testRejectsOverflowingRescale(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the supported integer minor-unit range');

        MoneyAmount::fromMinorUnits(PHP_INT_MAX, 0)->rescale(1);
    }

    public function testCanExplicitlyReinterpretScale(): void
    {
        $amount = MoneyAmount::fromMinorUnits(123, 2);

        self::assertSame('12.3', $amount->reinterpretScale(1)->toDecimalString());
        self::assertSame('12.3', $amount->withScale(1)->toDecimalString());
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function amountProvider(): iterable
    {
        yield 'integer' => ['10', 1000, '10.00'];
        yield 'one decimal' => ['10.5', 1050, '10.50'];
        yield 'two decimals' => ['10.57', 1057, '10.57'];
        yield 'comma' => ['10,57', 1057, '10.57'];
        yield 'negative' => ['-10.57', -1057, '-10.57'];
    }
}
