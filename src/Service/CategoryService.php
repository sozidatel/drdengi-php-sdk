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
        if ($ids === []) {
            return [];
        }

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
     * Updates a category without dropping fields required by setCategoryList.
     * Supported patch fields: name, parent_id, is_hidden, sort, description.
     *
     * @param array<string, mixed> $fields
     * @return list<array<string, mixed>>
     */
    public function update(string|int $serverId, array $fields): array
    {
        $serverId = DrebedengiNormalizer::positiveIntegerId($serverId, 'Category ID');
        $patch = $this->normalizeUpdatePatch($fields);
        $this->assertFullAccessForUpdate();
        $category = $this->categoryForUpdate((string)$serverId);

        $rawName = $this->nullableRawString($category->raw, 'name', 'category') ?? $category->name;
        $rawDescription = $this->nullableRawString($category->raw, 'description', 'category');
        $patch = $this->decodeEchoedTextPatch($patch, $category->raw, 'category');

        $payload = array_replace([
            'server_id' => (string)$serverId,
            'name' => $this->decodeSoapHtml($rawName),
            'parent_id' => $category->parentId ?? '-1',
            // getCategoryList exposes only expense categories; setCategoryList fixes type=3,
            // while is_for_duty is Place-only state but remains required by the legacy DAO payload.
            'type' => 3,
            'is_hidden' => $category->hidden,
            'is_for_duty' => false,
            'sort' => $category->sort ?? '0',
            'description' => $rawDescription === null ? null : $this->decodeSoapHtml($rawDescription),
        ], $patch);

        return $this->savePayloads([$payload]);
    }

    public function delete(string|int $id): bool
    {
        $id = DrebedengiNormalizer::positiveIntegerId($id, 'Category ID');
        $response = $this->transport->call('deleteObject', [$id, DeleteObjectType::Object->value]);
        if (!is_int($response) && !is_string($response)) {
            throw new UnexpectedResponseException(sprintf(
                'Drebedengi deleteObject response for category must be an integer, got %s.',
                get_debug_type($response),
            ));
        }
        $status = filter_var(trim((string)$response), FILTER_VALIDATE_INT);
        if ($status === false) {
            throw new UnexpectedResponseException('Drebedengi deleteObject response for category is not an integer.');
        }
        if ($status !== 0 && $status !== 1) {
            throw new UnexpectedResponseException('Drebedengi deleteObject response for category must be 0 or 1.');
        }

        return $status === 1;
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
     * @param array<string, mixed> $fields
     * @return array<string, bool|string|null>
     */
    private function normalizeUpdatePatch(array $fields): array
    {
        if ($fields === []) {
            throw new InvalidArgumentException('Cannot update a Drebedengi category without fields.');
        }

        $mutable = ['name', 'parent_id', 'is_hidden', 'sort', 'description'];
        // Accept complete objects returned by older SDK usage patterns, but never
        // let server-managed values override the method argument or fetched state.
        $ignored = ['id', 'server_id', 'budget_family_id', 'family_id', 'type', 'is_for_duty'];
        $allowed = array_merge($mutable, $ignored);
        $unsupported = array_values(array_diff(array_keys($fields), $allowed));
        if ($unsupported !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported Drebedengi category update field(s): %s.',
                implode(', ', $unsupported),
            ));
        }

        $patch = [];
        foreach ($fields as $field => $value) {
            switch ($field) {
                case 'name':
                    $patch[$field] = $this->normalizeName($value, 'Category name');
                    break;
                case 'parent_id':
                    $patch[$field] = $this->normalizeParentId($value);
                    break;
                case 'is_hidden':
                    $patch[$field] = $this->normalizeBoolean($value, 'Category field "is_hidden"');
                    break;
                case 'sort':
                    $patch[$field] = $this->normalizeIntegerString($value, 'Category field "sort"');
                    break;
                case 'description':
                    if ($value !== null && !is_string($value)) {
                        throw new InvalidArgumentException('Category field "description" must be a string or null.');
                    }
                    $patch[$field] = $value;
                    break;
                case 'server_id':
                case 'id':
                case 'budget_family_id':
                case 'family_id':
                case 'type':
                case 'is_for_duty':
                    // The method argument is the authoritative object identity.
                    break;
            }
        }

        if ($patch === []) {
            throw new InvalidArgumentException('Cannot update a Drebedengi category without mutable fields.');
        }

        return $patch;
    }

    private function assertFullAccessForUpdate(): void
    {
        if ((new AccountService($this->transport))->rightAccess() !== '0') {
            throw new InvalidArgumentException(
                'Drebedengi category updates require full account access; limited access masks required fields.',
            );
        }
    }

    private function categoryForUpdate(string $serverId): Category
    {
        foreach ($this->byIds([$serverId]) as $category) {
            if ($category->id === $serverId) {
                return $category;
            }
        }

        throw new UnexpectedResponseException(sprintf(
            'Drebedengi category %s was not found before update.',
            $serverId,
        ));
    }

    private function normalizeName(mixed $value, string $context): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('%s must be a string.', $context));
        }

        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('%s cannot be empty.', $context));
        }

        return $value;
    }

    private function normalizeParentId(mixed $value): string
    {
        if ($value === null) {
            return '-1';
        }
        if (!is_int($value) && !is_string($value)) {
            throw new InvalidArgumentException('Category field "parent_id" must be a positive integer ID, -1, or null.');
        }

        $value = trim((string)$value);
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || ($integer !== -1 && $integer <= 0)) {
            throw new InvalidArgumentException('Category field "parent_id" must be a positive integer ID, -1, or null.');
        }

        return (string)$integer;
    }

    private function normalizeIntegerString(mixed $value, string $context): string
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^-?\d+$/', trim((string)$value))) {
            throw new InvalidArgumentException(sprintf('%s must be an integer.', $context));
        }

        return trim((string)$value);
    }

    private function normalizeBoolean(mixed $value, string $context): bool
    {
        try {
            return DrebedengiNormalizer::bool($value);
        } catch (UnexpectedResponseException $exception) {
            throw new InvalidArgumentException(sprintf('%s must be a boolean.', $context), 0, $exception);
        }
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function nullableRawString(array $raw, string $field, string $context): ?string
    {
        if (!array_key_exists($field, $raw) || $raw[$field] === null) {
            return null;
        }
        if (!is_scalar($raw[$field])) {
            throw new UnexpectedResponseException(sprintf(
                'Drebedengi %s response contains non-scalar field "%s".',
                $context,
                $field,
            ));
        }

        return (string)$raw[$field];
    }

    /**
     * getCategoryList HTML-escapes text. A complete legacy payload often echoes
     * those exact values back into update(); decode that echo once so the server
     * cannot escape it a second time. Explicitly different values stay literal.
     *
     * @param array<string, bool|string|null> $patch
     * @param array<string, mixed> $raw
     * @return array<string, bool|string|null>
     */
    private function decodeEchoedTextPatch(array $patch, array $raw, string $context): array
    {
        foreach (['name', 'description'] as $field) {
            $value = $patch[$field] ?? null;
            if (!is_string($value)) {
                continue;
            }

            $rawValue = $this->nullableRawString($raw, $field, $context);
            if ($rawValue !== null && $value === $rawValue) {
                $patch[$field] = $this->decodeSoapHtml($value);
            }
        }

        return $patch;
    }

    private function decodeSoapHtml(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param list<array<string, mixed>> $created
     */
    private function extractServerId(array $created): ?string
    {
        foreach ($created as $item) {
            foreach (['server_id', 'id'] as $field) {
                $value = $item[$field] ?? null;
                if ((is_int($value) || is_string($value)) && trim((string)$value) !== '') {
                    return (string)$value;
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
        return array_map(static fn (int|string $id): string => (string)$id, $ids);
    }
}
