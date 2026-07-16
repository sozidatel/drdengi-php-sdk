<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Model\DeleteObjectType;
use Soz\Drebedengi\Model\ReferenceWriteToken;
use Soz\Drebedengi\Model\Source;
use Soz\Drebedengi\Model\SourceNode;
use Soz\Drebedengi\Model\SourceOption;
use Soz\Drebedengi\Model\WriteResult;
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
     * @param list<int|string> $ids
     * @return list<Source>
     */
    public function byIds(array $ids): array
    {
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            return [];
        }

        return $this->sort(array_map(Source::fromSoap(...), DrebedengiNormalizer::listOfArrays(
            $this->transport->call('getSourceList', [$ids]),
        )));
    }

    public function find(int|string $id): ?Source
    {
        $id = (string)DrebedengiNormalizer::positiveIntegerId($id, 'Source ID');

        foreach ($this->byIds([$id]) as $source) {
            if ($source->id === $id) {
                return $source;
            }
        }

        return null;
    }

    public function require(int|string $id): Source
    {
        $id = (string)DrebedengiNormalizer::positiveIntegerId($id, 'Source ID');

        return $this->find($id)
            ?? throw new InvalidArgumentException(sprintf('Unknown source ID "%s".', $id));
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

    public function create(
        string $name,
        int|string|null $parentId = null,
        bool $hidden = false,
        int|string $sort = 0,
        ?string $description = null,
        ?ReferenceWriteToken $writeToken = null,
    ): Source {
        $token = $writeToken ?? ReferenceWriteToken::generate();
        $name = $this->normalizeName($name);

        $payload = [
            'client_id' => $token->clientId,
            'name' => $name,
            'parent_id' => $this->normalizeParentId($parentId),
            'type' => 2,
            'is_hidden' => $hidden,
            'is_for_duty' => false,
            'sort' => $this->normalizeIntegerString($sort, 'Source sort'),
            'description' => $description,
        ];

        $result = new WriteResult($this->savePayloads([$payload]), [$payload]);
        $serverId = $result->serverIdForClientId($token->clientId);
        if ($serverId === null) {
            throw new UnexpectedResponseException(sprintf(
                'Drebedengi setSourceList response does not map client_id %d to a created source server_id.',
                $token->clientId,
            ));
        }

        $source = $this->find($serverId);
        if (!$source instanceof Source) {
            throw new UnexpectedResponseException(sprintf(
                'Created Drebedengi source "%s" was not found by server id %s.',
                $name,
                $serverId,
            ));
        }

        return $source;
    }

    /**
     * Updates a source without dropping fields required by setSourceList.
     * Supported patch fields: name, parent_id, is_hidden, sort, description.
     *
     * @param array<string, mixed> $fields
     */
    public function update(string|int $serverId, array $fields): Source
    {
        $serverId = (string)DrebedengiNormalizer::positiveIntegerId($serverId, 'Source ID');
        $patch = $this->normalizeUpdatePatch($fields);
        $this->assertFullAccess('updates');
        $source = $this->sourceForUpdate($serverId);

        $rawName = $this->nullableRawString($source->raw, 'name') ?? $source->name;
        $rawDescription = $this->nullableRawString($source->raw, 'description');
        $patch = $this->decodeEchoedTextPatch($patch, $source->raw);

        $payload = array_replace([
            'server_id' => $serverId,
            'name' => $this->decodeSoapHtml($rawName),
            'parent_id' => $source->parentId ?? '-1',
            'type' => 2,
            'is_hidden' => $source->hidden,
            'is_for_duty' => false,
            'sort' => $source->sort ?? '0',
            'description' => $rawDescription === null ? null : $this->decodeSoapHtml($rawDescription),
        ], $patch);

        $result = new WriteResult($this->savePayloads([$payload]));
        if (!in_array($serverId, $result->serverIds, true)) {
            throw new UnexpectedResponseException(sprintf(
                'Drebedengi setSourceList response does not confirm updated source server_id %s.',
                $serverId,
            ));
        }

        $updated = $this->find($serverId);
        if (!$updated instanceof Source) {
            throw new UnexpectedResponseException(sprintf(
                'Updated Drebedengi source %s was not found during read-back.',
                $serverId,
            ));
        }

        return $updated;
    }

    public function delete(string|int $id): bool
    {
        $id = (string)DrebedengiNormalizer::positiveIntegerId($id, 'Source ID');
        $this->assertFullAccess('deletes');
        if ($this->find($id) === null) {
            return false;
        }

        $response = $this->transport->call('deleteObject', [(int)$id, DeleteObjectType::Object->value]);
        if (!is_int($response) && !is_string($response)) {
            throw new UnexpectedResponseException(sprintf(
                'Drebedengi deleteObject response for source must be an integer, got %s.',
                get_debug_type($response),
            ));
        }

        $status = filter_var(trim((string)$response), FILTER_VALIDATE_INT);
        if ($status === false) {
            throw new UnexpectedResponseException('Drebedengi deleteObject response for source is not an integer.');
        }
        if ($status !== 0 && $status !== 1) {
            throw new UnexpectedResponseException('Drebedengi deleteObject response for source must be 0 or 1.');
        }

        return $status === 1;
    }

    /**
     * Raw escape hatch for callers that need unsupported setSourceList fields.
     *
     * @param list<array<string, mixed>> $payloads
     * @return list<array<string, mixed>>
     */
    public function savePayloads(array $payloads): array
    {
        if ($payloads === []) {
            return [];
        }

        return DrebedengiNormalizer::listOfArrays($this->transport->call('setSourceList', [$payloads]));
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

    /**
     * @param list<int|string> $ids
     * @return list<string>
     */
    private function normalizeIds(array $ids): array
    {
        $normalized = [];
        $seen = [];

        foreach ($ids as $id) {
            $id = (string)DrebedengiNormalizer::positiveIntegerId($id, 'Source ID');
            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $normalized[] = $id;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, bool|string|null>
     */
    private function normalizeUpdatePatch(array $fields): array
    {
        if ($fields === []) {
            throw new InvalidArgumentException('Cannot update a Drebedengi source without fields.');
        }

        $mutable = ['name', 'parent_id', 'is_hidden', 'sort', 'description'];
        $ignored = ['id', 'server_id', 'budget_family_id', 'family_id', 'type', 'is_for_duty'];
        $unsupported = array_values(array_diff(array_keys($fields), array_merge($mutable, $ignored)));
        if ($unsupported !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported Drebedengi source update field(s): %s.',
                implode(', ', $unsupported),
            ));
        }

        $patch = [];
        foreach ($fields as $field => $value) {
            switch ($field) {
                case 'name':
                    if (!is_string($value)) {
                        throw new InvalidArgumentException('Source name must be a string.');
                    }
                    $patch[$field] = $this->normalizeName($value);
                    break;
                case 'parent_id':
                    if ($value !== null && !is_int($value) && !is_string($value)) {
                        throw new InvalidArgumentException(
                            'Source field "parent_id" must be a positive integer ID, -1, or null.',
                        );
                    }
                    $patch[$field] = $this->normalizeParentId($value);
                    break;
                case 'is_hidden':
                    if (!is_bool($value)) {
                        throw new InvalidArgumentException('Source field "is_hidden" must be a boolean.');
                    }
                    $patch[$field] = $value;
                    break;
                case 'sort':
                    $patch[$field] = $this->normalizeIntegerString($value, 'Source field "sort"');
                    break;
                case 'description':
                    if ($value !== null && !is_string($value)) {
                        throw new InvalidArgumentException('Source field "description" must be a string or null.');
                    }
                    $patch[$field] = $value;
                    break;
                case 'id':
                case 'server_id':
                case 'budget_family_id':
                case 'family_id':
                case 'type':
                case 'is_for_duty':
                    break;
            }
        }

        if ($patch === []) {
            throw new InvalidArgumentException('Cannot update a Drebedengi source without mutable fields.');
        }

        return $patch;
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Source name cannot be empty.');
        }

        $length = preg_match_all('/./us', $name);
        if ($length === false) {
            throw new InvalidArgumentException('Source name must be valid UTF-8.');
        }
        if ($length > 128) {
            throw new InvalidArgumentException('Source name must not exceed 128 characters.');
        }

        return $name;
    }

    private function normalizeParentId(int|string|null $parentId): string
    {
        if ($parentId === null) {
            return '-1';
        }

        $value = trim((string)$parentId);
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || ($integer !== -1 && $integer <= 0)) {
            throw new InvalidArgumentException(
                'Source field "parent_id" must be a positive integer ID, -1, or null.',
            );
        }

        return (string)$integer;
    }

    private function normalizeIntegerString(mixed $value, string $context): string
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^-?\d+$/D', trim((string)$value)) !== 1) {
            throw new InvalidArgumentException(sprintf('%s must be an integer.', $context));
        }

        return trim((string)$value);
    }

    private function assertFullAccess(string $operation): void
    {
        if ((new AccountService($this->transport))->rightAccess() !== '0') {
            throw new InvalidArgumentException(sprintf(
                'Drebedengi source %s require full account access; limited access masks required fields.',
                $operation,
            ));
        }
    }

    private function sourceForUpdate(string $serverId): Source
    {
        $source = $this->find($serverId);
        if ($source instanceof Source) {
            return $source;
        }

        throw new InvalidArgumentException(sprintf(
            'Drebedengi source %s was not found before update.',
            $serverId,
        ));
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function nullableRawString(array $raw, string $field): ?string
    {
        if (!array_key_exists($field, $raw) || $raw[$field] === null) {
            return null;
        }
        if (!is_scalar($raw[$field])) {
            throw new UnexpectedResponseException(sprintf(
                'Drebedengi source response contains non-scalar field "%s".',
                $field,
            ));
        }

        return (string)$raw[$field];
    }

    /**
     * getSourceList HTML-escapes text. Decode values echoed unchanged from the
     * fetched object so read-modify-write does not double-escape them.
     *
     * @param array<string, bool|string|null> $patch
     * @param array<string, mixed> $raw
     * @return array<string, bool|string|null>
     */
    private function decodeEchoedTextPatch(array $patch, array $raw): array
    {
        foreach (['name', 'description'] as $field) {
            $value = $patch[$field] ?? null;
            if (!is_string($value)) {
                continue;
            }

            $rawValue = $this->nullableRawString($raw, $field);
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
}
