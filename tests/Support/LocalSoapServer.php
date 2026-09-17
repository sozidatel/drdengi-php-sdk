<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Support;

final class LocalSoapServer
{
    public readonly string $baseUri;

    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    public function __construct(string $fixture = 'soap-server.php')
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../Fixtures/' . $fixture],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            env_vars: [],
        );
        if ($process === false) {
            throw new \RuntimeException('Cannot start local SOAP fixture.');
        }
        $this->process = $process;
        $this->pipes = $pipes;
        $read = [$pipes[1]];
        $write = $except = [];
        if (stream_select($read, $write, $except, 5) !== 1) {
            $this->stop();
            throw new \RuntimeException('Local SOAP fixture did not start.');
        }
        $address = fgets($pipes[1]);
        if ($address === false || preg_match('/^127\.0\.0\.1:\d+\s*$/', $address) !== 1) {
            $this->stop();
            throw new \RuntimeException('Local SOAP fixture returned an invalid address.');
        }
        $this->baseUri = 'http://' . trim($address);
    }

    public function stop(): void
    {
        if (!is_resource($this->process)) {
            return;
        }
        proc_terminate($this->process);
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($this->process);
    }

    public function __destruct()
    {
        $this->stop();
    }
}
