<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Service\AccountService;
use Soz\Drebedengi\Tests\Support\FakeTransport;

final class AccountServiceTest extends TestCase
{
    public function testReadsAccountStatus(): void
    {
        $service = new AccountService(new FakeTransport([
            'getAccessStatus' => 1,
            'getUserIdByLogin' => '100',
            'getExpireDate' => '2026-12-31',
            'getRightAccess' => '0',
            'getSubscriptionStatus' => '1',
        ]));

        self::assertTrue($service->hasAccess());
        self::assertSame('100', $service->userId());
        self::assertSame('2026-12-31', $service->expireDate()?->format('Y-m-d'));
        self::assertSame('0', $service->rightAccess());
        self::assertSame('1', $service->subscriptionStatus());
    }

    public function testMapsZeroAccessStatusToFalse(): void
    {
        $service = new AccountService(new FakeTransport(['getAccessStatus' => '0']));

        self::assertFalse($service->hasAccess());
    }

    public function testPreservesLargePositiveUserIdWithoutIntegerConversion(): void
    {
        $service = new AccountService(new FakeTransport([
            'getUserIdByLogin' => '9223372036854775808123',
        ]));

        self::assertSame('9223372036854775808123', $service->userId());
    }

    #[DataProvider('invalidUserIdProvider')]
    public function testRejectsInvalidUserId(int|string $response): void
    {
        $service = new AccountService(new FakeTransport(['getUserIdByLogin' => $response]));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('must be a positive decimal integer ID');

        $service->userId();
    }

    /** @return iterable<string, array{int|string}> */
    public static function invalidUserIdProvider(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => [-1];
        yield 'non-numeric' => ['broken'];
        yield 'leading zero' => ['01'];
    }

    #[DataProvider('expireDateSentinelProvider')]
    public function testMapsDocumentedExpireDateSentinelsToNull(int|string $response): void
    {
        $service = new AccountService(new FakeTransport(['getExpireDate' => $response]));

        self::assertNull($service->expireDate());
    }

    /** @return iterable<string, array{int|string}> */
    public static function expireDateSentinelProvider(): iterable
    {
        yield 'no active subscription' => ['0'];
        yield 'server error sentinel' => [-1];
    }

    #[DataProvider('invalidResponseProvider')]
    public function testRejectsMalformedScalarResponses(
        string $transportMethod,
        string $serviceMethod,
        mixed $response,
        string $message,
    ): void {
        $service = new AccountService(new FakeTransport([$transportMethod => $response]));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage($message);

        $service->{$serviceMethod}();
    }

    /** @return iterable<string, array{string, string, mixed, string}> */
    public static function invalidResponseProvider(): iterable
    {
        yield 'access status is not binary' => [
            'getAccessStatus',
            'hasAccess',
            'broken',
            'getAccessStatus response must be 0 or 1',
        ];
        yield 'user ID is not scalar' => [
            'getUserIdByLogin',
            'userId',
            [],
            'getUserIdByLogin response must be a non-empty string or integer, got array',
        ];
        yield 'expire date is empty' => [
            'getExpireDate',
            'expireDate',
            '',
            'getExpireDate response must be a non-empty string or integer',
        ];
        yield 'expire date is not a real date' => [
            'getExpireDate',
            'expireDate',
            '2026-02-30',
            'getExpireDate response must be 0, -1, or a valid date',
        ];
        yield 'right access is not binary' => [
            'getRightAccess',
            'rightAccess',
            '2',
            'getRightAccess response must be 0 or 1',
        ];
        yield 'subscription status is not binary' => [
            'getSubscriptionStatus',
            'subscriptionStatus',
            3.14,
            'getSubscriptionStatus response must be a non-empty string or integer, got float',
        ];
    }
}
