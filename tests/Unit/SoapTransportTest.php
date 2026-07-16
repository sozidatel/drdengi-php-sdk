<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SoapClient;
use SoapFault;
use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Credentials;
use Soz\Drebedengi\Endpoint;
use Soz\Drebedengi\Exception\AmbiguousMutationException;
use Soz\Drebedengi\Exception\EndpointUnavailableException;
use Soz\Drebedengi\Exception\SoapFaultException;
use Soz\Drebedengi\Exception\TransportException;
use Soz\Drebedengi\Transport\SoapTransport;
use Soz\Drebedengi\WsdlCache;

final class SoapTransportTest extends TestCase
{
    #[DataProvider('infrastructureFaultCodes')]
    public function testExplicitInfrastructureFaultCodesAreClassifiedForFailover(string $faultCode): void
    {
        $transport = $this->transportThrowing(new SoapFault($faultCode, 'Network unavailable'));

        try {
            $transport->call('getBalance');
            self::fail('Expected endpoint failure.');
        } catch (EndpointUnavailableException $exception) {
            self::assertSame('getBalance', $exception->method);
            self::assertSame(Endpoint::DEFAULT_BASE_URI, $exception->endpoint);
            self::assertTrue($exception->retrySafe);
            self::assertSame($faultCode, $exception->faultCode);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function infrastructureFaultCodes(): iterable
    {
        yield 'HTTP' => ['HTTP'];
        yield 'WSDL' => ['WSDL'];
        yield 'namespaced HTTP' => ['SOAP-ENV:HTTP'];
    }

    public function testApplicationSoapFaultIsNotClassifiedForFailover(): void
    {
        $transport = $this->transportThrowing(new SoapFault('SOAP-ENV:Server', 'Business rule rejected'));

        try {
            $transport->call('getBalance');
            self::fail('Expected SOAP transport exception.');
        } catch (TransportException $exception) {
            self::assertInstanceOf(SoapFaultException::class, $exception);
            self::assertStringContainsString('Business rule rejected', $exception->getMessage());
            self::assertSame('getBalance', $exception->method);
            self::assertSame(Endpoint::DEFAULT_BASE_URI, $exception->endpoint);
            self::assertTrue($exception->retrySafe);
            self::assertSame('SOAP-ENV:Server', $exception->faultCode);
        }
    }

    public function testInfrastructureFailureOfMutationIsExplicitlyAmbiguousOnSingleEndpoint(): void
    {
        $transport = $this->transportThrowing(new SoapFault('HTTP', 'Response was lost'));

        try {
            $transport->call('setRecordList', [[['client_id' => 123]]]);
            self::fail('Expected ambiguous mutation failure.');
        } catch (AmbiguousMutationException $exception) {
            self::assertSame('setRecordList', $exception->method);
            self::assertSame(Endpoint::DEFAULT_BASE_URI, $exception->endpoint);
            self::assertFalse($exception->retrySafe);
            self::assertSame('HTTP', $exception->faultCode);
            self::assertNull($exception->getPrevious());
        }
    }

    public function testApplicationFaultOfMutationIsStructuredButNotMarkedRetrySafe(): void
    {
        $transport = $this->transportThrowing(
            new SoapFault('SOAP-ENV:Client', 'Record is invalid'),
        );

        try {
            $transport->call('setRecordList', [[['client_id' => 123]]]);
            self::fail('Expected application SOAP fault.');
        } catch (SoapFaultException $exception) {
            self::assertSame('setRecordList', $exception->method);
            self::assertSame(Endpoint::DEFAULT_BASE_URI, $exception->endpoint);
            self::assertFalse($exception->retrySafe);
            self::assertSame('SOAP-ENV:Client', $exception->faultCode);
        }
    }

    public function testKnownDrebedengiServerTimeoutIsClassifiedForFailover(): void
    {
        $transport = $this->transportThrowing(
            new SoapFault('SOAP-ENV:Server', 'Сервер слишком долго не отвечает.'),
        );

        try {
            $transport->call('getBalance');
            self::fail('Expected endpoint timeout.');
        } catch (EndpointUnavailableException $exception) {
            self::assertStringContainsString('[SOAP-ENV:Server]', $exception->getMessage());
            self::assertStringContainsString('Сервер слишком долго не отвечает', $exception->getMessage());
        }
    }

    public function testSameMessageWithClientFaultCodeIsNotClassifiedForFailover(): void
    {
        $transport = $this->transportThrowing(
            new SoapFault('SOAP-ENV:Client', 'Сервер слишком долго не отвечает'),
        );

        try {
            $transport->call('getBalance');
            self::fail('Expected SOAP transport exception.');
        } catch (TransportException $exception) {
            self::assertNotInstanceOf(EndpointUnavailableException::class, $exception);
        }
    }

    public function testCredentialsAreRedactedFromExceptionMessages(): void
    {
        $credentials = new Credentials('api-secret', 'login-secret', 'password-secret');
        $transport = $this->transportThrowing(
            new SoapFault('api-secret', 'api-secret login-secret password-secret'),
            $credentials,
        );

        try {
            $transport->call('getAccessStatus');
            self::fail('Expected SOAP transport exception.');
        } catch (TransportException $exception) {
            self::assertStringNotContainsString($credentials->apiId, $exception->getMessage());
            self::assertStringNotContainsString($credentials->login, $exception->getMessage());
            self::assertStringNotContainsString($credentials->password, $exception->getMessage());
            self::assertStringContainsString('[redacted]', $exception->getMessage());
            self::assertSame('[redacted]', $exception->faultCode);
            self::assertNull($exception->getPrevious());

            $trace = var_export($exception->getTrace(), true);
            self::assertStringNotContainsString($credentials->apiId, $trace);
            self::assertStringNotContainsString($credentials->login, $trace);
            self::assertStringNotContainsString($credentials->password, $trace);
        }
    }

    public function testCallerCannotOverrideReservedSoapOptions(): void
    {
        $endpoint = new Endpoint('https://example.test/base');
        $transport = new SoapTransport(
            new Credentials('api', 'login', 'password'),
            $endpoint,
            [
                'exceptions' => false,
                'location' => 'https://attacker.test/soap/',
                'trace' => true,
                'connection_timeout' => 7,
            ],
        );

        $method = new \ReflectionMethod($transport, 'clientOptions');
        $options = $method->invoke($transport);
        self::assertIsArray($options);

        self::assertTrue($options['exceptions']);
        self::assertSame($endpoint->soapLocation(), $options['location']);
        self::assertTrue($options['trace']);
        self::assertSame(7, $options['connection_timeout']);
        self::assertSame(WSDL_CACHE_MEMORY, $options['cache_wsdl']);
    }

    public function testTypedTransportOptionsAreMappedToSoapClient(): void
    {
        $transport = new SoapTransport(
            new Credentials('api', 'login', 'password'),
            new Endpoint(),
            options: new ClientOptions(
                connectTimeout: 12,
                readTimeout: 45.5,
                wsdlCache: WsdlCache::Disk,
            ),
        );

        $method = new \ReflectionMethod($transport, 'clientOptions');
        $options = $method->invoke($transport);
        self::assertIsArray($options);

        self::assertSame(12, $options['connection_timeout']);
        self::assertSame(WSDL_CACHE_DISK, $options['cache_wsdl']);
        self::assertArrayHasKey('stream_context', $options);
        self::assertIsResource($options['stream_context']);
        $contextOptions = stream_context_get_options($options['stream_context']);
        self::assertArrayHasKey('http', $contextOptions);
        self::assertIsArray($contextOptions['http']);
        self::assertSame(45.5, $contextOptions['http']['timeout']);
    }

    public function testRawSoapOptionsKeepPrecedenceOverTypedTransportOptions(): void
    {
        $streamContext = stream_context_create(['http' => ['timeout' => 99.0]]);
        $transport = new SoapTransport(
            new Credentials('api', 'login', 'password'),
            new Endpoint(),
            [
                'connection_timeout' => 77,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'stream_context' => $streamContext,
            ],
            new ClientOptions(
                connectTimeout: 12,
                readTimeout: 45.5,
                wsdlCache: WsdlCache::Disk,
            ),
        );

        $method = new \ReflectionMethod($transport, 'clientOptions');
        $options = $method->invoke($transport);
        self::assertIsArray($options);

        self::assertSame(77, $options['connection_timeout']);
        self::assertSame(WSDL_CACHE_NONE, $options['cache_wsdl']);
        self::assertSame($streamContext, $options['stream_context']);
    }

    public function testTypedTimeoutsCanBeDisabledWithoutAddingSoapOptions(): void
    {
        $transport = new SoapTransport(
            new Credentials('api', 'login', 'password'),
            new Endpoint(),
            options: new ClientOptions(connectTimeout: null, readTimeout: null),
        );

        $method = new \ReflectionMethod($transport, 'clientOptions');
        $options = $method->invoke($transport);
        self::assertIsArray($options);

        self::assertArrayNotHasKey('connection_timeout', $options);
        self::assertArrayNotHasKey('stream_context', $options);
    }

    private function transportThrowing(
        SoapFault $fault,
        ?Credentials $credentials = null,
    ): SoapTransport {
        $transport = new SoapTransport(
            $credentials ?? new Credentials('api', 'login', 'password'),
            new Endpoint(),
        );
        $property = new \ReflectionProperty($transport, 'client');
        $property->setValue($transport, new ThrowingSoapClient($fault));

        return $transport;
    }
}

final class ThrowingSoapClient extends SoapClient
{
    public function __construct(private readonly SoapFault $fault)
    {
    }

    /** @param list<mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        throw $this->fault;
    }
}
