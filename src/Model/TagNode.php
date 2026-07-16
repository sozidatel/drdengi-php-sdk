<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

final readonly class TagNode implements \JsonSerializable
{
    /**
     * @param list<TagNode> $children
     */
    public function __construct(
        public Tag $tag,
        public int $depth,
        public array $children,
    ) {
    }

    /**
     * @return array{tag: Tag, depth: int, children: list<TagNode>}
     */
    public function jsonSerialize(): array
    {
        return [
            'tag' => $this->tag,
            'depth' => $this->depth,
            'children' => $this->children,
        ];
    }
}
