<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$envPath = dirname(__DIR__);
if (is_file($envPath . '/.env')) {
    Dotenv::createImmutable($envPath)->safeLoad();
}
