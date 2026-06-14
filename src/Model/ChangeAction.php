<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

enum ChangeAction: int
{
    case Add = 1;
    case Update = 2;
    case Delete = 3;
}
