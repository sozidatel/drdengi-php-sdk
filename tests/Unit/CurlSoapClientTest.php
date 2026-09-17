<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Credentials;
use Soz\Drebedengi\Endpoint;
use Soz\Drebedengi\Exception\EndpointUnavailableException;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\SoapFaultException;
use Soz\Drebedengi\Tests\Support\LocalSoapServer;
use Soz\Drebedengi\Transport\SoapTransport;
use Soz\Drebedengi\WsdlCache;

final class CurlSoapClientTest extends TestCase
{
    private LocalSoapServer $server;

    protected function setUp(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('Local HTTP transport tests require proc_open.');
        }
        $this->server = new LocalSoapServer('curl-soap-server.php');
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            $this->server->stop();
        }
    }

    public function testCustomHeadersUserAgentAndBasicAuthenticationReachTheServer(): void
    {
        $transport = $this->transport('headers', [
            'user_agent' => 'SDK-compatibility-test/1.0',
            'login' => 'http-user',
            'password' => 'http-password',
            'stream_context' => stream_context_create([
                'http' => ['header' => "X-Request-Label: fixture\r\nAuthorization: ignored\r\nUser-Agent: ignored"],
            ]),
        ]);

        self::assertSame('1', $transport->call('getAccessStatus'));
        $requests = $this->soapRequests();
        self::assertCount(1, $requests);
        $headers = $requests[0]['headers'];
        self::assertSame('fixture', $headers['x-request-label']);
        self::assertSame('SDK-compatibility-test/1.0', $headers['user-agent']);
        self::assertSame('Basic ' . base64_encode('http-user:http-password'), $headers['authorization']);
        self::assertSame('"urn:getAccessStatus"', $headers['soapaction']);
        self::assertStringContainsString('text/xml', $headers['content-type']);
    }

    public function testHttpProxyAndItsCredentialsArePreserved(): void
    {
        $port = parse_url($this->server->baseUri, PHP_URL_PORT);
        self::assertIsInt($port);
        $transport = $this->transport('proxy', [
            'proxy_host' => '127.0.0.1',
            'proxy_port' => $port,
            'proxy_login' => 'proxy-user',
            'proxy_password' => 'proxy-password',
        ], new Endpoint('http://soap-fixture.invalid/proxy'));

        self::assertSame('1', $transport->call('getAccessStatus'));
        $requests = $this->soapRequests();
        self::assertCount(1, $requests);
        self::assertSame('http://soap-fixture.invalid/proxy/soap/', $requests[0]['target']);
        self::assertSame(
            'Basic ' . base64_encode('proxy-user:proxy-password'),
            $requests[0]['headers']['proxy-authorization'],
        );
    }

    public function testSoap12PreservesEnvelopeVersionAndAction(): void
    {
        $transport = $this->transport('soap12', ['soap_version' => SOAP_1_2]);

        self::assertSame('1', $transport->call('getAccessStatus'));
        $requests = $this->soapRequests();
        self::assertCount(1, $requests);
        self::assertStringContainsString('application/soap+xml', $requests[0]['headers']['content-type']);
        self::assertStringContainsString('action="urn:getAccessStatus"', $requests[0]['headers']['content-type']);
        self::assertStringContainsString('http://www.w3.org/2003/05/soap-envelope', $requests[0]['body']);
    }

    public function testHttp500SoapFaultRetainsItsApplicationMeaning(): void
    {
        $transport = $this->transport('fault');

        try {
            $transport->call('getAccessStatus');
            self::fail('Expected an application SOAP fault.');
        } catch (SoapFaultException $exception) {
            self::assertStringContainsString('Business rule rejected', $exception->getMessage());
            self::assertNotSame('HTTP', $exception->faultCode);
        }
        self::assertCount(1, $this->soapRequests());
    }

    public function testCookiesAndNativeTraceGettersSurviveRepeatedCalls(): void
    {
        $transport = $this->transport('cookies', ['trace' => true]);
        $client = $transport->soapClient();
        $client->__setCookie('manual', 'fixture-manual');

        self::assertSame('1', $transport->call('getAccessStatus'));
        self::assertSame('1', $transport->call('getAccessStatus'));
        $requests = $this->soapRequests();
        self::assertCount(2, $requests);
        self::assertStringContainsString('manual=fixture-manual', $requests[0]['headers']['cookie']);
        self::assertStringContainsString('session=fixture-session', $requests[1]['headers']['cookie']);
        self::assertArrayHasKey('session', $client->__getCookies());
        self::assertStringContainsString('getAccessStatus', (string)$client->__getLastRequest());
        self::assertStringContainsString('getAccessStatusResponse', (string)$client->__getLastResponse());
        self::assertStringContainsString('POST /cookies/soap/', (string)$client->__getLastRequestHeaders());
        self::assertStringContainsString('Set-Cookie: session=fixture-session', (string)$client->__getLastResponseHeaders());

        $client->__setCookie('manual');
        self::assertSame('1', $transport->call('getAccessStatus'));
        $requests = $this->soapRequests();
        self::assertStringNotContainsString('manual=', $requests[2]['headers']['cookie']);
    }

    /** @return iterable<string, array{string, int, ?string}> */
    public static function encodedResponses(): iterable
    {
        yield 'chunked response' => ['chunked', 0, null];
        yield 'gzip request and response' => ['gzip', SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP | 5, 'gzip'];
        yield 'deflate request and response' => ['deflate', SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_DEFLATE | 5, 'deflate'];
    }

    #[DataProvider('encodedResponses')]
    public function testHttpTransferAndContentEncodingsAreDecoded(
        string $scenario,
        int $compression,
        ?string $requestEncoding,
    ): void {
        $transport = $this->transport($scenario, ['compression' => $compression]);

        self::assertSame('1', $transport->call('getAccessStatus'));
        $requests = $this->soapRequests();
        self::assertCount(1, $requests);
        self::assertSame($requestEncoding, $requests[0]['headers']['content-encoding'] ?? null);
        self::assertStringContainsString('getAccessStatus', $requests[0]['body']);
    }

    public function testRedirectDoesNotForwardTheSoapPayload(): void
    {
        $transport = $this->transport('redirect');

        try {
            $transport->call('getAccessStatus');
            self::fail('Expected a rejected HTTP redirect.');
        } catch (EndpointUnavailableException $exception) {
            self::assertSame('HTTP', $exception->faultCode);
        }
        $requests = $this->soapRequests();
        self::assertCount(1, $requests);
        self::assertSame('/redirect/soap/', $requests[0]['path']);
    }

    public function testUnsupportedSecurityContextIsRejectedBeforeNetworkAccess(): void
    {
        $transport = $this->transport('unsupported', [
            'stream_context' => stream_context_create([
                'ssl' => ['peer_fingerprint' => ['sha256' => str_repeat('0', 64)]],
            ]),
        ]);

        try {
            $transport->call('getAccessStatus');
            self::fail('Expected unsupported security configuration to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('ssl.peer_fingerprint', $exception->getMessage());
        }
        self::assertSame([], $this->requests());
    }

    /** @param array<string, mixed> $soapOptions */
    private function transport(string $scenario, array $soapOptions = [], ?Endpoint $endpoint = null): SoapTransport
    {
        return new SoapTransport(
            new Credentials('dummy-api', 'dummy-login', 'dummy-password'),
            $endpoint ?? new Endpoint($this->server->baseUri . '/' . $scenario),
            $soapOptions,
            new ClientOptions(readTimeout: 2.0, wsdlCache: WsdlCache::None),
        );
    }

    /** @return list<array{target: string, path: string, headers: array<string, string>, body: string}> */
    private function requests(): array
    {
        $response = file_get_contents($this->server->baseUri . '/requests');
        self::assertIsString($response);
        /** @var list<array{target: string, path: string, headers: array<string, string>, body: string}> $requests */
        $requests = json_decode($response, true, flags: JSON_THROW_ON_ERROR);

        return $requests;
    }

    /** @return list<array{target: string, path: string, headers: array<string, string>, body: string}> */
    private function soapRequests(): array
    {
        return array_values(array_filter(
            $this->requests(),
            static fn (array $request): bool => !str_ends_with($request['path'], '.wsdl'),
        ));
    }
}
