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
            $this->byId = $this->buildIndex($currencies);
        }
    }

    /** @return list<Currency> */
    public function list(): array
    {
        if ($this->currencies === null) {
            return $this->refresh();
        }

        return $this->currencies;
    }

    /** @return list<Currency> */
    public function refresh(): array
    {
        $currencies = array_map(
            static fn (array $item): Currency => Currency::fromSoap($item),
            DrebedengiNormalizer::listOfArrays($this->transport->call('getCurrencyList')),
        );
        $byId = $this->buildIndex($currencies);

        // Replace both views only after the complete response has been parsed
        // and validated. A failed refresh must leave the last valid catalog usable.
        $this->currencies = $currencies;
        $this->byId = $byId;

        return $currencies;
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

    /**
     * @param list<Currency> $currencies
     * @return array<string, Currency>
     */
    private function buildIndex(array $currencies): array
    {
        $byId = [];

        foreach ($currencies as $currency) {
            if ($currency->id === '') {
                throw new UnexpectedResponseException('Currency response contains an empty ID.');
            }
            if (isset($byId[$currency->id])) {
                throw new UnexpectedResponseException(sprintf(
                    'Currency response contains duplicate ID "%s".',
                    $currency->id,
                ));
            }

            $byId[$currency->id] = $currency;
        }

        return $byId;
    }
}
