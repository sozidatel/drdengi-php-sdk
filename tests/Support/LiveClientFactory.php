<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Support;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Credentials;
use Soz\Drebedengi\DrebedengiClient;
use Soz\Drebedengi\Endpoint;

final class LiveClientFactory
{
    public static function readClientOrSkip(TestCase $testCase): DrebedengiClient
    {
        if (
            self::environmentValue('DREB_RUN_LIVE_READ_TESTS', '0') !== '1'
            && self::environmentValue('DREB_RUN_LIVE_TESTS', '0') !== '1'
        ) {
            $testCase::markTestSkipped(
                'Set DREB_RUN_LIVE_READ_TESTS=1 to run read-only Drebedengi integration tests.',
            );
        }

        return self::clientOrSkipForCredentials($testCase);
    }

    public static function writeClientOrSkip(TestCase $testCase): DrebedengiClient
    {
        if (self::environmentValue('DREB_RUN_LIVE_WRITE_TESTS', '0') !== '1') {
            $testCase::markTestSkipped(
                'Set DREB_RUN_LIVE_WRITE_TESTS=1 to run state-changing Drebedengi integration tests.',
            );
        }

        return self::clientOrSkipForCredentials($testCase);
    }

    private static function clientOrSkipForCredentials(TestCase $testCase): DrebedengiClient
    {
        foreach (['DREB_TEST_API_ID', 'DREB_TEST_LOGIN', 'DREB_TEST_PASSWORD'] as $name) {
            if (trim(self::environmentValue($name)) === '') {
                $testCase::markTestSkipped(sprintf('Missing %s for Drebedengi live integration tests.', $name));
            }
        }

        return DrebedengiClient::fromCredentials(
            new Credentials(
                self::environmentValue('DREB_TEST_API_ID'),
                self::environmentValue('DREB_TEST_LOGIN'),
                self::environmentValue('DREB_TEST_PASSWORD'),
            ),
            new Endpoint(self::environmentValue('DREB_TEST_BASE_URI', Endpoint::DEFAULT_BASE_URI)),
            new ClientOptions(new \DateTimeZone(self::environmentValue('DREB_TEST_TIMEZONE', 'UTC'))),
        );
    }

    private static function environmentValue(string $name, string $default = ''): string
    {
        $processValue = getenv($name);
        if ($processValue !== false) {
            return $processValue;
        }

        $value = $_ENV[$name] ?? $_SERVER[$name] ?? $default;

        return is_scalar($value) ? (string)$value : $default;
    }
}
