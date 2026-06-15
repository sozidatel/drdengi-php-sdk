<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

final readonly class ExpenseGroupItem
{
    public function __construct(
        public int|string $categoryId,
        public MoneyAmount $amount,
        public string $comment = '',
    ) {
    }
}
