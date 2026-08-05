<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use RuntimeException;

/**
 * Runs the isolated SMTP fixture without an additional process dependency.
 */
final class SmtpTestProcess
{
    /**
     * The child process resource.
     *
     * @var resource|null
     */
    private $process;

    /**
     * Child process output streams.
     *
     * @var array<int, resource>
     */
    private array $pipes;

    /**
     * Create a running SMTP fixture process.
     *
     * @param  resource  $process
     * @param  array<int, resource>  $pipes
     */
    private function __construct(
        $process,
        array $pipes,
        public readonly int $port,
        public readonly string $capturePath,
    ) {
        $this->process = $process;
        $this->pipes = $pipes;
    }

    /**
     * Start one isolated SMTP fixture and wait for its listening port.
     */
    public static function start(bool $acceptMessage): self
    {
        $capturePath = tempnam(sys_get_temp_dir(), 'nvl-mail-smtp-');

        if ($capturePath === false) {
            throw new RuntimeException('Unable to prepare the SMTP capture file.');
        }

        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__.'/smtp-server.php',
                $capturePath,
                $acceptMessage ? 'accept' : 'reject',
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (! is_resource($process)) {
            unlink($capturePath);

            throw new RuntimeException('Unable to start the SMTP test process.');
        }

        fclose($pipes[0]);
        unset($pipes[0]);
        stream_set_blocking($pipes[1], false);
        $port = self::awaitPort($process, $pipes);

        if ($port === null) {
            $error = trim((string) stream_get_contents($pipes[2]));
            self::terminate($process, $pipes);
            unlink($capturePath);

            throw new RuntimeException(
                $error !== ''
                    ? "The SMTP test process failed: {$error}"
                    : 'The SMTP test process did not expose a valid listening port.',
            );
        }

        return new self($process, $pipes, $port, $capturePath);
    }

    /**
     * Wait for the SMTP fixture to exit and return its delivery result code.
     */
    public function wait(): int
    {
        $process = $this->process;

        if (! is_resource($process)) {
            throw new RuntimeException('The SMTP test process is not running.');
        }

        $deadline = microtime(true) + 10;
        $status = proc_get_status($process);

        while ($status['running'] && microtime(true) < $deadline) {
            usleep(10_000);
            $status = proc_get_status($process);
        }

        if ($status['running']) {
            $this->stop();

            throw new RuntimeException('The SMTP test process did not exit in time.');
        }

        $error = trim((string) stream_get_contents($this->pipes[2]));
        self::closePipes($this->pipes);
        $closeExitCode = proc_close($process);
        $this->process = null;

        if ($error !== '') {
            throw new RuntimeException("The SMTP test process failed: {$error}");
        }

        return $status['exitcode'] >= 0
            ? $status['exitcode']
            : $closeExitCode;
    }

    /**
     * Stop the fixture process when a test exits before normal SMTP shutdown.
     */
    public function stop(): void
    {
        if (! is_resource($this->process)) {
            return;
        }

        self::terminate($this->process, $this->pipes);
        $this->process = null;
    }

    /**
     * Stop a leaked process during object destruction.
     */
    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Wait for the child process to publish a valid TCP port.
     *
     * @param  resource  $process
     * @param  array<int, resource>  $pipes
     */
    private static function awaitPort($process, array $pipes): ?int
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            $line = fgets($pipes[1]);

            if (is_string($line)) {
                $port = filter_var(trim($line), FILTER_VALIDATE_INT);

                if (is_int($port) && $port >= 1 && $port <= 65535) {
                    return $port;
                }
            }

            if (! proc_get_status($process)['running']) {
                return null;
            }

            usleep(10_000);
        }

        return null;
    }

    /**
     * Terminate a child process and close its streams.
     *
     * @param  resource  $process
     * @param  array<int, resource>  $pipes
     */
    private static function terminate($process, array $pipes): void
    {
        if (proc_get_status($process)['running']) {
            proc_terminate($process);
        }

        self::closePipes($pipes);
        proc_close($process);
    }

    /**
     * Close all child-process streams.
     *
     * @param  array<int, resource>  $pipes
     */
    private static function closePipes(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }
}
