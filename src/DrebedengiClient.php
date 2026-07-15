<?php

declare(strict_types=1);

namespace Soz\Drebedengi;

use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Service\BalanceService;
use Soz\Drebedengi\Service\AccountService;
use Soz\Drebedengi\Service\CategoryService;
use Soz\Drebedengi\Service\CurrencyService;
use Soz\Drebedengi\Service\PlaceService;
use Soz\Drebedengi\Service\RawService;
use Soz\Drebedengi\Service\RecordService;
use Soz\Drebedengi\Service\ReportService;
use Soz\Drebedengi\Service\SourceService;
use Soz\Drebedengi\Service\SyncService;
use Soz\Drebedengi\Service\TagService;
use Soz\Drebedengi\Support\CurrencyCatalog;
use Soz\Drebedengi\Transport\FailoverTransport;
use Soz\Drebedengi\Transport\SoapTransport;
use Soz\Drebedengi\Transport\TransportInterface;

final class DrebedengiClient
{
    private readonly CurrencyCatalog $currencyCatalog;

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly ClientOptions $options = new ClientOptions(),
        ?CurrencyCatalog $currencyCatalog = null,
    ) {
        $this->currencyCatalog = $currencyCatalog ?? new CurrencyCatalog($transport);
    }

    /**
     * @param Endpoint|string|list<Endpoint|string>|null $endpoint
     * @param ClientOptions|array<string, mixed>|null $options
     * @param array<string, mixed> $soapOptions
     * @phpstan-param Endpoint|string|list<mixed>|null $endpoint
     */
    public static function fromCredentials(
        Credentials $credentials,
        Endpoint|string|array|null $endpoint = null,
        ClientOptions|array|null $options = null,
        array $soapOptions = [],
    ): self {
        if (is_array($options)) {
            // Keep the legacy third-argument form compatible, while allowing
            // an explicitly supplied fourth argument to override its values.
            $soapOptions = array_replace($options, $soapOptions);
            $options = null;
        }

        $endpoints = self::normalizeEndpoints($endpoint);
        $transports = [];
        foreach ($endpoints as $candidate) {
            $transports[$candidate->baseUri()] = new SoapTransport($credentials, $candidate, $soapOptions);
        }

        $transport = count($transports) === 1
            ? array_values($transports)[0]
            : new FailoverTransport($transports);

        return new self($transport, $options ?? new ClientOptions());
    }

    /**
     * @param Endpoint|string|list<Endpoint|string>|null $endpoint
     * @return non-empty-list<Endpoint>
     * @phpstan-param Endpoint|string|list<mixed>|null $endpoint
     */
    private static function normalizeEndpoints(Endpoint|string|array|null $endpoint): array
    {
        $values = $endpoint === null
            ? [new Endpoint()]
            : (is_array($endpoint) ? $endpoint : [$endpoint]);

        if ($values === []) {
            throw new InvalidArgumentException('At least one Drebedengi endpoint must be configured.');
        }

        $endpoints = [];
        foreach ($values as $value) {
            if ($value instanceof Endpoint) {
                $endpoints[] = $value;
                continue;
            }
            if (is_string($value)) {
                $endpoints[] = new Endpoint($value);
                continue;
            }

            throw new InvalidArgumentException('Drebedengi endpoints must be Endpoint objects or base URI strings.');
        }

        if ($endpoints === []) {
            throw new InvalidArgumentException('At least one valid Drebedengi endpoint must be configured.');
        }

        return $endpoints;
    }

    public function records(): RecordService
    {
        return new RecordService($this->transport, $this->options, $this->currencyCatalog);
    }

    public function reports(): ReportService
    {
        return new ReportService($this->transport, $this->options, $this->currencyCatalog);
    }

    public function account(): AccountService
    {
        return new AccountService($this->transport, $this->options);
    }

    public function places(): PlaceService
    {
        return new PlaceService($this->transport);
    }

    public function categories(): CategoryService
    {
        return new CategoryService($this->transport);
    }

    public function sources(): SourceService
    {
        return new SourceService($this->transport);
    }

    public function currencies(): CurrencyService
    {
        return new CurrencyService($this->transport, $this->currencyCatalog);
    }

    public function tags(): TagService
    {
        return new TagService($this->transport);
    }

    public function balance(): BalanceService
    {
        return new BalanceService($this->transport, $this->currencyCatalog, $this->options);
    }

    public function sync(): SyncService
    {
        return new SyncService($this->transport, $this->options, $this->currencyCatalog);
    }

    public function raw(): RawService
    {
        return new RawService($this->transport);
    }
}
