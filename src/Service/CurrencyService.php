<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Model\Currency;
use Soz\Drebedengi\Model\DeleteObjectType;
use Soz\Drebedengi\Model\ReferenceWriteToken;
use Soz\Drebedengi\Model\WriteResult;
use Soz\Drebedengi\Support\CurrencyCatalog;
use Soz\Drebedengi\Support\DrebedengiNormalizer;
use Soz\Drebedengi\Transport\TransportInterface;

final readonly class CurrencyService
{
    private CurrencyCatalog $currencies;

    public function __construct(
        private TransportInterface $transport,
        ?CurrencyCatalog $currencies = null,
    )
    {
        $this->currencies = $currencies ?? new CurrencyCatalog($transport);
    }

    /**
     * @return list<Currency>
     */
    public function list(): array
    {
        return $this->currencies->list();
    }

    /**
     * Fetches exactly the requested currencies without consulting the shared
     * full-catalog cache.
     *
     * @param list<int|string> $ids
     * @return list<Currency>
     */
    public function byIds(array $ids): array
    {
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            return [];
        }

        return array_map(
            Currency::fromSoap(...),
            DrebedengiNormalizer::listOfArrays($this->transport->call('getCurrencyList', [$ids])),
        );
    }

    public function find(int|string $id): ?Currency
    {
        return $this->currencies->find($id);
    }

    public function require(int|string $id): Currency
    {
        return $this->currencies->require($id);
    }

    public function findByCode(string $code): ?Currency
    {
        $code = trim($code);

        foreach ($this->list() as $currency) {
            if ($currency->code !== null && strcasecmp($currency->code, $code) === 0) {
                return $currency;
            }
        }

        return null;
    }

    public function requireByCode(string $code): Currency
    {
        return $this->findByCode($code)
            ?? throw new InvalidArgumentException(sprintf('Unknown currency code "%s".', $code));
    }

    public function default(): ?Currency
    {
        foreach ($this->list() as $currency) {
            if ($currency->default) {
                return $currency;
            }
        }

        return null;
    }

    public function create(
        string $name,
        int|string $course = '1',
        ?string $code = null,
        bool $autoUpdate = false,
        bool $hidden = true,
        ?ReferenceWriteToken $writeToken = null,
    ): Currency {
        $name = $this->normalizeBoundedText($name, 'Currency name', 16, allowEmpty: false);
        $code = $this->normalizeBoundedText($code ?? '', 'Currency code', 16, allowEmpty: true);
        $course = $this->normalizeCourse($course);
        if ($autoUpdate && $code === '') {
            throw new InvalidArgumentException('Currency code cannot be empty when automatic course updates are enabled.');
        }

        $writeToken ??= ReferenceWriteToken::generate();
        $payload = [
            'client_id' => $writeToken->clientId,
            'name' => $name,
            'course' => $course,
            'code' => $code,
            'is_default' => false,
            'is_autoupdate' => $autoUpdate,
            'is_hidden' => $hidden,
        ];

        $this->currencies->invalidate();
        $result = $this->writePayloads([$payload]);
        $serverId = $result->serverIdForClientId($writeToken->clientId);
        if ($serverId === null) {
            throw new UnexpectedResponseException(
                'Drebedengi setCurrencyList response does not contain the created currency client_id mapping.',
            );
        }

        $this->refreshCatalogAfterMutation();

        return $this->currencies->find($serverId)
            ?? throw new UnexpectedResponseException(sprintf(
                'Created Drebedengi currency "%s" was not found by server id %s.',
                $name,
                $serverId,
            ));
    }

    /**
     * Safely updates a standard currency. Drebedengi setCurrencyList forcibly
     * writes ratio=1 and is_investing=false, so crypto/investing currencies are
     * rejected before any mutation.
     *
     * Supported fields: name, course, code, is_autoupdate, is_hidden.
     *
     * @param array<string, mixed> $fields
     */
    public function update(int|string $id, array $fields): Currency
    {
        $id = (string)DrebedengiNormalizer::positiveIntegerId($id, 'Currency ID');
        $patch = $this->normalizeUpdatePatch($fields);
        $this->assertFullAccess();
        $currency = $this->requireDirect($id, 'update');
        $this->assertSafelyWritable($currency);

        $payload = array_replace($this->payloadFor($currency), $patch);
        if ($payload['is_autoupdate'] && $payload['code'] === '') {
            throw new InvalidArgumentException(
                'Currency code cannot be empty when automatic course updates are enabled.',
            );
        }
        $this->currencies->invalidate();
        $this->confirmExistingWrite($payload, $id);
        $this->refreshCatalogAfterMutation();

        return $this->currencies->find($id)
            ?? throw new UnexpectedResponseException(sprintf(
                'Updated Drebedengi currency %s was not found after refresh.',
                $id,
            ));
    }

    /**
     * Makes a standard currency the account default. This is separate from
     * update() because the server also changes the previous default currency.
     */
    public function setDefault(int|string $id): Currency
    {
        $id = (string)DrebedengiNormalizer::positiveIntegerId($id, 'Currency ID');
        $this->assertFullAccess();
        $currency = $this->requireDirect($id, 'set as default');
        if ($currency->default) {
            // No mutation happened, so keep the previous valid snapshot if
            // this synchronization read fails.
            $this->currencies->refresh();
            $current = $this->currencies->find($id);
            if (!$current instanceof Currency || !$current->default) {
                throw new UnexpectedResponseException(sprintf(
                    'Drebedengi currency %s is not the default currency after catalog refresh.',
                    $id,
                ));
            }

            return $current;
        }
        $this->assertSafelyWritable($currency);

        $payload = $this->payloadFor($currency);
        $payload['is_default'] = true;
        $this->currencies->invalidate();
        $this->confirmExistingWrite($payload, $id);
        $this->refreshCatalogAfterMutation();
        $updated = $this->currencies->find($id);
        if (!$updated instanceof Currency || !$updated->default) {
            throw new UnexpectedResponseException(sprintf(
                'Drebedengi currency %s was not marked as default after refresh.',
                $id,
            ));
        }

        return $updated;
    }

    public function delete(int|string $id): bool
    {
        $id = DrebedengiNormalizer::positiveIntegerId($id, 'Currency ID');
        $this->assertFullAccess();
        $currency = $this->byIds([$id])[0] ?? null;
        if (!$currency instanceof Currency || $currency->id !== (string)$id) {
            return false;
        }
        if ($currency->default) {
            throw new InvalidArgumentException(
                'Cannot delete the default Drebedengi currency; set another default currency first.',
            );
        }

        $this->currencies->invalidate();
        $response = $this->transport->call('deleteObject', [$id, DeleteObjectType::Currency->value]);
        $deleted = $this->deleteStatus($response);
        if (!$deleted) {
            return false;
        }

        $this->refreshCatalogAfterMutation();
        if ($this->currencies->find($id) !== null) {
            throw new UnexpectedResponseException(sprintf(
                'Deleted Drebedengi currency %s is still present after refresh.',
                $id,
            ));
        }

        return true;
    }

    /** @return list<Currency> */
    public function refresh(): array
    {
        return $this->currencies->refresh();
    }

    /**
     * Raw setCurrencyList escape hatch. Call refresh() afterwards if the shared
     * catalog must immediately reflect the mutation.
     *
     * @param list<array<string, mixed>> $payloads
     * @return list<array<string, mixed>>
     */
    public function savePayloads(array $payloads): array
    {
        if ($payloads === []) {
            return [];
        }

        return DrebedengiNormalizer::listOfArrays($this->transport->call('setCurrencyList', [$payloads]));
    }

    /**
     * @param list<array<string, mixed>> $payloads
     */
    private function writePayloads(array $payloads): WriteResult
    {
        return new WriteResult($this->savePayloads($payloads), $payloads);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function confirmExistingWrite(array $payload, string $serverId): void
    {
        $result = new WriteResult($this->savePayloads([$payload]));
        if (!in_array($serverId, $result->serverIds, true)) {
            throw new UnexpectedResponseException(sprintf(
                'Drebedengi setCurrencyList response does not confirm currency server_id %s.',
                $serverId,
            ));
        }
    }

    /** @return list<Currency> */
    private function refreshCatalogAfterMutation(): array
    {
        return $this->currencies->refresh();
    }

    /**
     * @param list<int|string> $ids
     * @return list<string>
     */
    private function normalizeIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            $id = (string)DrebedengiNormalizer::positiveIntegerId($id, 'Currency ID');
            if (!in_array($id, $normalized, true)) {
                $normalized[] = $id;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, bool|string>
     */
    private function normalizeUpdatePatch(array $fields): array
    {
        if ($fields === []) {
            throw new InvalidArgumentException('Cannot update a Drebedengi currency without fields.');
        }

        $mutable = ['name', 'course', 'code', 'is_autoupdate', 'is_hidden'];
        $ignored = [
            'id',
            'server_id',
            'family_id',
            'is_default',
            'is_investing',
            'ratio',
        ];
        $unsupported = array_values(array_diff(array_keys($fields), [...$mutable, ...$ignored]));
        if ($unsupported !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported Drebedengi currency update field(s): %s.',
                implode(', ', $unsupported),
            ));
        }

        $patch = [];
        foreach ($fields as $field => $value) {
            switch ($field) {
                case 'name':
                    if (!is_string($value)) {
                        throw new InvalidArgumentException('Currency name must be a string.');
                    }
                    $patch[$field] = $this->normalizeBoundedText($value, 'Currency name', 16, allowEmpty: false);
                    break;
                case 'course':
                    if (!is_int($value) && !is_string($value)) {
                        throw new InvalidArgumentException('Currency course must be an integer or decimal string.');
                    }
                    $patch[$field] = $this->normalizeCourse($value);
                    break;
                case 'code':
                    if ($value !== null && !is_string($value)) {
                        throw new InvalidArgumentException('Currency code must be a string or null.');
                    }
                    $patch[$field] = $this->normalizeBoundedText(
                        $value ?? '',
                        'Currency code',
                        16,
                        allowEmpty: true,
                    );
                    break;
                case 'is_autoupdate':
                case 'is_hidden':
                    if (!is_bool($value)) {
                        throw new InvalidArgumentException(sprintf(
                            'Currency field "%s" must be a boolean.',
                            $field,
                        ));
                    }
                    $patch[$field] = $value;
                    break;
                case 'id':
                case 'server_id':
                case 'family_id':
                case 'is_default':
                case 'is_investing':
                case 'ratio':
                    break;
            }
        }

        if ($patch === []) {
            throw new InvalidArgumentException('Cannot update a Drebedengi currency without mutable fields.');
        }
        if (($patch['is_autoupdate'] ?? false) === true && ($patch['code'] ?? null) === '') {
            throw new InvalidArgumentException('Currency code cannot be empty when automatic course updates are enabled.');
        }

        return $patch;
    }

    /**
     * @return array{
     *     server_id: string,
     *     name: string,
     *     course: string,
     *     code: string,
     *     is_default: bool,
     *     is_autoupdate: bool,
     *     is_hidden: bool
     * }
     */
    private function payloadFor(Currency $currency): array
    {
        return [
            'server_id' => $currency->id,
            'name' => $this->decodeSoapHtml($currency->name),
            'course' => $currency->course ?? '1',
            'code' => $this->decodeSoapHtml($currency->code ?? ''),
            'is_default' => $currency->default,
            'is_autoupdate' => $currency->autoUpdate,
            'is_hidden' => $currency->hidden,
        ];
    }

    private function requireDirect(string $id, string $operation): Currency
    {
        foreach ($this->byIds([$id]) as $currency) {
            if ($currency->id === $id) {
                return $currency;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Unknown Drebedengi currency ID "%s"; cannot %s it.',
            $id,
            $operation,
        ));
    }

    private function assertSafelyWritable(Currency $currency): void
    {
        if ($currency->ratio !== 1 || $currency->investing) {
            throw new InvalidArgumentException(sprintf(
                'Drebedengi currency %s cannot be written safely: setCurrencyList would reset ratio and investing state.',
                $currency->id,
            ));
        }
    }

    private function assertFullAccess(): void
    {
        if ((new AccountService($this->transport))->rightAccess() !== '0') {
            throw new InvalidArgumentException(
                'Drebedengi currency mutations require full account access.',
            );
        }
    }

    private function normalizeBoundedText(
        string $value,
        string $context,
        int $maxLength,
        bool $allowEmpty,
    ): string {
        $value = trim($value);
        if (!$allowEmpty && $value === '') {
            throw new InvalidArgumentException(sprintf('%s cannot be empty.', $context));
        }

        $length = preg_match_all('/./us', $value);
        if ($length === false) {
            throw new InvalidArgumentException(sprintf('%s must be valid UTF-8.', $context));
        }
        if ($length > $maxLength) {
            throw new InvalidArgumentException(sprintf(
                '%s must not exceed %d characters.',
                $context,
                $maxLength,
            ));
        }

        return $value;
    }

    private function normalizeCourse(int|string $course): string
    {
        $course = trim((string)$course);
        if (
            !preg_match('/^\+?(?:\d+(?:\.\d*)?|\.\d+)$/D', $course)
            || !preg_match('/[1-9]/', $course)
        ) {
            throw new InvalidArgumentException('Currency course must be a positive decimal number.');
        }

        return ltrim($course, '+');
    }

    private function decodeSoapHtml(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private function deleteStatus(mixed $response): bool
    {
        if (!is_int($response) && !is_string($response)) {
            throw new UnexpectedResponseException(sprintf(
                'Drebedengi deleteObject response for currency must be an integer, got %s.',
                get_debug_type($response),
            ));
        }

        $status = filter_var(trim((string)$response), FILTER_VALIDATE_INT);
        if ($status === false) {
            throw new UnexpectedResponseException(
                'Drebedengi deleteObject response for currency is not an integer.',
            );
        }
        if ($status !== 0 && $status !== 1) {
            throw new UnexpectedResponseException(
                'Drebedengi deleteObject response for currency must be 0 or 1.',
            );
        }

        return $status === 1;
    }
}
