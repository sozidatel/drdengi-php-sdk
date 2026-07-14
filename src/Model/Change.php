<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Support\DrebedengiNormalizer;
use Soz\Drebedengi\Support\DrebedengiDateTime;

final readonly class Change implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public int $revision,
        public ChangeAction $action,
        public ChangedObjectType $objectType,
        public string $objectId,
        public ?\DateTimeImmutable $date,
        public array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromSoap(array $raw, ?\DateTimeZone $timezone = null): self
    {
        $timezone ??= new \DateTimeZone(date_default_timezone_get());
        $date = null;
        if (array_key_exists('date', $raw) && $raw['date'] !== null && $raw['date'] !== '') {
            $dateValue = DrebedengiNormalizer::requiredString($raw, 'date', 'change');
            try {
                $date = DrebedengiDateTime::parseDateTime($dateValue, $timezone);
            } catch (\Throwable $exception) {
                throw new UnexpectedResponseException(
                    'Drebedengi change response contains invalid field "date".',
                    0,
                    $exception,
                );
            }
        }

        $revision = DrebedengiNormalizer::requiredInteger($raw, 'revision', 'change');
        if ($revision < 0) {
            throw new UnexpectedResponseException('Drebedengi change response contains a negative revision.');
        }

        $actionValue = DrebedengiNormalizer::requiredInteger($raw, 'action_id', 'change');
        $action = ChangeAction::tryFrom($actionValue)
            ?? throw new UnexpectedResponseException(sprintf(
                'Drebedengi change response contains unknown action ID %d.',
                $actionValue,
            ));
        $objectTypeValue = DrebedengiNormalizer::requiredInteger($raw, 'object_type_id', 'change');
        $objectType = ChangedObjectType::tryFrom($objectTypeValue)
            ?? throw new UnexpectedResponseException(sprintf(
                'Drebedengi change response contains unknown object type ID %d.',
                $objectTypeValue,
            ));

        return new self(
            revision: $revision,
            action: $action,
            objectType: $objectType,
            objectId: DrebedengiNormalizer::requiredString($raw, 'object_id', 'change'),
            date: $date,
            raw: $raw,
        );
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
