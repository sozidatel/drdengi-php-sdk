<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Model\DeleteObjectType;
use Soz\Drebedengi\Model\Place;
use Soz\Drebedengi\Model\PlaceNode;
use Soz\Drebedengi\Support\DrebedengiNormalizer;
use Soz\Drebedengi\Transport\TransportInterface;

final readonly class PlaceService
{
    public function __construct(private TransportInterface $transport)
    {
    }

    /**
     * @return list<Place>
     */
    public function list(): array
    {
        return $this->sort(array_map(Place::fromSoap(...), DrebedengiNormalizer::listOfArrays(
            $this->transport->call('getPlaceList'),
        )));
    }

    /**
     * @param list<int|string> $ids
     * @return list<Place>
     */
    public function byIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->sort(array_map(Place::fromSoap(...), DrebedengiNormalizer::listOfArrays(
            $this->transport->call('getPlaceList', [$this->normalizeIds($ids)]),
        )));
    }

    /**
     * @return list<Place>
     */
    public function accounts(bool $includeHidden = true): array
    {
        return array_values(array_filter($this->list(), static function (Place $place) use ($includeHidden): bool {
            return $place->isAccount() && ($includeHidden || !$place->hidden);
        }));
    }

    /**
     * @return list<Place>
     */
    public function folders(bool $includeHidden = true): array
    {
        return array_values(array_filter($this->list(), static function (Place $place) use ($includeHidden): bool {
            return $place->isFolder() && ($includeHidden || !$place->hidden);
        }));
    }

    /**
     * @return list<PlaceNode>
     */
    public function tree(bool $includeHidden = true): array
    {
        $places = $this->list();
        if (!$includeHidden) {
            $places = array_values(array_filter($places, static fn (Place $place): bool => !$place->hidden));
        }

        return $this->buildLevel($places, null, 0);
    }

    /**
     * Updates an account without dropping fields required by setPlaceList.
     * Supported patch fields: name, parent_id, is_hidden, sort, description, icon_id.
     *
     * @param array<string, mixed> $fields
     * @return list<array<string, mixed>>
     */
    public function update(string|int $serverId, array $fields): array
    {
        $serverId = DrebedengiNormalizer::positiveIntegerId($serverId, 'Place ID');
        $patch = $this->normalizeUpdatePatch($fields);
        $this->assertFullAccessForUpdate();
        $place = $this->placeForUpdate((string)$serverId);

        if (!$place->isAccount()) {
            throw new InvalidArgumentException(sprintf(
                'Drebedengi place %s is not an account and cannot be updated via setPlaceList.',
                $serverId,
            ));
        }
        if ($place->forDuty) {
            throw new InvalidArgumentException(sprintf(
                'Drebedengi duty place %s is server-managed and cannot be updated safely via setPlaceList.',
                $serverId,
            ));
        }
        // The legacy setPlaceList implementation forces is_credit_card=false on every write.
        if ($place->creditCard) {
            throw new InvalidArgumentException(sprintf(
                'Drebedengi credit-card place %s cannot be updated safely via setPlaceList.',
                $serverId,
            ));
        }

        $rawName = $this->nullableRawString($place->raw, 'name', 'place') ?? $place->name;
        $rawDescription = $this->nullableRawString($place->raw, 'description', 'place');
        $patch = $this->decodeEchoedTextPatch($patch, $place->raw, 'place');

        $payload = array_replace([
            'server_id' => (string)$serverId,
            'name' => $this->decodeSoapHtml($rawName),
            'parent_id' => $place->parentId ?? $place->systemParentId ?? '-1',
            'type' => $place->type->value,
            'is_hidden' => $place->hidden,
            'is_for_duty' => $place->forDuty,
            'sort' => $place->sort ?? '0',
            'purse_of_nuid' => $place->purseOfUserId,
            'icon_id' => $place->iconId,
            'is_autohide' => $place->autoHide,
            'description' => $rawDescription === null ? null : $this->decodeSoapHtml($rawDescription),
            'is_credit_card' => $place->creditCard,
        ], $patch);

        return DrebedengiNormalizer::listOfArrays($this->transport->call('setPlaceList', [[$payload]]));
    }

    public function delete(string|int $id): bool
    {
        $id = DrebedengiNormalizer::positiveIntegerId($id, 'Place ID');
        $response = $this->transport->call('deleteObject', [$id, DeleteObjectType::Object->value]);
        if (!is_int($response) && !is_string($response)) {
            throw new UnexpectedResponseException(sprintf(
                'Drebedengi deleteObject response for place must be an integer, got %s.',
                get_debug_type($response),
            ));
        }
        $status = filter_var(trim((string)$response), FILTER_VALIDATE_INT);
        if ($status === false) {
            throw new UnexpectedResponseException('Drebedengi deleteObject response for place is not an integer.');
        }
        if ($status !== 0 && $status !== 1) {
            throw new UnexpectedResponseException('Drebedengi deleteObject response for place must be 0 or 1.');
        }

        return $status === 1;
    }

    /**
     * @param list<int|string> $ids
     * @return list<string>
     */
    private function normalizeIds(array $ids): array
    {
        return array_map(static fn (int|string $id): string => (string)$id, $ids);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, bool|string|null>
     */
    private function normalizeUpdatePatch(array $fields): array
    {
        if ($fields === []) {
            throw new InvalidArgumentException('Cannot update a Drebedengi place without fields.');
        }

        $mutable = ['name', 'parent_id', 'is_hidden', 'sort', 'description', 'icon_id'];
        // Full objects from older call sites contain these server-managed fields.
        // They are accepted for compatibility but intentionally ignored.
        $ignored = [
            'id',
            'server_id',
            'budget_family_id',
            'family_id',
            'type',
            'is_for_duty',
            'purse_of_nuid',
            'is_autohide',
            'is_credit_card',
        ];
        $allowed = array_merge($mutable, $ignored);
        $unsupported = array_values(array_diff(array_keys($fields), $allowed));
        if ($unsupported !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported Drebedengi place update field(s): %s.',
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
                    $patch[$field] = $this->normalizeBoolean($value, 'Place field "is_hidden"');
                    break;
                case 'sort':
                    $patch[$field] = $this->normalizeIntegerString($value, 'Place field "sort"');
                    break;
                case 'description':
                    if ($value !== null && !is_string($value)) {
                        throw new InvalidArgumentException('Place field "description" must be a string or null.');
                    }
                    $patch[$field] = $value;
                    break;
                case 'icon_id':
                    if ($value !== null && !is_int($value) && !is_string($value)) {
                        throw new InvalidArgumentException('Place field "icon_id" must be a positive integer ID or null.');
                    }
                    $patch[$field] = $value === null
                        ? null
                        : (string)DrebedengiNormalizer::positiveIntegerId($value, 'Place field "icon_id"');
                    break;
                case 'server_id':
                case 'id':
                case 'budget_family_id':
                case 'family_id':
                case 'type':
                case 'is_for_duty':
                case 'purse_of_nuid':
                case 'is_autohide':
                case 'is_credit_card':
                    // The method argument is the authoritative object identity.
                    break;
            }
        }

        if ($patch === []) {
            throw new InvalidArgumentException('Cannot update a Drebedengi place without mutable fields.');
        }

        return $patch;
    }

    private function assertFullAccessForUpdate(): void
    {
        if ((new AccountService($this->transport))->rightAccess() !== '0') {
            throw new InvalidArgumentException(
                'Drebedengi place updates require full account access; limited access masks required fields.',
            );
        }
    }

    private function placeForUpdate(string $serverId): Place
    {
        foreach ($this->byIds([$serverId]) as $place) {
            if ($place->id === $serverId) {
                return $place;
            }
        }

        throw new UnexpectedResponseException(sprintf(
            'Drebedengi place %s was not found before update.',
            $serverId,
        ));
    }

    private function normalizeName(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Place name must be a string.');
        }

        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('Place name cannot be empty.');
        }

        return $value;
    }

    private function normalizeParentId(mixed $value): string
    {
        if ($value === null) {
            return '-1';
        }
        if (!is_int($value) && !is_string($value)) {
            throw new InvalidArgumentException('Place field "parent_id" must be a positive integer ID, -1, -3, or null.');
        }

        $value = trim((string)$value);
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || ($integer !== -1 && $integer !== (int)Place::SYSTEM_PARENT_HIDDEN_AMOUNTS && $integer <= 0)) {
            throw new InvalidArgumentException('Place field "parent_id" must be a positive integer ID, -1, -3, or null.');
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
     * @param list<Place> $places
     * @return list<Place>
     */
    private function sort(array $places): array
    {
        usort($places, static function (Place $a, Place $b): int {
            return [(int)($a->sort ?? 0), $a->name, $a->id] <=> [(int)($b->sort ?? 0), $b->name, $b->id];
        });

        return $places;
    }

    /**
     * @param list<Place> $places
     * @return list<PlaceNode>
     */
    private function buildLevel(array $places, ?string $parentId, int $depth): array
    {
        $nodes = [];
        foreach ($places as $place) {
            if ($place->parentId !== $parentId) {
                continue;
            }

            $nodes[] = new PlaceNode(
                place: $place,
                depth: $depth,
                children: $this->buildLevel($places, $place->id, $depth + 1),
            );
        }

        return $nodes;
    }

}
