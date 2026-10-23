<?php

declare(strict_types=1);

/*
 * A scripted SMTP server for the Mailer's tests (tests/Fakes/FakeSmtpServer.php starts it with proc_open). It listens
 * on 127.0.0.1 on a port the system picks, prints that port on its first line, serves one connection and exits.
 *
 * Usage: php smtp-server.php '<script as JSON>' <transcript file>
 *
 * The script maps each step to its reply: greeting, EHLO, AUTH (the reply to AUTH LOGIN), AUTH_USER, AUTH_PASS,
 * MAIL, RCPT, DATA, body (the reply after the message's lone dot) and QUIT. A reply of several lines separates them
 * with "\n". A null reply answers nothing and waits for the client to hang up; "CLOSE" hangs up at once. Every line
 * the client sends is appended to the transcript file as it arrives. Nothing here reaches beyond 127.0.0.1.
 */

$defaults = [
    'greeting' => '220 fake.test ESMTP ready',
    'EHLO' => "250-fake.test greets you\n250-AUTH LOGIN\n250 ENHANCEDSTATUSCODES",
    'AUTH' => '334 VXNlcm5hbWU6',
    'AUTH_USER' => '334 UGFzc3dvcmQ6',
    'AUTH_PASS' => '235 2.7.0 Authentication successful',
    'MAIL' => '250 2.1.0 Ok',
    'RCPT' => '250 2.1.5 Ok',
    'DATA' => '354 End data with <CR><LF>.<CR><LF>',
    'body' => '250 2.0.0 Ok: queued as 4ABC123',
    'QUIT' => '221 2.0.0 Bye',
    'UNKNOWN' => '502 5.5.2 Command not recognised',
];
$script = json_decode($argv[1] ?? '{}', true, 16, JSON_THROW_ON_ERROR);
$script = (is_array($script) ? $script : []) + $defaults;
$transcript = fopen((string) ($argv[2] ?? 'php://memory'), 'ab');

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false || $transcript === false) {
    fwrite(STDERR, "The fake SMTP server could not start\n");
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
fwrite(STDOUT, substr($name, (int) strrpos($name, ':') + 1) . "\n");
fflush(STDOUT);

$conn = @stream_socket_accept($server, 20);
if ($conn === false) {
    exit(2);
}
stream_set_timeout($conn, 20);

/** Sends the step's reply; false when the conversation ends here (no reply, or a hang-up). */
$say = static function (string $step) use ($conn, $script): bool {
    $reply = array_key_exists($step, $script) ? $script[$step] : $script['UNKNOWN'];
    if ($reply === null) {
        while (fgets($conn) !== false) {
            continue;
        }
        return false;
    }
    if ($reply === 'CLOSE') {
        return false;
    }
    fwrite($conn, str_replace("\n", "\r\n", (string) $reply) . "\r\n");
    return true;
};

if ($say('greeting')) {
    $auth = 0;
    while (($line = fgets($conn)) !== false) {
        fwrite($transcript, $line);
        $command = strtoupper((string) strtok(trim($line), ' :'));
        if ($auth === 1) {
            $step = 'AUTH_USER';
            $auth = 2;
        } elseif ($auth === 2) {
            $step = 'AUTH_PASS';
            $auth = 0;
        } else {
            $step = match ($command) {
                'EHLO', 'HELO' => 'EHLO',
                'AUTH', 'MAIL', 'RCPT', 'DATA', 'QUIT' => $command,
                default => 'UNKNOWN',
            };
            if ($step === 'AUTH' && str_starts_with((string) $script['AUTH'], '334')) {
                $auth = 1;
            }
        }
        if (!$say($step) || $step === 'QUIT') {
            break;
        }
        if ($step === 'DATA' && str_starts_with((string) $script['DATA'], '354')) {
            $ended = false;
            while (($part = fgets($conn)) !== false) {
                fwrite($transcript, $part);
                if ($part === ".\r\n") {
                    $ended = true;
                    break;
                }
            }
            if (!$ended || !$say('body')) {
                break;
            }
        }
    }
}
fclose($conn);
fclose($transcript);
