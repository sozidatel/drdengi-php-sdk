<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

final readonly class TagOption implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $label,
        public int $depth,
        public Tag $tag,
    ) {
    }

    /**
     * @return array{id: string, label: string, depth: int, tag: Tag}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'depth' => $this->depth,
            'tag' => $this->tag,
        ];
    }
}
