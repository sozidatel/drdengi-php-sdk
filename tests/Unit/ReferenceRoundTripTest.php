<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Service\CategoryService;
use Soz\Drebedengi\Service\CurrencyService;
use Soz\Drebedengi\Service\PlaceService;
use Soz\Drebedengi\Service\SourceService;
use Soz\Drebedengi\Service\TagService;
use Soz\Drebedengi\Tests\Support\FakeTransport;

final class ReferenceRoundTripTest extends TestCase
{
    #[DataProvider('sortedReferences')]
    public function testNegativeSortControlsOrderAndSurvivesUnrelatedUpdate(string $kind): void
    {
        $raw = [
            'id' => '10',
            'name' => 'Z first',
            'type' => '4',
            'parent_id' => '5',
            'user_id' => '7',
            'sort' => ' -1 ',
            'is_hidden' => true,
        ];
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getUserIdByLogin' => '7',
            'get' . $kind . 'List' => [
                array_replace($raw, ['id' => '11', 'name' => 'A second', 'sort' => '0']),
                $raw,
            ],
            'set' . $kind . 'List' => [['server_id' => '10']],
        ]);
        $service = self::service($kind, $transport);
        self::assertNotInstanceOf(CurrencyService::class, $service);
        $items = $service->list();

        self::assertSame(['10', '11'], array_column($items, 'id'));
        self::assertSame('-1', $items[0]->sort);
        self::assertSame('0', $items[1]->sort);

        $service->update('10', ['is_hidden' => false]);

        $writeIndex = array_search('set' . $kind . 'List', array_column($transport->calls, 'method'), true);
        self::assertIsInt($writeIndex);
        $payload = $transport->mapListArgument($writeIndex)[0];
        self::assertSame('-1', $payload['sort']);
        self::assertSame('Z first', $payload['name']);
        self::assertSame('5', $payload['parent_id']);
        self::assertFalse($payload['is_hidden']);
    }

    /** @return iterable<string, array{string}> */
    public static function sortedReferences(): iterable
    {
        foreach (['Category', 'Source', 'Place', 'Tag'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('escapedReferences')]
    public function testValidMaximumLengthNameCanBeEchoedFromSoap(string $kind, int $maxLength): void
    {
        $name = str_repeat('&', $maxLength);
        $escaped = str_repeat('&amp;', $maxLength);
        $transport = self::transport($kind, $escaped);

        self::service($kind, $transport)->update('10', ['name' => $escaped]);

        $payload = $transport->mapListArgument(2)[0];
        self::assertSame($name, $payload['name']);
        if ($kind !== 'Currency') {
            self::assertSame('-1', $payload['sort']);
            self::assertSame('A &amp; B', $payload['description']);
        }
    }

    #[DataProvider('escapedReferences')]
    public function testEchoDecodesOneLayerBeforeTrimming(string $kind, int $maxLength): void
    {
        $escaped = ' A &amp;amp; B ';
        $transport = self::transport($kind, $escaped);

        self::service($kind, $transport)->update('10', ['name' => $escaped]);

        self::assertSame('A &amp; B', $transport->mapListArgument(2)[0]['name']);
    }

    #[DataProvider('escapedReferences')]
    public function testNameEchoStillMatchesAfterTrimmingUserWhitespace(string $kind, int $maxLength): void
    {
        $transport = self::transport($kind, 'A &amp; B');

        self::service($kind, $transport)->update('10', ['name' => ' A &amp; B ']);

        self::assertSame('A & B', $transport->mapListArgument(2)[0]['name']);
    }

    #[DataProvider('describedReferences')]
    public function testDescriptionWhitespaceRemainsLiteralInsteadOfMatchingAnEcho(string $kind): void
    {
        $transport = self::transport($kind, 'Name');
        $description = ' A &amp;amp; B ';

        self::service($kind, $transport)->update('10', ['description' => $description]);

        self::assertSame($description, $transport->mapListArgument(2)[0]['description']);
    }

    /** @return iterable<string, array{string}> */
    public static function describedReferences(): iterable
    {
        foreach (['Category', 'Source', 'Place'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('escapedReferences')]
    public function testDifferentHtmlLookingNameStaysLiteral(string $kind, int $maxLength): void
    {
        $transport = self::transport($kind, 'Old &amp; name');

        self::service($kind, $transport)->update('10', ['name' => 'New &amp; X']);

        self::assertSame('New &amp; X', $transport->mapListArgument(2)[0]['name']);
    }

    #[DataProvider('escapedReferences')]
    public function testDifferentOverlongHtmlLookingNameCannotBypassLimit(string $kind, int $maxLength): void
    {
        $transport = self::transport($kind, 'Original');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($maxLength . ' characters');

        try {
            self::service($kind, $transport)->update('10', ['name' => str_repeat('&amp;', $maxLength)]);
        } finally {
            self::assertSame(
                ['getRightAccess', 'get' . $kind . 'List'],
                array_column($transport->calls, 'method'),
            );
        }
    }

    #[DataProvider('escapedReferences')]
    public function testPlainOverlongNameStillFailsBeforeSoap(string $kind, int $maxLength): void
    {
        $transport = new FakeTransport();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($maxLength . ' characters');

        try {
            self::service($kind, $transport)->update('10', ['name' => str_repeat('я', $maxLength + 1)]);
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    public function testCurrencyCodeUsesTheSameEchoRulesAsName(): void
    {
        $escaped = str_repeat('&amp;', 16);
        $transport = new FakeTransport([
            'getRightAccess' => '0',
            'getCurrencyList' => [[
                'id' => '10',
                'name' => 'Coin',
                'code' => $escaped,
                'ratio' => '1',
                'course' => '1',
            ]],
            'setCurrencyList' => [['server_id' => '10']],
        ]);
        $service = new CurrencyService($transport);

        $service->update('10', ['code' => $escaped]);
        $service->update('10', ['code' => 'X&amp;Y']);
        $service->update('10', ['code' => ' ' . $escaped . ' ']);

        self::assertSame(str_repeat('&', 16), $transport->mapListArgument(2)[0]['code']);
        self::assertSame('X&amp;Y', $transport->mapListArgument(6)[0]['code']);
        self::assertSame(str_repeat('&', 16), $transport->mapListArgument(10)[0]['code']);
    }

    /** @return iterable<string, array{string, int}> */
    public static function escapedReferences(): iterable
    {
        yield 'Category' => ['Category', 128];
        yield 'Source' => ['Source', 128];
        yield 'Place' => ['Place', 128];
        yield 'Currency' => ['Currency', 16];
    }

    private static function transport(string $kind, string $name): FakeTransport
    {
        return new FakeTransport([
            'getRightAccess' => '0',
            'get' . $kind . 'List' => [[
                'id' => '10',
                'name' => $name,
                'type' => '4',
                'ratio' => '1',
                'sort' => '-1',
                'description' => 'A &amp;amp; B',
            ]],
            'set' . $kind . 'List' => [['server_id' => '10']],
        ]);
    }

    private static function service(
        string $kind,
        FakeTransport $transport,
    ): CategoryService|SourceService|PlaceService|TagService|CurrencyService {
        return match ($kind) {
            'Category' => new CategoryService($transport),
            'Source' => new SourceService($transport),
            'Place' => new PlaceService($transport),
            'Tag' => new TagService($transport),
            'Currency' => new CurrencyService($transport),
            default => throw new \LogicException('Unknown reference service.'),
        };
    }
}
