<?php

declare(strict_types=1);

namespace Psc\Tests;

use Psc\App;
use Psc\Config;
use Psc\Content\Content;
use Psc\Controllers\ChatController;
use Psc\Db\Database;
use Psc\Db\Migrator;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\Mail\Mailer;

/**
 * Boots a fresh application per test: an in-memory SQLite database with every migration applied, a temporary
 * storage directory, emails captured by the log driver, and an empty session. request() runs a request through
 * the whole pipeline (routing, middleware, controller, headers) without a web server.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected App $app;
    protected Database $db;
    protected string $storage;
    protected Mailer $mail;

    /** @var array<string, string> cookies the "browser" holds between requests */
    protected array $jar = [];

    /** @param array<string, mixed> $config */
    protected function bootApp(array $config = []): void
    {
        $this->storage = sys_get_temp_dir() . '/psc-test-' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0777, true);
        $base = [
            'app' => [
                'url' => 'https://patternsshowcontrol.test',
                'env' => 'testing',
                'debug' => true,
                'secret' => str_repeat('ab', 32),
                'force_https' => false,
                'admin_emails' => ['admin@example.test'],
            ],
            'storage' => ['path' => $this->storage],
            'mail' => ['driver' => 'log', 'admin_to' => 'owner@example.test'],
            'db' => ['auto_migrate' => false],
            'chat' => ['api_key' => ''],
        ];
        $merged = array_replace_recursive($base, $config);
        $this->app = App::boot(Config::fromArray(PSC_ROOT, $merged));
        $this->db = Database::connect('sqlite::memory:');
        (new Migrator($this->db, PSC_ROOT . '/database/migrations', $this->storage . '/cache/schema-version'))->migrate();
        $this->app->useDatabase($this->db);
        $this->mail = $this->app->mailer();
        $_SESSION = [];
        $this->jar = [];
        Content::flush();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp();
    }

    protected function tearDown(): void
    {
        ChatController::useGateway(null);
        $_SESSION = [];
        if (isset($this->storage) && is_dir($this->storage)) {
            self::rmrf($this->storage);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, string> $headers
     */
    protected function request(string $method, string $uri, array $post = [], array $headers = [], ?string $json = null, bool $https = true): Response
    {
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        parse_str((string) (parse_url($uri, PHP_URL_QUERY) ?? ''), $query);
        $lower = [];
        foreach ($headers as $k => $v) {
            $lower[strtolower($k)] = $v;
        }
        $lower += ['host' => 'patternsshowcontrol.test', 'user-agent' => 'phpunit'];
        if ($json !== null) {
            $lower['content-type'] = 'application/json';
        }
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'QUERY_STRING' => (string) (parse_url($uri, PHP_URL_QUERY) ?? ''),
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTPS' => $https ? 'on' : '',
        ];
        $request = new Request($method, Request::normalisePath($path), $query, $post, $lower, $this->jar, [], $server, $json ?? '');
        // Each request gets fresh per-request services (as a new PHP process would), sharing database and session.
        $config = $this->app->config;
        $this->app = App::boot($config);
        $this->app->useDatabase($this->db);
        $this->app->useMailer($this->mail);
        $response = $this->app->handle($request);
        foreach ($response->cookies() as [$name, $value, $options]) {
            if (($options['expires'] ?? 0) !== 0 && (int) $options['expires'] < time()) {
                unset($this->jar[$name]);
            } else {
                $this->jar[$name] = $value;
            }
        }
        return $response;
    }

    /** @param array<string, mixed> $post */
    protected function post(string $uri, array $post = [], bool $withCsrf = true): Response
    {
        if ($withCsrf) {
            $post['_csrf'] = $this->csrf();
        }
        return $this->request('POST', $uri, $post);
    }

    protected function csrf(): string
    {
        $token = $_SESSION['_csrf'] ?? null;
        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['_csrf'] = $token;
            $_SESSION['_created'] ??= time();
        }
        return $token;
    }

    /** @return list<array{to: string, subject: string, template: string, data: array<string, mixed>, html: string}> */
    protected function mails(): array
    {
        return $this->mail->sent;
    }

    /** The last email sent to $to, or null. */
    protected function lastMailTo(string $to): ?array
    {
        foreach (array_reverse($this->mail->sent) as $m) {
            if ($m['to'] === $to) {
                return $m;
            }
        }
        return null;
    }

    /** Creates an account directly in the database. */
    protected function makeUser(string $email = 'client@example.test', string $password = 'correct horse battery', string $role = 'client', string $status = 'active'): int
    {
        return $this->db->insert('users', [
            'email' => $email,
            'name' => 'Test ' . ucfirst(strtok($email, '@') ?: 'user'),
            'company' => null,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
            'role' => $role,
            'status' => $status,
            'email_verified_at' => $status === 'pending' ? null : time(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    /** Signs a user in through the real login form. */
    protected function signIn(string $email = 'client@example.test', string $password = 'correct horse battery'): Response
    {
        return $this->post('/portal/login/', ['email' => $email, 'password' => $password]);
    }

    protected static function rmrf(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
