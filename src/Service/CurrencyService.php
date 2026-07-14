<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Model\Currency;
use Soz\Drebedengi\Support\CurrencyCatalog;
use Soz\Drebedengi\Transport\TransportInterface;

final readonly class CurrencyService
{
    private CurrencyCatalog $currencies;

    public function __construct(TransportInterface $transport, ?CurrencyCatalog $currencies = null)
    {
        $this->currencies = $currencies ?? new CurrencyCatalog($transport);
    }

    /**
     * @return list<Currency>
     */
    public function list(): array
    {
        return $this->currencies->list();
    }

    public function find(int|string $id): ?Currency
    {
        return $this->currencies->find($id);
    }

    public function require(int|string $id): Currency
    {
        return $this->currencies->require($id);
    }

    public function findByCode(string $code): ?Currency
    {
        $code = trim($code);

        foreach ($this->list() as $currency) {
            if ($currency->code !== null && strcasecmp($currency->code, $code) === 0) {
                return $currency;
            }
        }

        return null;
    }

    public function requireByCode(string $code): Currency
    {
        return $this->findByCode($code)
            ?? throw new InvalidArgumentException(sprintf('Unknown currency code "%s".', $code));
    }

    public function default(): ?Currency
    {
        foreach ($this->list() as $currency) {
            if ($currency->default) {
                return $currency;
            }
        }

        return null;
    }

    /** @return list<Currency> */
    public function refresh(): array
    {
        return $this->currencies->refresh();
    }
}
