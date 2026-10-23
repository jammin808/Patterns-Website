<?php

declare(strict_types=1);

namespace Psc\Tests\Fakes;

/**
 * A scripted SMTP server on 127.0.0.1 for one connection (tests/Fakes/smtp-server.php), started with proc_open on a
 * port the system picks. A test passes the replies it wants (see the script's header), points the Mailer's smtp
 * settings at port(), sends, then reads what the client said with transcript(). stop() ends the process it started,
 * by its own handle, and runs from the destructor too.
 */
final class FakeSmtpServer
{
    /** @var resource|null */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    private function __construct(public readonly int $port, private readonly string $transcriptFile)
    {
    }

    /** @param array<string, string|null> $script the replies that differ from a server that accepts everything */
    public static function start(array $script, string $dir): self
    {
        $transcript = $dir . '/smtp-' . bin2hex(random_bytes(4)) . '.txt';
        touch($transcript);
        $process = proc_open(
            [PHP_BINARY, '-n', __DIR__ . '/smtp-server.php', json_encode((object) $script, JSON_THROW_ON_ERROR), $transcript],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if ($process === false) {
            throw new \RuntimeException('The fake SMTP server did not start');
        }
        $read = [$pipes[1]];
        $none = null;
        $line = stream_select($read, $none, $none, 10) === 1 ? fgets($pipes[1]) : false;
        $port = (int) trim((string) $line);
        $server = new self($port, $transcript);
        $server->process = $process;
        $server->pipes = $pipes;
        if ($port <= 0) {
            $server->stop();
            throw new \RuntimeException('The fake SMTP server gave no port');
        }
        return $server;
    }

    /** A port on 127.0.0.1 with nothing listening, so a connection to it is refused. */
    public static function closedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new \RuntimeException('No free port');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /** Everything the client sent, once the conversation has ended (stop() waits for that). */
    public function transcript(): string
    {
        $this->stop();
        return (string) file_get_contents($this->transcriptFile);
    }

    /** Waits a few seconds for the server to finish its one conversation, then ends it if it has not. */
    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }
        for ($i = 0; $i < 150 && (proc_get_status($this->process)['running'] ?? false); $i++) {
            usleep(20000);
        }
        if (proc_get_status($this->process)['running'] ?? false) {
            proc_terminate($this->process);
        }
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        $this->pipes = [];
        proc_close($this->process);
        $this->process = null;
    }

    public function __destruct()
    {
        $this->stop();
    }
}
