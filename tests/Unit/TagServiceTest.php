<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Model\ReferenceWriteToken;
use Soz\Drebedengi\Service\TagService;
use Soz\Drebedengi\Tests\Support\FakeTransport;

final class TagServiceTest extends TestCase
{
    public function testListReturnsTypedTagsSortedBySort(): void
    {
        $service = new TagService(new FakeTransport([
            'getTagList' => [
                [
                    'id' => '3',
                    'parent_id' => '-1',
                    'family_id' => '7',
                    'user_id' => '42',
                    'name' => 'Third',
                    'is_hidden' => 't',
                    'is_family' => 'f',
                    'sort' => '30',
                ],
                ['id' => '1', 'parent_id' => '-1', 'name' => 'First', 'sort' => '10'],
                ['id' => '2', 'parent_id' => '-1', 'name' => 'Second', 'sort' => '20'],
            ],
        ]));

        $tags = $service->list();

        self::assertSame(['1', '2', '3'], array_map(static fn ($tag): string => $tag->id, $tags));
        self::assertSame('7', $tags[2]->familyId);
        self::assertSame('42', $tags[2]->userId);
        self::assertTrue($tags[2]->hidden);
        self::assertFalse($tags[2]->family);
        self::assertSame('42', $tags[2]->jsonSerialize()['userId']);
    }

    public function testByIdsValidatesDeduplicatesAndSendsIds(): void
    {
        $transport = new FakeTransport([
            'getTagList' => [
                ['id' => '1', 'parent_id' => '-1', 'name' => 'First', 'sort' => '10'],
                ['id' => '2', 'parent_id' => '-1', 'name' => 'Second', 'sort' => '20'],
            ],
        ]);
        $service = new TagService($transport);

        self::assertSame(['1', '2'], array_map(static fn ($tag): string => $tag->id, $service->byIds([2, '1', 2])));
        self::assertSame([['2', '1']], $transport->calls[0]['arguments']);
    }

    public function testByIdsReturnsEmptyListWithoutSoapCall(): void
    {
        $transport = new FakeTransport();

        self::assertSame([], (new TagService($transport))->byIds([]));
        self::assertSame([], $transport->calls);
    }

    public function testByIdsRejectsInvalidIdBeforeSoapCall(): void
    {
        $transport = new FakeTransport();
        $service = new TagService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag ID');

        try {
            $service->byIds(['not-an-id']);
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    public function testFindAndRequireUseExactServerId(): void
    {
        $transport = new FakeTransport([
            'getTagList' => ['__sequence' => [
                [['id' => '10', 'parent_id' => '-1', 'name' => 'Found']],
                [],
            ]],
        ]);
        $service = new TagService($transport);

        self::assertSame('Found', $service->find('10')?->name);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tag ID "11"');

        $service->require('11');
    }

    public function testBuildsTreeAndOptionsInSortOrder(): void
    {
        $service = new TagService(new FakeTransport([
            'getTagList' => [
                ['id' => '11', 'parent_id' => '10', 'name' => 'Child', 'sort' => '20'],
                ['id' => '10', 'parent_id' => '-1', 'name' => 'Root', 'sort' => '10'],
                ['id' => '12', 'parent_id' => '-1', 'name' => 'Hidden', 'sort' => '30', 'is_hidden' => 't'],
            ],
        ]));

        $tree = $service->tree(includeHidden: false);
        self::assertCount(1, $tree);
        self::assertSame('10', $tree[0]->tag->id);
        self::assertSame('11', $tree[0]->children[0]->tag->id);
        self::assertSame(1, $tree[0]->children[0]->depth);

        $options = $service->options(includeHidden: false, indent: '..');
        self::assertSame(['Root', '..Child'], array_map(static fn ($option): string => $option->label, $options));
        self::assertSame('11', $options[1]->tag->id);
    }

    public function testCreateUsesReusableTokenAndReadsTagBack(): void
    {
        $transport = new FakeTransport([
            'setTagList' => [[
                'server_id' => '101',
                'client_id' => '123',
                'status' => 'inserted',
            ]],
            'getTagList' => [[
                'id' => '101',
                'parent_id' => '10',
                'family_id' => '7',
                'user_id' => '42',
                'name' => 'Travel',
                'is_hidden' => 't',
                'is_family' => 't',
                'sort' => '20',
            ]],
        ]);
        $service = new TagService($transport);

        $tag = $service->create(
            name: '  Travel  ',
            parentId: '10',
            hidden: true,
            family: true,
            sort: 20,
            writeToken: ReferenceWriteToken::fromClientId(123),
        );

        self::assertSame('101', $tag->id);
        self::assertSame('Travel', $tag->name);
        self::assertSame('10', $tag->parentId);
        self::assertTrue($tag->hidden);
        self::assertTrue($tag->family);
        self::assertSame([
            'client_id' => 123,
            'name' => 'Travel',
            'parent_id' => '10',
            'is_hidden' => true,
            'is_family' => true,
            'sort' => '20',
        ], $transport->mapListArgument(0)[0]);
        self::assertSame('getTagList', $transport->calls[1]['method']);
        self::assertSame([['101']], $transport->calls[1]['arguments']);
    }

    public function testCreateUsesRootDefaults(): void
    {
        $transport = new FakeTransport([
            'setTagList' => [[
                'server_id' => '101',
                'client_id' => '123',
            ]],
            'getTagList' => [['id' => '101', 'parent_id' => '-1', 'name' => 'Travel']],
        ]);

        (new TagService($transport))->create(
            'Travel',
            writeToken: ReferenceWriteToken::fromClientId(123),
        );

        self::assertSame([
            'client_id' => 123,
            'name' => 'Travel',
            'parent_id' => '-1',
            'is_hidden' => false,
            'is_family' => false,
            'sort' => '0',
        ], $transport->mapListArgument(0)[0]);
    }

    public function testCreateRequiresClientServerMapping(): void
    {
        $service = new TagService(new FakeTransport([
            'setTagList' => [['server_id' => '101']],
        ]));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('server_id/client_id mapping');

        $service->create('Travel', writeToken: ReferenceWriteToken::fromClientId(123));
    }

    #[DataProvider('invalidNames')]
    public function testCreateRejectsInvalidName(string $name, string $message): void
    {
        $transport = new FakeTransport();
        $service = new TagService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        try {
            $service->create($name);
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => ['   ', 'cannot be empty'];
        yield 'over 64 Unicode characters' => [str_repeat('я', 65), 'must not exceed 64 characters'];
    }

    public function testUpdateReadsCurrentTagWritesCompletePayloadAndReadsBack(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getUserIdByLogin' => '42',
            'getTagList' => ['__sequence' => [
                [[
                    'id' => '10',
                    'parent_id' => '5',
                    'family_id' => '7',
                    'user_id' => '42',
                    'name' => 'Old',
                    'is_hidden' => 'f',
                    'is_family' => 't',
                    'sort' => '12',
                ]],
                [[
                    'id' => '10',
                    'parent_id' => '-1',
                    'family_id' => '7',
                    'user_id' => '42',
                    'name' => 'New',
                    'is_hidden' => 't',
                    'is_family' => 'f',
                    'sort' => '20',
                ]],
            ]],
            'setTagList' => [['server_id' => '10', 'status' => 'updated']],
        ]);
        $service = new TagService($transport);

        $updated = $service->update('10', [
            'server_id' => '999',
            'name' => '  New  ',
            'parent_id' => null,
            'is_hidden' => true,
            'is_family' => false,
            'sort' => 20,
        ]);

        self::assertSame('New', $updated->name);
        self::assertSame([
            'server_id' => '10',
            'name' => 'New',
            'parent_id' => '-1',
            'is_hidden' => true,
            'is_family' => false,
            'sort' => '20',
        ], $transport->mapListArgument(3)[0]);
        self::assertSame(
            ['getRightAccess', 'getTagList', 'getUserIdByLogin', 'setTagList', 'getTagList'],
            array_column($transport->calls, 'method'),
        );
    }

    public function testUpdateRequiresResponseToConfirmTargetServerId(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getUserIdByLogin' => '42',
            'getTagList' => [[
                'id' => '10',
                'parent_id' => '-1',
                'user_id' => '42',
                'name' => 'Old',
            ]],
            'setTagList' => [['server_id' => '11']],
        ]);
        $service = new TagService($transport);

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('does not confirm tag server_id 10');

        try {
            $service->update('10', ['name' => 'New']);
        } finally {
            self::assertSame(
                ['getRightAccess', 'getTagList', 'getUserIdByLogin', 'setTagList'],
                array_column($transport->calls, 'method'),
            );
        }
    }

    public function testUpdateRejectsTagOwnedByAnotherFamilyUserBeforeWrite(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getUserIdByLogin' => '42',
            'getTagList' => [[
                'id' => '10',
                'parent_id' => '-1',
                'user_id' => '99',
                'name' => 'Shared',
            ]],
        ]);
        $service = new TagService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot update Drebedengi tag 10 owned by another family user');

        try {
            $service->update('10', ['name' => 'New']);
        } finally {
            self::assertSame(
                ['getRightAccess', 'getTagList', 'getUserIdByLogin'],
                array_column($transport->calls, 'method'),
            );
        }
    }

    public function testUpdateRejectsTagWithoutOwnerIdBeforeWrite(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getUserIdByLogin' => '42',
            'getTagList' => [[
                'id' => '10',
                'parent_id' => '-1',
                'name' => 'Unknown owner',
            ]],
        ]);
        $service = new TagService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('owner user ID is unavailable');

        try {
            $service->update('10', ['name' => 'New']);
        } finally {
            self::assertSame(
                ['getRightAccess', 'getTagList', 'getUserIdByLogin'],
                array_column($transport->calls, 'method'),
            );
        }
    }

    public function testUpdateRejectsNonBooleanFlagBeforeSoapCall(): void
    {
        $transport = new FakeTransport();
        $service = new TagService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('field "is_hidden" must be a boolean');

        try {
            $service->update('10', ['is_hidden' => 't']);
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    public function testUpdateRejectsUnsupportedFieldsBeforeSoapCall(): void
    {
        $transport = new FakeTransport();
        $service = new TagService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported Drebedengi tag update field(s): client_id.');

        try {
            $service->update('10', ['client_id' => 123]);
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    public function testUpdateRequiresExistingTagBeforeWrite(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getTagList' => [],
        ]);
        $service = new TagService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tag ID "10"');

        try {
            $service->update('10', ['name' => 'New']);
        } finally {
            self::assertSame(['getRightAccess', 'getTagList'], array_column($transport->calls, 'method'));
        }
    }

    public function testUpdateRejectsLimitedAccessBeforeReadingTag(): void
    {
        $transport = new FakeTransport(['getRightAccess' => '1']);
        $service = new TagService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('require full account access');

        try {
            $service->update('10', ['name' => 'New']);
        } finally {
            self::assertSame(['getRightAccess'], array_column($transport->calls, 'method'));
        }
    }

    public function testDeletePreflightsTagAndUsesTagDeleteType(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getUserIdByLogin' => '42',
            'getTagList' => [[
                'id' => '10',
                'parent_id' => '-1',
                'user_id' => '42',
                'name' => 'Travel',
            ]],
            'deleteObject' => '1',
        ]);
        $service = new TagService($transport);

        self::assertTrue($service->delete('10'));
        self::assertSame(
            ['getRightAccess', 'getTagList', 'getUserIdByLogin', 'deleteObject'],
            array_column($transport->calls, 'method'),
        );
        self::assertSame([10, 'tag'], $transport->calls[3]['arguments']);
    }

    public function testDeleteRejectsLimitedAccessBeforeReadingTag(): void
    {
        $transport = new FakeTransport(['getRightAccess' => '1']);
        $service = new TagService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tag deletes require full account access');

        try {
            $service->delete('10');
        } finally {
            self::assertSame(['getRightAccess'], array_column($transport->calls, 'method'));
        }
    }

    public function testDeleteRejectsTagOwnedByAnotherFamilyUserBeforeMutation(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getUserIdByLogin' => '42',
            'getTagList' => [[
                'id' => '10',
                'parent_id' => '-1',
                'user_id' => '99',
                'name' => 'Shared',
            ]],
        ]);
        $service = new TagService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot delete Drebedengi tag 10 owned by another family user');

        try {
            $service->delete('10');
        } finally {
            self::assertSame(
                ['getRightAccess', 'getTagList', 'getUserIdByLogin'],
                array_column($transport->calls, 'method'),
            );
        }
    }

    public function testDeleteRejectsTagWithoutOwnerIdBeforeMutation(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getUserIdByLogin' => '42',
            'getTagList' => [[
                'id' => '10',
                'parent_id' => '-1',
                'name' => 'Unknown owner',
            ]],
        ]);
        $service = new TagService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('owner user ID is unavailable');

        try {
            $service->delete('10');
        } finally {
            self::assertSame(
                ['getRightAccess', 'getTagList', 'getUserIdByLogin'],
                array_column($transport->calls, 'method'),
            );
        }
    }

    public function testDeleteReturnsFalseWithoutMutationWhenTagIsMissing(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getTagList' => [],
        ]);

        self::assertFalse((new TagService($transport))->delete('10'));
        self::assertSame(['getRightAccess', 'getTagList'], array_column($transport->calls, 'method'));
    }

    public function testDeleteRejectsMalformedResponse(): void
    {
        $service = new TagService(new FakeTransport([
            'getRightAccess' => '0',
            'getUserIdByLogin' => '42',
            'getTagList' => [[
                'id' => '10',
                'parent_id' => '-1',
                'user_id' => '42',
                'name' => 'Travel',
            ]],
            'deleteObject' => ['unexpected'],
        ]));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('deleteObject response for tag must be an integer');

        $service->delete('10');
    }

    public function testSavePayloadsReturnsEmptyWithoutSoapCall(): void
    {
        $transport = new FakeTransport();

        self::assertSame([], (new TagService($transport))->savePayloads([]));
        self::assertSame([], $transport->calls);
    }
}
