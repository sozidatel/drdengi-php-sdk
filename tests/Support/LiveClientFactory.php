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
    public static function clientOrSkip(TestCase $testCase): DrebedengiClient
    {
        if (($_ENV['DREB_RUN_LIVE_TESTS'] ?? '0') !== '1') {
            $testCase::markTestSkipped('Set DREB_RUN_LIVE_TESTS=1 to run Drebedengi live integration tests.');
        }

        foreach (['DREB_TEST_API_ID', 'DREB_TEST_LOGIN', 'DREB_TEST_PASSWORD'] as $name) {
            if (trim((string)($_ENV[$name] ?? '')) === '') {
                $testCase::markTestSkipped(sprintf('Missing %s for Drebedengi live integration tests.', $name));
            }
        }

        return DrebedengiClient::fromCredentials(
            new Credentials(
                (string)$_ENV['DREB_TEST_API_ID'],
                (string)$_ENV['DREB_TEST_LOGIN'],
                (string)$_ENV['DREB_TEST_PASSWORD'],
            ),
            new Endpoint((string)($_ENV['DREB_TEST_BASE_URI'] ?? Endpoint::DEFAULT_BASE_URI)),
            new ClientOptions(new \DateTimeZone((string)($_ENV['DREB_TEST_TIMEZONE'] ?? 'UTC'))),
        );
    }
}
