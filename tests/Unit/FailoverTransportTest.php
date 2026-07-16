<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Credentials;
use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\DrebedengiClient;
use Soz\Drebedengi\Endpoint;
use Soz\Drebedengi\Exception\AmbiguousMutationException;
use Soz\Drebedengi\Exception\EndpointUnavailableException;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\TransportException;
use Soz\Drebedengi\Transport\FailoverTransport;
use Soz\Drebedengi\Transport\SoapTransport;
use Soz\Drebedengi\Transport\TransportInterface;
use Soz\Drebedengi\WsdlCache;

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

    public function testUnavailableActiveFallbackWrapsAroundToPrimary(): void
    {
        $primary = new ScriptedTransport([
            'getBalance' => new EndpointUnavailableException('primary unavailable'),
            'getCurrencyList' => ['primary recovered'],
            'getTagList' => ['primary remains active'],
        ]);
        $fallback = new ScriptedTransport([
            'getBalance' => ['fallback selected'],
            'getCurrencyList' => new EndpointUnavailableException('fallback unavailable'),
        ]);
        $transport = $this->failover($primary, $fallback);

        self::assertSame(['fallback selected'], $transport->call('getBalance'));
        self::assertSame(['primary recovered'], $transport->call('getCurrencyList'));
        self::assertSame(['primary remains active'], $transport->call('getTagList'));
        self::assertSame(['getBalance', 'getCurrencyList', 'getTagList'], $primary->methods());
        self::assertSame(['getBalance', 'getCurrencyList'], $fallback->methods());
    }

    public function testUnavailableActiveFallbackDoesNotRetryMutationOnPrimary(): void
    {
        $primary = new ScriptedTransport([
            'getBalance' => new EndpointUnavailableException('primary unavailable'),
            'getAccessStatus' => '1',
            'setCategoryList' => [['server_id' => '10']],
        ]);
        $fallback = new ScriptedTransport([
            'getBalance' => ['fallback selected'],
            'setRecordList' => new EndpointUnavailableException('response was lost'),
        ]);
        $transport = $this->failover($primary, $fallback);

        self::assertSame(['fallback selected'], $transport->call('getBalance'));

        try {
            $transport->call('setRecordList', [[['client_id' => 123]]]);
            self::fail('Expected ambiguous mutation failure.');
        } catch (AmbiguousMutationException $exception) {
            self::assertStringContainsString(Endpoint::ME_BASE_URI, $exception->getMessage());
            self::assertStringContainsString('not retried', $exception->getMessage());
            self::assertSame('setRecordList', $exception->method);
            self::assertSame(Endpoint::ME_BASE_URI, $exception->endpoint);
            self::assertFalse($exception->retrySafe);
        }

        self::assertSame(
            [['server_id' => '10']],
            $transport->call('setCategoryList', [[['server_id' => '10', 'name' => 'Food']]]),
        );
        self::assertSame(['getBalance', 'getAccessStatus', 'setCategoryList'], $primary->methods());
        self::assertSame(['getBalance', 'setRecordList'], $fallback->methods());
    }

    public function testAllFailedReadEndpointsClearStickySelectionBeforeMutation(): void
    {
        $primary = new ScriptedTransport([
            'getBalance' => new EndpointUnavailableException('primary unavailable'),
            'getCurrencyList' => new EndpointUnavailableException('primary still unavailable'),
            'getAccessStatus' => '1',
            'setCategoryList' => [['server_id' => '10']],
        ]);
        $fallback = new ScriptedTransport([
            'getBalance' => ['fallback selected'],
            'getCurrencyList' => new EndpointUnavailableException('fallback unavailable'),
        ]);
        $transport = $this->failover($primary, $fallback);

        self::assertSame(['fallback selected'], $transport->call('getBalance'));

        try {
            $transport->call('getCurrencyList');
            self::fail('Expected all endpoints to be unavailable.');
        } catch (EndpointUnavailableException $exception) {
            self::assertStringContainsString('attempted', $exception->getMessage());
        }

        self::assertSame(
            [['server_id' => '10']],
            $transport->call('setCategoryList', [[['server_id' => '10', 'name' => 'Food']]]),
        );
        self::assertSame(
            ['getBalance', 'getCurrencyList', 'getAccessStatus', 'setCategoryList'],
            $primary->methods(),
        );
        self::assertSame(['getBalance', 'getCurrencyList'], $fallback->methods());
    }

    public function testInitialSyncRecordListIsNeverRetried(): void
    {
        $primary = new ScriptedTransport([
            'getAccessStatus' => '1',
            'getRecordList' => new EndpointUnavailableException('response was lost'),
        ]);
        $fallback = new ScriptedTransport([
            'getRecordList' => [['id' => 'duplicate']],
        ]);
        $transport = $this->failover($primary, $fallback);

        try {
            $transport->call('getRecordList', [['is_report' => false], []]);
            self::fail('Expected ambiguous initial-sync failure.');
        } catch (AmbiguousMutationException $exception) {
            self::assertStringContainsString('not retried', $exception->getMessage());
            self::assertSame('getRecordList', $exception->method);
            self::assertFalse($exception->retrySafe);
        } finally {
            self::assertSame(['getAccessStatus', 'getRecordList'], $primary->methods());
            self::assertSame([], $fallback->methods());
        }
    }

    public function testRecordListWithUnknownArgumentsIsTreatedAsMutation(): void
    {
        $primary = new ScriptedTransport([
            'getAccessStatus' => '1',
            'getRecordList' => new EndpointUnavailableException('response was lost'),
        ]);
        $fallback = new ScriptedTransport([
            'getRecordList' => [['id' => 'duplicate']],
        ]);
        $transport = $this->failover($primary, $fallback);

        try {
            $transport->call('getRecordList');
            self::fail('Expected conservative mutation failure.');
        } catch (AmbiguousMutationException $exception) {
            self::assertStringContainsString('not retried', $exception->getMessage());
            self::assertSame('getRecordList', $exception->method);
            self::assertFalse($exception->retrySafe);
        } finally {
            self::assertSame(['getAccessStatus', 'getRecordList'], $primary->methods());
            self::assertSame([], $fallback->methods());
        }
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
            $this->failover($primary, $fallback)->call('getRecordList', [['is_report' => true], []]),
        );
        self::assertSame(['getRecordList'], $primary->methods());
        self::assertSame(['getRecordList'], $fallback->methods());
    }

    public function testKnownRawAccumReadCanBeRetriedOnFallback(): void
    {
        $primary = new ScriptedTransport([
            'getAccumList' => new EndpointUnavailableException('response unavailable'),
        ]);
        $fallback = new ScriptedTransport(['getAccumList' => [['id' => '42']]]);

        self::assertSame(
            [['id' => '42']],
            $this->failover($primary, $fallback)->call('getAccumList', [[]]),
        );
        self::assertSame(['getAccumList'], $primary->methods());
        self::assertSame(['getAccumList'], $fallback->methods());
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
        } catch (AmbiguousMutationException $exception) {
            self::assertStringContainsString('setRecordList', $exception->getMessage());
            self::assertStringContainsString(Endpoint::DEFAULT_BASE_URI, $exception->getMessage());
            self::assertStringContainsString('not retried', $exception->getMessage());
            self::assertSame('setRecordList', $exception->method);
            self::assertSame(Endpoint::DEFAULT_BASE_URI, $exception->endpoint);
            self::assertFalse($exception->retrySafe);
        } finally {
            self::assertSame(['getAccessStatus', 'setRecordList'], $primary->methods());
            self::assertSame([], $fallback->methods());
        }
    }

    public function testMutationFailureKnownToBePreSendIsNotReportedAsAmbiguous(): void
    {
        $primary = new ScriptedTransport([
            'getAccessStatus' => 1,
            'setRecordList' => new EndpointUnavailableException(
                message: 'connection failed before send',
                method: 'setRecordList',
                endpoint: Endpoint::RU_BASE_URI,
                retrySafe: true,
                faultCode: 'WSDL',
            ),
        ]);
        $fallback = new ScriptedTransport();
        $transport = $this->failover($primary, $fallback);

        try {
            $transport->call('setRecordList', [[['client_id' => 123]]]);
            self::fail('Expected retry-safe endpoint failure.');
        } catch (EndpointUnavailableException $exception) {
            self::assertNotInstanceOf(AmbiguousMutationException::class, $exception);
            self::assertSame('setRecordList', $exception->method);
            self::assertSame(Endpoint::RU_BASE_URI, $exception->endpoint);
            self::assertTrue($exception->retrySafe);
            self::assertSame('WSDL', $exception->faultCode);
            self::assertStringContainsString('may be retried', $exception->getMessage());
        }

        self::assertSame(['getAccessStatus', 'setRecordList'], $primary->methods());
        self::assertSame([], $fallback->methods());
    }

    public function testMutationEndpointProbeFailureIsRetrySafeAndUsesRequestedMethod(): void
    {
        $primary = new ScriptedTransport([
            'getAccessStatus' => new EndpointUnavailableException('primary unavailable'),
        ]);
        $fallback = new ScriptedTransport([
            'getAccessStatus' => new EndpointUnavailableException('fallback unavailable'),
        ]);
        $transport = $this->failover($primary, $fallback);

        try {
            $transport->call('setRecordList', [[['client_id' => 123]]]);
            self::fail('Expected endpoint selection failure.');
        } catch (EndpointUnavailableException $exception) {
            self::assertNotInstanceOf(AmbiguousMutationException::class, $exception);
            self::assertSame('setRecordList', $exception->method);
            self::assertSame(Endpoint::ME_BASE_URI, $exception->endpoint);
            self::assertTrue($exception->retrySafe);
        }

        self::assertSame(['getAccessStatus'], $primary->methods());
        self::assertSame(['getAccessStatus'], $fallback->methods());
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

    public function testExplicitSoapOptionsOverrideAndExtendLegacyThirdArgument(): void
    {
        $client = DrebedengiClient::fromCredentials(
            $this->credentials(),
            Endpoint::RU_BASE_URI,
            [
                'connection_timeout' => 3,
                'cache_wsdl' => WSDL_CACHE_DISK,
            ],
            [
                'cache_wsdl' => WSDL_CACHE_MEMORY,
                'user_agent' => 'Drebedengi SDK test',
            ],
        );
        $transport = $this->clientTransport($client);
        self::assertInstanceOf(SoapTransport::class, $transport);

        $optionsProperty = new \ReflectionProperty(SoapTransport::class, 'soapOptions');
        self::assertSame(
            [
                'connection_timeout' => 3,
                'cache_wsdl' => WSDL_CACHE_MEMORY,
                'user_agent' => 'Drebedengi SDK test',
            ],
            $optionsProperty->getValue($transport),
        );
    }

    public function testTypedTransportOptionsArePropagatedToEveryFailoverEndpoint(): void
    {
        $options = new ClientOptions(
            connectTimeout: 8,
            readTimeout: 24.5,
            wsdlCache: WsdlCache::Both,
        );
        $client = DrebedengiClient::fromCredentials(
            $this->credentials(),
            [Endpoint::RU_BASE_URI, Endpoint::ME_BASE_URI],
            $options,
        );
        $failover = $this->clientTransport($client);
        self::assertInstanceOf(FailoverTransport::class, $failover);

        $optionsProperty = new \ReflectionProperty(SoapTransport::class, 'options');
        foreach ($this->failoverTransports($failover) as $transport) {
            self::assertSame($options, $optionsProperty->getValue($transport));
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
            self::assertSame('getBalance', $exception->method);
            self::assertSame(Endpoint::ME_BASE_URI, $exception->endpoint);
            self::assertTrue($exception->retrySafe);
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
        $transport = $property->getValue($client);
        if (!$transport instanceof TransportInterface) {
            throw new \LogicException('Client transport reflection returned an unexpected value.');
        }

        return $transport;
    }

    private function soapEndpoint(SoapTransport $transport): Endpoint
    {
        $property = new \ReflectionProperty($transport, 'endpoint');
        $endpoint = $property->getValue($transport);
        if (!$endpoint instanceof Endpoint) {
            throw new \LogicException('SOAP endpoint reflection returned an unexpected value.');
        }

        return $endpoint;
    }

    /** @return array<string, TransportInterface> */
    private function failoverTransports(FailoverTransport $transport): array
    {
        $property = new \ReflectionProperty($transport, 'transports');
        $transports = $property->getValue($transport);
        if (!is_array($transports)) {
            throw new \LogicException('Failover transports reflection returned an unexpected value.');
        }

        $result = [];
        foreach ($transports as $endpoint => $candidate) {
            if (!is_string($endpoint) || !$candidate instanceof TransportInterface) {
                throw new \LogicException('Failover transports reflection returned an invalid map.');
            }
            $result[$endpoint] = $candidate;
        }

        return $result;
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
