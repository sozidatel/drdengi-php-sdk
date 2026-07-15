<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Service\CategoryService;
use Soz\Drebedengi\Tests\Support\FakeTransport;

final class CategoryServiceTest extends TestCase
{
    public function testListReturnsFlatCategoriesSortedBySort(): void
    {
        $service = new CategoryService(new FakeTransport([
            'getCategoryList' => [
                ['id' => '3', 'parent_id' => '-1', 'name' => 'Third', 'sort' => '30'],
                ['id' => '1', 'parent_id' => '-1', 'name' => 'First', 'sort' => '10'],
                ['id' => '2', 'parent_id' => '-1', 'name' => 'Second', 'sort' => '20'],
            ],
        ]));

        self::assertSame(['1', '2', '3'], array_map(static fn ($category): string => $category->id, $service->list()));
    }

    public function testBuildsTreeInSortOrder(): void
    {
        $service = new CategoryService(new FakeTransport([
            'getCategoryList' => [
                ['id' => '12', 'parent_id' => '10', 'name' => 'Child B', 'sort' => '12'],
                ['id' => '20', 'parent_id' => '-1', 'name' => 'Root B', 'sort' => '20'],
                ['id' => '11', 'parent_id' => '10', 'name' => 'Child A', 'sort' => '11'],
                ['id' => '10', 'parent_id' => '-1', 'name' => 'Root A', 'sort' => '10'],
            ],
        ]));

        $tree = $service->tree();

        self::assertCount(2, $tree);
        self::assertSame('10', $tree[0]->category->id);
        self::assertSame('20', $tree[1]->category->id);
        self::assertSame(['11', '12'], array_map(static fn ($node): string => $node->category->id, $tree[0]->children));
        self::assertSame(1, $tree[0]->children[0]->depth);
    }

    public function testOptionsAreInTreeOrderWithIndent(): void
    {
        $service = new CategoryService(new FakeTransport([
            'getCategoryList' => [
                ['id' => '10', 'parent_id' => '-1', 'name' => 'Root', 'sort' => '10'],
                ['id' => '11', 'parent_id' => '10', 'name' => 'Child', 'sort' => '11'],
            ],
        ]));

        $options = $service->options(indent: '..');

        self::assertSame('Root', $options[0]->label);
        self::assertSame('..Child', $options[1]->label);
        self::assertSame(1, $options[1]->depth);
    }

    public function testTreeCanExcludeHiddenCategories(): void
    {
        $service = new CategoryService(new FakeTransport([
            'getCategoryList' => [
                ['id' => '10', 'parent_id' => '-1', 'name' => 'Visible', 'sort' => '10', 'is_hidden' => 'f'],
                ['id' => '20', 'parent_id' => '-1', 'name' => 'Hidden', 'sort' => '20', 'is_hidden' => 't'],
            ],
        ]));

        $tree = $service->tree(includeHidden: false);

        self::assertCount(1, $tree);
        self::assertSame('Visible', $tree[0]->category->name);
    }

    public function testByIdsReturnsEmptyListWithoutSoapCallForEmptyIds(): void
    {
        $transport = new FakeTransport();
        $service = new CategoryService($transport);

        self::assertSame([], $service->byIds([]));
        self::assertSame([], $transport->calls);
    }

    public function testCreatesCategoryViaSetCategoryList(): void
    {
        $transport = new FakeTransport([
            'setCategoryList' => [
                ['server_id' => '101', 'client_id' => '123'],
            ],
            'getCategoryList' => [
                ['id' => '101', 'parent_id' => '10', 'name' => 'Food', 'sort' => '20', 'is_hidden' => 't'],
            ],
        ]);
        $service = new CategoryService($transport);

        $category = $service->create(
            name: 'Food',
            parentId: '10',
            hidden: true,
        );

        self::assertSame('101', $category->id);
        self::assertSame('Food', $category->name);
        self::assertSame('10', $category->parentId);
        self::assertTrue($category->hidden);
        self::assertSame('setCategoryList', $transport->calls[0]['method']);

        $payload = $transport->mapListArgument(0)[0];
        self::assertIsInt($payload['client_id']);
        self::assertSame('Food', $payload['name']);
        self::assertSame('10', $payload['parent_id']);
        self::assertSame(3, $payload['type']);
        self::assertTrue($payload['is_hidden']);
        self::assertFalse($payload['is_for_duty']);
        self::assertSame('0', $payload['sort']);
        self::assertSame('getCategoryList', $transport->calls[1]['method']);
        self::assertSame([['101']], $transport->calls[1]['arguments']);
    }

    public function testCreateCategoryUsesRootParentByDefault(): void
    {
        $transport = new FakeTransport([
            'setCategoryList' => [['server_id' => '101']],
            'getCategoryList' => [['id' => '101', 'parent_id' => '-1', 'name' => 'Food']],
        ]);
        $service = new CategoryService($transport);

        $service->create('Food');

        $payload = $transport->mapListArgument(0)[0];
        self::assertSame('-1', $payload['parent_id']);
        self::assertFalse($payload['is_hidden']);
        self::assertSame('0', $payload['sort']);
    }

    public function testCannotCreateCategoryWithoutName(): void
    {
        $service = new CategoryService(new FakeTransport());

        $this->expectException(InvalidArgumentException::class);

        $service->create('   ');
    }

    public function testCreateCategoryRequiresServerIdInResponse(): void
    {
        $service = new CategoryService(new FakeTransport(['setCategoryList' => [[]]]));

        $this->expectException(UnexpectedResponseException::class);

        $service->create('Food');
    }

    public function testCreateCategoryRequiresCreatedCategoryToBeReadable(): void
    {
        $service = new CategoryService(new FakeTransport([
            'setCategoryList' => [['server_id' => '101']],
            'getCategoryList' => [],
        ]));

        $this->expectException(UnexpectedResponseException::class);

        $service->create('Food');
    }

    public function testUpdateReadsCurrentCategoryAndSendsCompletePayload(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getCategoryList' => [[
                'id' => '10',
                'parent_id' => '5',
                'budget_family_id' => '7',
                'type' => '3',
                'name' => 'Old name',
                'is_hidden' => 't',
                'sort' => '12',
                'description' => 'Keep this description',
            ]],
            'setCategoryList' => [['server_id' => '10', 'status' => 'updated']],
        ]);
        $service = new CategoryService($transport);

        $result = $service->update('10', [
            'server_id' => '999',
            'name' => '  Food  ',
        ]);

        self::assertSame([['server_id' => '10', 'status' => 'updated']], $result);
        self::assertSame('getRightAccess', $transport->calls[0]['method']);
        self::assertSame('getCategoryList', $transport->calls[1]['method']);
        self::assertSame([['10']], $transport->calls[1]['arguments']);
        self::assertSame('setCategoryList', $transport->calls[2]['method']);
        self::assertSame([
            'server_id' => '10',
            'name' => 'Food',
            'parent_id' => '5',
            'type' => 3,
            'is_hidden' => true,
            'is_for_duty' => false,
            'sort' => '12',
            'description' => 'Keep this description',
        ], $transport->mapListArgument(2)[0]);
    }

    public function testUpdateNormalizesMutableCategoryFields(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getCategoryList' => [[
                'id' => '10',
                'parent_id' => '5',
                'name' => 'Old name',
                'is_hidden' => 't',
                'sort' => '12',
                'description' => 'Old description',
            ]],
            'setCategoryList' => [['server_id' => '10']],
        ]);

        (new CategoryService($transport))->update('10', [
            'parent_id' => null,
            'is_hidden' => false,
            'sort' => 20,
            'description' => null,
        ]);

        $payload = $transport->mapListArgument(2)[0];
        self::assertSame('-1', $payload['parent_id']);
        self::assertFalse($payload['is_hidden']);
        self::assertSame('20', $payload['sort']);
        self::assertNull($payload['description']);
    }

    public function testUpdateRejectsInvalidCategoryIdBeforeSoapCall(): void
    {
        $transport = new FakeTransport();
        $service = new CategoryService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Category ID');

        try {
            $service->update('not-an-id', ['name' => 'Food']);
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    public function testUpdateRequiresExistingCategoryBeforeWrite(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getCategoryList' => [],
        ]);
        $service = new CategoryService($transport);

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('category 10 was not found');

        try {
            $service->update('10', ['name' => 'Food']);
        } finally {
            self::assertCount(2, $transport->calls);
            self::assertSame('getRightAccess', $transport->calls[0]['method']);
            self::assertSame('getCategoryList', $transport->calls[1]['method']);
        }
    }

    public function testUpdateRejectsLimitedAccessBeforeReadingCategory(): void
    {
        $transport = new FakeTransport(['getRightAccess' => '1']);
        $service = new CategoryService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('require full account access');

        try {
            $service->update('10', ['name' => 'Food']);
        } finally {
            self::assertSame(['getRightAccess'], array_column($transport->calls, 'method'));
        }
    }

    public function testUpdateRejectsUnsupportedCategoryFieldsBeforeSoapCall(): void
    {
        $transport = new FakeTransport();
        $service = new CategoryService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported Drebedengi category update field(s): client_id.');

        try {
            $service->update('10', ['client_id' => 123]);
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    public function testUpdateRejectsNonBooleanCategoryFlagBeforeSoapCall(): void
    {
        $transport = new FakeTransport();
        $service = new CategoryService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('field "is_hidden" must be a boolean');

        try {
            $service->update('10', ['is_hidden' => 'broken']);
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    public function testUpdateRequiresMutableCategoryFields(): void
    {
        $transport = new FakeTransport();
        $service = new CategoryService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('without mutable fields');

        try {
            $service->update('10', ['server_id' => '20']);
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    public function testUpdateAcceptsLegacyRawPayloadWithoutDoubleEscapingText(): void
    {
        $raw = [
            'id' => '10',
            'parent_id' => '5',
            'budget_family_id' => '7',
            'family_id' => '7',
            'type' => '3',
            'name' => 'A &amp; B',
            'is_hidden' => 't',
            'is_for_duty' => 'f',
            'sort' => '12',
            'description' => 'x &quot;y&quot; &lt;z&gt; &#039;q&#039;',
        ];
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getCategoryList' => [$raw],
            'setCategoryList' => [['server_id' => '10']],
        ]);

        (new CategoryService($transport))->update('10', $raw);

        $payload = $transport->mapListArgument(2)[0];
        self::assertSame('A & B', $payload['name']);
        self::assertSame('x "y" <z> \'q\'', $payload['description']);
        self::assertSame('10', $payload['server_id']);
        self::assertSame(3, $payload['type']);
        self::assertFalse($payload['is_for_duty']);
    }

    public function testUpdateKeepsExplicitlyDifferentHtmlLookingTextLiteral(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getCategoryList' => [[
                'id' => '10',
                'parent_id' => '-1',
                'name' => 'Old &amp; name',
            ]],
            'setCategoryList' => [['server_id' => '10']],
        ]);

        (new CategoryService($transport))->update('10', ['name' => 'New &amp; literal']);

        self::assertSame('New &amp; literal', $transport->mapListArgument(2)[0]['name']);
    }

    public function testDeletesCategoryAsObject(): void
    {
        $transport = new FakeTransport(['deleteObject' => 1]);
        $service = new CategoryService($transport);

        self::assertTrue($service->delete('10'));
        self::assertSame('deleteObject', $transport->calls[0]['method']);
        self::assertSame([10, 'object'], $transport->calls[0]['arguments']);
    }

    public function testDeleteRejectsMalformedCategoryResponse(): void
    {
        $service = new CategoryService(new FakeTransport(['deleteObject' => ['unexpected']]));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('deleteObject response for category must be an integer');

        $service->delete('10');
    }

    public function testDeleteRejectsUnknownCategoryStatus(): void
    {
        $service = new CategoryService(new FakeTransport(['deleteObject' => 2]));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('must be 0 or 1');

        $service->delete('10');
    }

    public function testDeleteRejectsInvalidCategoryIdBeforeSoapCall(): void
    {
        $transport = new FakeTransport();
        $service = new CategoryService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Category ID');

        try {
            $service->delete('not-an-id');
        } finally {
            self::assertSame([], $transport->calls);
        }
    }
}
