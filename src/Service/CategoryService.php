<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Model\Category;
use Soz\Drebedengi\Model\CategoryNode;
use Soz\Drebedengi\Model\CategoryOption;
use Soz\Drebedengi\Model\DeleteObjectType;
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
     * @param list<int|string> $ids
     * @return list<Category>
     */
    public function byIds(array $ids): array
    {
        return $this->sort(array_map(Category::fromSoap(...), DrebedengiNormalizer::listOfArrays(
            $this->transport->call('getCategoryList', [$this->normalizeIds($ids)]),
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

    public function create(
        string $name,
        int|string|null $parentId = null,
        bool $hidden = false,
    ): Category {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Cannot create a Drebedengi category without name.');
        }

        $payload = [
            'client_id' => $this->clientId(),
            'name' => $name,
            'parent_id' => $parentId === null ? '-1' : (string)$parentId,
            'type' => 3,
            'is_hidden' => $hidden,
            'is_for_duty' => false,
            'sort' => '0',
        ];

        $serverId = $this->extractServerId($this->savePayloads([$payload]));
        if ($serverId === null) {
            throw new UnexpectedResponseException('Drebedengi setCategoryList response does not contain created category server_id.');
        }

        $category = $this->byIds([$serverId])[0] ?? null;
        if (!$category instanceof Category) {
            throw new UnexpectedResponseException(sprintf('Created Drebedengi category "%s" was not found by server id %s.', $name, $serverId));
        }

        return $category;
    }

    /**
     * @param array<string, mixed> $fields
     * @return list<array<string, mixed>>
     */
    public function update(string|int $serverId, array $fields): array
    {
        $payload = array_replace($fields, ['server_id' => (string)$serverId]);

        return $this->savePayloads([$payload]);
    }

    public function delete(string|int $id): bool
    {
        return (int)$this->transport->call('deleteObject', [(int)$id, DeleteObjectType::Object->value]) === 1;
    }

    /**
     * @param list<array<string, mixed>> $payloads
     * @return list<array<string, mixed>>
     */
    public function savePayloads(array $payloads): array
    {
        if ($payloads === []) {
            return [];
        }

        return DrebedengiNormalizer::listOfArrays($this->transport->call('setCategoryList', [$payloads]));
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

    private function clientId(): int
    {
        return random_int(1, 999_999_999);
    }

    /**
     * @param list<array<string, mixed>> $created
     */
    private function extractServerId(array $created): ?string
    {
        foreach ($created as $item) {
            foreach (['server_id', 'id'] as $field) {
                if (array_key_exists($field, $item) && trim((string)$item[$field]) !== '') {
                    return (string)$item[$field];
                }
            }
        }

        return null;
    }

    /**
     * @param list<int|string> $ids
     * @return list<string>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_map(static fn (int|string $id): string => (string)$id, $ids));
    }
}
