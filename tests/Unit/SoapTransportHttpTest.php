<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Credentials;
use Soz\Drebedengi\Endpoint;
use Soz\Drebedengi\Exception\AmbiguousMutationException;
use Soz\Drebedengi\Exception\SoapFaultException;
use Soz\Drebedengi\Tests\Support\LocalSoapServer;
use Soz\Drebedengi\Transport\FailoverTransport;
use Soz\Drebedengi\Transport\SoapTransport;
use Soz\Drebedengi\WsdlCache;

final class SoapTransportHttpTest extends TestCase
{
    private LocalSoapServer $server;

    protected function setUp(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('Local HTTP transport tests require proc_open.');
        }
        $this->server = new LocalSoapServer();
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            $this->server->stop();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function malformedResponses(): iterable
    {
        yield 'HTML gateway response' => ['non-xml'];
        yield 'truncated XML' => ['truncated'];
        yield 'missing SOAP envelope' => ['missing-envelope'];
        yield 'missing SOAP body' => ['missing-body'];
    }

    #[DataProvider('malformedResponses')]
    public function testUnreadableReadResponseTriggersFailover(string $scenario): void
    {
        $transport = new FailoverTransport([
            'first' => $this->transport($scenario),
            'fallback' => $this->transport('success'),
        ]);

        self::assertSame('1', $transport->call('getAccessStatus'));
        self::assertSame(['/' . $scenario . '/soap/', '/success/soap/'], $this->requests());
    }

    #[DataProvider('malformedResponses')]
    public function testUnreadableMutationResponseIsAmbiguousAndNeverRetried(string $scenario): void
    {
        $endpoint = 'mutation-' . $scenario;
        $transport = new FailoverTransport([
            'first' => $this->transport($endpoint),
            'fallback' => $this->transport('success'),
        ]);
        try {
            $transport->call('setRecordList');
            self::fail('Expected an ambiguous mutation response.');
        } catch (AmbiguousMutationException $exception) {
            self::assertSame('setRecordList', $exception->method);
            self::assertFalse($exception->retrySafe);
        }
        // One read-only endpoint probe, then exactly one mutation. The healthy
        // fallback must never receive the mutation after an unreadable reply.
        self::assertSame(['/' . $endpoint . '/soap/', '/' . $endpoint . '/soap/'], $this->requests());
    }

    /** @return iterable<string, array{string}> */
    public static function businessFaults(): iterable
    {
        yield 'Client fault' => ['fault'];
        yield 'Server fault' => ['fault-server'];
        yield 'application message resembles a parser error' => ['fault-parser-message'];
    }

    #[DataProvider('businessFaults')]
    public function testValidSoapFaultDoesNotTriggerFailover(string $scenario): void
    {
        $transport = new FailoverTransport([
            'first' => $this->transport($scenario),
            'fallback' => $this->transport('success'),
        ]);

        try {
            $transport->call('getAccessStatus');
            self::fail('Expected an application fault.');
        } catch (SoapFaultException $exception) {
            self::assertSame('getAccessStatus', $exception->method);
        }
        self::assertSame(['/' . $scenario . '/soap/'], $this->requests());
    }

    private function transport(string $scenario): SoapTransport
    {
        return new SoapTransport(
            new Credentials('dummy-api', 'dummy-login', 'dummy-password'),
            new Endpoint($this->server->baseUri . '/' . $scenario),
            options: new ClientOptions(wsdlCache: WsdlCache::None),
        );
    }

    /** @return mixed */
    private function requests(): mixed
    {
        $response = file_get_contents($this->server->baseUri . '/requests');
        self::assertIsString($response);

        return json_decode($response, true, flags: JSON_THROW_ON_ERROR);
    }
}
