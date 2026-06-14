<?php

declare(strict_types=1);

namespace Soz\Drebedengi;

use Soz\Drebedengi\Service\BalanceService;
use Soz\Drebedengi\Service\AccountService;
use Soz\Drebedengi\Service\CategoryService;
use Soz\Drebedengi\Service\CurrencyService;
use Soz\Drebedengi\Service\PlaceService;
use Soz\Drebedengi\Service\RawService;
use Soz\Drebedengi\Service\RecordService;
use Soz\Drebedengi\Service\SourceService;
use Soz\Drebedengi\Service\SyncService;
use Soz\Drebedengi\Service\TagService;
use Soz\Drebedengi\Transport\SoapTransport;
use Soz\Drebedengi\Transport\TransportInterface;

final class DrebedengiClient
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly ClientOptions $options = new ClientOptions(),
    )
    {
    }

    /**
     * @param array<string, mixed> $soapOptions
     */
    public static function fromCredentials(
        Credentials $credentials,
        ?Endpoint $endpoint = null,
        ClientOptions|array|null $options = null,
        array $soapOptions = [],
    ): self {
        if (is_array($options)) {
            $soapOptions = $options;
            $options = null;
        }

        return new self(
            new SoapTransport($credentials, $endpoint ?? new Endpoint(), $soapOptions),
            $options ?? new ClientOptions(),
        );
    }

    public function records(): RecordService
    {
        return new RecordService($this->transport, $this->options);
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
        return new CurrencyService($this->transport);
    }

    public function tags(): TagService
    {
        return new TagService($this->transport);
    }

    public function balance(): BalanceService
    {
        return new BalanceService($this->transport);
    }

    public function sync(): SyncService
    {
        return new SyncService($this->transport, $this->options);
    }

    public function raw(): RawService
    {
        return new RawService($this->transport);
    }
}
