<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Model\OperationType;
use Soz\Drebedengi\Model\ReportQuery;
use Soz\Drebedengi\Model\ReportRow;
use Soz\Drebedengi\Support\CurrencyCatalog;
use Soz\Drebedengi\Support\DrebedengiNormalizer;
use Soz\Drebedengi\Transport\TransportInterface;

final readonly class ReportService
{
    private CurrencyCatalog $currencies;

    public function __construct(
        private TransportInterface $transport,
        private ClientOptions $options = new ClientOptions(),
        ?CurrencyCatalog $currencies = null,
    ) {
        $this->currencies = $currencies ?? new CurrencyCatalog($transport);
    }

    /** @return list<ReportRow> */
    public function expensesByCategory(?ReportQuery $query = null): array
    {
        return $this->list($query ?? new ReportQuery(), OperationType::Expense);
    }

    /** @return list<ReportRow> */
    public function incomeBySource(?ReportQuery $query = null): array
    {
        return $this->list($query ?? new ReportQuery(), OperationType::Income);
    }

    /**
     * @return list<ReportRow>
     */
    private function list(ReportQuery $query, OperationType $operationType): array
    {
        $params = $query->toSoapParams($operationType, $this->options->timezone);
        $rows = DrebedengiNormalizer::listOfArrays(
            $this->transport->call('getRecordList', [$params, []]),
        );

        return array_map(function (array $row): ReportRow {
            $currencyId = DrebedengiNormalizer::requiredString($row, 'currency_id', 'report row');
            $currency = $this->currencies->find($currencyId)
                ?? throw new UnexpectedResponseException(sprintf(
                    'Drebedengi report row references unknown currency ID "%s".',
                    $currencyId,
                ));

            return ReportRow::fromSoap($row, $currency);
        }, $rows);
    }
}
