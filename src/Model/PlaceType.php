<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Support\DrebedengiNormalizer;

enum PlaceType: int
{
    case Account = 4;
    case Folder = 9;
    case Unknown = 0;

    public static function fromSoap(mixed $value): self
    {
        if ($value === null) {
            return self::Unknown;
        }

        $integer = filter_var(DrebedengiNormalizer::string($value), FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new UnexpectedResponseException('Drebedengi place response contains non-integer field "type".');
        }

        return match ($integer) {
            self::Account->value => self::Account,
            self::Folder->value => self::Folder,
            default => self::Unknown,
        };
    }
}
