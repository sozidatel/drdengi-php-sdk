<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Model\BalanceQuery;
use Soz\Drebedengi\Model\Currency;
use Soz\Drebedengi\Service\BalanceService;
use Soz\Drebedengi\Support\CurrencyCatalog;
use Soz\Drebedengi\Tests\Support\FakeTransport;

final class BalanceServiceTest extends TestCase
{
    public function testReadsBalanceUsingCurrencyPrecision(): void
    {
        $transport = new FakeTransport(['getBalance' => [[
            'place_id' => '1',
            'place_name' => 'Wallet',
            'currency_id' => '7',
            'currency_name' => 'Bitcoin',
            'sum' => '1234',
        ]]]);
        $btc = Currency::fromSoap([
            'id' => '7',
            'name' => 'Bitcoin',
            'code' => 'BTC',
            'ratio' => '1000000',
        ]);
        $service = new BalanceService(
            $transport,
            new CurrencyCatalog($transport, [$btc]),
        );

        $balance = $service->list()[0];

        self::assertSame(8, $balance->sum->scale);
        self::assertSame('7', $balance->sum->currencyId);
        self::assertSame('0.00001234', $balance->sum->toDecimalString());
        self::assertSame(['getBalance'], array_column($transport->calls, 'method'));
    }

    public function testAcceptsTypedQueryUsingClientTimezone(): void
    {
        $transport = new FakeTransport([
            'getBalance' => [],
            'getCurrencyList' => [],
        ]);
        $service = new BalanceService(
            $transport,
            new CurrencyCatalog($transport, []),
            new ClientOptions(new \DateTimeZone('Europe/Podgorica')),
        );

        $service->list(
            BalanceQuery::at(new \DateTimeImmutable('2026-07-13 22:30:00', new \DateTimeZone('UTC')))
                ->includeHidden()
                ->includeZero(),
        );

        self::assertSame([
            'is_with_accum' => false,
            'is_with_duty' => false,
            'is_with_hidden' => true,
            'is_with_null' => true,
            'restDate' => '2026-07-14',
        ], $transport->calls[0]['arguments'][0]);
    }

    public function testKeepsLegacyArrayParametersCompatible(): void
    {
        $transport = new FakeTransport(['getBalance' => []]);
        $service = new BalanceService(
            $transport,
            new CurrencyCatalog($transport, []),
        );
        $legacyParams = [
            'restDate' => '2026-07-14',
            'is_with_accum' => true,
            'is_with_hidden' => true,
        ];

        $service->list(params: $legacyParams);

        self::assertSame($legacyParams, $transport->calls[0]['arguments'][0]);
    }
}
