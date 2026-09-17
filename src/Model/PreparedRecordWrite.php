<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Exception\InvalidArgumentException;

/**
 * An immutable snapshot of a record creation request, without credentials.
 *
 * Saving a snapshot does not extend the server's limited deduplication window.
 * Restore only trusted snapshots: JSON is a storage format, not an approval or
 * authenticity guarantee. Amount scales describe the captured request and are
 * not looked up again when the snapshot is restored.
 *
 * @phpstan-type Payload array{client_id: int, place_id: string, budget_object_id: string, sum: int, operation_date: string, comment: string, currency_id: string, is_duty: bool, operation_type: int, group_id?: string, client_move_id?: int, client_change_id?: int}
 */
final readonly class PreparedRecordWrite implements \JsonSerializable, \Countable
{
    /** @var array<string, string> */
    private const REQUIRED_FIELDS = [
        'client_id' => 'integer',
        'place_id' => 'string',
        'budget_object_id' => 'string',
        'sum' => 'integer',
        'operation_date' => 'string',
        'comment' => 'string',
        'currency_id' => 'string',
        'is_duty' => 'boolean',
        'operation_type' => 'integer',
    ];

    /** @var array<string, string> */
    private const OPTIONAL_FIELDS = [
        'group_id' => 'string',
        'client_move_id' => 'integer',
        'client_change_id' => 'integer',
    ];

    /** @var list<Payload> */
    public array $payloads;

    /** @var list<MoneyAmount> */
    public array $amounts;

    /** @var list<int> */
    private array $scales;

    /**
     * @param array<mixed> $payloads Creation rows, in their submission order.
     * @param array<mixed> $scales One captured currency scale for each row.
     */
    public function __construct(array $payloads, array $scales)
    {
        if (!array_is_list($payloads) || !array_is_list($scales) || count($payloads) !== count($scales)) {
            throw new InvalidArgumentException('Prepared record payloads and scales must be lists of equal length.');
        }

        $copiedPayloads = [];
        $copiedScales = [];
        $amounts = [];
        foreach ($payloads as $index => $payload) {
            $row = self::copyPayload($payload);
            $scale = $scales[$index];
            if (!is_int($scale)) {
                throw new InvalidArgumentException('Prepared record scales must be integers.');
            }

            $copiedPayloads[] = $row;
            $copiedScales[] = $scale;
            $amounts[] = new MoneyAmount($row['sum'], $scale, $row['currency_id']);
        }

        self::assertLinks($copiedPayloads);
        $this->payloads = $copiedPayloads;
        $this->scales = $copiedScales;
        $this->amounts = $amounts;
    }

    public function count(): int
    {
        return count($this->payloads);
    }

    public function toJson(): string
    {
        try {
            return json_encode($this, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('Prepared record write cannot be encoded as JSON.', previous: $exception);
        }
    }

    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('Prepared record write contains invalid JSON.', previous: $exception);
        }

        if (!$decoded instanceof \stdClass) {
            throw new InvalidArgumentException('Prepared record write JSON must contain an object.');
        }

        $data = get_object_vars($decoded);
        if (isset($data['payloads']) && is_array($data['payloads'])) {
            $rows = [];
            foreach ($data['payloads'] as $payload) {
                if (!$payload instanceof \stdClass) {
                    throw new InvalidArgumentException('Prepared record write JSON payloads must contain objects.');
                }
                $rows[] = get_object_vars($payload);
            }
            $data['payloads'] = $rows;
        }

        return self::fromArray($data);
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 3
            || ($data['version'] ?? null) !== 1
            || !isset($data['payloads'], $data['scales'])
            || !is_array($data['payloads'])
            || !is_array($data['scales'])
        ) {
            throw new InvalidArgumentException('Prepared record write must contain version 1, payloads, and scales only.');
        }

        return new self($data['payloads'], $data['scales']);
    }

    /** @return array{version: 1, payloads: list<Payload>, scales: list<int>} */
    public function jsonSerialize(): array
    {
        return ['version' => 1, 'payloads' => $this->payloads, 'scales' => $this->scales];
    }

    /** @return Payload */
    private static function copyPayload(mixed $payload): array
    {
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Each prepared record payload must be an array.');
        }

        foreach (self::REQUIRED_FIELDS as $field => $type) {
            if (!array_key_exists($field, $payload) || gettype($payload[$field]) !== $type) {
                throw new InvalidArgumentException(sprintf('Prepared record field "%s" must be %s.', $field, $type));
            }
        }

        $fields = self::REQUIRED_FIELDS + self::OPTIONAL_FIELDS;
        $copy = [];
        foreach ($payload as $field => $value) {
            if (!isset($fields[$field]) || gettype($value) !== $fields[$field]) {
                throw new InvalidArgumentException(sprintf('Unsupported prepared record field or type: "%s".', $field));
            }
            // Assign each scalar by value, detaching references held by callers.
            $copy[$field] = $value;
        }

        /** @var Payload $copy All required and optional scalar types were checked above. */
        if (!in_array($copy['operation_type'], [2, 3, 4, 5], true)) {
            throw new InvalidArgumentException('Prepared records only support expense, income, transfer, or exchange creation.');
        }

        return $copy;
    }

    /** @param list<Payload> $payloads */
    private static function assertLinks(array $payloads): void
    {
        if ($payloads === []) {
            return;
        }

        $clientIds = array_column($payloads, 'client_id');
        // Reuse the token's ID range and uniqueness rules without generating IDs.
        new RecordWriteToken($clientIds, $clientIds[0]);
        $byId = array_column($payloads, null, 'client_id');
        $groups = [];
        foreach ($payloads as $payload) {
            $operation = OperationType::from($payload['operation_type']);
            $linkField = match ($operation) {
                OperationType::Transfer => 'client_move_id',
                OperationType::Exchange => 'client_change_id',
                default => null,
            };

            foreach (['client_move_id', 'client_change_id'] as $field) {
                if (isset($payload[$field]) && $field !== $linkField) {
                    throw new InvalidArgumentException('Prepared record link does not match its operation type.');
                }
            }
            if ($linkField !== null) {
                $partnerId = $payload[$linkField] ?? null;
                $partner = $partnerId === null ? null : ($byId[$partnerId] ?? null);
                if (
                    $partner === null
                    || $partnerId === $payload['client_id']
                    || $partner['operation_type'] !== $payload['operation_type']
                    || ($partner[$linkField] ?? null) !== $payload['client_id']
                ) {
                    throw new InvalidArgumentException('Prepared transfer and exchange rows must have reciprocal client links.');
                }
            }

            if (isset($payload['group_id'])) {
                if ($operation !== OperationType::Expense) {
                    throw new InvalidArgumentException('Only prepared expenses may have a group ID.');
                }
                new RecordWriteToken([$payload['client_id']], $payload['group_id']);
                $groups[$payload['group_id']] = ($groups[$payload['group_id']] ?? 0) + 1;
            }
        }

        foreach ($groups as $count) {
            if ($count < 2) {
                throw new InvalidArgumentException('A prepared expense group must contain at least two rows.');
            }
        }
    }
}
