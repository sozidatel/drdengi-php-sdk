<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

enum OperationType: int
{
    case Income = 2;
    case Expense = 3;
    case Transfer = 4;
    case Exchange = 5;
    case All = 6;
}
