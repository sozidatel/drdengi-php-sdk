<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\TransportException;
use Soz\Drebedengi\Model\MoneyAmount;
use Soz\Drebedengi\Model\OperationType;
use Soz\Drebedengi\Tests\Support\LiveClientFactory;

final class LiveRecordWriteTest extends TestCase
{
    public function testCanCreateReadAndDeleteExpense(): void
    {
        $client = LiveClientFactory::clientOrSkip($this);

        $place = $client->places()->accounts()[0] ?? null;
        $category = $client->categories()->list()[0] ?? null;
        $currency = $client->currencies()->list()[0] ?? null;
        if (!$place || !$category || !$currency) {
            self::markTestSkipped('Live Drebedengi account does not have place/category/currency fixtures.');
        }

        $comment = 'drdengi-php-sdk live test ' . bin2hex(random_bytes(4));
        try {
            $created = $client->records()->createExpense(
                placeId: $place->id,
                categoryId: $category->id,
                amount: MoneyAmount::fromDecimalString('1.23'),
                currencyId: $currency->id,
                date: new \DateTimeImmutable('now'),
                comment: $comment,
            );
        } catch (TransportException $exception) {
            if (str_contains($exception->getMessage(), 'No payment')) {
                self::markTestSkipped('Drebedengi test account does not allow write calls: No payment.');
            }

            throw $exception;
        }

        $serverId = $this->extractServerId($created);
        if ($serverId === null) {
            foreach ($client->records()->list() as $record) {
                if ($record->comment === $comment) {
                    $serverId = $record->id;
                    break;
                }
            }
        }

        self::assertNotNull($serverId, 'Created record server id was not found.');

        try {
            $records = $client->records()->byIds([$serverId]);
            self::assertNotEmpty($records);
            self::assertSame($comment, $records[0]->comment);
        } finally {
            $client->records()->delete($serverId, OperationType::Expense);
        }
    }

    public function testCanCreateReadAndDeleteIncomeTransferAndExchange(): void
    {
        $client = LiveClientFactory::clientOrSkip($this);

        $places = $client->places()->accounts();
        $placeA = $places[0] ?? null;
        $placeB = $places[1] ?? null;
        $source = $client->sources()->list()[0] ?? null;
        $currencies = $client->currencies()->list();
        $currency = $currencies[0] ?? null;
        $currencyB = $currencies[1] ?? null;
        if (!$placeA || !$placeB || !$source || !$currency) {
            self::markTestSkipped('Live Drebedengi account does not have enough place/source/currency fixtures.');
        }

        $createdIds = [];
        try {
            $incomeComment = 'drdengi-php-sdk live income ' . bin2hex(random_bytes(4));
            $income = $client->records()->createIncome(
                placeId: $placeA->id,
                sourceId: $source->id,
                amount: MoneyAmount::fromDecimalString('2.34'),
                currencyId: $currency->id,
                date: new \DateTimeImmutable('now'),
                comment: $incomeComment,
            );
            $incomeId = $this->requireCreatedId($income, $client, $incomeComment);
            $createdIds[] = [$incomeId, OperationType::Income];
            self::assertSame($incomeComment, $client->records()->byIds([$incomeId])[0]->comment);

            $transferComment = 'drdengi-php-sdk live transfer ' . bin2hex(random_bytes(4));
            $transfer = $client->records()->createTransfer(
                fromPlaceId: $placeA->id,
                toPlaceId: $placeB->id,
                amount: MoneyAmount::fromDecimalString('1.00'),
                currencyId: $currency->id,
                date: new \DateTimeImmutable('now'),
                comment: $transferComment,
            );
            $transferIds = $this->extractServerIds($transfer);
            self::assertCount(2, $transferIds);
            foreach ($transferIds as $id) {
                $createdIds[] = [$id, OperationType::Transfer];
            }
            self::assertCount(2, $client->records()->byIds($transferIds));

            if ($currencyB !== null) {
                $exchangeComment = 'drdengi-php-sdk live exchange ' . bin2hex(random_bytes(4));
                $exchange = $client->records()->createExchange(
                    placeId: $placeA->id,
                    soldAmount: MoneyAmount::fromDecimalString('1.00'),
                    soldCurrencyId: $currency->id,
                    boughtAmount: MoneyAmount::fromDecimalString('0.50'),
                    boughtCurrencyId: $currencyB->id,
                    date: new \DateTimeImmutable('now'),
                    comment: $exchangeComment,
                );
                $exchangeIds = $this->extractServerIds($exchange);
                self::assertCount(2, $exchangeIds);
                foreach ($exchangeIds as $id) {
                    $createdIds[] = [$id, OperationType::Exchange];
                }
                self::assertCount(2, $client->records()->byIds($exchangeIds));
            }
        } finally {
            foreach (array_reverse($createdIds) as [$id, $type]) {
                try {
                    $client->records()->delete($id, $type);
                } catch (\Throwable) {
                    // Keep cleanup best-effort so the original assertion is not hidden.
                }
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $created
     */
    private function extractServerId(array $created): ?string
    {
        return $this->extractServerIds($created)[0] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $created
     * @return list<string>
     */
    private function extractServerIds(array $created): array
    {
        $ids = [];
        foreach ($created as $item) {
            foreach (['server_id', 'id'] as $field) {
                if (array_key_exists($field, $item) && trim((string)$item[$field]) !== '') {
                    $ids[] = (string)$item[$field];
                    break;
                }
            }
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $created
     */
    private function requireCreatedId(array $created, \Soz\Drebedengi\DrebedengiClient $client, string $comment): string
    {
        $serverId = $this->extractServerId($created);
        if ($serverId !== null) {
            return $serverId;
        }

        foreach ($client->records()->list() as $record) {
            if ($record->comment === $comment) {
                return $record->id;
            }
        }

        self::fail('Created record server id was not found.');
    }
}
