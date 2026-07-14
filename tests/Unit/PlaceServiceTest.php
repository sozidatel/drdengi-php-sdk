<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Service\PlaceService;
use Soz\Drebedengi\Tests\Support\FakeTransport;

final class PlaceServiceTest extends TestCase
{
    public function testListReturnsPlacesSortedBySort(): void
    {
        $service = new PlaceService(new FakeTransport([
            'getPlaceList' => [
                ['id' => '3', 'type' => '4', 'parent_id' => '-1', 'name' => 'Third', 'sort' => '30'],
                ['id' => '1', 'type' => '4', 'parent_id' => '-1', 'name' => 'First', 'sort' => '10'],
                ['id' => '2', 'type' => '4', 'parent_id' => '-1', 'name' => 'Second', 'sort' => '20'],
            ],
        ]));

        self::assertSame(['1', '2', '3'], array_map(static fn ($place): string => $place->id, $service->list()));
    }

    public function testBuildsTreeWithFoldersAndAccounts(): void
    {
        $service = new PlaceService(new FakeTransport([
            'getPlaceList' => [
                ['id' => '12', 'type' => '4', 'parent_id' => '10', 'name' => 'Account B', 'sort' => '12'],
                ['id' => '20', 'type' => '4', 'parent_id' => '-1', 'name' => 'Root Account', 'sort' => '20'],
                ['id' => '11', 'type' => '4', 'parent_id' => '10', 'name' => 'Account A', 'sort' => '11'],
                ['id' => '10', 'type' => '9', 'parent_id' => '-1', 'name' => 'Folder', 'sort' => '10'],
            ],
        ]));

        $tree = $service->tree();

        self::assertCount(2, $tree);
        self::assertSame('10', $tree[0]->place->id);
        self::assertTrue($tree[0]->place->isFolder());
        self::assertSame(['11', '12'], array_map(static fn ($node): string => $node->place->id, $tree[0]->children));
        self::assertSame('20', $tree[1]->place->id);
    }

    public function testFiltersAccountsAndFolders(): void
    {
        $service = new PlaceService(new FakeTransport([
            'getPlaceList' => [
                ['id' => '10', 'type' => '9', 'parent_id' => '-1', 'name' => 'Folder', 'sort' => '10'],
                ['id' => '11', 'type' => '4', 'parent_id' => '10', 'name' => 'Account', 'sort' => '11'],
            ],
        ]));

        self::assertSame(['11'], array_map(static fn ($place): string => $place->id, $service->accounts()));
        self::assertSame(['10'], array_map(static fn ($place): string => $place->id, $service->folders()));
    }

    public function testUpdateForcesServerIdFromMethodArgument(): void
    {
        $transport = new FakeTransport(['setPlaceList' => [['server_id' => '10']]]);
        $service = new PlaceService($transport);

        $service->update('10', [
            'server_id' => '20',
            'name' => 'Cash',
        ]);

        self::assertSame('10', $transport->calls[0]['arguments'][0][0]['server_id']);
        self::assertSame('Cash', $transport->calls[0]['arguments'][0][0]['name']);
    }
}
