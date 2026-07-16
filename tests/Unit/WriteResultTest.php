<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Model\WriteResult;

final class WriteResultTest extends TestCase
{
    public function testExposesTypedIdsAndLegacyListAccess(): void
    {
        $result = new WriteResult(
            raw: [
                ['server_id' => '100', 'client_id' => '111'],
                ['id' => 101, 'client_id' => '222'],
            ],
            payloads: [
                ['client_id' => 111],
                ['client_id' => 222],
            ],
        );

        self::assertSame(['100', '101'], $result->serverIds);
        self::assertSame([111, 222], $result->clientIds);
        self::assertSame('100', $result->firstServerId());
        self::assertSame('100', $result->serverIdForClientId(111));
        self::assertSame('101', $result->serverIdForClientId('222'));
        self::assertCount(2, $result);
        self::assertSame(['server_id' => '100', 'client_id' => '111'], $result[0]);
        self::assertSame($result->raw, iterator_to_array($result));
    }

    public function testMapsClientIdFromItsOwnResponseRowInsteadOfPayloadPosition(): void
    {
        $result = new WriteResult(
            raw: [
                ['server_id' => '10', 'status' => 'updated'],
                ['server_id' => '20', 'client_id' => '222', 'status' => 'inserted'],
            ],
            payloads: [
                ['client_id' => 222],
            ],
        );

        self::assertSame(['10', '20'], $result->serverIds);
        self::assertSame([222], $result->clientIds);
        self::assertSame('20', $result->serverIdForClientId(222));
    }

    public function testFallsBackToKnownUpdateServerId(): void
    {
        $result = new WriteResult(
            raw: [['status' => 'ok']],
            payloads: [['server_id' => '50']],
        );

        self::assertSame(['50'], $result->serverIds);
        self::assertSame('50', $result->firstServerId());
    }

    public function testRejectsMalformedResponseId(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('field "server_id" must be a positive integer ID');

        new WriteResult([['server_id' => ['10']]]);
    }

    public function testRejectsInvalidClientIdLookup(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('client ID must be a positive integer ID');

        WriteResult::empty()->serverIdForClientId(0);
    }

    public function testArrayAccessIsImmutable(): void
    {
        $result = new WriteResult([['server_id' => '10']]);

        $this->expectException(\LogicException::class);
        $result[0] = ['server_id' => '20'];
    }
}
