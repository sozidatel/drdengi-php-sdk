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
            $sequence = $response['__sequence'];
            if (!is_array($sequence) || !array_is_list($sequence)) {
                throw new \LogicException(sprintf(
                    'Fake response sequence for "%s" must be a list.',
                    $method,
                ));
            }

            $index = count(array_filter(
                $this->calls,
                static fn (array $call): bool => $call['method'] === $method,
            )) - 1;
            if ($index < 0) {
                throw new \LogicException(sprintf('No recorded call for "%s".', $method));
            }

            return $sequence[$index] ?? [];
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function mapArgument(int $callIndex, int $argumentIndex = 0): array
    {
        $value = $this->argument($callIndex, $argumentIndex);
        if (!is_array($value)) {
            throw new \LogicException(sprintf(
                'Argument %d of fake call %d must be an array, got %s.',
                $argumentIndex,
                $callIndex,
                get_debug_type($value),
            ));
        }

        return $this->stringKeyedMap($value, sprintf('argument %d of fake call %d', $argumentIndex, $callIndex));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function mapListArgument(int $callIndex, int $argumentIndex = 0): array
    {
        $value = $this->argument($callIndex, $argumentIndex);
        if (!is_array($value) || !array_is_list($value)) {
            throw new \LogicException(sprintf(
                'Argument %d of fake call %d must be a list.',
                $argumentIndex,
                $callIndex,
            ));
        }

        $result = [];
        foreach ($value as $itemIndex => $item) {
            if (!is_array($item)) {
                throw new \LogicException(sprintf(
                    'Item %d in argument %d of fake call %d must be an array, got %s.',
                    $itemIndex,
                    $argumentIndex,
                    $callIndex,
                    get_debug_type($item),
                ));
            }

            $result[] = $this->stringKeyedMap(
                $item,
                sprintf('item %d in argument %d of fake call %d', $itemIndex, $argumentIndex, $callIndex),
            );
        }

        return $result;
    }

    private function argument(int $callIndex, int $argumentIndex): mixed
    {
        if (!array_key_exists($callIndex, $this->calls)) {
            throw new \OutOfBoundsException(sprintf('Fake call %d was not recorded.', $callIndex));
        }

        $arguments = $this->calls[$callIndex]['arguments'];
        if (!array_key_exists($argumentIndex, $arguments)) {
            throw new \OutOfBoundsException(sprintf(
                'Argument %d of fake call %d was not recorded.',
                $argumentIndex,
                $callIndex,
            ));
        }

        return $arguments[$argumentIndex];
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private function stringKeyedMap(array $value, string $context): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \LogicException(sprintf('%s must use string keys.', ucfirst($context)));
            }

            $result[$key] = $item;
        }

        return $result;
    }
}
