<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\DrebedengiClient;
use Soz\Drebedengi\Model\RecordQuery;
use Soz\Drebedengi\Model\ReferenceWriteToken;
use Soz\Drebedengi\Tests\Support\LiveClientFactory;

final class LiveReferenceWriteTest extends TestCase
{
    private const FIXTURE_PREFIX = 'drdengi-php-sdk live ';
    private const CURRENCY_PREFIX = 'SDKTEST-';
    private const MISSING_ID = 2_147_483_647;

    private ?DrebedengiClient $cleanupClient = null;

    protected function tearDown(): void
    {
        try {
            if ($this->cleanupClient !== null) {
                $this->removeSdkFixtures($this->cleanupClient);
            }
        } finally {
            $this->cleanupClient = null;
            parent::tearDown();
        }
    }

    public function testCanCreateQueryUpdateAndDeleteObjectReferences(): void
    {
        $client = $this->writeClientOrSkip();
        $suffix = bin2hex(random_bytes(4));
        $categoryId = null;
        $placeId = null;
        $sourceId = null;
        $tagId = null;

        try {
            $categoryName = self::FIXTURE_PREFIX . 'category ' . $suffix;
            $categoryToken = ReferenceWriteToken::generate();
            $category = $client->categories()->create(
                name: $categoryName,
                hidden: true,
                sort: 901,
                description: 'Live category fixture',
                writeToken: $categoryToken,
            );
            $categoryId = $category->id;
            self::assertSame($categoryId, $client->categories()->create(
                name: $categoryName,
                hidden: true,
                sort: 901,
                description: 'Live category fixture',
                writeToken: $categoryToken,
            )->id);
            self::assertSame(
                [$categoryId],
                array_column($client->categories()->byIds([$categoryId, self::MISSING_ID, $categoryId]), 'id'),
            );
            self::assertCount(1, array_filter(
                $client->categories()->list(),
                static fn ($item): bool => $item->name === $categoryName,
            ));
            $category = $client->categories()->update($categoryId, [
                'name' => $categoryName . ' updated',
                'description' => 'Updated live category fixture',
            ]);
            self::assertSame($categoryName . ' updated', $category->name);

            $placeName = self::FIXTURE_PREFIX . 'place ' . $suffix;
            $place = $client->places()->createAccount(
                name: $placeName,
                hidden: true,
                sort: 902,
                description: 'Live place fixture',
            );
            $placeId = $place->id;
            self::assertTrue($place->isAccount());
            self::assertSame(
                [$placeId],
                array_column($client->places()->byIds([$placeId, self::MISSING_ID, $placeId]), 'id'),
            );
            $place = $client->places()->update($placeId, [
                'name' => $placeName . ' updated',
                'description' => 'Updated live place fixture',
            ]);
            self::assertSame($placeName . ' updated', $place->name);

            $sourceName = self::FIXTURE_PREFIX . 'source ' . $suffix;
            $source = $client->sources()->create(
                name: $sourceName,
                hidden: true,
                sort: 903,
                description: 'Live source fixture',
            );
            $sourceId = $source->id;
            self::assertSame(
                [$sourceId],
                array_column($client->sources()->byIds([$sourceId, self::MISSING_ID, $sourceId]), 'id'),
            );
            $source = $client->sources()->update($sourceId, [
                'name' => $sourceName . ' updated',
                'description' => 'Updated live source fixture',
            ]);
            self::assertSame($sourceName . ' updated', $source->name);

            $tagName = self::FIXTURE_PREFIX . 'tag ' . $suffix;
            $tag = $client->tags()->create(
                name: $tagName,
                hidden: true,
                family: false,
                sort: 904,
            );
            $tagId = $tag->id;
            self::assertSame(
                [$tagId],
                array_column($client->tags()->byIds([$tagId, self::MISSING_ID, $tagId]), 'id'),
            );
            $tag = $client->tags()->update($tagId, [
                'name' => $tagName . ' updated',
                'is_hidden' => false,
            ]);
            self::assertSame($tagName . ' updated', $tag->name);
            self::assertFalse($tag->hidden);

            self::assertTrue($client->tags()->delete($tagId));
            self::assertNull($client->tags()->find($tagId));
            $tagId = null;

            self::assertTrue($client->sources()->delete($sourceId));
            self::assertNull($client->sources()->find($sourceId));
            $sourceId = null;

            self::assertTrue($client->places()->delete($placeId));
            self::assertNull($client->places()->find($placeId));
            $placeId = null;

            self::assertTrue($client->categories()->delete($categoryId));
            self::assertNull($client->categories()->find($categoryId));
            $categoryId = null;
        } finally {
            if ($tagId !== null) {
                $this->bestEffort(static fn () => $client->tags()->delete($tagId));
            }
            if ($sourceId !== null) {
                $this->bestEffort(static fn () => $client->sources()->delete($sourceId));
            }
            if ($placeId !== null) {
                $this->bestEffort(static fn () => $client->places()->delete($placeId));
            }
            if ($categoryId !== null) {
                $this->bestEffort(static fn () => $client->categories()->delete($categoryId));
            }
        }
    }

    public function testCanCreateUpdateSetDefaultRestoreAndDeleteCurrency(): void
    {
        $client = $this->writeClientOrSkip();
        $originalDefault = $client->currencies()->default();
        self::assertNotNull($originalDefault, 'Live Drebedengi account must have a default currency.');
        self::assertSame(1, $originalDefault->ratio, 'Live default currency must be safely writable for restoration.');
        self::assertFalse($originalDefault->investing, 'Live default currency must be safely writable for restoration.');

        $name = self::CURRENCY_PREFIX . bin2hex(random_bytes(4));
        $currencyId = null;

        try {
            $currency = $client->currencies()->create(
                name: $name,
                course: '1.25',
                code: 'SDKTST',
                hidden: true,
            );
            $currencyId = $currency->id;
            self::assertSame(16, strlen($currency->name));
            self::assertSame(
                [$currencyId],
                array_column($client->currencies()->byIds([$currencyId, self::MISSING_ID, $currencyId]), 'id'),
            );

            $currency = $client->currencies()->update($currencyId, [
                'course' => '2.5',
                'is_hidden' => false,
            ]);
            self::assertSame('2.5', $currency->course);
            self::assertFalse($currency->hidden);

            $currency = $client->currencies()->setDefault($currencyId);
            self::assertTrue($currency->default);
            $currentDefault = $client->currencies()->default();
            self::assertNotNull($currentDefault);
            self::assertSame($currencyId, $currentDefault->id);

            $restored = $client->currencies()->setDefault($originalDefault->id);
            self::assertTrue($restored->default);
            $currentDefault = $client->currencies()->default();
            self::assertNotNull($currentDefault);
            self::assertSame($originalDefault->id, $currentDefault->id);

            self::assertTrue($client->currencies()->delete($currencyId));
            self::assertNull($client->currencies()->find($currencyId));
            $currencyId = null;
        } finally {
            if ($currencyId !== null) {
                $this->bestEffort(static fn () => $client->currencies()->setDefault($originalDefault->id));
                $this->bestEffort(static fn () => $client->currencies()->delete($currencyId));
            }
        }
    }

    private function writeClientOrSkip(): DrebedengiClient
    {
        $client = LiveClientFactory::writeClientOrSkip($this);
        $this->cleanupClient = $client;
        $this->removeSdkFixtures($client);

        return $client;
    }

    private function removeSdkFixtures(DrebedengiClient $client): void
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            foreach ($this->sdkFixtureRecords($client) as $record) {
                $this->bestEffort(static fn () => $client->records()->delete($record->id, $record->operationType));
            }
            foreach ($this->sdkFixtureTags($client) as $tag) {
                $this->bestEffort(static fn () => $client->tags()->delete($tag->id));
            }
            foreach ($this->sdkFixtureCategories($client) as $category) {
                $this->bestEffort(static fn () => $client->categories()->delete($category->id));
            }
            foreach ($this->sdkFixtureSources($client) as $source) {
                $this->bestEffort(static fn () => $client->sources()->delete($source->id));
            }
            foreach ($this->sdkFixturePlaces($client) as $place) {
                $this->bestEffort(static fn () => $client->places()->delete($place->id));
            }

            $this->restoreNonFixtureDefaultIfNeeded($client);
            foreach ($this->sdkFixtureCurrencies($client) as $currency) {
                $this->bestEffort(static fn () => $client->currencies()->delete($currency->id));
            }
        }

        self::assertSame([], array_column($this->sdkFixtureRecords($client), 'id'), 'Record fixtures remain.');
        self::assertSame([], array_column($this->sdkFixtureTags($client), 'id'), 'Tag fixtures remain.');
        self::assertSame([], array_column($this->sdkFixtureCategories($client), 'id'), 'Category fixtures remain.');
        self::assertSame([], array_column($this->sdkFixtureSources($client), 'id'), 'Source fixtures remain.');
        self::assertSame([], array_column($this->sdkFixturePlaces($client), 'id'), 'Place fixtures remain.');
        self::assertSame([], array_column($this->sdkFixtureCurrencies($client), 'id'), 'Currency fixtures remain.');
    }

    private function restoreNonFixtureDefaultIfNeeded(DrebedengiClient $client): void
    {
        $currencies = $client->currencies()->refresh();
        $default = null;
        $fallback = null;
        foreach ($currencies as $currency) {
            if ($currency->default) {
                $default = $currency;
            }
            if (
                !str_starts_with($currency->name, self::CURRENCY_PREFIX)
                && $currency->ratio === 1
                && !$currency->investing
            ) {
                $fallback ??= $currency;
            }
        }

        if (
            $default !== null
            && str_starts_with($default->name, self::CURRENCY_PREFIX)
            && $fallback !== null
        ) {
            $this->bestEffort(static fn () => $client->currencies()->setDefault($fallback->id));
        }
    }

    /** @return list<\Soz\Drebedengi\Model\Record> */
    private function sdkFixtureRecords(DrebedengiClient $client): array
    {
        return array_values(array_filter(
            $client->records()->list((new RecordQuery())->allTime()),
            static fn ($record): bool => str_starts_with($record->comment, self::FIXTURE_PREFIX),
        ));
    }

    /** @return list<\Soz\Drebedengi\Model\Tag> */
    private function sdkFixtureTags(DrebedengiClient $client): array
    {
        return array_values(array_filter(
            $client->tags()->list(),
            static fn ($tag): bool => str_starts_with($tag->name, self::FIXTURE_PREFIX),
        ));
    }

    /** @return list<\Soz\Drebedengi\Model\Category> */
    private function sdkFixtureCategories(DrebedengiClient $client): array
    {
        return array_values(array_filter(
            $client->categories()->list(),
            static fn ($category): bool => str_starts_with($category->name, self::FIXTURE_PREFIX),
        ));
    }

    /** @return list<\Soz\Drebedengi\Model\Source> */
    private function sdkFixtureSources(DrebedengiClient $client): array
    {
        return array_values(array_filter(
            $client->sources()->list(),
            static fn ($source): bool => str_starts_with($source->name, self::FIXTURE_PREFIX),
        ));
    }

    /** @return list<\Soz\Drebedengi\Model\Place> */
    private function sdkFixturePlaces(DrebedengiClient $client): array
    {
        return array_values(array_filter(
            $client->places()->list(),
            static fn ($place): bool => str_starts_with($place->name, self::FIXTURE_PREFIX),
        ));
    }

    /** @return list<\Soz\Drebedengi\Model\Currency> */
    private function sdkFixtureCurrencies(DrebedengiClient $client): array
    {
        return array_values(array_filter(
            $client->currencies()->refresh(),
            static fn ($currency): bool => str_starts_with($currency->name, self::CURRENCY_PREFIX),
        ));
    }

    private function bestEffort(callable $cleanup): void
    {
        try {
            $cleanup();
        } catch (\Throwable) {
            // The second cleanup pass and final read-back determine the result.
        }
    }
}
