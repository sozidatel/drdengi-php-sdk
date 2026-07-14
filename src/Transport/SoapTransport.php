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
    private const ENDPOINT_TIMEOUT_MESSAGE = 'Сервер слишком долго не отвечает';

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
                    'Drebedengi SOAP call "%s" at %s failed [%s]: %s',
                    $method,
                    $this->endpoint->baseUri(),
                    (string)($exception->faultcode ?? 'unknown'),
                    $exception->getMessage(),
                )),
                0,
            );
        }
    }

    public function soapClient(): SoapClient
    {
        if ($this->client instanceof SoapClient) {
            return $this->client;
        }

        $options = $this->clientOptions();

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
            );
        }

        return $this->client;
    }

    /**
     * @return array<string, mixed>
     */
    private function clientOptions(): array
    {
        return array_replace(
            [
                'exceptions' => true,
                'trace' => false,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'location' => $this->endpoint->soapLocation(),
            ],
            $this->soapOptions,
            [
                // These options are part of the transport contract. Disabling
                // exceptions bypasses fault classification, while overriding
                // location can silently send credentials to another endpoint.
                'exceptions' => true,
                'location' => $this->endpoint->soapLocation(),
            ],
        );
    }

    private function isInfrastructureFault(SoapFault $exception): bool
    {
        $faultCode = strtoupper((string)($exception->faultcode ?? ''));

        if ($faultCode === 'HTTP'
            || $faultCode === 'WSDL'
            || str_ends_with($faultCode, ':HTTP')
            || str_ends_with($faultCode, ':WSDL')) {
            return true;
        }

        // Drebedengi can report its own upstream timeout as a generic Server
        // SOAP fault, so the fault code alone cannot distinguish it from a
        // business error. Keep this exception deliberately exact and narrow.
        $message = rtrim(trim($exception->getMessage()), '.');

        return ($faultCode === 'SERVER' || str_ends_with($faultCode, ':SERVER'))
            && $message === self::ENDPOINT_TIMEOUT_MESSAGE;
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
