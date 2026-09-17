<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Model\PreparedRecordWrite;

final class PreparedRecordWriteTest extends TestCase
{
    public function testCapturesExactPayloadAndSignedAmountWithItsScale(): void
    {
        $payload = self::expense([
            'place_id' => 'legacy place',
            'budget_object_id' => '',
            'currency_id' => ' 03 ',
            'operation_date' => '2026-09-18 13:45:00',
            'comment' => "Обед / café\nSecond line",
            'sum' => -123456,
        ]);

        $prepared = new PreparedRecordWrite([$payload], [3]);
        $restored = PreparedRecordWrite::fromJson($prepared->toJson());

        self::assertSame([$payload], $prepared->payloads);
        self::assertSame($prepared->payloads, $restored->payloads);
        self::assertSame('-123.456', $restored->amounts[0]->toDecimalString());
        self::assertSame(' 03 ', $restored->amounts[0]->currencyId);
        self::assertSame(1, count($restored));
        self::assertSame([
            'version' => 1,
            'payloads' => [$payload],
            'scales' => [3],
        ], $prepared->jsonSerialize());
        self::assertSame($prepared->toJson(), json_encode($prepared, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function testSnapshotDetachesCallerReferencesAtEveryArrayLevel(): void
    {
        $comment = 'Approved comment';
        $sum = -1250;
        $scale = 2;
        $payload = self::expense();
        $payload['comment'] =& $comment;
        $payload['sum'] =& $sum;
        $payloads = [&$payload];
        $scales = [&$scale];
        $data = ['version' => 1, 'payloads' => &$payloads, 'scales' => &$scales];
        $prepared = PreparedRecordWrite::fromArray($data);
        $json = $prepared->toJson();

        $comment = 'Changed after approval';
        $sum = -99999;
        $scale = 6;
        $payload['client_id'] = 900;
        $payloads[] = self::expense();
        $scales[] = 0;
        $serialized = $prepared->jsonSerialize();
        $serialized['payloads'][0]['comment'] = 'Changed copy';
        $serialized['scales'][0] = 0;

        self::assertSame($json, $prepared->toJson());
        self::assertSame('Approved comment', $prepared->payloads[0]['comment']);
        self::assertSame(-1250, $prepared->amounts[0]->minorUnits);
        self::assertSame(2, $prepared->amounts[0]->scale);
        self::assertCount(1, $prepared);
    }

    public function testEmptySnapshotRoundTripsAsLists(): void
    {
        $prepared = PreparedRecordWrite::fromJson('{"version":1,"payloads":[],"scales":[]}');

        self::assertCount(0, $prepared);
        self::assertSame([], $prepared->payloads);
        self::assertSame([], $prepared->amounts);
        self::assertSame('{"version":1,"payloads":[],"scales":[]}', $prepared->toJson());
    }

    public function testGroupedExpenseKeepsAllClientAndGroupIds(): void
    {
        $payloads = [
            self::expense(['client_id' => 111, 'group_id' => '333']),
            self::expense(['client_id' => 222, 'group_id' => '333', 'sum' => -2500]),
        ];

        $restored = PreparedRecordWrite::fromJson((new PreparedRecordWrite($payloads, [2, 2]))->toJson());

        self::assertSame($payloads, $restored->payloads);
        self::assertSame('-12.50', $restored->amounts[0]->toDecimalString());
        self::assertSame('-25.00', $restored->amounts[1]->toDecimalString());
    }

    #[DataProvider('pairedOperations')]
    public function testPairedRowsKeepReciprocalLinksAndIndependentScales(int $operation, string $field): void
    {
        $payloads = [
            self::expense(['client_id' => 111, 'operation_type' => $operation, $field => 222]),
            self::expense(['client_id' => 222, 'operation_type' => $operation, $field => 111, 'sum' => 9123]),
        ];

        $restored = PreparedRecordWrite::fromArray((new PreparedRecordWrite($payloads, [2, 3]))->jsonSerialize());

        self::assertSame($payloads, $restored->payloads);
        self::assertSame('-12.50', $restored->amounts[0]->toDecimalString());
        self::assertSame('9.123', $restored->amounts[1]->toDecimalString());
    }

    /** @return iterable<string, array{int, string}> */
    public static function pairedOperations(): iterable
    {
        yield 'transfer' => [4, 'client_move_id'];
        yield 'exchange' => [5, 'client_change_id'];
    }

    public function testIntegerExtremesRemainIntegersThroughJson(): void
    {
        $prepared = new PreparedRecordWrite([
            self::expense(['sum' => PHP_INT_MIN]),
            self::expense(['client_id' => 222, 'operation_type' => 2, 'sum' => PHP_INT_MAX]),
        ], [18, 0]);

        $restored = PreparedRecordWrite::fromJson($prepared->toJson());

        self::assertSame(PHP_INT_MIN, $restored->amounts[0]->minorUnits);
        self::assertSame(PHP_INT_MAX, $restored->amounts[1]->minorUnits);
        self::assertSame($prepared->payloads, $restored->payloads);
    }

    /**
     * @param array<mixed> $payloads
     * @param array<mixed> $scales
     */
    #[DataProvider('invalidPayloads')]
    public function testRejectsMalformedPayloadsBeforeTheyCanBeSubmitted(array $payloads, array $scales): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PreparedRecordWrite($payloads, $scales);
    }

    /** @return iterable<string, array{array<mixed>, array<mixed>}> */
    public static function invalidPayloads(): iterable
    {
        yield 'associative rows' => [['first' => self::expense()], [2]];
        yield 'sparse rows' => [[1 => self::expense()], [2]];
        yield 'associative scales' => [[self::expense()], ['scale' => 2]];
        yield 'missing scale' => [[self::expense()], []];
        yield 'surplus scale' => [[self::expense()], [2, 2]];
        yield 'object row' => [[(object)self::expense()], [2]];
        yield 'scalar row' => [['invalid'], [2]];
        yield 'empty row' => [[[]], [2]];
        yield 'float scale' => [[self::expense()], [2.0]];
        yield 'string scale' => [[self::expense()], ['2']];
        yield 'negative scale' => [[self::expense()], [-1]];
        yield 'excess scale' => [[self::expense()], [19]];
        yield 'float amount' => [[self::expense(['sum' => -1250.0])], [2]];
        yield 'string amount' => [[self::expense(['sum' => '-1250'])], [2]];
        yield 'object amount' => [[self::expense(['sum' => (object)['minorUnits' => -1250]])], [2]];
        yield 'non-string place' => [[self::expense(['place_id' => 1])], [2]];
        yield 'non-string date' => [[self::expense(['operation_date' => new \DateTimeImmutable()])], [2]];
        yield 'null comment' => [[self::expense(['comment' => null])], [2]];
        yield 'integer boolean' => [[self::expense(['is_duty' => 0])], [2]];
        yield 'string client ID' => [[self::expense(['client_id' => '111'])], [2]];
        yield 'zero client ID' => [[self::expense(['client_id' => 0])], [2]];
        yield 'excess client ID' => [[self::expense(['client_id' => 1000000000])], [2]];
        yield 'duplicate client ID' => [[self::expense(), self::expense()], [2, 2]];
        yield 'server ID' => [[self::expense(['server_id' => '10'])], [2]];
        yield 'unknown field' => [[self::expense(['arbitrary' => false])], [2]];
        yield 'unknown operation' => [[self::expense(['operation_type' => 6])], [2]];
        yield 'string operation' => [[self::expense(['operation_type' => '3'])], [2]];
        yield 'integer group ID' => [[self::expense(['group_id' => 333])], [2]];
        yield 'singleton group' => [[self::expense(['group_id' => '333'])], [2]];
        yield 'invalid group ID' => [[
            self::expense(['group_id' => '0']),
            self::expense(['client_id' => 222, 'group_id' => '0']),
        ], [2, 2]];
        yield 'income group' => [[
            self::expense(['group_id' => '333', 'operation_type' => 2]),
            self::expense(['client_id' => 222, 'group_id' => '333']),
        ], [2, 2]];
        yield 'expense with move link' => [[self::expense(['client_move_id' => 222])], [2]];
        yield 'transfer without link' => [[self::expense(['operation_type' => 4])], [2]];
        yield 'transfer to missing row' => [[self::expense(['operation_type' => 4, 'client_move_id' => 222])], [2]];
        yield 'transfer self link' => [[self::expense(['operation_type' => 4, 'client_move_id' => 111])], [2]];
        yield 'mixed pair operation types' => [[
            self::expense(['operation_type' => 4, 'client_move_id' => 222]),
            self::expense(['client_id' => 222, 'operation_type' => 5, 'client_change_id' => 111]),
        ], [2, 2]];
        yield 'nonreciprocal links' => [[
            self::expense(['operation_type' => 4, 'client_move_id' => 222]),
            self::expense(['client_id' => 222, 'operation_type' => 4, 'client_move_id' => 333]),
        ], [2, 2]];
    }

    /** @param array<mixed> $data */
    #[DataProvider('invalidEnvelopes')]
    public function testRejectsInvalidSerializedEnvelope(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);

        PreparedRecordWrite::fromArray($data);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidEnvelopes(): iterable
    {
        yield 'missing version' => [['payloads' => [], 'scales' => []]];
        yield 'future version' => [['version' => 2, 'payloads' => [], 'scales' => []]];
        yield 'string version' => [['version' => '1', 'payloads' => [], 'scales' => []]];
        yield 'float version' => [['version' => 1.0, 'payloads' => [], 'scales' => []]];
        yield 'extra metadata' => [['version' => 1, 'payloads' => [], 'scales' => [], 'credentials' => []]];
        yield 'missing payloads' => [['version' => 1, 'scales' => []]];
        yield 'missing scales' => [['version' => 1, 'payloads' => []]];
        yield 'null rows' => [['version' => 1, 'payloads' => null, 'scales' => []]];
        yield 'scalar scales' => [['version' => 1, 'payloads' => [], 'scales' => 2]];
    }

    #[DataProvider('invalidJsonShapes')]
    public function testDistinguishesJsonObjectsFromLists(string $json): void
    {
        $this->expectException(InvalidArgumentException::class);

        PreparedRecordWrite::fromJson($json);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidJsonShapes(): iterable
    {
        yield 'top-level list' => ['[]'];
        yield 'top-level scalar' => ['1'];
        yield 'top-level null' => ['null'];
        yield 'object payload list' => ['{"version":1,"payloads":{},"scales":[]}'];
        yield 'object scale list' => ['{"version":1,"payloads":[],"scales":{}}'];
        yield 'list row' => ['{"version":1,"payloads":[[]],"scales":[2]}'];
        yield 'keyed row list' => ['{"version":1,"payloads":{"0":{}},"scales":[2]}'];
    }

    public function testInvalidJsonWrapsItsOriginalException(): void
    {
        try {
            PreparedRecordWrite::fromJson('{');
            self::fail('Malformed JSON must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertInstanceOf(\JsonException::class, $exception->getPrevious());
        }
    }

    public function testUnencodableCommentFailsWithoutAlteringTheSnapshot(): void
    {
        $prepared = new PreparedRecordWrite([self::expense(['comment' => "\xFF"])], [2]);

        try {
            $prepared->toJson();
            self::fail('Invalid UTF-8 must not be silently replaced.');
        } catch (InvalidArgumentException $exception) {
            self::assertInstanceOf(\JsonException::class, $exception->getPrevious());
            self::assertSame("\xFF", $prepared->payloads[0]['comment']);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function expense(array $overrides = []): array
    {
        return array_replace([
            'client_id' => 111,
            'place_id' => '1',
            'budget_object_id' => '2',
            'sum' => -1250,
            'operation_date' => '2026-09-18 12:00:00',
            'comment' => '',
            'currency_id' => '3',
            'is_duty' => false,
            'operation_type' => 3,
        ], $overrides);
    }
}
