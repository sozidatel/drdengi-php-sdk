<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Credentials;
use Soz\Drebedengi\DrebedengiClient;
use Soz\Drebedengi\Endpoint;
use Soz\Drebedengi\Exception\EndpointUnavailableException;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\TransportException;
use Soz\Drebedengi\Transport\FailoverTransport;
use Soz\Drebedengi\Transport\SoapTransport;
use Soz\Drebedengi\Transport\TransportInterface;

final class FailoverTransportTest extends TestCase
{
    public function testPrimarySuccessDoesNotCallFallback(): void
    {
        $primary = new ScriptedTransport(['getBalance' => [['sum' => 1]]]);
        $fallback = new ScriptedTransport(['getBalance' => [['sum' => 2]]]);
        $transport = $this->failover($primary, $fallback);

        self::assertSame([['sum' => 1]], $transport->call('getBalance'));
        self::assertSame(['getBalance'], $primary->methods());
        self::assertSame([], $fallback->methods());
    }

    public function testUnavailablePrimarySelectsFallback(): void
    {
        $primary = new ScriptedTransport([
            'getBalance' => new EndpointUnavailableException('primary unavailable'),
        ]);
        $fallback = new ScriptedTransport(['getBalance' => [['sum' => 2]]]);

        self::assertSame([['sum' => 2]], $this->failover($primary, $fallback)->call('getBalance'));
        self::assertSame(['getBalance'], $primary->methods());
        self::assertSame(['getBalance'], $fallback->methods());
    }

    public function testSelectedFallbackRemainsActiveForFollowingCalls(): void
    {
        $primary = new ScriptedTransport([
            'getBalance' => new EndpointUnavailableException('primary unavailable'),
        ]);
        $fallback = new ScriptedTransport([
            'getBalance' => ['first'],
            'getCurrencyList' => ['second'],
        ]);
        $transport = $this->failover($primary, $fallback);

        self::assertSame(['first'], $transport->call('getBalance'));
        self::assertSame(['second'], $transport->call('getCurrencyList'));
        self::assertSame(['getBalance'], $primary->methods());
        self::assertSame(['getBalance', 'getCurrencyList'], $fallback->methods());
    }

    public function testBusinessSoapFaultDoesNotTriggerFallback(): void
    {
        $fault = new TransportException('Invalid credentials');
        $primary = new ScriptedTransport(['getAccessStatus' => $fault]);
        $fallback = new ScriptedTransport(['getAccessStatus' => 1]);

        try {
            $this->failover($primary, $fallback)->call('getAccessStatus');
            self::fail('Expected business SOAP fault.');
        } catch (TransportException $exception) {
            self::assertSame($fault, $exception);
        }

        self::assertSame(['getAccessStatus'], $primary->methods());
        self::assertSame([], $fallback->methods());
    }

    public function testReadOnlyCallCanBeRetriedOnFallbackAfterInfrastructureFailure(): void
    {
        $primary = new ScriptedTransport([
            'getRecordList' => new EndpointUnavailableException('response unavailable'),
        ]);
        $fallback = new ScriptedTransport(['getRecordList' => [['id' => '42']]]);

        self::assertSame(
            [['id' => '42']],
            $this->failover($primary, $fallback)->call('getRecordList', [[], []]),
        );
        self::assertSame(['getRecordList'], $primary->methods());
        self::assertSame(['getRecordList'], $fallback->methods());
    }

    public function testMutationIsNotRetriedAfterAmbiguousInfrastructureFailure(): void
    {
        $primary = new ScriptedTransport([
            'getAccessStatus' => 1,
            'setRecordList' => new EndpointUnavailableException('response was lost'),
        ]);
        $fallback = new ScriptedTransport([
            'getAccessStatus' => 1,
            'setRecordList' => [['server_id' => 'duplicate']],
        ]);
        $transport = $this->failover($primary, $fallback);

        try {
            $transport->call('setRecordList', [[['client_id' => 123]]]);
            self::fail('Expected ambiguous mutation failure.');
        } catch (EndpointUnavailableException $exception) {
            self::assertStringContainsString('setRecordList', $exception->getMessage());
            self::assertStringContainsString(Endpoint::DEFAULT_BASE_URI, $exception->getMessage());
            self::assertStringContainsString('not retried', $exception->getMessage());
        } finally {
            self::assertSame(['getAccessStatus', 'setRecordList'], $primary->methods());
            self::assertSame([], $fallback->methods());
        }
    }

    public function testFirstMutationSelectsEndpointWithReadOnlyProbe(): void
    {
        $primary = new ScriptedTransport([
            'getAccessStatus' => new EndpointUnavailableException('primary unavailable'),
        ]);
        $fallback = new ScriptedTransport([
            'getAccessStatus' => 1,
            'deleteObject' => 1,
        ]);
        $transport = $this->failover($primary, $fallback);

        self::assertSame(1, $transport->call('deleteObject', [42, 1]));
        self::assertSame(['getAccessStatus'], $primary->methods());
        self::assertSame(['getAccessStatus', 'deleteObject'], $fallback->methods());
    }

    public function testUnknownRawMethodIsTreatedAsMutation(): void
    {
        $primary = new ScriptedTransport([
            'getAccessStatus' => 1,
            'futureApiMethod' => 'ok',
        ]);
        $fallback = new ScriptedTransport();

        self::assertSame('ok', $this->failover($primary, $fallback)->call('futureApiMethod'));
        self::assertSame(['getAccessStatus', 'futureApiMethod'], $primary->methods());
    }

    public function testDefaultEndpointUsesRuWithoutHiddenFallback(): void
    {
        $endpoint = new Endpoint();
        self::assertSame(Endpoint::RU_BASE_URI, Endpoint::DEFAULT_BASE_URI);
        self::assertSame(Endpoint::RU_BASE_URI, $endpoint->baseUri());
        self::assertSame([$endpoint], $endpoint->failoverSequence());

        $client = DrebedengiClient::fromCredentials($this->credentials());
        $transport = $this->clientTransport($client);
        self::assertInstanceOf(SoapTransport::class, $transport);
        self::assertSame(Endpoint::RU_BASE_URI, $this->soapEndpoint($transport)->baseUri());
    }

    public function testCustomEndpointDoesNotReceiveHiddenFallback(): void
    {
        $endpoint = new Endpoint('https://money.example.test');

        self::assertSame([$endpoint], $endpoint->failoverSequence());
        $client = DrebedengiClient::fromCredentials($this->credentials(), $endpoint);
        self::assertInstanceOf(SoapTransport::class, $this->clientTransport($client));
    }

    public function testEndpointCanBePassedAsBaseUriStringWithPath(): void
    {
        $client = DrebedengiClient::fromCredentials(
            $this->credentials(),
            'https://money.example.test/drebedengi/',
        );
        $transport = $this->clientTransport($client);
        self::assertInstanceOf(SoapTransport::class, $transport);

        $endpoint = $this->soapEndpoint($transport);
        self::assertSame('https://money.example.test/drebedengi', $endpoint->baseUri());
        self::assertSame('https://money.example.test/drebedengi/soap/dd.wsdl', $endpoint->wsdlUri());
        self::assertSame('https://money.example.test/drebedengi/soap/', $endpoint->soapLocation());
    }

    public function testExplicitEndpointListEnablesFailoverInGivenOrder(): void
    {
        $client = DrebedengiClient::fromCredentials(
            $this->credentials(),
            [Endpoint::ME_BASE_URI, new Endpoint(Endpoint::RU_BASE_URI)],
        );
        $transport = $this->clientTransport($client);
        self::assertInstanceOf(FailoverTransport::class, $transport);

        self::assertSame(
            [Endpoint::ME_BASE_URI, Endpoint::RU_BASE_URI],
            array_keys($this->failoverTransports($transport)),
        );
    }

    public function testEmptyEndpointListIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one Drebedengi endpoint');

        DrebedengiClient::fromCredentials($this->credentials(), []);
    }

    public function testInvalidEndpointListItemIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Endpoint objects or base URI strings');

        DrebedengiClient::fromCredentials($this->credentials(), [Endpoint::RU_BASE_URI, 42]);
    }

    public function testSoapOptionsArePreservedForEveryOfficialEndpoint(): void
    {
        $streamContext = stream_context_create([
            'http' => [
                'timeout' => 7.5,
                'user_agent' => 'Drebedengi SDK test',
            ],
        ]);
        $soapOptions = [
            'connection_timeout' => 3,
            'cache_wsdl' => WSDL_CACHE_MEMORY,
            'stream_context' => $streamContext,
            'user_agent' => 'Drebedengi SDK test',
        ];
        $client = DrebedengiClient::fromCredentials(
            $this->credentials(),
            [Endpoint::RU_BASE_URI, Endpoint::ME_BASE_URI],
            $soapOptions,
        );
        $failover = $this->clientTransport($client);
        self::assertInstanceOf(FailoverTransport::class, $failover);

        $optionsProperty = new \ReflectionProperty(SoapTransport::class, 'soapOptions');
        foreach ($this->failoverTransports($failover) as $transport) {
            self::assertSame($soapOptions, $optionsProperty->getValue($transport));
        }
    }

    public function testAllUnavailableExceptionContainsBothAttempts(): void
    {
        $transport = $this->failover(
            new ScriptedTransport(['getBalance' => new EndpointUnavailableException('me down')]),
            new ScriptedTransport(['getBalance' => new EndpointUnavailableException('ru down')]),
        );

        try {
            $transport->call('getBalance');
            self::fail('Expected endpoint failure.');
        } catch (EndpointUnavailableException $exception) {
            self::assertStringContainsString(Endpoint::RU_BASE_URI, $exception->getMessage());
            self::assertStringContainsString(Endpoint::ME_BASE_URI, $exception->getMessage());
        }
    }

    private function failover(
        TransportInterface $primary,
        TransportInterface $fallback,
    ): FailoverTransport {
        return new FailoverTransport([
            Endpoint::RU_BASE_URI => $primary,
            Endpoint::ME_BASE_URI => $fallback,
        ]);
    }

    private function credentials(): Credentials
    {
        return new Credentials('api-secret', 'login-secret', 'password-secret');
    }

    private function clientTransport(DrebedengiClient $client): TransportInterface
    {
        $property = new \ReflectionProperty($client, 'transport');

        return $property->getValue($client);
    }

    private function soapEndpoint(SoapTransport $transport): Endpoint
    {
        $property = new \ReflectionProperty($transport, 'endpoint');

        return $property->getValue($transport);
    }

    /** @return array<string, TransportInterface> */
    private function failoverTransports(FailoverTransport $transport): array
    {
        $property = new \ReflectionProperty($transport, 'transports');

        return $property->getValue($transport);
    }
}

final class ScriptedTransport implements TransportInterface
{
    /** @var list<array{method: string, arguments: list<mixed>}> */
    private array $calls = [];

    /** @param array<string, mixed> $responses */
    public function __construct(private readonly array $responses = [])
    {
    }

    public function call(string $method, array $arguments = []): mixed
    {
        $this->calls[] = ['method' => $method, 'arguments' => $arguments];
        $response = $this->responses[$method] ?? null;

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }

    /** @return list<string> */
    public function methods(): array
    {
        return array_column($this->calls, 'method');
    }
}
