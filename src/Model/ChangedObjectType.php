<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

enum ChangedObjectType: int
{
    case Record = 1;
    case IncomeSource = 2;
    case ExpenseCategory = 3;
    case Place = 4;
    case Currency = 5;
    case Tag = 6;
    case Accum = 7;
    case AccumOrder = 8;
}
