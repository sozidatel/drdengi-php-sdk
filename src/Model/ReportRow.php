<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Support\DrebedengiNormalizer;

final readonly class ReportRow implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $objectId,
        public string $name,
        public ?string $parentId,
        public DecimalMoneyAmount $amount,
        public string $currencyId,
        public bool $leaf,
        public ?string $nickname,
        public array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromSoap(array $raw, Currency $currency): self
    {
        $currencyId = DrebedengiNormalizer::requiredString($raw, 'currency_id', 'report row');
        if ($currency->id !== $currencyId) {
            throw new InvalidArgumentException(sprintf(
                'Report row currency ID "%s" does not match supplied currency "%s".',
                $currencyId,
                $currency->id,
            ));
        }

        $difference = $raw['difference'] ?? null;
        if (!is_int($difference) && !is_float($difference) && !is_string($difference)) {
            throw new UnexpectedResponseException('Drebedengi report row response is missing numeric difference.');
        }

        try {
            $amount = new DecimalMoneyAmount($difference, $currency->decimalPlaces, $currencyId);
        } catch (InvalidArgumentException $exception) {
            throw new UnexpectedResponseException(
                'Drebedengi report row response contains invalid numeric difference.',
                previous: $exception,
            );
        }

        $parentId = DrebedengiNormalizer::requiredString($raw, 'parent_id', 'report row');
        $noChildren = DrebedengiNormalizer::requiredString($raw, 'nochild', 'report row');
        $nickname = DrebedengiNormalizer::string($raw['nick'] ?? '');

        return new self(
            objectId: DrebedengiNormalizer::requiredString($raw, 'budget_object_id', 'report row'),
            name: DrebedengiNormalizer::requiredString($raw, 'name', 'report row'),
            parentId: DrebedengiNormalizer::nullableId($parentId),
            amount: $amount,
            currencyId: $currencyId,
            leaf: DrebedengiNormalizer::bool($noChildren),
            nickname: $nickname === '' ? null : $nickname,
            raw: $raw,
        );
    }

    public function hasChildren(): bool
    {
        return !$this->leaf;
    }

    /**
     * @return array{
     *     objectId: string,
     *     name: string,
     *     parentId: string|null,
     *     amount: DecimalMoneyAmount,
     *     currencyId: string,
     *     leaf: bool,
     *     nickname: string|null,
     *     raw: array<string, mixed>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'objectId' => $this->objectId,
            'name' => $this->name,
            'parentId' => $this->parentId,
            'amount' => $this->amount,
            'currencyId' => $this->currencyId,
            'leaf' => $this->leaf,
            'nickname' => $this->nickname,
            'raw' => $this->raw,
        ];
    }
}
