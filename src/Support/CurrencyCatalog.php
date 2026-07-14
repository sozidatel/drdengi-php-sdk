<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Support;

use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Model\Currency;
use Soz\Drebedengi\Transport\TransportInterface;

final class CurrencyCatalog
{
    /** @var list<Currency>|null */
    private ?array $currencies;

    /** @var array<string, Currency>|null */
    private ?array $byId = null;

    /** @param list<Currency>|null $currencies */
    public function __construct(
        private readonly TransportInterface $transport,
        ?array $currencies = null,
    ) {
        $this->currencies = $currencies;

        if ($currencies !== null) {
            $this->index($currencies);
        }
    }

    /** @return list<Currency> */
    public function list(): array
    {
        if ($this->currencies === null) {
            $this->currencies = array_map(
                static fn (array $item): Currency => Currency::fromSoap($item),
                DrebedengiNormalizer::listOfArrays($this->transport->call('getCurrencyList')),
            );
            $this->index($this->currencies);
        }

        return $this->currencies;
    }

    /** @return list<Currency> */
    public function refresh(): array
    {
        $this->currencies = null;
        $this->byId = null;

        return $this->list();
    }

    public function find(int|string $id): ?Currency
    {
        $this->list();

        return $this->byId[(string)$id] ?? null;
    }

    public function require(int|string $id): Currency
    {
        return $this->find($id)
            ?? throw new InvalidArgumentException(sprintf('Unknown currency ID "%s".', (string)$id));
    }

    /** @param list<Currency> $currencies */
    private function index(array $currencies): void
    {
        $this->byId = [];

        foreach ($currencies as $currency) {
            if ($currency->id === '') {
                throw new UnexpectedResponseException('Currency response contains an empty ID.');
            }

            $this->byId[$currency->id] = $currency;
        }
    }
}
