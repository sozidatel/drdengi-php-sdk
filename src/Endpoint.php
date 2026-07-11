<?php

declare(strict_types=1);

namespace Soz\Drebedengi;

use Soz\Drebedengi\Exception\InvalidArgumentException;

final readonly class Endpoint
{
    public const DEFAULT_BASE_URI = 'https://www.drebedengi.me';
    public const FALLBACK_BASE_URI = 'https://www.drebedengi.ru';

    private string $baseUri;

    public function __construct(string $baseUri = self::DEFAULT_BASE_URI)
    {
        $baseUri = rtrim(trim($baseUri), '/');
        if ($baseUri === '' || !str_contains($baseUri, '://')) {
            throw new InvalidArgumentException('Drebedengi endpoint must be an absolute base URI.');
        }

        $this->baseUri = $baseUri;
    }

    public function baseUri(): string
    {
        return $this->baseUri;
    }

    public function wsdlUri(): string
    {
        return $this->baseUri . '/soap/dd.wsdl';
    }

    public function soapLocation(): string
    {
        return $this->baseUri . '/soap/';
    }

    /**
     * @return non-empty-list<self>
     */
    public function failoverSequence(): array
    {
        if ($this->baseUri !== self::DEFAULT_BASE_URI) {
            return [$this];
        }

        return [
            $this,
            new self(self::FALLBACK_BASE_URI),
        ];
    }
}
