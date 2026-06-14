<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\Model\Source;
use Soz\Drebedengi\Model\SourceNode;
use Soz\Drebedengi\Model\SourceOption;
use Soz\Drebedengi\Support\DrebedengiNormalizer;
use Soz\Drebedengi\Transport\TransportInterface;

final readonly class SourceService
{
    public function __construct(private TransportInterface $transport)
    {
    }

    /**
     * @return list<Source>
     */
    public function list(): array
    {
        return $this->sort(array_map(Source::fromSoap(...), DrebedengiNormalizer::listOfArrays(
            $this->transport->call('getSourceList'),
        )));
    }

    /**
     * @return list<SourceNode>
     */
    public function tree(bool $includeHidden = true): array
    {
        $sources = $this->list();
        if (!$includeHidden) {
            $sources = array_values(array_filter($sources, static fn (Source $source): bool => !$source->hidden));
        }

        return $this->buildLevel($sources, null, 0);
    }

    /**
     * Returns sources in tree order, convenient for `<select>` controls.
     *
     * @return list<SourceOption>
     */
    public function options(bool $includeHidden = true, string $indent = '— '): array
    {
        $options = [];
        foreach ($this->tree($includeHidden) as $node) {
            $this->appendOptions($node, $options, $indent);
        }

        return $options;
    }

    /**
     * @param list<Source> $sources
     * @return list<Source>
     */
    private function sort(array $sources): array
    {
        usort($sources, static function (Source $a, Source $b): int {
            return [(int)($a->sort ?? 0), $a->name, $a->id] <=> [(int)($b->sort ?? 0), $b->name, $b->id];
        });

        return $sources;
    }

    /**
     * @param list<Source> $sources
     * @return list<SourceNode>
     */
    private function buildLevel(array $sources, ?string $parentId, int $depth): array
    {
        $nodes = [];
        foreach ($sources as $source) {
            if ($source->parentId !== $parentId) {
                continue;
            }

            $nodes[] = new SourceNode(
                source: $source,
                depth: $depth,
                children: $this->buildLevel($sources, $source->id, $depth + 1),
            );
        }

        return $nodes;
    }

    /**
     * @param list<SourceOption> $options
     */
    private function appendOptions(SourceNode $node, array &$options, string $indent): void
    {
        $options[] = new SourceOption(
            id: $node->source->id,
            label: str_repeat($indent, $node->depth) . $node->source->name,
            depth: $node->depth,
            source: $node->source,
        );

        foreach ($node->children as $child) {
            $this->appendOptions($child, $options, $indent);
        }
    }
}
