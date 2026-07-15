<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Support\DrebedengiDateTime;
use Soz\Drebedengi\Transport\TransportInterface;

final readonly class AccountService
{
    public function __construct(
        private TransportInterface $transport,
        private ClientOptions $options = new ClientOptions(),
    )
    {
    }

    public function hasAccess(): bool
    {
        return $this->binaryStatus('getAccessStatus') === '1';
    }

    public function userId(): string
    {
        $value = $this->nonEmptyScalar('getUserIdByLogin');
        if (preg_match('/^[1-9]\d*$/D', $value) !== 1) {
            throw new UnexpectedResponseException(
                'Drebedengi getUserIdByLogin response must be a positive decimal integer ID.',
            );
        }

        return $value;
    }

    public function expireDate(): ?\DateTimeImmutable
    {
        $value = $this->nonEmptyScalar('getExpireDate');
        if ($value === '0' || $value === '-1') {
            return null;
        }

        try {
            return DrebedengiDateTime::parseDate($value, $this->options->timezone);
        } catch (UnexpectedResponseException $exception) {
            throw new UnexpectedResponseException(
                'Drebedengi getExpireDate response must be 0, -1, or a valid date in format "Y-m-d".',
                0,
                $exception,
            );
        }
    }

    public function rightAccess(): string
    {
        return $this->binaryStatus('getRightAccess');
    }

    public function subscriptionStatus(): string
    {
        return $this->binaryStatus('getSubscriptionStatus');
    }

    private function binaryStatus(string $method): string
    {
        $value = $this->nonEmptyScalar($method);
        if ($value !== '0' && $value !== '1') {
            throw new UnexpectedResponseException(sprintf(
                'Drebedengi %s response must be 0 or 1.',
                $method,
            ));
        }

        return $value;
    }

    private function nonEmptyScalar(string $method): string
    {
        $response = $this->transport->call($method);
        if (!is_int($response) && !is_string($response)) {
            throw new UnexpectedResponseException(sprintf(
                'Drebedengi %s response must be a non-empty string or integer, got %s.',
                $method,
                get_debug_type($response),
            ));
        }

        $value = trim((string)$response);
        if ($value === '') {
            throw new UnexpectedResponseException(sprintf(
                'Drebedengi %s response must be a non-empty string or integer.',
                $method,
            ));
        }

        return $value;
    }
}
