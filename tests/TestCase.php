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
use Psc\News\ArticleStore;
use Psc\News\Draft;
use Psc\Support\FormGuard;

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
            // The Google tag is off in tests (no page reaches Google); tests that need it switch it on.
            'analytics' => ['ga_id' => ''],
        ];
        $merged = array_replace_recursive($base, $config);
        $this->app = App::boot($this->shadowRoot !== null ? $this->shadowedConfig($merged) : Config::fromArray(PSC_ROOT, $merged));
        $this->db = self::database($this->storage);
        $this->app->useDatabase($this->db);
        $this->mail = $this->app->mailer();
        $_SESSION = [];
        $this->jar = [];
        Content::flush();
    }

    /** A site root whose config/local.php is shadowed by the server file outside it (bootShadowed()). */
    private ?string $shadowRoot = null;

    /**
     * Boots as a server where config/local.php was uploaded after the file outside the web root existed: the
     * settings come from that outside file, and the local one is ignored and reported.
     *
     * @param array<string, mixed> $config
     */
    protected function bootShadowed(array $config = []): void
    {
        $this->shadowRoot = sys_get_temp_dir() . '/psc-shadow-' . bin2hex(random_bytes(6));
        mkdir($this->shadowRoot . '/site/config', 0777, true);
        mkdir($this->shadowRoot . '/patterns-data', 0777, true);
        copy(PSC_ROOT . '/config/defaults.php', $this->shadowRoot . '/site/config/defaults.php');
        file_put_contents($this->shadowRoot . '/site/config/local.php', '<?php return ' . var_export(['app' => ['page_cache_seconds' => 42]], true) . ';');
        $this->bootApp($config);
    }

    /** @param array<string, mixed> $values */
    private function shadowedConfig(array $values): Config
    {
        file_put_contents($this->shadowRoot . '/patterns-data/config.php', '<?php return ' . var_export($values, true) . ';');
        return Config::load($this->shadowRoot . '/site');
    }

    private static ?Database $shared = null;

    /**
     * SQLite in memory by default. With PSC_TEST_DB_DSN set (a MySQL or MariaDB database that may be wiped), the
     * suite runs against it instead: the schema is rebuilt once per run and every table is emptied before each
     * test, so the queries are proven on the production engine too.
     */
    private static function database(string $storage): Database
    {
        $dsn = (string) getenv('PSC_TEST_DB_DSN');
        if ($dsn === '') {
            $db = Database::connect('sqlite::memory:');
            (new Migrator($db, PSC_ROOT . '/database/migrations', $storage . '/cache/schema-version'))->migrate();
            return $db;
        }
        if (self::$shared === null) {
            self::$shared = Database::connect($dsn, (string) getenv('PSC_TEST_DB_USER'), (string) getenv('PSC_TEST_DB_PASSWORD'));
            self::$shared->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (self::$shared->all('SHOW TABLES') as $row) {
                self::$shared->pdo()->exec('DROP TABLE `' . str_replace('`', '', (string) array_values($row)[0]) . '`');
            }
            self::$shared->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
            (new Migrator(self::$shared, PSC_ROOT . '/database/migrations', $storage . '/cache/schema-version'))->migrate();
            return self::$shared;
        }
        $db = self::$shared;
        foreach ($db->all('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()') as $row) {
            $db->pdo()->exec('DROP TRIGGER IF EXISTS `' . str_replace('`', '', (string) array_values($row)[0]) . '`');
        }
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($db->all('SHOW TABLES') as $row) {
            $table = (string) array_values($row)[0];
            if ($table !== 'schema_migrations') {
                $db->pdo()->exec('TRUNCATE TABLE `' . str_replace('`', '', $table) . '`');
            }
        }
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        return $db;
    }

    /**
     * Makes every $event (INSERT, UPDATE, DELETE) on $table fail with a database error, in the dialect of the
     * database under test, to prove a handler survives a failed write.
     */
    protected function refuseWrites(string $name, string $event, string $table): void
    {
        if ($this->db->driver() === 'mysql') {
            $this->db->pdo()->exec("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW SIGNAL SQLSTATE 'HY000' SET MESSAGE_TEXT = 'refused'");
        } else {
            // Not RAISE(ABORT): SQLite reports that as a constraint violation, which handlers rightly treat as a
            // race. A trigger reading a missing table fails as a plain database error.
            $this->db->pdo()->exec("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} BEGIN SELECT * FROM no_such_table; END");
        }
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
        if ($this->shadowRoot !== null) {
            self::rmrf($this->shadowRoot);
            $this->shadowRoot = null;
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, string> $headers
     */
    protected function request(string $method, string $uri, array $post = [], array $headers = [], ?string $json = null, bool $https = true, array $files = [], array $server = []): Response
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
        ] + $server;
        $request = new Request($method, Request::normalisePath($path), $query, $post, $lower, $this->jar, $files, $server, $json ?? '');
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

    /**
     * Posts a form with files. $files maps a field name to one or more fake uploads made by fakeUpload(); the
     * application accepts them because app.env is "testing" (see Uploads::store's $trustTestFiles).
     *
     * @param array<string, mixed> $post
     * @param array<string, list<array{name: string, type: string, tmp_name: string, error: int, size: int}>> $files
     */
    protected function postFiles(string $uri, array $post, array $files): Response
    {
        $post['_csrf'] = $this->csrf();
        $php = [];
        foreach ($files as $field => $list) {
            $php[$field] = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
            foreach ($list as $f) {
                foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $k) {
                    $php[$field][$k][] = $f[$k];
                }
            }
        }
        return $this->request('POST', $uri, $post, [], null, true, $php);
    }

    /**
     * A temporary file standing in for an upload.
     *
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    protected function fakeUpload(string $name, string $contents): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'psc-up');
        file_put_contents($tmp, $contents);
        return ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($contents)];
    }

    /** The smallest valid PNG, for upload tests. */
    protected static function pngBytes(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
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

    /**
     * What a person's browser sends with one of the FormGuard forms besides the fields: the empty honeypot and the
     * time the form was shown, $seconds ago, signed for this session as the form would have signed it.
     *
     * @return array{_started: string, website: string}
     */
    protected function filledIn(string $form, int $seconds = 30): array
    {
        $at = time() - $seconds;
        return [
            FormGuard::STARTED => $at . '.' . FormGuard::signature($this->app->secret(), $form, $at, $this->csrf()),
            FormGuard::HONEYPOT => '',
        ];
    }

    /**
     * A page with each form's signed showing time blanked: the time a page was drawn is all that changes between
     * two views of it, so pages that must read the same for every address are compared without it.
     */
    protected static function withoutFormTimes(string $html): string
    {
        return (string) preg_replace('/name="' . FormGuard::STARTED . '" value="[^"]*"/', 'name="' . FormGuard::STARTED . '" value=""', $html);
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

    /** Signs in as an administrator (admin@example.test, made if missing) and returns the account's id. */
    protected function signInAdmin(): int
    {
        return $this->signInAccount('admin@example.test', 'admin');
    }

    /** Signs in as a client ($email, made if missing) and returns the account's id. */
    protected function signInClient(string $email = 'client@example.test'): int
    {
        return $this->signInAccount($email, 'client');
    }

    private function signInAccount(string $email, string $role): int
    {
        $id = $this->db->value('SELECT id FROM users WHERE email = ?', [$email]);
        $id = $id !== null ? (int) $id : $this->makeUser($email, 'correct horse battery', $role);
        $this->signIn($email, 'correct horse battery');
        self::assertSame($id, (int) ($_SESSION['uid'] ?? 0), $email . ' is signed in');
        return $id;
    }

    /** How many articles makeArticle() has made in this test, so each has words and a source of its own. */
    private int $articleCount = 0;

    /**
     * Creates a valid live article through ArticleStore and returns its id: a manual article in Video with one
     * source and one confirmed item, published a minute ago. $over replaces any field of the article JSON
     * (Draft::fromArray(): title, slug, categories, sources, story_key, status ...), and sets origin (manual, ai or
     * import; ai and import are AI-written with the desk's byline unless ai_written or byline say otherwise) and
     * published_at (Unix seconds, a date and time, or null for none; a draft has none unless given one).
     *
     * @param array<string, mixed> $over
     */
    protected function makeArticle(array $over = []): int
    {
        $n = ++$this->articleCount;
        $origin = (string) ($over['origin'] ?? 'manual');
        $source = 'https://example.com/news/story-' . $n;
        $data = $over + [
            'title' => 'Sample story number ' . $n . ' from the test suite',
            'dek' => 'A standfirst for sample story number ' . $n . ', long enough for the rules that apply when an article is published.',
            'body' => str_repeat('This paragraph stands in for the body of sample story ' . $n . ', and it is repeated until the body is long enough to publish. ', 4),
            'impact' => 'Nothing changes for a show tomorrow; it is a sample.',
            'categories' => ['video'],
            'tags' => ['sample'],
            'sources' => [['url' => $source, 'title' => 'Sample source ' . $n, 'publisher' => 'Example News', 'kind' => 'press']],
            'confirmed' => [['text' => 'The sample story was published.', 'url' => $source]],
            'verdict' => 'watch',
            'verdict_note' => 'Worth keeping an eye on.',
            'status' => 'published',
        ];
        $published = array_key_exists('published_at', $over) ? $over['published_at'] : ($data['status'] === 'published' ? time() - 60 : null);
        unset($data['origin'], $data['published_at'], $data['publish_at']);
        if (is_string($published)) {
            $data['published_at'] = $published;
        }
        $draft = Draft::fromArray($data, 'manual');
        $draft->origin = $origin;
        $draft->aiWritten = array_key_exists('ai_written', $over) ? (bool) $over['ai_written'] : $origin !== 'manual';
        if (is_int($published) || $published === null) {
            $draft->publishAt = $published;
        }
        if ($origin !== 'manual' && !array_key_exists('byline', $over)) {
            $draft->byline = Draft::deskByline();
        }
        return (new ArticleStore($this->app))->create($draft, null, null);
    }

    protected static function rmrf(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
