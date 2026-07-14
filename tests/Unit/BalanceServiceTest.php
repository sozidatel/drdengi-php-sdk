<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
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
}
