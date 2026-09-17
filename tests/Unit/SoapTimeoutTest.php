<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Credentials;
use Soz\Drebedengi\Endpoint;
use Soz\Drebedengi\Exception\AmbiguousMutationException;
use Soz\Drebedengi\Exception\EndpointUnavailableException;
use Soz\Drebedengi\Tests\Support\LocalSoapServer;
use Soz\Drebedengi\Transport\FailoverTransport;
use Soz\Drebedengi\Transport\SoapTransport;
use Soz\Drebedengi\WsdlCache;

final class SoapTimeoutTest extends TestCase
{
    /** @var list<LocalSoapServer> */
    private array $servers = [];

    protected function setUp(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('Local HTTP timeout tests require proc_open.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function delayedResponses(): iterable
    {
        yield 'delayed HTTP headers' => ['delay-headers'];
        yield 'stalled partial SOAP body' => ['delay-body'];
    }

    #[DataProvider('delayedResponses')]
    public function testFractionalTimeoutInterruptsActualSoapResponse(string $scenario): void
    {
        $server = $this->server();
        $transport = $this->transport($server, $scenario, 0.1);
        $transport->soapClient();
        $globalTimeout = ini_get('default_socket_timeout');
        $started = microtime(true);

        try {
            $transport->call('getAccessStatus');
            self::fail('Expected the configured read timeout to interrupt the response.');
        } catch (EndpointUnavailableException $exception) {
            self::assertSame('getAccessStatus', $exception->method);
            self::assertTrue($exception->retrySafe);
        }

        // The exception is the regression assertion; leave a broad time bound
        // so scheduling jitter on shared CI does not cause a timing-only failure.
        self::assertLessThan(2.0, microtime(true) - $started);
        self::assertSame($globalTimeout, ini_get('default_socket_timeout'));
        self::assertSame(['/' . $scenario . '/soap/'], $this->requests($server));
    }

    #[DataProvider('delayedResponses')]
    public function testResponseWithinConfiguredTimeoutSucceedsWithoutChangingGlobalTimeout(string $scenario): void
    {
        $server = $this->server();
        $transport = $this->transport($server, $scenario, 2.0);
        $globalTimeout = ini_get('default_socket_timeout');

        self::assertSame('1', $transport->call('getAccessStatus'));
        self::assertSame($globalTimeout, ini_get('default_socket_timeout'));
        self::assertSame(['/' . $scenario . '/soap/'], $this->requests($server));
    }

    public function testNullReadTimeoutAllowsDelayedResponseWithoutChangingGlobalTimeout(): void
    {
        $server = $this->server();
        $transport = $this->transport($server, 'delay-body', null);
        $globalTimeout = ini_get('default_socket_timeout');

        self::assertSame('1', $transport->call('getAccessStatus'));
        self::assertSame($globalTimeout, ini_get('default_socket_timeout'));
        self::assertSame(['/delay-body/soap/'], $this->requests($server));
    }

    #[DataProvider('delayedResponses')]
    public function testShorterRawContextTimeoutTakesPrecedence(string $scenario): void
    {
        $server = $this->server();
        $context = stream_context_create(['http' => ['timeout' => 0.1]]);
        $transport = $this->transport($server, $scenario, 2.0, ['stream_context' => $context]);
        $globalTimeout = ini_get('default_socket_timeout');

        try {
            $transport->call('getAccessStatus');
            self::fail('Expected the raw stream context timeout to override the typed timeout.');
        } catch (EndpointUnavailableException $exception) {
            self::assertTrue($exception->retrySafe);
        }

        self::assertSame($globalTimeout, ini_get('default_socket_timeout'));
        self::assertSame(['/' . $scenario . '/soap/'], $this->requests($server));
    }

    #[DataProvider('delayedResponses')]
    public function testLongerRawContextTimeoutTakesPrecedence(string $scenario): void
    {
        $server = $this->server();
        $context = stream_context_create(['http' => ['timeout' => 2.0]]);
        $transport = $this->transport($server, $scenario, 0.1, ['stream_context' => $context]);
        $globalTimeout = ini_get('default_socket_timeout');

        self::assertSame('1', $transport->call('getAccessStatus'));
        self::assertSame($globalTimeout, ini_get('default_socket_timeout'));
        self::assertSame(['/' . $scenario . '/soap/'], $this->requests($server));
    }

    public function testRawContextWithoutTimeoutRetainsTypedTimeoutAndCallerOptions(): void
    {
        $server = $this->server();
        $context = stream_context_create(['ssl' => ['verify_peer' => true]]);
        $contextOptions = stream_context_get_options($context);
        $transport = $this->transport($server, 'delay-headers', 0.1, ['stream_context' => $context]);

        try {
            $transport->call('getAccessStatus');
            self::fail('Expected the typed timeout to apply when the raw context has no timeout.');
        } catch (EndpointUnavailableException $exception) {
            self::assertTrue($exception->retrySafe);
        }

        self::assertSame($contextOptions, stream_context_get_options($context));
        self::assertSame(['/delay-headers/soap/'], $this->requests($server));
    }

    #[DataProvider('delayedResponses')]
    public function testReadTimeoutTriesIndependentFallbackOnce(string $scenario): void
    {
        $primary = $this->server();
        $fallback = $this->server();
        $transport = new FailoverTransport([
            'primary' => $this->transport($primary, $scenario, 0.1),
            'fallback' => $this->transport($fallback, 'success', 0.1),
        ]);

        self::assertSame('1', $transport->call('getAccessStatus'));
        self::assertSame(['/' . $scenario . '/soap/'], $this->requests($primary));
        self::assertSame(['/success/soap/'], $this->requests($fallback));
    }

    #[DataProvider('delayedResponses')]
    public function testMutationTimeoutAfterSuccessfulProbeIsAmbiguousAndNeverRetried(string $scenario): void
    {
        $primary = $this->server();
        $fallback = $this->server();
        $mutationScenario = 'mutation-' . $scenario;
        $transport = new FailoverTransport([
            'primary' => $this->transport($primary, $mutationScenario, 0.1),
            'fallback' => $this->transport($fallback, 'success', 0.1),
        ]);
        $globalTimeout = ini_get('default_socket_timeout');

        try {
            $transport->call('setRecordList');
            self::fail('Expected a timeout after sending a mutation to be ambiguous.');
        } catch (AmbiguousMutationException $exception) {
            self::assertSame('setRecordList', $exception->method);
            self::assertFalse($exception->retrySafe);
        }

        self::assertSame($globalTimeout, ini_get('default_socket_timeout'));
        // One successful read-only probe, followed by one mutation. Fetching
        // /requests waits until the serial fixture has finished its delayed reply.
        self::assertSame([
            '/' . $mutationScenario . '/soap/',
            '/' . $mutationScenario . '/soap/',
        ], $this->requests($primary));
        self::assertSame([], $this->requests($fallback));
    }

    public function testWsdlHeaderTimeoutIsRetrySafeBeforeMutationIsSent(): void
    {
        $server = $this->server();
        $transport = $this->transport($server, 'delay-wsdl-headers', 0.1);
        $globalTimeout = ini_get('default_socket_timeout');

        try {
            // ext-soap emits native WSDL-loader warnings as well as the fault
            // that the transport converts into the asserted exception.
            @$transport->call('setRecordList');
            self::fail('Expected WSDL initialization to respect the read timeout.');
        } catch (EndpointUnavailableException $exception) {
            self::assertSame('setRecordList', $exception->method);
            self::assertTrue($exception->retrySafe);
        }

        self::assertSame($globalTimeout, ini_get('default_socket_timeout'));
        self::assertSame([], $this->requests($server));
    }

    public function testNullRawContextRetainsTypedWsdlTimeoutBeforeMutationIsSent(): void
    {
        $server = $this->server();
        $transport = $this->transport($server, 'delay-wsdl-headers', 0.1, ['stream_context' => null]);
        $globalTimeout = ini_get('default_socket_timeout');

        try {
            // Expected WSDL-load failure also emits native ext-soap warnings.
            @$transport->call('setRecordList');
            self::fail('Expected a null raw context to preserve the typed WSDL timeout.');
        } catch (EndpointUnavailableException $exception) {
            self::assertSame('setRecordList', $exception->method);
            self::assertTrue($exception->retrySafe);
        }

        self::assertSame($globalTimeout, ini_get('default_socket_timeout'));
        self::assertSame([], $this->requests($server));
    }

    public function testNullConnectTimeoutSupportsUnlimitedProcessSocketTimeout(): void
    {
        $server = $this->server();
        $transport = new SoapTransport(
            new Credentials('dummy-api', 'dummy-login', 'dummy-password'),
            new Endpoint($server->baseUri . '/success'),
            options: new ClientOptions(connectTimeout: null, readTimeout: 2.0, wsdlCache: WsdlCache::None),
        );
        $globalTimeout = ini_get('default_socket_timeout');

        try {
            self::assertNotFalse(ini_set('default_socket_timeout', '-1'));
            self::assertSame('1', $transport->call('getAccessStatus'));
            self::assertSame('-1', ini_get('default_socket_timeout'));
            self::assertSame(['/success/soap/'], $this->requests($server));
        } finally {
            ini_set('default_socket_timeout', $globalTimeout);
        }

        self::assertSame($globalTimeout, ini_get('default_socket_timeout'));
    }

    private function server(): LocalSoapServer
    {
        $server = new LocalSoapServer();
        $this->servers[] = $server;

        return $server;
    }

    /** @param array<string, mixed> $soapOptions */
    private function transport(
        LocalSoapServer $server,
        string $scenario,
        ?float $timeout,
        array $soapOptions = [],
    ): SoapTransport {
        return new SoapTransport(
            new Credentials('dummy-api', 'dummy-login', 'dummy-password'),
            new Endpoint($server->baseUri . '/' . $scenario),
            $soapOptions,
            new ClientOptions(readTimeout: $timeout, wsdlCache: WsdlCache::None),
        );
    }

    /** @return mixed */
    private function requests(LocalSoapServer $server): mixed
    {
        $response = file_get_contents(
            $server->baseUri . '/requests',
            context: stream_context_create(['http' => ['timeout' => 5.0]]),
        );
        self::assertIsString($response);

        return json_decode($response, true, flags: JSON_THROW_ON_ERROR);
    }
}
