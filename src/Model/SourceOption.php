<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

final readonly class SourceOption implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $label,
        public int $depth,
        public Source $source,
    ) {
    }

    /**
     * @return array{id: string, label: string, depth: int, source: Source}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'depth' => $this->depth,
            'source' => $this->source,
        ];
    }
}
