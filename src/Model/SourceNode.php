<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

final readonly class SourceNode implements \JsonSerializable
{
    /**
     * @param list<SourceNode> $children
     */
    public function __construct(
        public Source $source,
        public int $depth,
        public array $children,
    ) {
    }

    /**
     * @return array{source: Source, depth: int, children: list<SourceNode>}
     */
    public function jsonSerialize(): array
    {
        return [
            'source' => $this->source,
            'depth' => $this->depth,
            'children' => $this->children,
        ];
    }
}
