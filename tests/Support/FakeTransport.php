<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Support;

use Soz\Drebedengi\Transport\TransportInterface;

final class FakeTransport implements TransportInterface
{
    /**
     * @var list<array{method: string, arguments: list<mixed>}>
     */
    public array $calls = [];

    /**
     * @param array<string, mixed> $responses
     */
    public function __construct(private readonly array $responses = [])
    {
    }

    public function call(string $method, array $arguments = []): mixed
    {
        $this->calls[] = [
            'method' => $method,
            'arguments' => $arguments,
        ];

        $response = $this->responses[$method] ?? [];
        if (is_array($response) && array_key_exists('__sequence', $response)) {
            $index = count(array_filter(
                $this->calls,
                static fn (array $call): bool => $call['method'] === $method,
            )) - 1;

            return $response['__sequence'][$index] ?? [];
        }

        return $response;
    }
}
