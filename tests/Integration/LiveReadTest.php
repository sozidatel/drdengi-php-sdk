<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Tests\Support\LiveClientFactory;

final class LiveReadTest extends TestCase
{
    public function testCanReadCoreDataFromDrebedengi(): void
    {
        $client = LiveClientFactory::clientOrSkip($this);

        self::assertGreaterThanOrEqual(0, $client->sync()->currentRevision());
        self::assertNotSame('', $client->account()->userId());
        self::assertIsArray($client->places()->list());
        self::assertIsArray($client->categories()->list());
        self::assertIsArray($client->sources()->list());
        self::assertIsArray($client->currencies()->list());
        self::assertIsArray($client->tags()->list());
        self::assertIsArray($client->balance()->list());
        self::assertIsArray($client->records()->list());
    }
}
