<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

final readonly class CategoryNode implements \JsonSerializable
{
    /**
     * @param list<CategoryNode> $children
     */
    public function __construct(
        public Category $category,
        public int $depth,
        public array $children,
    ) {
    }

    /**
     * @return array{category: Category, depth: int, children: list<CategoryNode>}
     */
    public function jsonSerialize(): array
    {
        return [
            'category' => $this->category,
            'depth' => $this->depth,
            'children' => $this->children,
        ];
    }
}
