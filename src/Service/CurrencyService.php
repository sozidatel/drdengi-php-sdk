<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\Model\Currency;
use Soz\Drebedengi\Support\DrebedengiNormalizer;
use Soz\Drebedengi\Transport\TransportInterface;

final readonly class CurrencyService
{
    public function __construct(private TransportInterface $transport)
    {
    }

    /**
     * @return list<Currency>
     */
    public function list(): array
    {
        return array_map(Currency::fromSoap(...), DrebedengiNormalizer::listOfArrays(
            $this->transport->call('getCurrencyList'),
        ));
    }
}
