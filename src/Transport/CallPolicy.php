<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Transport;

/**
 * Conservative retry policy for SOAP methods, including raw calls.
 *
 * Methods not explicitly listed here are potentially mutating.
 */
final class CallPolicy
{
    private const READ_ONLY_METHODS = [
        'getAccessStatus' => true,
        'getAccumList' => true,
        'getBalance' => true,
        'getCategoryList' => true,
        'getChangeList' => true,
        'getCheckList' => true,
        'getCheckToRecordList' => true,
        'getCurrencyList' => true,
        'getCurrentRevision' => true,
        'getExpireDate' => true,
        'getOrderList' => true,
        'getPlaceList' => true,
        'getRightAccess' => true,
        'getSourceList' => true,
        'getSubscriptionStatus' => true,
        'getTagList' => true,
        'getUserIdByLogin' => true,
    ];

    /**
     * getRecordList is read-only only in explicit report mode. Drebedengi uses
     * is_report=false for initial synchronization and clears deduplication
     * state, so an unknown or legacy argument shape remains a mutation.
     *
     * @param list<mixed> $arguments
     */
    public static function isReadOnly(string $method, array $arguments = []): bool
    {
        if ($method !== 'getRecordList') {
            return isset(self::READ_ONLY_METHODS[$method]);
        }

        $params = $arguments[0] ?? null;

        return is_array($params) && ($params['is_report'] ?? null) === true;
    }
}
