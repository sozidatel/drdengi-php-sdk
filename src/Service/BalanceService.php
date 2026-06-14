<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\Model\BalanceItem;
use Soz\Drebedengi\Support\DrebedengiNormalizer;
use Soz\Drebedengi\Transport\TransportInterface;

final readonly class BalanceService
{
    public function __construct(private TransportInterface $transport)
    {
    }

    /**
     * @param array{restDate?: string, is_with_accum?: bool, is_with_duty?: bool} $params
     * @return list<BalanceItem>
     */
    public function list(array $params = []): array
    {
        return array_map(BalanceItem::fromSoap(...), DrebedengiNormalizer::listOfArrays(
            $this->transport->call('getBalance', [$params]),
        ));
    }
}
