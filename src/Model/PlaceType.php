<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

enum PlaceType: int
{
    case Account = 4;
    case Folder = 9;
    case Unknown = 0;

    public static function fromSoap(mixed $value): self
    {
        return match ((int)$value) {
            self::Account->value => self::Account,
            self::Folder->value => self::Folder,
            default => self::Unknown,
        };
    }
}
