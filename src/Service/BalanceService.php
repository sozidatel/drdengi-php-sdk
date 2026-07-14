<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\Model\BalanceItem;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Support\CurrencyCatalog;
use Soz\Drebedengi\Support\DrebedengiNormalizer;
use Soz\Drebedengi\Transport\TransportInterface;

final readonly class BalanceService
{
    private CurrencyCatalog $currencies;

    public function __construct(private TransportInterface $transport, ?CurrencyCatalog $currencies = null)
    {
        $this->currencies = $currencies ?? new CurrencyCatalog($transport);
    }

    /**
     * @param array{restDate?: string, is_with_accum?: bool, is_with_duty?: bool} $params
     * @return list<BalanceItem>
     */
    public function list(array $params = []): array
    {
        return array_map(
            function (array $item): BalanceItem {
                $currencyId = DrebedengiNormalizer::string($item['currency_id'] ?? '');
                $currency = $this->currencies->find($currencyId)
                    ?? throw new UnexpectedResponseException(sprintf(
                        'Balance response refers to unknown currency ID "%s".',
                        $currencyId,
                    ));

                return BalanceItem::fromSoap($item, $currency);
            },
            DrebedengiNormalizer::listOfArrays($this->transport->call('getBalance', [$params])),
        );
    }
}
