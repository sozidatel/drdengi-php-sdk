<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Model\DeleteObjectType;
use Soz\Drebedengi\Model\ReferenceWriteToken;
use Soz\Drebedengi\Model\Tag;
use Soz\Drebedengi\Model\TagNode;
use Soz\Drebedengi\Model\TagOption;
use Soz\Drebedengi\Model\WriteResult;
use Soz\Drebedengi\Support\DrebedengiNormalizer;
use Soz\Drebedengi\Transport\TransportInterface;

final readonly class TagService
{
    public function __construct(private TransportInterface $transport)
    {
    }

    /**
     * @return list<Tag>
     */
    public function list(): array
    {
        return $this->sort(array_map(Tag::fromSoap(...), DrebedengiNormalizer::listOfArrays(
            $this->transport->call('getTagList'),
        )));
    }

    /**
     * @param list<int|string> $ids
     * @return list<Tag>
     */
    public function byIds(array $ids): array
    {
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            return [];
        }

        return $this->sort(array_map(Tag::fromSoap(...), DrebedengiNormalizer::listOfArrays(
            $this->transport->call('getTagList', [$ids]),
        )));
    }

    public function find(int|string $id): ?Tag
    {
        $id = (string)DrebedengiNormalizer::positiveIntegerId($id, 'Tag ID');

        foreach ($this->byIds([$id]) as $tag) {
            if ($tag->id === $id) {
                return $tag;
            }
        }

        return null;
    }

    public function require(int|string $id): Tag
    {
        $normalizedId = DrebedengiNormalizer::positiveIntegerId($id, 'Tag ID');

        return $this->find($normalizedId)
            ?? throw new InvalidArgumentException(sprintf('Unknown tag ID "%d".', $normalizedId));
    }

    /**
     * @return list<TagNode>
     */
    public function tree(bool $includeHidden = true): array
    {
        $tags = $this->list();
        if (!$includeHidden) {
            $tags = array_values(array_filter($tags, static fn (Tag $tag): bool => !$tag->hidden));
        }

        return $this->buildLevel($tags, null, 0);
    }

    /**
     * Returns tags in tree order, convenient for `<select>` controls.
     *
     * @return list<TagOption>
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
        bool $family = false,
        int|string $sort = 0,
        ?ReferenceWriteToken $writeToken = null,
    ): Tag {
        $writeToken ??= ReferenceWriteToken::generate();
        $payload = [
            'client_id' => $writeToken->clientId,
            'name' => $this->normalizeName($name),
            'parent_id' => $this->normalizeParentId($parentId),
            'is_hidden' => $hidden,
            'is_family' => $family,
            'sort' => $this->normalizeIntegerString($sort, 'Tag sort'),
        ];

        $result = new WriteResult($this->savePayloads([$payload]), [$payload]);
        $serverId = $result->serverIdForClientId($writeToken->clientId);
        if ($serverId === null) {
            throw new UnexpectedResponseException(
                'Drebedengi setTagList response does not contain the created tag server_id/client_id mapping.',
            );
        }

        $tag = $this->find($serverId);
        if (!$tag instanceof Tag) {
            throw new UnexpectedResponseException(sprintf(
                'Created Drebedengi tag "%s" was not found by server id %s.',
                $payload['name'],
                $serverId,
            ));
        }

        return $tag;
    }

    /**
     * Updates a tag without dropping fields required by setTagList.
     * Supported patch fields: name, parent_id, is_hidden, is_family, sort.
     *
     * @param array<string, mixed> $fields
     */
    public function update(int|string $serverId, array $fields): Tag
    {
        $serverId = (string)DrebedengiNormalizer::positiveIntegerId($serverId, 'Tag ID');
        $patch = $this->normalizeUpdatePatch($fields);
        $this->assertFullAccessForUpdate();
        $tag = $this->find($serverId);
        if (!$tag instanceof Tag) {
            throw new InvalidArgumentException(sprintf('Unknown tag ID "%s".', $serverId));
        }
        $this->assertOwnedByCurrentUser($tag, 'update');

        $payload = array_replace([
            'server_id' => $serverId,
            'name' => $tag->name,
            'parent_id' => $tag->parentId ?? '-1',
            'is_hidden' => $tag->hidden,
            'is_family' => $tag->family,
            'sort' => $tag->sort ?? '0',
        ], $patch);

        $this->confirmExistingWrite($payload, $serverId);

        $updated = $this->find($serverId);
        if (!$updated instanceof Tag) {
            throw new UnexpectedResponseException(sprintf(
                'Updated Drebedengi tag %s was not found after setTagList.',
                $serverId,
            ));
        }

        return $updated;
    }

    /**
     * Deletes a tag only after verifying that the ID belongs to a visible tag.
     *
     * Drebedengi may also remove the first `[TagName]` occurrence from comments
     * of up to 1000 records linked to the deleted tag.
     */
    public function delete(int|string $id): bool
    {
        $id = DrebedengiNormalizer::positiveIntegerId($id, 'Tag ID');
        $this->assertFullAccess('deletes');
        $tag = $this->find($id);
        if (!$tag instanceof Tag) {
            return false;
        }
        $this->assertOwnedByCurrentUser($tag, 'delete');

        $response = $this->transport->call('deleteObject', [$id, DeleteObjectType::Tag->value]);
        if (!is_int($response) && !is_string($response)) {
            throw new UnexpectedResponseException(sprintf(
                'Drebedengi deleteObject response for tag must be an integer, got %s.',
                get_debug_type($response),
            ));
        }

        $status = filter_var(trim((string)$response), FILTER_VALIDATE_INT);
        if ($status === false) {
            throw new UnexpectedResponseException('Drebedengi deleteObject response for tag is not an integer.');
        }
        if ($status !== 0 && $status !== 1) {
            throw new UnexpectedResponseException('Drebedengi deleteObject response for tag must be 0 or 1.');
        }

        return $status === 1;
    }

    /**
     * Raw escape hatch for advanced or forward-compatible setTagList payloads.
     *
     * @param list<array<string, mixed>> $payloads
     * @return list<array<string, mixed>>
     */
    public function savePayloads(array $payloads): array
    {
        if ($payloads === []) {
            return [];
        }

        return DrebedengiNormalizer::listOfArrays($this->transport->call('setTagList', [$payloads]));
    }

    /**
     * @param list<Tag> $tags
     * @return list<Tag>
     */
    private function sort(array $tags): array
    {
        usort($tags, static function (Tag $a, Tag $b): int {
            return [(int)($a->sort ?? 0), $a->name, $a->id] <=> [(int)($b->sort ?? 0), $b->name, $b->id];
        });

        return $tags;
    }

    /**
     * @param list<Tag> $tags
     * @return list<TagNode>
     */
    private function buildLevel(array $tags, ?string $parentId, int $depth): array
    {
        $nodes = [];
        foreach ($tags as $tag) {
            if ($tag->parentId !== $parentId) {
                continue;
            }

            $nodes[] = new TagNode(
                tag: $tag,
                depth: $depth,
                children: $this->buildLevel($tags, $tag->id, $depth + 1),
            );
        }

        return $nodes;
    }

    /**
     * @param list<TagOption> $options
     */
    private function appendOptions(TagNode $node, array &$options, string $indent): void
    {
        $options[] = new TagOption(
            id: $node->tag->id,
            label: str_repeat($indent, $node->depth) . $node->tag->name,
            depth: $node->depth,
            tag: $node->tag,
        );

        foreach ($node->children as $child) {
            $this->appendOptions($child, $options, $indent);
        }
    }

    /**
     * @param list<int|string> $ids
     * @return list<string>
     */
    private function normalizeIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            $value = (string)DrebedengiNormalizer::positiveIntegerId($id, 'Tag ID');
            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, bool|string>
     */
    private function normalizeUpdatePatch(array $fields): array
    {
        if ($fields === []) {
            throw new InvalidArgumentException('Cannot update a Drebedengi tag without fields.');
        }

        $mutable = ['name', 'parent_id', 'is_hidden', 'is_family', 'sort'];
        $ignored = ['id', 'server_id', 'family_id', 'user_id', 'nuid'];
        $unsupported = array_values(array_diff(array_keys($fields), array_merge($mutable, $ignored)));
        if ($unsupported !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported Drebedengi tag update field(s): %s.',
                implode(', ', $unsupported),
            ));
        }

        $patch = [];
        foreach ($fields as $field => $value) {
            switch ($field) {
                case 'name':
                    $patch[$field] = $this->normalizeName($value);
                    break;
                case 'parent_id':
                    $patch[$field] = $this->normalizeParentId($value);
                    break;
                case 'is_hidden':
                case 'is_family':
                    if (!is_bool($value)) {
                        throw new InvalidArgumentException(sprintf('Tag field "%s" must be a boolean.', $field));
                    }
                    $patch[$field] = $value;
                    break;
                case 'sort':
                    $patch[$field] = $this->normalizeIntegerString($value, 'Tag field "sort"');
                    break;
                case 'id':
                case 'server_id':
                case 'family_id':
                case 'user_id':
                case 'nuid':
                    break;
            }
        }

        if ($patch === []) {
            throw new InvalidArgumentException('Cannot update a Drebedengi tag without mutable fields.');
        }

        return $patch;
    }

    private function normalizeName(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Tag name must be a string.');
        }

        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('Tag name cannot be empty.');
        }

        $length = preg_match_all('/./us', $value, $matches);
        if ($length === false) {
            throw new InvalidArgumentException('Tag name must be valid UTF-8.');
        }
        if ($length > 64) {
            throw new InvalidArgumentException('Tag name must not exceed 64 characters.');
        }

        return $value;
    }

    private function normalizeParentId(mixed $value): string
    {
        if ($value === null) {
            return '-1';
        }
        if (!is_int($value) && !is_string($value)) {
            throw new InvalidArgumentException('Tag parent ID must be a positive integer ID, -1, or null.');
        }

        $value = trim((string)$value);
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || ($integer !== -1 && ($integer <= 0 || !preg_match('/^\d+$/', $value)))) {
            throw new InvalidArgumentException('Tag parent ID must be a positive integer ID, -1, or null.');
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

    private function assertFullAccess(string $operation): void
    {
        if ((new AccountService($this->transport))->rightAccess() !== '0') {
            throw new InvalidArgumentException(sprintf(
                'Drebedengi tag %s require full account access.',
                $operation,
            ));
        }
    }

    private function assertFullAccessForUpdate(): void
    {
        $this->assertFullAccess('updates');
    }

    private function assertOwnedByCurrentUser(Tag $tag, string $operation): void
    {
        $currentUserId = (new AccountService($this->transport))->userId();
        if ($tag->userId === null) {
            throw new InvalidArgumentException(sprintf(
                'Cannot %s Drebedengi tag %s safely because its owner user ID is unavailable.',
                $operation,
                $tag->id,
            ));
        }
        if ($tag->userId !== $currentUserId) {
            throw new InvalidArgumentException(sprintf(
                'Cannot %s Drebedengi tag %s owned by another family user.',
                $operation,
                $tag->id,
            ));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function confirmExistingWrite(array $payload, string $serverId): void
    {
        $result = new WriteResult($this->savePayloads([$payload]));
        if (!in_array($serverId, $result->serverIds, true)) {
            throw new UnexpectedResponseException(sprintf(
                'Drebedengi setTagList response does not confirm tag server_id %s.',
                $serverId,
            ));
        }
    }
}
