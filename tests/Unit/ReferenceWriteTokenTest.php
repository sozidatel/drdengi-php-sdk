<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Model\ReferenceWriteToken;

final class ReferenceWriteTokenTest extends TestCase
{
    public function testGeneratesReusableClientId(): void
    {
        $token = ReferenceWriteToken::generate();

        self::assertGreaterThanOrEqual(1, $token->clientId);
        self::assertLessThanOrEqual(999_999_999, $token->clientId);
        self::assertSame(['clientId' => $token->clientId], $token->jsonSerialize());
    }

    public function testCreatesTokenFromExplicitClientId(): void
    {
        self::assertSame(123, ReferenceWriteToken::fromClientId('123')->clientId);
    }

    #[DataProvider('invalidClientIds')]
    public function testRejectsInvalidClientId(int|string $clientId): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reference write client ID');

        ReferenceWriteToken::fromClientId($clientId);
    }

    /**
     * @return iterable<string, array{int|string}>
     */
    public static function invalidClientIds(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'too large' => [1_000_000_000];
        yield 'not integer' => ['1.5'];
        yield 'padded' => ['001'];
    }
}
