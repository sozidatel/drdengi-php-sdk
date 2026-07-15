<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Model\Change;
use Soz\Drebedengi\Model\Currency;
use Soz\Drebedengi\Model\Record;
use Soz\Drebedengi\Model\RecordQuery;
use Soz\Drebedengi\Support\CurrencyCatalog;
use Soz\Drebedengi\Support\DrebedengiNormalizer;
use Soz\Drebedengi\Transport\TransportInterface;

final readonly class SyncService
{
    private CurrencyCatalog $currencies;

    public function __construct(
        private TransportInterface $transport,
        private ClientOptions $options = new ClientOptions(),
        ?CurrencyCatalog $currencies = null,
    ) {
        $this->currencies = $currencies ?? new CurrencyCatalog($transport);
    }

    public function currentRevision(): int
    {
        $response = $this->transport->call('getCurrentRevision');
        if (is_int($response)) {
            if ($response >= 0) {
                return $response;
            }
        } elseif (is_string($response)) {
            $value = trim($response);
            if (preg_match('/^(?:0|[1-9]\d*)$/D', $value) === 1) {
                $revision = filter_var($value, FILTER_VALIDATE_INT);
                if ($revision !== false) {
                    return $revision;
                }
            }
        }

        throw new UnexpectedResponseException(sprintf(
            'Drebedengi getCurrentRevision response must be a non-negative integer, got %s.',
            get_debug_type($response),
        ));
    }

    /**
     * @return list<Change>
     */
    public function changesSince(int|string $revision): array
    {
        $changes = array_map(fn (array $item): Change => Change::fromSoap($item, $this->options->timezone), DrebedengiNormalizer::listOfArrays(
            $this->transport->call('getChangeList', [(string)$revision]),
        ));

        usort($changes, static fn (Change $a, Change $b): int => $a->revision <=> $b->revision);

        return $changes;
    }

    /**
     * Returns the complete record set for a real initial synchronization.
     *
     * Drebedengi treats `is_report=false` as a state-changing initial-sync
     * request and clears its client_id-to-server_id deduplication mappings for
     * the current API ID. Do not use this method for ordinary ledger reads.
     *
     * @return list<Record>
     */
    public function initialRecords(): array
    {
        // Resolve and validate currencies before the state-changing initial-sync
        // request clears Drebedengi's client ID deduplication mappings.
        $this->currencies->list();

        $params = (new RecordQuery())
            ->allTime()
            ->toSoapParams($this->options->timezone);
        $params['is_report'] = false;

        return array_map(
            fn (array $item): Record => $this->recordFromSoap($item),
            DrebedengiNormalizer::listOfArrays(
                $this->transport->call('getRecordList', [$params, []]),
            ),
        );
    }

    /** @param array<string, mixed> $raw */
    private function recordFromSoap(array $raw): Record
    {
        $currencyId = DrebedengiNormalizer::requiredString($raw, 'currency_id', 'record');

        return Record::fromSoap(
            $raw,
            $this->options->timezone,
            $this->currencyFromResponse($currencyId),
        );
    }

    private function currencyFromResponse(string $currencyId): Currency
    {
        return $this->currencies->find($currencyId)
            ?? throw new UnexpectedResponseException(sprintf(
                'Record response refers to unknown currency ID "%s".',
                $currencyId,
            ));
    }
}
