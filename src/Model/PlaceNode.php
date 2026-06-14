<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

final readonly class PlaceNode implements \JsonSerializable
{
    /**
     * @param list<PlaceNode> $children
     */
    public function __construct(
        public Place $place,
        public int $depth,
        public array $children,
    ) {
    }

    /**
     * @return array{place: Place, depth: int, children: list<PlaceNode>}
     */
    public function jsonSerialize(): array
    {
        return [
            'place' => $this->place,
            'depth' => $this->depth,
            'children' => $this->children,
        ];
    }
}
