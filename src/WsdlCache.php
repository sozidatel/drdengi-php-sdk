<?php

declare(strict_types=1);

namespace Soz\Drebedengi;

enum WsdlCache: int
{
    case None = WSDL_CACHE_NONE;
    case Disk = WSDL_CACHE_DISK;
    case Memory = WSDL_CACHE_MEMORY;
    case Both = WSDL_CACHE_BOTH;
}
