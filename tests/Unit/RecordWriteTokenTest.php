<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Model\RecordWriteToken;

final class RecordWriteTokenTest extends TestCase
{
    public function testGeneratesRequestedNumberOfReusableIds(): void
    {
        $token = RecordWriteToken::generate(3);

        self::assertCount(3, $token->clientIds);
        self::assertCount(3, array_unique($token->clientIds));
        self::assertNotContains($token->groupId, $token->clientIds);
        self::assertSame($token->clientIds, $token->jsonSerialize()['clientIds']);
    }

    public function testAcceptsExplicitIdsAndGroupId(): void
    {
        $token = new RecordWriteToken([111, '222'], 333);

        self::assertSame([111, 222], $token->clientIds);
        self::assertSame(333, $token->groupId);
        self::assertSame(222, $token->clientId(1));
    }

    /**
     * @param list<int|string> $ids
     */
    #[DataProvider('invalidIdsProvider')]
    public function testRejectsInvalidIds(array $ids, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new RecordWriteToken($ids);
    }

    /** @return iterable<string, array{list<int|string>, string}> */
    public static function invalidIdsProvider(): iterable
    {
        yield 'empty' => [[], 'at least one client ID'];
        yield 'zero' => [[0], 'must be between 1 and'];
        yield 'too large' => [[1_000_000_000], 'must be between 1 and'];
        yield 'duplicate' => [[123, '123'], 'must be unique'];
    }
}
