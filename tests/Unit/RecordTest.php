<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Model\Record;

final class RecordTest extends TestCase
{
    #[DataProvider('supportedSumProvider')]
    public function testPreservesSupportedIntegerSums(string $field, int|float|string $value, int $expected): void
    {
        $record = Record::fromSoap([$field => $value] + self::recordPayload());

        self::assertSame($expected, $record->sum->minorUnits);
        self::assertSame($value, $record->raw[$field]);
    }

    /** @return iterable<string, array{string, int|float|string, int}> */
    public static function supportedSumProvider(): iterable
    {
        foreach (['sum', 'difference'] as $field) {
            yield $field . ' maximum integer' => [$field, PHP_INT_MAX, PHP_INT_MAX];
            yield $field . ' minimum integer' => [$field, PHP_INT_MIN, PHP_INT_MIN];
            yield $field . ' maximum string' => [$field, (string)PHP_INT_MAX, PHP_INT_MAX];
            yield $field . ' minimum string' => [$field, (string)PHP_INT_MIN, PHP_INT_MIN];
            yield $field . ' maximum with leading zeros' => [$field, '000' . PHP_INT_MAX, PHP_INT_MAX];
            yield $field . ' minimum with leading zeros' => [$field, '-000' . substr((string)PHP_INT_MIN, 1), PHP_INT_MIN];
            yield $field . ' padded amount' => [$field, '  -001234  ', -1234];
            yield $field . ' zero' => [$field, '000', 0];
            yield $field . ' negative zero' => [$field, '-000', 0];
            yield $field . ' integral float' => [$field, 1234.0, 1234];
        }
    }

    #[DataProvider('overflowingSumProvider')]
    public function testRejectsSumOutsideIntegerRange(string $field, string $value): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('record sum outside the supported integer range');

        Record::fromSoap([$field => $value] + self::recordPayload());
    }

    /** @return iterable<string, array{string, string}> */
    public static function overflowingSumProvider(): iterable
    {
        $aboveMaximum = substr((string)PHP_INT_MIN, 1);
        $belowMinimum = substr((string)PHP_INT_MIN, 0, -1) . '9';

        foreach (['sum', 'difference'] as $field) {
            yield $field . ' above maximum' => [$field, $aboveMaximum];
            yield $field . ' below minimum' => [$field, $belowMinimum];
            yield $field . ' padded above maximum' => [$field, '  000' . $aboveMaximum . '  '];
            yield $field . ' far below minimum' => [$field, '-' . str_repeat('9', 100)];
        }
    }

    public function testFallsBackToDifferenceWhenSumIsEmpty(): void
    {
        $record = Record::fromSoap(['sum' => '', 'difference' => '001234'] + self::recordPayload());

        self::assertSame(1234, $record->sum->minorUnits);
    }

    public function testDoesNotReplaceAnOverflowingSumWithDifference(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('record sum outside the supported integer range');

        Record::fromSoap([
            'sum' => substr((string)PHP_INT_MIN, 1),
            'difference' => '1234',
        ] + self::recordPayload());
    }

    /** @return array<string, mixed> */
    private static function recordPayload(): array
    {
        return [
            'id' => '20',
            'place_id' => '1',
            'budget_object_id' => '2',
            'operation_date' => '2026-06-14 12:00:00',
            'currency_id' => '3',
            'operation_type' => 3,
        ];
    }
}
