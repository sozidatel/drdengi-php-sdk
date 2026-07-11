<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Transport;

use SoapClient;
use SoapFault;
use Soz\Drebedengi\Credentials;
use Soz\Drebedengi\Endpoint;
use Soz\Drebedengi\Exception\EndpointUnavailableException;
use Soz\Drebedengi\Exception\TransportException;

final class SoapTransport implements TransportInterface
{
    private ?SoapClient $client = null;

    /**
     * @param array<string, mixed> $soapOptions
     */
    public function __construct(
        private readonly Credentials $credentials,
        private readonly Endpoint $endpoint = new Endpoint(),
        private readonly array $soapOptions = [],
    ) {
    }

    public function call(string $method, array $arguments = []): mixed
    {
        try {
            return $this->soapClient()->{$method}(...array_merge([
                $this->credentials->apiId,
                $this->credentials->login,
                $this->credentials->password,
            ], $arguments));
        } catch (SoapFault $exception) {
            $exceptionClass = $this->isInfrastructureFault($exception)
                ? EndpointUnavailableException::class
                : TransportException::class;

            throw new $exceptionClass(
                $this->sanitize(sprintf(
                    'Drebedengi SOAP call "%s" at %s failed: %s',
                    $method,
                    $this->endpoint->baseUri(),
                    $exception->getMessage(),
                )),
                0,
                $exception,
            );
        }
    }

    public function soapClient(): SoapClient
    {
        if ($this->client instanceof SoapClient) {
            return $this->client;
        }

        $options = $this->soapOptions + [
            'exceptions' => true,
            'trace' => false,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'location' => $this->endpoint->soapLocation(),
        ];

        try {
            $this->client = new SoapClient($this->endpoint->wsdlUri(), $options);
        } catch (SoapFault $exception) {
            throw new EndpointUnavailableException(
                $this->sanitize(sprintf(
                    'Cannot initialize Drebedengi SOAP client at %s: %s',
                    $this->endpoint->baseUri(),
                    $exception->getMessage(),
                )),
                0,
                $exception,
            );
        }

        return $this->client;
    }

    private function isInfrastructureFault(SoapFault $exception): bool
    {
        $faultCode = strtoupper((string)($exception->faultcode ?? ''));

        return $faultCode === 'HTTP'
            || $faultCode === 'WSDL'
            || str_ends_with($faultCode, ':HTTP')
            || str_ends_with($faultCode, ':WSDL');
    }

    private function sanitize(string $message): string
    {
        return str_replace(
            [$this->credentials->apiId, $this->credentials->login, $this->credentials->password],
            '[redacted]',
            $message,
        );
    }
}
