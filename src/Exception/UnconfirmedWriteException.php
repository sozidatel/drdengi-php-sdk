<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Exception;

/**
 * A record write returned a response that cannot confirm the submitted records.
 * Reconcile the result by reading the ledger; do not automatically repeat it.
 */
final class UnconfirmedWriteException extends AmbiguousMutationException
{
    /** @param list<array<string, mixed>> $payloads */
    public function __construct(
        public readonly array $payloads,
        public readonly mixed $response,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            message: 'Drebedengi setRecordList returned an unconfirmed result. '
                . 'The records may have been saved; reconcile them before retrying.',
            previous: $previous,
            method: 'setRecordList',
        );
    }
}
