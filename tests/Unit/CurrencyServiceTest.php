<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\DrebedengiClient;
use Soz\Drebedengi\Exception\AmbiguousMutationException;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Model\ReferenceWriteToken;
use Soz\Drebedengi\Service\CurrencyService;
use Soz\Drebedengi\Tests\Support\FakeTransport;

final class CurrencyServiceTest extends TestCase
{
    public function testFindsCurrenciesAndCachesCatalogUntilRefresh(): void
    {
        $first = [
            ['id' => '3', 'name' => 'Euro', 'code' => 'EUR', 'ratio' => '1', 'is_default' => '1'],
            ['id' => '7', 'name' => 'Bitcoin', 'code' => 'BTC', 'ratio' => '1000000'],
        ];
        $second = [
            ['id' => '3', 'name' => 'Euro', 'code' => 'EUR', 'ratio' => '1', 'is_default' => '1'],
        ];
        $transport = new FakeTransport([
            'getCurrencyList' => ['__sequence' => [$first, $second]],
        ]);
        $service = new CurrencyService($transport);

        self::assertSame(8, $service->require('7')->decimalPlaces);
        self::assertSame('7', $service->requireByCode('btc')->id);
        self::assertSame('3', $service->default()?->id);
        self::assertCount(1, $transport->calls);

        self::assertCount(1, $service->refresh());
        self::assertNull($service->find('7'));
        self::assertCount(2, $transport->calls);
    }

    public function testClientSharesCurrencyCatalogAcrossServices(): void
    {
        $transport = new FakeTransport([
            'getCurrencyList' => [[
                'id' => '7',
                'name' => 'Bitcoin',
                'code' => 'BTC',
                'ratio' => '1000000',
            ]],
            'getBalance' => [[
                'place_id' => '1',
                'currency_id' => '7',
                'sum' => '1234',
            ]],
            'getRecordList' => [[
                'id' => '10',
                'place_id' => '1',
                'budget_object_id' => '2',
                'sum' => '1234',
                'operation_date' => '2026-07-14 12:00:00',
                'currency_id' => '7',
                'operation_type' => '3',
            ]],
        ]);
        $client = new DrebedengiClient($transport);

        self::assertSame(8, $client->currencies()->require('7')->decimalPlaces);
        self::assertSame(8, $client->balance()->list()[0]->sum->scale);
        self::assertSame(8, $client->records()->list()[0]->sum->scale);
        self::assertSame(1, count(array_filter(
            $transport->calls,
            static fn (array $call): bool => $call['method'] === 'getCurrencyList',
        )));
    }

    public function testFailedRefreshKeepsLastValidCatalog(): void
    {
        $valid = [[
            'id' => '7',
            'name' => 'Bitcoin',
            'code' => 'BTC',
            'ratio' => '1000000',
        ]];
        $invalid = [[
            'id' => '3',
            'name' => 'Euro',
            'code' => 'EUR',
            'ratio' => '3',
        ]];
        $transport = new FakeTransport([
            'getCurrencyList' => ['__sequence' => [$valid, $invalid]],
        ]);
        $service = new CurrencyService($transport);
        $cached = $service->require('7');

        try {
            $service->refresh();
            self::fail('Invalid refreshed currency data must be rejected.');
        } catch (UnexpectedResponseException) {
            // Expected: the old cache must remain intact below.
        }

        self::assertSame($cached, $service->require('7'));
        self::assertNull($service->find('3'));
        self::assertCount(2, $transport->calls);
    }

    public function testDuplicateIdRefreshIsRejectedWithoutReplacingValidCatalog(): void
    {
        $valid = [[
            'id' => '7',
            'name' => 'Bitcoin',
            'code' => 'BTC',
            'ratio' => '1000000',
        ]];
        $duplicates = [
            ['id' => '3', 'name' => 'Euro', 'code' => 'EUR', 'ratio' => '1'],
            ['id' => '3', 'name' => 'Other euro', 'code' => 'EUR2', 'ratio' => '100'],
        ];
        $transport = new FakeTransport([
            'getCurrencyList' => ['__sequence' => [$valid, $duplicates]],
        ]);
        $service = new CurrencyService($transport);
        $cached = $service->require('7');

        try {
            $service->refresh();
            self::fail('Duplicate currency IDs must be rejected.');
        } catch (UnexpectedResponseException $exception) {
            self::assertStringContainsString('duplicate ID "3"', $exception->getMessage());
        }

        self::assertSame($cached, $service->require('7'));
        self::assertNull($service->find('3'));
        self::assertCount(2, $transport->calls);
    }

    public function testByIdsValidatesDeduplicatesAndUsesDirectSoapQuery(): void
    {
        $transport = new FakeTransport([
            'getCurrencyList' => [[
                'id' => '7',
                'name' => 'Bitcoin',
                'code' => 'BTC',
                'ratio' => '1000000',
            ]],
        ]);
        $service = new CurrencyService($transport);

        $currencies = $service->byIds(['7', 7]);

        self::assertSame(['7'], array_column($currencies, 'id'));
        self::assertSame([['7']], $transport->calls[0]['arguments']);
        self::assertSame([], $service->byIds([]));
        self::assertCount(1, $transport->calls);
    }

    public function testByIdsRejectsInvalidIdBeforeSoapCall(): void
    {
        $transport = new FakeTransport();
        $service = new CurrencyService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency ID');

        try {
            $service->byIds(['not-an-id']);
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    public function testCreatesHiddenStandardCurrencyAndRefreshesSharedCatalog(): void
    {
        $transport = new FakeTransport([
            'getCurrencyList' => ['__sequence' => [
                [['id' => '3', 'name' => 'Euro', 'code' => 'EUR', 'course' => '1', 'ratio' => '1', 'is_default' => true]],
                [
                    ['id' => '3', 'name' => 'Euro', 'code' => 'EUR', 'course' => '1', 'ratio' => '1', 'is_default' => true],
                    ['id' => '9', 'name' => 'Test coin', 'code' => 'TST', 'course' => '1.25', 'ratio' => '1', 'is_hidden' => true],
                ],
            ]],
            'setCurrencyList' => [
                ['server_id' => '3', 'status' => 'updated'],
                ['server_id' => '9', 'client_id' => '123', 'status' => 'inserted'],
            ],
        ]);
        $service = new CurrencyService($transport);
        self::assertCount(1, $service->list());

        $currency = $service->create(
            name: 'Test coin',
            course: '1.25',
            code: 'TST',
            writeToken: ReferenceWriteToken::fromClientId(123),
        );

        self::assertSame('9', $currency->id);
        self::assertSame('9', $service->find('9')?->id);
        self::assertSame(
            ['getCurrencyList', 'setCurrencyList', 'getCurrencyList'],
            array_column($transport->calls, 'method'),
        );
        self::assertSame([
            'client_id' => 123,
            'name' => 'Test coin',
            'course' => '1.25',
            'code' => 'TST',
            'is_default' => false,
            'is_autoupdate' => false,
            'is_hidden' => true,
        ], $transport->mapListArgument(1)[0]);
    }

    public function testCreateRequiresResponseMappingForItsClientId(): void
    {
        $service = new CurrencyService(new FakeTransport([
            'setCurrencyList' => [['server_id' => '9', 'client_id' => '999']],
        ]));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('client_id mapping');

        $service->create('Test', writeToken: ReferenceWriteToken::fromClientId(123));
    }

    #[DataProvider('invalidCreateArguments')]
    public function testCreateValidatesFieldsBeforeSoapCall(
        string $name,
        int|string $course,
        ?string $code,
        bool $autoUpdate,
    ): void {
        $transport = new FakeTransport();
        $service = new CurrencyService($transport);

        $this->expectException(InvalidArgumentException::class);

        try {
            $service->create($name, $course, $code, $autoUpdate);
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    /**
     * @return iterable<string, array{string, int|string, string|null, bool}>
     */
    public static function invalidCreateArguments(): iterable
    {
        yield 'empty name' => ['', '1', 'TST', false];
        yield 'long name' => ['12345678901234567', '1', 'TST', false];
        yield 'invalid course' => ['Test', 'nan', 'TST', false];
        yield 'zero course' => ['Test', '0', 'TST', false];
        yield 'long code' => ['Test', '1', '12345678901234567', false];
        yield 'autoupdate without code' => ['Test', '1', null, true];
    }

    public function testUpdatesStandardCurrencyWithCompletePayloadAndRefresh(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getCurrencyList' => ['__sequence' => [
                [[
                    'id' => '9',
                    'name' => 'A &amp; B',
                    'code' => 'TST',
                    'course' => '1',
                    'ratio' => '1',
                    'is_default' => false,
                    'is_autoupdate' => false,
                    'is_hidden' => true,
                ]],
                [[
                    'id' => '9',
                    'name' => 'A &amp; B',
                    'code' => 'TST',
                    'course' => '2.5',
                    'ratio' => '1',
                    'is_default' => false,
                    'is_autoupdate' => false,
                    'is_hidden' => true,
                ]],
            ]],
            'setCurrencyList' => [['server_id' => '9', 'status' => 'updated']],
        ]);
        $service = new CurrencyService($transport);

        $currency = $service->update('9', ['course' => '2.5']);

        self::assertSame('2.5', $currency->course);
        self::assertSame(
            ['getRightAccess', 'getCurrencyList', 'setCurrencyList', 'getCurrencyList'],
            array_column($transport->calls, 'method'),
        );
        self::assertSame([
            'server_id' => '9',
            'name' => 'A & B',
            'course' => '2.5',
            'code' => 'TST',
            'is_default' => false,
            'is_autoupdate' => false,
            'is_hidden' => true,
        ], $transport->mapListArgument(2)[0]);
    }

    public function testUpdateRejectsCryptoBeforeSetCurrencyList(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getCurrencyList' => [[
                'id' => '7',
                'name' => 'Bitcoin',
                'code' => 'BTC',
                'course' => '1',
                'ratio' => '1000000',
                'is_investing' => false,
            ]],
        ]);
        $service = new CurrencyService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('would reset ratio and investing state');

        try {
            $service->update('7', ['course' => '2']);
        } finally {
            self::assertSame(['getRightAccess', 'getCurrencyList'], array_column($transport->calls, 'method'));
        }
    }

    public function testUpdateRejectsLimitedAccessBeforeReadingCurrency(): void
    {
        $transport = new FakeTransport(['getRightAccess' => '1']);
        $service = new CurrencyService($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('require full account access');

        try {
            $service->update('9', ['course' => '2']);
        } finally {
            self::assertSame(['getRightAccess'], array_column($transport->calls, 'method'));
        }
    }

    public function testUpdateRequiresResponseToConfirmTargetServerId(): void
    {
        $before = [[
            'id' => '9',
            'name' => 'Test',
            'code' => 'TST',
            'course' => '1',
            'ratio' => '1',
            'is_default' => false,
        ]];
        $after = [[
            'id' => '9',
            'name' => 'Test',
            'code' => 'TST',
            'course' => '2',
            'ratio' => '1',
            'is_default' => false,
        ]];
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getCurrencyList' => ['__sequence' => [$before, $before, $after]],
            'setCurrencyList' => [['server_id' => '10']],
        ]);
        $service = new CurrencyService($transport);
        self::assertSame('1', $service->require('9')->course);

        try {
            $service->update('9', ['course' => '2']);
            self::fail('A response for another currency must be rejected.');
        } catch (UnexpectedResponseException $exception) {
            self::assertStringContainsString('does not confirm currency server_id 9', $exception->getMessage());
        }

        self::assertSame('2', $service->require('9')->course);
        self::assertSame(
            ['getCurrencyList', 'getRightAccess', 'getCurrencyList', 'setCurrencyList', 'getCurrencyList'],
            array_column($transport->calls, 'method'),
        );
    }

    public function testAmbiguousMutationInvalidatesLoadedCurrencyCatalog(): void
    {
        $before = [[
            'id' => '9',
            'name' => 'Test',
            'code' => 'TST',
            'course' => '1',
            'ratio' => '1',
            'is_default' => false,
        ]];
        $after = [[
            'id' => '9',
            'name' => 'Test',
            'code' => 'TST',
            'course' => '2',
            'ratio' => '1',
            'is_default' => false,
        ]];
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getCurrencyList' => ['__sequence' => [$before, $before, $after]],
            'setCurrencyList' => new AmbiguousMutationException(
                'Response lost after currency write.',
                method: 'setCurrencyList',
                endpoint: 'https://www.drebedengi.ru/soap/',
            ),
        ]);
        $service = new CurrencyService($transport);
        self::assertSame('1', $service->require('9')->course);

        try {
            $service->update('9', ['course' => '2']);
            self::fail('Ambiguous currency mutation must be surfaced.');
        } catch (AmbiguousMutationException) {
            // The server may have applied the write, so the old cache is unsafe.
        }

        self::assertSame('2', $service->require('9')->course);
        self::assertSame(
            ['getCurrencyList', 'getRightAccess', 'getCurrencyList', 'setCurrencyList', 'getCurrencyList'],
            array_column($transport->calls, 'method'),
        );
    }

    public function testConfirmedMutationInvalidatesCacheBeforeFailedRefresh(): void
    {
        $before = [[
            'id' => '9',
            'name' => 'Test',
            'code' => 'TST',
            'course' => '1',
            'ratio' => '1',
            'is_default' => false,
        ]];
        $invalidRefresh = [[
            'id' => '9',
            'name' => 'Test',
            'code' => 'TST',
            'course' => '2',
            'ratio' => '3',
            'is_default' => false,
        ]];
        $after = [[
            'id' => '9',
            'name' => 'Test',
            'code' => 'TST',
            'course' => '2',
            'ratio' => '1',
            'is_default' => false,
        ]];
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getCurrencyList' => ['__sequence' => [$before, $before, $invalidRefresh, $after]],
            'setCurrencyList' => [['server_id' => '9']],
        ]);
        $service = new CurrencyService($transport);
        self::assertSame('1', $service->require('9')->course);

        try {
            $service->update('9', ['course' => '2']);
            self::fail('Invalid post-mutation refresh must be rejected.');
        } catch (UnexpectedResponseException) {
            // The next read must retry SOAP instead of exposing the stale
            // pre-mutation snapshot.
        }

        self::assertSame('2', $service->require('9')->course);
        self::assertSame(4, count(array_filter(
            $transport->calls,
            static fn (array $call): bool => $call['method'] === 'getCurrencyList',
        )));
    }

    public function testSetsDefaultUsingTargetRowInsteadOfFirstResponseRow(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getCurrencyList' => ['__sequence' => [
                [[
                    'id' => '9',
                    'name' => 'Dollar',
                    'code' => 'USD',
                    'course' => '1.1',
                    'ratio' => '1',
                    'is_default' => false,
                ]],
                [
                    ['id' => '3', 'name' => 'Euro', 'code' => 'EUR', 'course' => '1', 'ratio' => '1', 'is_default' => false],
                    ['id' => '9', 'name' => 'Dollar', 'code' => 'USD', 'course' => '1.1', 'ratio' => '1', 'is_default' => true],
                ],
            ]],
            'setCurrencyList' => [
                ['server_id' => '3', 'status' => 'updated'],
                ['server_id' => '9', 'status' => 'updated'],
            ],
        ]);
        $service = new CurrencyService($transport);

        $currency = $service->setDefault('9');

        self::assertSame('9', $currency->id);
        self::assertTrue($currency->default);
        self::assertTrue($transport->mapListArgument(2)[0]['is_default']);
    }

    public function testSetDefaultRefreshesCatalogEvenWhenCurrencyIsAlreadyDefault(): void
    {
        $before = [[
            'id' => '9',
            'name' => 'Dollar',
            'code' => 'USD',
            'course' => '1',
            'ratio' => '1',
            'is_default' => true,
        ]];
        $after = [[
            'id' => '9',
            'name' => 'Dollar',
            'code' => 'USD',
            'course' => '1.1',
            'ratio' => '1',
            'is_default' => true,
        ]];
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getCurrencyList' => ['__sequence' => [$before, $before, $after]],
        ]);
        $service = new CurrencyService($transport);
        self::assertSame('1', $service->require('9')->course);

        $currency = $service->setDefault('9');

        self::assertSame('1.1', $currency->course);
        self::assertSame(
            ['getCurrencyList', 'getRightAccess', 'getCurrencyList', 'getCurrencyList'],
            array_column($transport->calls, 'method'),
        );
    }

    public function testSetDefaultRequiresResponseToConfirmTargetServerId(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getCurrencyList' => [[
                'id' => '9',
                'name' => 'Dollar',
                'code' => 'USD',
                'course' => '1',
                'ratio' => '1',
                'is_default' => false,
            ]],
            'setCurrencyList' => [['server_id' => '3']],
        ]);

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('does not confirm currency server_id 9');

        try {
            (new CurrencyService($transport))->setDefault('9');
        } finally {
            self::assertSame(
                ['getRightAccess', 'getCurrencyList', 'setCurrencyList'],
                array_column($transport->calls, 'method'),
            );
        }
    }

    public function testSetDefaultRejectsLimitedAccessBeforeReadingCurrency(): void
    {
        $transport = new FakeTransport(['getRightAccess' => '1']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('require full account access');

        try {
            (new CurrencyService($transport))->setDefault('9');
        } finally {
            self::assertSame(['getRightAccess'], array_column($transport->calls, 'method'));
        }
    }

    public function testSetDefaultRejectsCryptoBeforeMutation(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getCurrencyList' => [[
                'id' => '7',
                'name' => 'Bitcoin',
                'code' => 'BTC',
                'course' => '1',
                'ratio' => '1000000',
                'is_default' => false,
            ]],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('would reset ratio');

        (new CurrencyService($transport))->setDefault('7');
    }

    public function testDeletesExistingNonDefaultCurrencyAndRefreshesCatalog(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getCurrencyList' => ['__sequence' => [
                [[
                    'id' => '9',
                    'name' => 'Test',
                    'code' => 'TST',
                    'course' => '1',
                    'ratio' => '1',
                    'is_default' => false,
                ]],
                [['id' => '3', 'name' => 'Euro', 'code' => 'EUR', 'course' => '1', 'ratio' => '1', 'is_default' => true]],
            ]],
            'deleteObject' => '1',
        ]);
        $service = new CurrencyService($transport);

        self::assertTrue($service->delete('9'));
        self::assertSame(
            ['getRightAccess', 'getCurrencyList', 'deleteObject', 'getCurrencyList'],
            array_column($transport->calls, 'method'),
        );
        self::assertSame([9, 'currency'], $transport->calls[2]['arguments']);
        self::assertNull($service->find('9'));
    }

    public function testDeleteReturnsFalseForUnknownCurrencyWithoutDeleteCall(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getCurrencyList' => [],
        ]);

        self::assertFalse((new CurrencyService($transport))->delete('9'));
        self::assertSame(['getRightAccess', 'getCurrencyList'], array_column($transport->calls, 'method'));
    }

    public function testDeleteRejectsLimitedAccessBeforeReadingCurrency(): void
    {
        $transport = new FakeTransport(['getRightAccess' => '1']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('require full account access');

        try {
            (new CurrencyService($transport))->delete('9');
        } finally {
            self::assertSame(['getRightAccess'], array_column($transport->calls, 'method'));
        }
    }

    public function testDeleteRejectsDefaultCurrencyBeforeDeleteCall(): void
    {
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getCurrencyList' => [[
                'id' => '3',
                'name' => 'Euro',
                'code' => 'EUR',
                'course' => '1',
                'ratio' => '1',
                'is_default' => true,
            ]],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot delete the default');

        try {
            (new CurrencyService($transport))->delete('3');
        } finally {
            self::assertSame(['getRightAccess', 'getCurrencyList'], array_column($transport->calls, 'method'));
        }
    }
}
