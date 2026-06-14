<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

enum DeleteObjectType: string
{
    case Expense = 'waste';
    case Income = 'income';
    case Transfer = 'move';
    case Exchange = 'change';
    case Object = 'object';
    case Currency = 'currency';
    case Tag = 'tag';
    case Accum = 'accum';
}
