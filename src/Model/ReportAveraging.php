<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

enum ReportAveraging: int
{
    case None = 0;
    case Daily = 86_400;
    case Weekly = 604_800;
    case Monthly = 2_592_000;
}
