<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Model\Change;
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
}
