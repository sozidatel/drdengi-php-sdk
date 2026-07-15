<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
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

    public function testByIdsReturnsEmptyListWithoutSoapCallForEmptyIds(): void
    {
        $transport = new FakeTransport();
        $service = new PlaceService($transport);

        self::assertSame([], $service->byIds([]));
        self::assertSame([], $transport->calls);
    }

    public function testUpdateReadsCurrentPlaceAndSendsCompletePayload(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getPlaceList' => [[
                'id' => '10',
                'budget_family_id' => '7',
                'type' => '4',
                'parent_id' => '-3',
                'name' => 'Old name',
                'is_hidden' => 't',
                'is_for_duty' => 'f',
                'sort' => '12',
                'purse_of_nuid' => '42',
                'icon_id' => '8',
                'is_autohide' => 't',
                'description' => 'Keep this description',
                'is_credit_card' => 'f',
            ]],
            'setPlaceList' => [['server_id' => '10', 'status' => 'updated']],
        ]);
        $service = new PlaceService($transport);

        $result = $service->update('10', [
            'server_id' => '20',
            'name' => '  Cash  ',
        ]);

        self::assertSame([['server_id' => '10', 'status' => 'updated']], $result);
        self::assertSame('getRightAccess', $transport->calls[0]['method']);
        self::assertSame('getPlaceList', $transport->calls[1]['method']);
        self::assertSame([['10']], $transport->calls[1]['arguments']);
        self::assertSame('setPlaceList', $transport->calls[2]['method']);
        self::assertSame([
            'server_id' => '10',
            'name' => 'Cash',
            'parent_id' => '-3',
            'type' => 4,
            'is_hidden' => true,
            'is_for_duty' => false,
            'sort' => '12',
            'purse_of_nuid' => '42',
            'icon_id' => '8',
            'is_autohide' => true,
            'description' => 'Keep this description',
            'is_credit_card' => false,
        ], $transport->mapListArgument(2)[0]);
    }

    public function testUpdateNormalizesMutablePlaceFields(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getPlaceList' => [[
                'id' => '10',
                'type' => '4',
                'parent_id' => '5',
                'name' => 'Old name',
                'is_hidden' => 't',
                'sort' => '12',
            ]],
            'setPlaceList' => [['server_id' => '10']],
        ]);

        (new PlaceService($transport))->update('10', [
            'parent_id' => null,
            'is_hidden' => false,
            'sort' => 20,
            'description' => null,
            'icon_id' => 9,
        ]);

        $payload = $transport->mapListArgument(2)[0];
        self::assertSame('-1', $payload['parent_id']);
        self::assertFalse($payload['is_hidden']);
        self::assertSame('20', $payload['sort']);
        self::assertNull($payload['description']);
        self::assertSame('9', $payload['icon_id']);
    }

    public function testUpdateRejectsInvalidPlaceIdBeforeSoapCall(): void
    {
        $transport = new FakeTransport();
        $service = new PlaceService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Place ID');

        try {
            $service->update('not-an-id', ['name' => 'Cash']);
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    public function testUpdateRequiresExistingPlaceBeforeWrite(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getPlaceList' => [],
        ]);
        $service = new PlaceService($transport);

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('place 10 was not found');

        try {
            $service->update('10', ['name' => 'Cash']);
        } finally {
            self::assertCount(2, $transport->calls);
            self::assertSame('getRightAccess', $transport->calls[0]['method']);
            self::assertSame('getPlaceList', $transport->calls[1]['method']);
        }
    }

    public function testUpdateRejectsLimitedAccessBeforeReadingPlace(): void
    {
        $transport = new FakeTransport(['getRightAccess' => '1']);
        $service = new PlaceService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('require full account access');

        try {
            $service->update('10', ['name' => 'Cash']);
        } finally {
            self::assertSame(['getRightAccess'], array_column($transport->calls, 'method'));
        }
    }

    public function testUpdateRejectsUnsupportedPlaceFieldsBeforeSoapCall(): void
    {
        $transport = new FakeTransport();
        $service = new PlaceService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported Drebedengi place update field(s): client_id.');

        try {
            $service->update('10', ['client_id' => 123]);
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    public function testUpdateRejectsFolderBeforeWrite(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getPlaceList' => [[
                'id' => '10',
                'type' => '9',
                'parent_id' => '-1',
                'name' => 'Folder',
            ]],
        ]);
        $service = new PlaceService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not an account');

        try {
            $service->update('10', ['name' => 'Renamed folder']);
        } finally {
            self::assertCount(2, $transport->calls);
        }
    }

    public function testUpdateRejectsCreditCardPlaceBeforeWrite(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getPlaceList' => [[
                'id' => '10',
                'type' => '4',
                'parent_id' => '-1',
                'name' => 'Credit card',
                'is_credit_card' => 't',
            ]],
        ]);
        $service = new PlaceService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be updated safely');

        try {
            $service->update('10', ['name' => 'Renamed card']);
        } finally {
            self::assertCount(2, $transport->calls);
        }
    }

    public function testUpdateRejectsServerManagedDutyPlaceBeforeWrite(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getPlaceList' => [[
                'id' => '10',
                'type' => '4',
                'parent_id' => '-1',
                'name' => 'Debt account',
                'is_for_duty' => 't',
            ]],
        ]);
        $service = new PlaceService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('server-managed');

        try {
            $service->update('10', ['name' => 'Renamed debt account']);
        } finally {
            self::assertSame(['getRightAccess', 'getPlaceList'], array_column($transport->calls, 'method'));
        }
    }

    public function testUpdateAcceptsLegacyRawPayloadWithoutDoubleEscapingText(): void
    {
        $raw = [
            'id' => '10',
            'budget_family_id' => '7',
            'family_id' => '7',
            'type' => '4',
            'parent_id' => '5',
            'name' => 'A &amp; B',
            'is_hidden' => 'f',
            'is_for_duty' => 'f',
            'sort' => '12',
            'purse_of_nuid' => '42',
            'icon_id' => '8',
            'is_autohide' => 't',
            'description' => 'x &quot;y&quot; &lt;z&gt;',
            'is_credit_card' => 'f',
        ];
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getPlaceList' => [$raw],
            'setPlaceList' => [['server_id' => '10']],
        ]);

        (new PlaceService($transport))->update('10', $raw);

        $payload = $transport->mapListArgument(2)[0];
        self::assertSame('A & B', $payload['name']);
        self::assertSame('x "y" <z>', $payload['description']);
        self::assertSame('10', $payload['server_id']);
        self::assertSame(4, $payload['type']);
        self::assertFalse($payload['is_for_duty']);
        self::assertSame('42', $payload['purse_of_nuid']);
        self::assertTrue($payload['is_autohide']);
    }

    public function testDeletesPlaceAsObject(): void
    {
        $transport = new FakeTransport(['deleteObject' => '1']);
        $service = new PlaceService($transport);

        self::assertTrue($service->delete('10'));
        self::assertSame('deleteObject', $transport->calls[0]['method']);
        self::assertSame([10, 'object'], $transport->calls[0]['arguments']);
    }

    public function testDeleteRejectsMalformedPlaceResponse(): void
    {
        $service = new PlaceService(new FakeTransport(['deleteObject' => 'broken']));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('deleteObject response for place is not an integer');

        $service->delete('10');
    }

    public function testDeleteRejectsUnknownPlaceStatus(): void
    {
        $service = new PlaceService(new FakeTransport(['deleteObject' => -1]));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('must be 0 or 1');

        $service->delete('10');
    }

    public function testDeleteRejectsInvalidPlaceIdBeforeSoapCall(): void
    {
        $transport = new FakeTransport();
        $service = new PlaceService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Place ID');

        try {
            $service->delete('not-an-id');
        } finally {
            self::assertSame([], $transport->calls);
        }
    }
}
