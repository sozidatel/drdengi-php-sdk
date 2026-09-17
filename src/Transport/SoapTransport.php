<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Transport;

use SoapClient;
use SoapFault;
use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Credentials;
use Soz\Drebedengi\Endpoint;
use Soz\Drebedengi\Exception\AmbiguousMutationException;
use Soz\Drebedengi\Exception\EndpointUnavailableException;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\SoapFaultException;

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
        private readonly ClientOptions $options = new ClientOptions(),
    ) {
    }

    public function call(string $method, array $arguments = []): mixed
    {
        try {
            return $this->soapClient($method)->{$method}(...array_merge([
                $this->credentials->apiId,
                $this->credentials->login,
                $this->credentials->password,
            ], $arguments));
        } catch (SoapFault $exception) {
            $faultCode = $this->faultCode($exception);
            $message = $this->sanitize(sprintf(
                'Drebedengi SOAP call "%s" at %s failed [%s]: %s',
                $method,
                $this->endpoint->baseUri(),
                $faultCode ?? 'unknown',
                $exception->getMessage(),
            ));
            $retrySafe = CallPolicy::isReadOnly($method, $arguments);

            if (!$this->isInfrastructureFault($exception)) {
                throw new SoapFaultException(
                    message: $message,
                    method: $method,
                    endpoint: $this->endpoint->baseUri(),
                    retrySafe: $retrySafe,
                    faultCode: $faultCode,
                );
            }

            if ($retrySafe) {
                throw new EndpointUnavailableException(
                    message: $message,
                    method: $method,
                    endpoint: $this->endpoint->baseUri(),
                    retrySafe: true,
                    faultCode: $faultCode,
                );
            }

            throw new AmbiguousMutationException(
                message: $message,
                method: $method,
                endpoint: $this->endpoint->baseUri(),
                faultCode: $faultCode,
            );
        }
    }

    public function soapClient(?string $method = null): SoapClient
    {
        if ($this->client instanceof SoapClient) {
            return $this->client;
        }

        $options = $this->clientOptions();
        $readTimeout = $this->effectiveReadTimeout($options);

        try {
            $this->client = $readTimeout === null
                ? new SoapClient($this->endpoint->wsdlUri(), $options)
                : new CurlSoapClient($this->endpoint->wsdlUri(), $options, $readTimeout);
        } catch (SoapFault $exception) {
            throw new EndpointUnavailableException(
                message: $this->sanitize(sprintf(
                    'Cannot initialize Drebedengi SOAP client at %s: %s',
                    $this->endpoint->baseUri(),
                    $exception->getMessage(),
                )),
                method: $method,
                endpoint: $this->endpoint->baseUri(),
                retrySafe: true,
                faultCode: $this->faultCode($exception),
            );
        }

        return $this->client;
    }

    /**
     * @return array<string, mixed>
     */
    private function clientOptions(): array
    {
        $typedOptions = [
            'cache_wsdl' => $this->options->wsdlCache->value,
        ];
        if ($this->options->connectTimeout !== null) {
            $typedOptions['connection_timeout'] = $this->options->connectTimeout;
        }
        if ($this->options->readTimeout !== null) {
            $typedOptions['stream_context'] = stream_context_create([
                'http' => ['timeout' => $this->options->readTimeout],
            ]);
        }

        $options = array_replace(
            [
                'exceptions' => true,
                'trace' => false,
                'location' => $this->endpoint->soapLocation(),
            ],
            $typedOptions,
            // Raw SOAP options remain the escape hatch and intentionally take
            // precedence over typed options for backwards compatibility.
            $this->soapOptions,
            [
                // These options are part of the transport contract. Disabling
                // exceptions bypasses fault classification, while overriding
                // location can silently send credentials to another endpoint.
                'exceptions' => true,
                'location' => $this->endpoint->soapLocation(),
            ],
        );

        // A TLS/header-only raw context must not discard the typed timeout.
        // Clone the context so WSDL loading sees the limit too, without changing
        // a resource that the caller may share with other clients.
        $context = $options['stream_context'] ?? null;
        if ($this->options->readTimeout !== null && $context === null) {
            $options['stream_context'] = stream_context_create([
                'http' => ['timeout' => $this->options->readTimeout],
            ]);
        } elseif ($this->options->readTimeout !== null
            && is_resource($context)
            && get_resource_type($context) === 'stream-context') {
            $contextOptions = stream_context_get_options($context);
            $httpOptions = $contextOptions['http'] ?? [];
            if (!is_array($httpOptions)) {
                throw new InvalidArgumentException('SOAP HTTP context options must be an array.');
            }
            if (!array_key_exists('timeout', $httpOptions)) {
                $httpOptions['timeout'] = $this->options->readTimeout;
                $contextOptions['http'] = $httpOptions;
                $params = stream_context_get_params($context);
                unset($params['options']);
                $options['stream_context'] = stream_context_create($contextOptions, $params);
            }
        }

        return $options;
    }

    /** @param array<string, mixed> $options */
    private function effectiveReadTimeout(array $options): ?float
    {
        $context = $options['stream_context'] ?? null;
        if (is_resource($context) && get_resource_type($context) === 'stream-context') {
            $contextOptions = stream_context_get_options($context);
            $httpOptions = $contextOptions['http'] ?? [];
            if (!is_array($httpOptions)) {
                throw new InvalidArgumentException('SOAP HTTP context options must be an array.');
            }
            if (array_key_exists('timeout', $httpOptions)) {
                $timeout = $httpOptions['timeout'];
                if (!is_int($timeout) && !is_float($timeout) && !(is_string($timeout) && is_numeric($timeout))) {
                    throw new InvalidArgumentException('HTTP context timeout must be a finite positive number of seconds.');
                }
                $timeout = (float)$timeout;
                if (!is_finite($timeout) || $timeout <= 0) {
                    throw new InvalidArgumentException('HTTP context timeout must be a finite positive number of seconds.');
                }

                return $timeout;
            }
        }

        return $this->options->readTimeout;
    }

    private function faultCode(SoapFault $exception): ?string
    {
        $faultCode = trim((string)($exception->faultcode ?? ''));

        return $faultCode !== '' ? $this->sanitize($faultCode) : null;
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

        // These are response-parser errors emitted by ext-soap itself. A reply
        // that cannot be read as a SOAP envelope does not tell us whether a
        // mutation was applied. Do not classify every Client fault this way:
        // valid application faults must still stop endpoint failover.
        if (in_array($faultCode, ['CLIENT', 'SENDER'], true)
            && in_array($exception->getMessage(), [
                'looks like we got no XML document',
                'looks like we got XML without "Envelope" element',
                'Body must be present in a SOAP envelope',
            ], true)) {
            return true;
        }
        if ($faultCode === 'VERSIONMISMATCH' && $exception->getMessage() === 'Wrong Version') {
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
