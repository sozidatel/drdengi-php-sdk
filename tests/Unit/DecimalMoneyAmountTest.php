<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Model\DecimalMoneyAmount;

final class DecimalMoneyAmountTest extends TestCase
{
    #[DataProvider('amountProvider')]
    public function testPreservesFractionalMinorUnitsExactly(
        int|float|string $minorUnits,
        int $scale,
        string $normalizedMinorUnits,
        string $decimal,
    ): void {
        $amount = new DecimalMoneyAmount($minorUnits, $scale, '18');

        self::assertSame($normalizedMinorUnits, $amount->minorUnits);
        self::assertSame($decimal, $amount->toDecimalString());
        self::assertSame('18', $amount->currencyId);
    }

    /**
     * @return iterable<string, array{int|float|string, int, string, string}>
     */
    public static function amountProvider(): iterable
    {
        yield 'integer minor units' => [4_946_100, 2, '4946100', '49461.00'];
        yield 'fractional converted minor units' => [
            '-9057.3831561769',
            2,
            '-9057.3831561769',
            '-90.573831561769',
        ];
        yield 'fraction smaller than one minor unit' => ['0.5', 2, '0.5', '0.005'];
        yield 'scientific notation' => ['1.25e3', 2, '1250', '12.50'];
        yield 'negative scientific notation' => ['-1.25e-3', 2, '-0.00125', '-0.0000125'];
        yield 'zero scale' => ['12.3400', 0, '12.34', '12.34'];
        yield 'negative zero' => ['-0.00', 2, '0', '0.00'];
    }

    public function testSerializesDecimalAndMinorUnitsAsStrings(): void
    {
        self::assertSame([
            'minorUnits' => '-9057.3831561769',
            'scale' => 2,
            'decimal' => '-90.573831561769',
            'currencyId' => '18',
        ], (new DecimalMoneyAmount('-9057.3831561769', 2, '18'))->jsonSerialize());
    }

    public function testRejectsNonNumericMinorUnits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DecimalMoneyAmount('not-a-number');
    }

    public function testRejectsNonFiniteFloat(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DecimalMoneyAmount(INF);
    }

    public function testRejectsUnboundedExponent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exponent is too large');

        new DecimalMoneyAmount('1e1001');
    }
}
