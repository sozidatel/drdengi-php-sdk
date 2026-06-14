<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
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
        ]));

        self::assertTrue($service->hasAccess());
        self::assertSame('100', $service->userId());
        self::assertSame('2026-12-31', $service->expireDate()?->format('Y-m-d'));
    }
}
