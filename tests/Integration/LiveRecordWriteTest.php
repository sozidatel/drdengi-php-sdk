<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\TransportException;
use Soz\Drebedengi\DrebedengiClient;
use Soz\Drebedengi\Model\ExpenseGroupItem;
use Soz\Drebedengi\Model\OperationType;
use Soz\Drebedengi\Model\RecordQuery;
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
                amount: $currency->amount('1.23'),
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
                amount: $currency->amount('2.34'),
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
                amount: $currency->amount('1.00'),
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
                    soldAmount: $currency->amount('1.00'),
                    soldCurrencyId: $currency->id,
                    boughtAmount: $currencyB->amount('0.50'),
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

    public function testExpenseCategoryCannotBeDeletedWhileExpenseUsesIt(): void
    {
        $client = LiveClientFactory::clientOrSkip($this);

        $place = $client->places()->accounts()[0] ?? null;
        $currency = $client->currencies()->list()[0] ?? null;
        if (!$place || !$currency) {
            self::markTestSkipped('Live Drebedengi account does not have place/currency fixtures.');
        }

        $categoryId = null;
        $recordId = null;
        try {
            $categoryId = $this->createCategory($client, 'drdengi-php-sdk live category ' . bin2hex(random_bytes(4)));
            $comment = 'drdengi-php-sdk live category expense ' . bin2hex(random_bytes(4));

            $createdRecord = $client->records()->createExpense(
                placeId: $place->id,
                categoryId: $categoryId,
                amount: $currency->amount('1.11'),
                currencyId: $currency->id,
                date: new \DateTimeImmutable('now'),
                comment: $comment,
            );
            $recordId = $this->requireCreatedId($createdRecord, $client, $comment);

            $records = $client->records()->list(
                RecordQuery::forDateRange(new \DateTimeImmutable('today'), new \DateTimeImmutable('today'))
                    ->operationType(OperationType::Expense)
                    ->onlyCategories([$categoryId]),
            );

            $matched = array_values(array_filter(
                $records,
                static fn ($record): bool => $record->id === $recordId
                    && $record->budgetObjectId === $categoryId
                    && $record->comment === $comment,
            ));
            self::assertCount(1, $matched);

            $this->expectException(TransportException::class);
            try {
                $client->categories()->delete($categoryId);
            } catch (TransportException $exception) {
                $client->records()->delete($recordId, OperationType::Expense);
                $recordId = null;

                self::assertTrue($client->categories()->delete($categoryId));
                $categoryId = null;

                throw $exception;
            }
        } finally {
            if ($recordId !== null) {
                try {
                    $client->records()->delete($recordId, OperationType::Expense);
                } catch (\Throwable) {
                    // Keep cleanup best-effort so the original assertion is not hidden.
                }
            }
            if ($categoryId !== null) {
                try {
                    $client->categories()->delete($categoryId);
                } catch (\Throwable) {
                    // Keep cleanup best-effort so the original assertion is not hidden.
                }
            }
        }
    }

    public function testDeletingParentCategoryAlsoDeletesChildCategory(): void
    {
        $client = LiveClientFactory::clientOrSkip($this);

        $parentId = null;
        $childId = null;
        try {
            $parentId = $this->createCategory($client, 'drdengi-php-sdk live parent ' . bin2hex(random_bytes(4)));
            $childId = $this->createCategory($client, 'drdengi-php-sdk live child ' . bin2hex(random_bytes(4)), $parentId);

            $child = $this->findCategory($client, $childId);
            self::assertNotNull($child);
            self::assertSame($parentId, $child->parentId);

            self::assertTrue($client->categories()->delete($parentId));

            self::assertNull($this->findCategory($client, $parentId));
            self::assertNull($this->findCategory($client, $childId));

            $parentId = null;
            $childId = null;
        } finally {
            if ($childId !== null) {
                try {
                    $client->categories()->delete($childId);
                } catch (\Throwable) {
                    // Parent deletion can already remove the whole subtree.
                }
            }
            if ($parentId !== null) {
                try {
                    $client->categories()->delete($parentId);
                } catch (\Throwable) {
                    // Keep cleanup best-effort so the original assertion is not hidden.
                }
            }
        }
    }

    public function testCanCreateReadAndDeleteExpenseGroup(): void
    {
        $client = LiveClientFactory::clientOrSkip($this);

        $place = $client->places()->accounts()[0] ?? null;
        $currency = $client->currencies()->list()[0] ?? null;
        if (!$place || !$currency) {
            self::markTestSkipped('Live Drebedengi account does not have place/currency fixtures.');
        }

        $categoryIds = [];
        $recordIds = [];
        try {
            $categoryIds[] = $this->createCategory($client, 'drdengi-php-sdk live group category A ' . bin2hex(random_bytes(4)));
            $categoryIds[] = $this->createCategory($client, 'drdengi-php-sdk live group category B ' . bin2hex(random_bytes(4)));

            $firstComment = 'drdengi-php-sdk live group first ' . bin2hex(random_bytes(4));
            $secondComment = 'drdengi-php-sdk live group second ' . bin2hex(random_bytes(4));
            $created = $client->records()->createExpenseGroup(
                placeId: $place->id,
                items: [
                    new ExpenseGroupItem($categoryIds[0], $currency->amount('1.23'), $firstComment),
                    ['categoryId' => $categoryIds[1], 'amount' => $currency->amount('4.56'), 'comment' => $secondComment],
                ],
                currencyId: $currency->id,
                date: new \DateTimeImmutable('now'),
            );

            $recordIds = $this->extractServerIds($created);
            self::assertCount(2, $recordIds);

            $records = $client->records()->byIds($recordIds);
            self::assertCount(2, $records);

            usort($records, static fn ($a, $b): int => $a->comment <=> $b->comment);
            self::assertSame($firstComment, $records[0]->comment);
            self::assertSame($secondComment, $records[1]->comment);
            self::assertNotNull($records[0]->groupId);
            self::assertSame($records[0]->groupId, $records[1]->groupId);
            self::assertNotSame($records[0]->id, $records[0]->groupId);
            self::assertSame($categoryIds[0], $records[0]->budgetObjectId);
            self::assertSame($categoryIds[1], $records[1]->budgetObjectId);
        } finally {
            foreach (array_reverse($recordIds) as $recordId) {
                try {
                    $client->records()->delete($recordId, OperationType::Expense);
                } catch (\Throwable) {
                    // Keep cleanup best-effort so the original assertion is not hidden.
                }
            }
            foreach (array_reverse($categoryIds) as $categoryId) {
                try {
                    $client->categories()->delete($categoryId);
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
    private function requireCreatedId(array $created, DrebedengiClient $client, string $comment): string
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

    private function createCategory(DrebedengiClient $client, string $name, int|string|null $parentId = null): string
    {
        try {
            $category = $client->categories()->create($name, $parentId);
        } catch (TransportException $exception) {
            if (str_contains($exception->getMessage(), 'No payment')) {
                self::markTestSkipped('Drebedengi test account does not allow write calls: No payment.');
            }

            throw $exception;
        }

        return $category->id;
    }

    private function findCategory(DrebedengiClient $client, string $id): ?\Soz\Drebedengi\Model\Category
    {
        foreach ($client->categories()->list() as $category) {
            if ($category->id === $id) {
                return $category;
            }
        }

        return null;
    }
}
