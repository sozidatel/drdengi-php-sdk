<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Model\BalanceQuery;
use Soz\Drebedengi\Model\BalanceItem;
use Soz\Drebedengi\Support\CurrencyCatalog;
use Soz\Drebedengi\Support\DrebedengiNormalizer;
use Soz\Drebedengi\Transport\TransportInterface;

final readonly class BalanceService
{
    private CurrencyCatalog $currencies;
    private ClientOptions $options;

    public function __construct(
        private TransportInterface $transport,
        ?CurrencyCatalog $currencies = null,
        ?ClientOptions $options = null,
    ) {
        $this->currencies = $currencies ?? new CurrencyCatalog($transport);
        $this->options = $options ?? new ClientOptions();
    }

    /**
     * @param array{
     *     restDate?: string,
     *     is_with_accum?: bool,
     *     is_with_duty?: bool,
     *     is_with_hidden?: bool,
     *     is_with_null?: bool
     * }|BalanceQuery $params
     * @return list<BalanceItem>
     */
    public function list(BalanceQuery|array $params = []): array
    {
        $soapParams = $params instanceof BalanceQuery
            ? $params->toSoapParams($this->options->timezone)
            : $params;

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
            DrebedengiNormalizer::listOfArrays($this->transport->call('getBalance', [$soapParams])),
        );
    }
}
