<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Transport;

use SoapClient;
use SoapFault;
use Soz\Drebedengi\Credentials;
use Soz\Drebedengi\Endpoint;
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
            throw new TransportException(
                sprintf('Drebedengi SOAP call "%s" failed: %s', $method, $exception->getMessage()),
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
            throw new TransportException('Cannot initialize Drebedengi SOAP client: ' . $exception->getMessage(), 0, $exception);
        }

        return $this->client;
    }
}
