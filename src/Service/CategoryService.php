<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\Model\Category;
use Soz\Drebedengi\Model\CategoryNode;
use Soz\Drebedengi\Model\CategoryOption;
use Soz\Drebedengi\Support\DrebedengiNormalizer;
use Soz\Drebedengi\Transport\TransportInterface;

final readonly class CategoryService
{
    public function __construct(private TransportInterface $transport)
    {
    }

    /**
     * @return list<Category>
     */
    public function list(): array
    {
        return $this->sort(array_map(Category::fromSoap(...), DrebedengiNormalizer::listOfArrays(
            $this->transport->call('getCategoryList'),
        )));
    }

    /**
     * @return list<CategoryNode>
     */
    public function tree(bool $includeHidden = true): array
    {
        $categories = $this->list();
        if (!$includeHidden) {
            $categories = array_values(array_filter($categories, static fn (Category $category): bool => !$category->hidden));
        }

        return $this->buildLevel($categories, null, 0);
    }

    /**
     * Returns categories in tree order, convenient for `<select>` controls.
     *
     * @return list<CategoryOption>
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
     * @param list<Category> $categories
     * @return list<Category>
     */
    private function sort(array $categories): array
    {
        usort($categories, static function (Category $a, Category $b): int {
            return [(int)($a->sort ?? 0), $a->name, $a->id] <=> [(int)($b->sort ?? 0), $b->name, $b->id];
        });

        return $categories;
    }

    /**
     * @param list<Category> $categories
     * @return list<CategoryNode>
     */
    private function buildLevel(array $categories, ?string $parentId, int $depth): array
    {
        $nodes = [];
        foreach ($categories as $category) {
            if ($category->parentId !== $parentId) {
                continue;
            }

            $nodes[] = new CategoryNode(
                category: $category,
                depth: $depth,
                children: $this->buildLevel($categories, $category->id, $depth + 1),
            );
        }

        return $nodes;
    }

    /**
     * @param list<CategoryOption> $options
     */
    private function appendOptions(CategoryNode $node, array &$options, string $indent): void
    {
        $options[] = new CategoryOption(
            id: $node->category->id,
            label: str_repeat($indent, $node->depth) . $node->category->name,
            depth: $node->depth,
            category: $node->category,
        );

        foreach ($node->children as $child) {
            $this->appendOptions($child, $options, $indent);
        }
    }
}
