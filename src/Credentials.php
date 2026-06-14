<?php

declare(strict_types=1);

namespace Soz\Drebedengi;

use Soz\Drebedengi\Exception\InvalidArgumentException;

final readonly class Credentials
{
    public function __construct(
        public string $apiId,
        public string $login,
        public string $password,
    ) {
        foreach (['apiId' => $apiId, 'login' => $login, 'password' => $password] as $name => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('Drebedengi credential "%s" must not be empty.', $name));
            }
        }
    }
}
