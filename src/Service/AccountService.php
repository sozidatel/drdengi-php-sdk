<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\ClientOptions;
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
        return (int)$this->transport->call('getAccessStatus') === 1;
    }

    public function userId(): string
    {
        return (string)$this->transport->call('getUserIdByLogin');
    }

    public function expireDate(): ?\DateTimeImmutable
    {
        $value = trim((string)$this->transport->call('getExpireDate'));
        if ($value === '' || $value === '0' || $value === '-1') {
            return null;
        }

        return DrebedengiDateTime::parseDate($value, $this->options->timezone);
    }

    public function rightAccess(): string
    {
        return (string)$this->transport->call('getRightAccess');
    }

    public function subscriptionStatus(): string
    {
        return (string)$this->transport->call('getSubscriptionStatus');
    }
}
