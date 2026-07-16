<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Model\ReferenceWriteToken;
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

    public function testSourceExposesDescriptionInDtoAndJson(): void
    {
        $source = (new SourceService(new FakeTransport([
            'getSourceList' => [[
                'id' => '10',
                'parent_id' => '-1',
                'name' => 'Salary',
                'description' => 'Main income',
            ]],
        ])))->list()[0];

        self::assertSame('Main income', $source->description);
        self::assertSame('Main income', $source->jsonSerialize()['description']);
    }

    public function testByIdsValidatesDeduplicatesAndPassesIdsToSoap(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getSourceList' => [
                ['id' => '2', 'parent_id' => '-1', 'name' => 'Second', 'sort' => '20'],
                ['id' => '1', 'parent_id' => '-1', 'name' => 'First', 'sort' => '10'],
            ],
        ]);

        $sources = (new SourceService($transport))->byIds([2, '1', 2, 1]);

        self::assertSame(['1', '2'], array_map(static fn ($source): string => $source->id, $sources));
        self::assertSame([['2', '1']], $transport->calls[0]['arguments']);
    }

    public function testByIdsReturnsEmptyListWithoutSoapCall(): void
    {
        $transport = new FakeTransport();

        self::assertSame([], (new SourceService($transport))->byIds([]));
        self::assertSame([], $transport->calls);
    }

    public function testByIdsRejectsInvalidIdBeforeSoapCall(): void
    {
        $transport = new FakeTransport();
        $service = new SourceService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source ID must be a positive integer ID');

        try {
            $service->byIds(['0']);
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    public function testFindRequiresAnExactResponseId(): void
    {
        $transport = new FakeTransport([
            'getSourceList' => [
                ['id' => '11', 'parent_id' => '-1', 'name' => 'Another source'],
            ],
        ]);

        self::assertNull((new SourceService($transport))->find('10'));
        self::assertSame([['10']], $transport->calls[0]['arguments']);
    }

    public function testRequireRejectsMissingSource(): void
    {
        $service = new SourceService(new FakeTransport(['getSourceList' => []]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown source ID "10"');

        $service->require('10');
    }

    public function testCreateWritesCompletePayloadWithStableTokenAndReadsSourceBack(): void
    {
        $transport = new FakeTransport([
            'setSourceList' => [
                ['server_id' => '101', 'client_id' => '123'],
            ],
            'getSourceList' => [[
                'id' => '101',
                'parent_id' => '10',
                'name' => 'Salary',
                'sort' => '7',
                'is_hidden' => 't',
                'description' => 'Monthly salary',
            ]],
        ]);
        $service = new SourceService($transport);

        $source = $service->create(
            name: '  Salary  ',
            parentId: '10',
            hidden: true,
            sort: 7,
            description: 'Monthly salary',
            writeToken: ReferenceWriteToken::fromClientId(123),
        );

        self::assertSame('101', $source->id);
        self::assertSame('Monthly salary', $source->description);
        self::assertSame([
            'client_id' => 123,
            'name' => 'Salary',
            'parent_id' => '10',
            'type' => 2,
            'is_hidden' => true,
            'is_for_duty' => false,
            'sort' => '7',
            'description' => 'Monthly salary',
        ], $transport->mapListArgument(0)[0]);
        self::assertSame('getSourceList', $transport->calls[1]['method']);
        self::assertSame([['101']], $transport->calls[1]['arguments']);
    }

    public function testCreateUsesRootParentAndSafeDefaults(): void
    {
        $transport = new FakeTransport([
            'setSourceList' => [['server_id' => '101', 'client_id' => '123']],
            'getSourceList' => [['id' => '101', 'parent_id' => '-1', 'name' => 'Salary']],
        ]);

        (new SourceService($transport))->create(
            'Salary',
            writeToken: ReferenceWriteToken::fromClientId(123),
        );

        self::assertSame([
            'client_id' => 123,
            'name' => 'Salary',
            'parent_id' => '-1',
            'type' => 2,
            'is_hidden' => false,
            'is_for_duty' => false,
            'sort' => '0',
            'description' => null,
        ], $transport->mapListArgument(0)[0]);
    }

    public function testCreateRejectsEmptyAndOverlongNamesBeforeSoapCall(): void
    {
        $transport = new FakeTransport();
        $service = new SourceService($transport);

        foreach (['   ', str_repeat('я', 129)] as $name) {
            try {
                $service->create($name);
                self::fail('Invalid source name was accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $transport->calls);
            }
        }
    }

    public function testCreateRejectsInvalidParentBeforeSoapCall(): void
    {
        $transport = new FakeTransport();
        $service = new SourceService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('parent_id');

        try {
            $service->create('Salary', parentId: 0);
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    public function testCreateRequiresResponseMappingForItsClientId(): void
    {
        $service = new SourceService(new FakeTransport([
            'setSourceList' => [['server_id' => '101', 'client_id' => '999']],
        ]));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('does not map client_id 123');

        $service->create('Salary', writeToken: ReferenceWriteToken::fromClientId(123));
    }

    public function testCreateRequiresCreatedSourceToBeReadable(): void
    {
        $service = new SourceService(new FakeTransport([
            'setSourceList' => [['server_id' => '101', 'client_id' => '123']],
            'getSourceList' => [],
        ]));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('was not found by server id 101');

        $service->create('Salary', writeToken: ReferenceWriteToken::fromClientId(123));
    }

    public function testUpdateReadsCurrentStateWritesCompletePayloadAndReturnsReadBack(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getSourceList' => [
                '__sequence' => [
                    [[
                        'id' => '10',
                        'parent_id' => '5',
                        'budget_family_id' => '7',
                        'name' => 'Old &amp; name',
                        'is_hidden' => 't',
                        'sort' => '12',
                        'description' => 'Keep &quot;this&quot;',
                    ]],
                    [[
                        'id' => '10',
                        'parent_id' => '5',
                        'name' => 'New name',
                        'is_hidden' => 't',
                        'sort' => '12',
                        'description' => 'Keep &quot;this&quot;',
                    ]],
                ],
            ],
            'setSourceList' => [['server_id' => '10', 'status' => 'updated']],
        ]);

        $updated = (new SourceService($transport))->update('10', ['name' => '  New name  ']);

        self::assertSame('10', $updated->id);
        self::assertSame('New name', $updated->name);
        self::assertSame(
            ['getRightAccess', 'getSourceList', 'setSourceList', 'getSourceList'],
            array_column($transport->calls, 'method'),
        );
        self::assertSame([
            'server_id' => '10',
            'name' => 'New name',
            'parent_id' => '5',
            'type' => 2,
            'is_hidden' => true,
            'is_for_duty' => false,
            'sort' => '12',
            'description' => 'Keep "this"',
        ], $transport->mapListArgument(2)[0]);
    }

    public function testUpdateNormalizesAllMutableFields(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getSourceList' => [
                '__sequence' => [
                    [[
                        'id' => '10',
                        'parent_id' => '5',
                        'name' => 'Old name',
                        'is_hidden' => 't',
                        'sort' => '12',
                        'description' => 'Old description',
                    ]],
                    [[
                        'id' => '10',
                        'parent_id' => '-1',
                        'name' => 'Old name',
                        'is_hidden' => 'f',
                        'sort' => '20',
                    ]],
                ],
            ],
            'setSourceList' => [['server_id' => '10']],
        ]);

        (new SourceService($transport))->update('10', [
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

    public function testUpdateDecodesEchoedTextWithoutWeakeningBooleanInput(): void
    {
        $raw = [
            'id' => '10',
            'server_id' => '999',
            'parent_id' => '5',
            'budget_family_id' => '7',
            'family_id' => '7',
            'type' => '2',
            'name' => 'A &amp; B',
            'is_hidden' => true,
            'is_for_duty' => 'f',
            'sort' => '12',
            'description' => 'x &quot;y&quot; &lt;z&gt; &#039;q&#039;',
        ];
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getSourceList' => ['__sequence' => [[$raw], [$raw]]],
            'setSourceList' => [['server_id' => '10']],
        ]);

        (new SourceService($transport))->update('10', $raw);

        $payload = $transport->mapListArgument(2)[0];
        self::assertSame('10', $payload['server_id']);
        self::assertSame('A & B', $payload['name']);
        self::assertSame('x "y" <z> \'q\'', $payload['description']);
        self::assertSame(2, $payload['type']);
        self::assertFalse($payload['is_for_duty']);
    }

    public function testUpdateRejectsInvalidInputBeforeSoapCall(): void
    {
        $cases = [
            ['id' => 'broken', 'fields' => ['name' => 'Salary'], 'message' => 'Source ID'],
            ['id' => '10', 'fields' => [], 'message' => 'without fields'],
            ['id' => '10', 'fields' => ['client_id' => 1], 'message' => 'Unsupported'],
            ['id' => '10', 'fields' => ['is_hidden' => 't'], 'message' => 'must be a boolean'],
            ['id' => '10', 'fields' => ['server_id' => '20'], 'message' => 'without mutable fields'],
            ['id' => '10', 'fields' => ['name' => str_repeat('x', 129)], 'message' => '128 characters'],
        ];

        foreach ($cases as $case) {
            $transport = new FakeTransport();
            try {
                (new SourceService($transport))->update($case['id'], $case['fields']);
                self::fail(sprintf('Invalid update case "%s" was accepted.', $case['message']));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($case['message'], $exception->getMessage());
                self::assertSame([], $transport->calls);
            }
        }
    }

    public function testUpdateRejectsLimitedAccessBeforeReadingSource(): void
    {
        $transport = new FakeTransport(['getRightAccess' => '1']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('source updates require full account access');

        try {
            (new SourceService($transport))->update('10', ['name' => 'Salary']);
        } finally {
            self::assertSame(['getRightAccess'], array_column($transport->calls, 'method'));
        }
    }

    public function testUpdateRequiresExistingSourceBeforeWrite(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getSourceList' => [],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('source 10 was not found before update');

        try {
            (new SourceService($transport))->update('10', ['name' => 'Salary']);
        } finally {
            self::assertSame(['getRightAccess', 'getSourceList'], array_column($transport->calls, 'method'));
        }
    }

    public function testUpdateRequiresUpdatedSourceReadBack(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getSourceList' => [
                '__sequence' => [
                    [['id' => '10', 'parent_id' => '-1', 'name' => 'Old']],
                    [],
                ],
            ],
            'setSourceList' => [['server_id' => '10']],
        ]);

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('not found during read-back');

        (new SourceService($transport))->update('10', ['name' => 'New']);
    }

    public function testUpdateRequiresResponseToConfirmTargetServerId(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getSourceList' => [['id' => '10', 'parent_id' => '-1', 'name' => 'Old']],
            'setSourceList' => [['server_id' => '11']],
        ]);

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('does not confirm updated source server_id 10');

        try {
            (new SourceService($transport))->update('10', ['name' => 'New']);
        } finally {
            self::assertSame(
                ['getRightAccess', 'getSourceList', 'setSourceList'],
                array_column($transport->calls, 'method'),
            );
        }
    }

    public function testDeleteVerifiesExactSourceBeforeGenericObjectDeletion(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getSourceList' => [
                ['id' => '10', 'parent_id' => '-1', 'name' => 'Salary'],
            ],
            'deleteObject' => 1,
        ]);

        self::assertTrue((new SourceService($transport))->delete('10'));
        self::assertSame(
            ['getRightAccess', 'getSourceList', 'deleteObject'],
            array_column($transport->calls, 'method'),
        );
        self::assertSame([10, 'object'], $transport->calls[2]['arguments']);
    }

    public function testDeleteRejectsLimitedAccessBeforeReadingSource(): void
    {
        $transport = new FakeTransport(['getRightAccess' => '1']);
        $service = new SourceService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('source deletes require full account access');

        try {
            $service->delete('10');
        } finally {
            self::assertSame(['getRightAccess'], array_column($transport->calls, 'method'));
        }
    }

    public function testDeleteReturnsFalseForMissingOrCrossTypeObjectBeforeDeleteCall(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            // A generic object lookup could return another ID/type. The source
            // endpoint must confirm this exact ID before deleteObject('object').
            'getSourceList' => [
                ['id' => '11', 'parent_id' => '-1', 'name' => 'Different source'],
            ],
            'deleteObject' => 1,
        ]);

        self::assertFalse((new SourceService($transport))->delete('10'));
        self::assertSame(['getRightAccess', 'getSourceList'], array_column($transport->calls, 'method'));
    }

    public function testDeleteValidatesIdAndResponse(): void
    {
        $transport = new FakeTransport();
        try {
            (new SourceService($transport))->delete('broken');
            self::fail('Invalid source ID was accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $transport->calls);
        }

        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getSourceList' => [['id' => '10', 'parent_id' => '-1', 'name' => 'Salary']],
            'deleteObject' => 2,
        ]);

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('must be 0 or 1');

        (new SourceService($transport))->delete('10');
    }

    public function testSavePayloadsRemainsRawEscapeHatchAndSkipsEmptyWrite(): void
    {
        $transport = new FakeTransport([
            'setSourceList' => [['server_id' => '10', 'custom' => 'value']],
        ]);
        $service = new SourceService($transport);

        self::assertSame([], $service->savePayloads([]));
        self::assertSame([], $transport->calls);
        self::assertSame(
            [['server_id' => '10', 'custom' => 'value']],
            $service->savePayloads([['custom_field' => 'custom value']]),
        );
        self::assertSame([['custom_field' => 'custom value']], $transport->mapListArgument(0));
    }
}
