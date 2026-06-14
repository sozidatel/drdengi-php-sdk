<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Service\SourceService;
use Soz\Drebedengi\Tests\Support\FakeTransport;

final class SourceServiceTest extends TestCase
{
    public function testListReturnsFlatSourcesSortedBySort(): void
    {
        $service = new SourceService(new FakeTransport([
            'getSourceList' => [
                ['id' => '3', 'parent_id' => '-1', 'name' => 'Third', 'sort' => '30'],
                ['id' => '1', 'parent_id' => '-1', 'name' => 'First', 'sort' => '10'],
                ['id' => '2', 'parent_id' => '-1', 'name' => 'Second', 'sort' => '20'],
            ],
        ]));

        self::assertSame(['1', '2', '3'], array_map(static fn ($source): string => $source->id, $service->list()));
    }

    public function testBuildsTreeInSortOrder(): void
    {
        $service = new SourceService(new FakeTransport([
            'getSourceList' => [
                ['id' => '12', 'parent_id' => '10', 'name' => 'Child B', 'sort' => '12'],
                ['id' => '20', 'parent_id' => '-1', 'name' => 'Root B', 'sort' => '20'],
                ['id' => '11', 'parent_id' => '10', 'name' => 'Child A', 'sort' => '11'],
                ['id' => '10', 'parent_id' => '-1', 'name' => 'Root A', 'sort' => '10'],
            ],
        ]));

        $tree = $service->tree();

        self::assertCount(2, $tree);
        self::assertSame('10', $tree[0]->source->id);
        self::assertSame('20', $tree[1]->source->id);
        self::assertSame(['11', '12'], array_map(static fn ($node): string => $node->source->id, $tree[0]->children));
    }

    public function testOptionsAreInTreeOrderWithIndent(): void
    {
        $service = new SourceService(new FakeTransport([
            'getSourceList' => [
                ['id' => '10', 'parent_id' => '-1', 'name' => 'Root', 'sort' => '10'],
                ['id' => '11', 'parent_id' => '10', 'name' => 'Child', 'sort' => '11'],
            ],
        ]));

        $options = $service->options(indent: '..');

        self::assertSame('Root', $options[0]->label);
        self::assertSame('..Child', $options[1]->label);
        self::assertSame(1, $options[1]->depth);
    }

    public function testTreeCanExcludeHiddenSources(): void
    {
        $service = new SourceService(new FakeTransport([
            'getSourceList' => [
                ['id' => '10', 'parent_id' => '-1', 'name' => 'Visible', 'sort' => '10', 'is_hidden' => 'f'],
                ['id' => '20', 'parent_id' => '-1', 'name' => 'Hidden', 'sort' => '20', 'is_hidden' => 't'],
            ],
        ]));

        $tree = $service->tree(includeHidden: false);

        self::assertCount(1, $tree);
        self::assertSame('Visible', $tree[0]->source->name);
    }
}
