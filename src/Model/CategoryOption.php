<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

final readonly class CategoryOption implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $label,
        public int $depth,
        public Category $category,
    ) {
    }

    /**
     * @return array{id: string, label: string, depth: int, category: Category}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'depth' => $this->depth,
            'category' => $this->category,
        ];
    }
}
