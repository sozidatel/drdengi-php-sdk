<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Model\Change;
use Soz\Drebedengi\Model\Record;
use Soz\Drebedengi\Model\RecordQuery;
use Soz\Drebedengi\Support\DrebedengiNormalizer;
use Soz\Drebedengi\Transport\TransportInterface;

final readonly class SyncService
{
    public function __construct(
        private TransportInterface $transport,
        private ClientOptions $options = new ClientOptions(),
    )
    {
    }

    public function currentRevision(): int
    {
        return (int)$this->transport->call('getCurrentRevision');
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
        $params = (new RecordQuery())
            ->allTime()
            ->toSoapParams($this->options->timezone);
        $params['is_report'] = false;

        return array_map(
            fn (array $item): Record => Record::fromSoap($item, $this->options->timezone),
            DrebedengiNormalizer::listOfArrays(
                $this->transport->call('getRecordList', [$params, []]),
            ),
        );
    }
}
