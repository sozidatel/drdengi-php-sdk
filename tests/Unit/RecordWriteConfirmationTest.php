<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Exception\AmbiguousMutationException;
use Soz\Drebedengi\Exception\EndpointUnavailableException;
use Soz\Drebedengi\Exception\UnconfirmedWriteException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Model\Currency;
use Soz\Drebedengi\Model\MoneyAmount;
use Soz\Drebedengi\Model\Record;
use Soz\Drebedengi\Model\RecordWriteToken;
use Soz\Drebedengi\Model\WriteResult;
use Soz\Drebedengi\Service\RecordService;
use Soz\Drebedengi\Support\CurrencyCatalog;
use Soz\Drebedengi\Tests\Support\FakeTransport;

final class RecordWriteConfirmationTest extends TestCase
{
    #[DataProvider('unconfirmedResponses')]
    public function testIncompleteOrMalformedCreatePreservesEvidenceWithoutRetry(mixed $response): void
    {
        $transport = new FakeTransport(['setRecordList' => $response]);

        try {
            $this->transfer($transport);
            self::fail('An unconfirmed transfer must not return normally.');
        } catch (UnconfirmedWriteException $exception) {
            self::assertInstanceOf(AmbiguousMutationException::class, $exception);
            self::assertInstanceOf(UnexpectedResponseException::class, $exception->getPrevious());
            self::assertSame('setRecordList', $exception->method);
            self::assertFalse($exception->retrySafe);
            self::assertNull($exception->endpoint);
            self::assertSame($response, $exception->response);
            self::assertSame($transport->mapListArgument(0), $exception->payloads);
            self::assertCount(1, $transport->calls);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function unconfirmedResponses(): iterable
    {
        yield 'empty' => [[]];
        yield 'one half' => [[['server_id' => '10', 'client_id' => 111]]];
        yield 'missing ID' => [[['server_id' => '10'], ['status' => 'ok']]];
        yield 'duplicate server' => [[['server_id' => '10'], ['server_id' => '10']]];
        yield 'equivalent server IDs' => [[['server_id' => '10'], ['server_id' => '010']]];
        yield 'duplicate client' => [[['server_id' => '10', 'client_id' => 111], ['server_id' => '11', 'client_id' => 111]]];
        yield 'unexpected client' => [[['server_id' => '10', 'client_id' => 111], ['server_id' => '11', 'client_id' => 333]]];
        yield 'extra record' => [[['server_id' => '10'], ['server_id' => '11'], ['server_id' => '12']]];
        yield 'invalid server ID' => [[['server_id' => '10'], ['server_id' => 'invalid']]];
        yield 'malformed list' => [[['server_id' => '10'], 'invalid']];
        yield 'scalar' => ['invalid'];
        yield 'false' => [false];
    }

    public function testReorderedResponseUsesExplicitMapping(): void
    {
        $response = [['server_id' => '20', 'client_id' => 222], ['server_id' => '10', 'client_id' => 111]];
        $transport = new FakeTransport(['setRecordList' => $response]);

        $result = $this->transfer($transport);

        self::assertSame('10', $result->serverIdForClientId(111));
        self::assertSame('20', $result->serverIdForClientId(222));
        self::assertSame($response, $result->raw);
        self::assertCount(1, $transport->calls);
    }

    /** @param list<array<string, mixed>> $response */
    #[DataProvider('legacyResponses')]
    public function testLegacyResponsesDoNotInventClientMappings(array $response): void
    {
        $result = $this->transfer(new FakeTransport(['setRecordList' => $response]));

        self::assertSame(['10', '20'], $result->serverIds);
        self::assertNull($result->serverIdForClientId(222));
    }

    /** @return iterable<string, array{list<array<string, mixed>>}> */
    public static function legacyResponses(): iterable
    {
        yield 'IDs only' => [[['server_id' => '10'], ['id' => '20']]];
        yield 'mixed' => [[['server_id' => '10', 'client_id' => 111], ['server_id' => '20']]];
    }

    public function testUpdateStillAcceptsEmptyLegacyResponse(): void
    {
        $transport = new FakeTransport(['setRecordList' => []]);
        $record = Record::fromSoap([
            'id' => '10', 'place_id' => '1', 'budget_object_id' => '2', 'sum' => '-100',
            'operation_date' => '2026-01-01 10:00:00', 'currency_id' => '3', 'operation_type' => '3',
        ]);

        $result = $this->service($transport)->update($record);

        self::assertSame([], $result->raw);
        self::assertSame('10', $result->firstServerId());
    }

    public function testRawWriteRemainsAnUncheckedEscapeHatch(): void
    {
        $transport = new FakeTransport(['setRecordList' => []]);

        self::assertSame([], $this->service($transport)->savePayloads([['client_id' => 111]]));
        self::assertCount(1, $transport->calls);
    }

    public function testPreSendFailureKeepsItsOriginalClassification(): void
    {
        $failure = new EndpointUnavailableException('WSDL unavailable', retrySafe: true);
        $transport = new FakeTransport(['setRecordList' => $failure]);

        try {
            $this->transfer($transport);
            self::fail('Transport failure must propagate.');
        } catch (EndpointUnavailableException $exception) {
            self::assertSame($failure, $exception);
            self::assertTrue($exception->retrySafe);
            self::assertCount(1, $transport->calls);
        }
    }

    private function transfer(FakeTransport $transport): WriteResult
    {
        return $this->service($transport)->createTransfer(
            '1', '2', MoneyAmount::fromDecimalString('5.00'), '3',
            new \DateTimeImmutable('2026-01-01 10:00:00'),
            writeToken: RecordWriteToken::fromClientIds(111, 222),
        );
    }

    private function service(FakeTransport $transport): RecordService
    {
        return new RecordService(
            $transport,
            new ClientOptions(new \DateTimeZone('UTC')),
            new CurrencyCatalog($transport, [Currency::fromSoap([
                'id' => '3', 'name' => 'EUR', 'code' => 'EUR', 'ratio' => '1',
            ])]),
        );
    }
}
