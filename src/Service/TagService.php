<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\Model\Tag;
use Soz\Drebedengi\Support\DrebedengiNormalizer;
use Soz\Drebedengi\Transport\TransportInterface;

final readonly class TagService
{
    public function __construct(private TransportInterface $transport)
    {
    }

    /**
     * @return list<Tag>
     */
    public function list(): array
    {
        return array_map(Tag::fromSoap(...), DrebedengiNormalizer::listOfArrays(
            $this->transport->call('getTagList'),
        ));
    }
}
