<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Model\Currency;
use Soz\Drebedengi\Model\RecordQuery;
use Soz\Drebedengi\Service\RecordService;
use Soz\Drebedengi\Support\CurrencyCatalog;
use Soz\Drebedengi\Tests\Support\FakeTransport;
use Soz\Drebedengi\Transport\TransportInterface;

final class RecordBalanceAfterTest extends TestCase
{
    public function testExcludingDebtsDoesNotChangeBalanceAfterVisibleRecord(): void
    {
        $income = $this->row('1', 10000, '10:00:00');
        $debt = $this->row('2', -3000, '12:00:00') + ['is_duty' => true];
        $transport = new FakeTransport([
            'getRecordList' => ['__sequence' => [[$income], [$debt, $income]]],
            'getBalance' => [['place_id' => '1', 'currency_id' => '3', 'sum' => '7000']],
        ]);

        $records = $this->service($transport)->list($this->query()->includeDebts(false));

        self::assertCount(1, $records);
        self::assertSame('1', $records[0]->id);
        self::assertSame('100.00', $records[0]->balanceAfter?->toDecimalString());
        self::assertFalse($transport->mapArgument(0)['is_show_duty']);
        self::assertTrue($transport->mapArgument(1)['is_show_duty']);
    }

    public function testRestoresBalancesForHiddenAccountEndingAtZero(): void
    {
        $rows = [$this->row('2', -10000, '12:00:00'), $this->row('1', 10000, '10:00:00')];
        $transport = new class($rows) implements TransportInterface {
            /** @param list<array<string, mixed>> $rows */
            public function __construct(private readonly array $rows)
            {
            }

            public function call(string $method, array $arguments = []): mixed
            {
                if ($method === 'getRecordList') {
                    return $this->rows;
                }
                if ($method === 'getBalance') {
                    $params = $arguments[0] ?? [];
                    if (is_array($params)
                        && ($params['is_with_hidden'] ?? false) === true
                        && ($params['is_with_null'] ?? false) === true) {
                        return [['place_id' => '1', 'currency_id' => '3', 'sum' => '0']];
                    }

                    return [];
                }

                throw new \LogicException('Unexpected test call: ' . $method);
            }
        };

        $records = $this->service($transport)->list($this->query());

        self::assertSame('0.00', $records[0]->balanceAfter?->toDecimalString());
        self::assertSame('100.00', $records[1]->balanceAfter?->toDecimalString());
        self::assertSame('3', $records[1]->balanceAfter->currencyId);
    }

    private function query(): RecordQuery
    {
        return RecordQuery::forDateRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-01'),
        )->withBalanceAfter();
    }

    /** @return array<string, mixed> */
    private function row(string $id, int $sum, string $time): array
    {
        return [
            'id' => $id,
            'place_id' => '1',
            'budget_object_id' => '10',
            'sum' => (string)$sum,
            'operation_date' => '2026-01-01 ' . $time,
            'currency_id' => '3',
            'operation_type' => $sum < 0 ? '3' : '2',
        ];
    }

    private function service(TransportInterface $transport): RecordService
    {
        return new RecordService(
            $transport,
            new ClientOptions(new \DateTimeZone('UTC')),
            new CurrencyCatalog($transport, [Currency::fromSoap([
                'id' => '3', 'name' => 'EUR', 'code' => 'EUR', 'ratio' => '1',
            ])]),
        );
    }
}
