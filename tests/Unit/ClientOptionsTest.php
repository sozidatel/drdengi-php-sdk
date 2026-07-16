<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\WsdlCache;

final class ClientOptionsTest extends TestCase
{
    public function testTransportDefaultsAreExplicitAndConservative(): void
    {
        $options = new ClientOptions();

        self::assertSame(10, $options->connectTimeout);
        self::assertSame(30.0, $options->readTimeout);
        self::assertSame(WsdlCache::Memory, $options->wsdlCache);
    }

    public function testTimeoutsCanBeDisabledExplicitly(): void
    {
        $options = new ClientOptions(connectTimeout: null, readTimeout: null);

        self::assertNull($options->connectTimeout);
        self::assertNull($options->readTimeout);
    }

    #[DataProvider('invalidTimeouts')]
    public function testInvalidTimeoutsAreRejected(?int $connectTimeout, ?float $readTimeout): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ClientOptions(connectTimeout: $connectTimeout, readTimeout: $readTimeout);
    }

    /** @return iterable<string, array{?int, ?float}> */
    public static function invalidTimeouts(): iterable
    {
        yield 'zero connect timeout' => [0, 30.0];
        yield 'negative connect timeout' => [-1, 30.0];
        yield 'zero read timeout' => [10, 0.0];
        yield 'negative read timeout' => [10, -1.0];
        yield 'infinite read timeout' => [10, INF];
        yield 'NaN read timeout' => [10, NAN];
    }
}
