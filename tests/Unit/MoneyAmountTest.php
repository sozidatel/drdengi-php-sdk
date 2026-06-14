<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
        $this->expectException(\Soz\Drebedengi\Exception\InvalidArgumentException::class);

        MoneyAmount::fromDecimalString('0.001', 2);
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
