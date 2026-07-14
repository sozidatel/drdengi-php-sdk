<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

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
     * @param array<string, mixed> $fields
     * @return list<array<string, mixed>>
     */
    public function update(string|int $serverId, array $fields): array
    {
        $payload = array_replace($fields, ['server_id' => (string)$serverId]);

        return DrebedengiNormalizer::listOfArrays($this->transport->call('setPlaceList', [[$payload]]));
    }

    public function delete(string|int $id): bool
    {
        return (int)$this->transport->call('deleteObject', [(int)$id, DeleteObjectType::Object->value]) === 1;
    }

    /**
     * @param list<int|string> $ids
     * @return list<string>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_map(static fn (int|string $id): string => (string)$id, $ids));
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
