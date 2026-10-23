<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\Portal;

use PHPUnit\Framework\Attributes\DataProvider;
use Psc\Controllers\Admin\AdminController;
use Psc\Http\Response;
use Psc\Tests\TestCase;

/**
 * The dashboard and the administration pages: who may open them, the account actions (approve, disable, enable,
 * roles, resend), inviting, the message inbox, the assistant's conversations and the audit log. Each test asserts
 * statuses, redirects, stored rows, audit rows and emails first, and page words only beside them.
 */
final class AdminTest extends TestCase
{
    private const PASSWORD = 'correct horse battery';
    private const ADMIN = 'boss@example.test';
    private const CONVERSATION = '0123456789abcdef0123456789abcdef';

    // ----------------------------------------------------------------- helpers

    /** @return list<array{type: string, message: string}> the flash messages waiting in the session */
    private static function flashes(): array
    {
        return array_values($_SESSION['_flash'] ?? []);
    }

    private static function lastFlash(): array
    {
        $all = self::flashes();
        self::assertNotSame([], $all, 'a flash message was queued');
        return end($all);
    }

    private static function assertRedirect(Response $r, string $location, int $status = 303): void
    {
        self::assertSame($status, $r->status, 'status');
        self::assertSame($location, $r->getHeader('Location'));
    }

    private function freshBrowser(): void
    {
        $_SESSION = [];
        $this->jar = [];
    }

    private function signInAsAdmin(): int
    {
        $id = $this->makeUser(self::ADMIN, self::PASSWORD, 'admin');
        $this->signIn(self::ADMIN, self::PASSWORD);
        self::assertSame($id, (int) ($_SESSION['uid'] ?? 0), 'the administrator is signed in');
        return $id;
    }

    private function signInAsClient(string $email = 'client@example.test'): int
    {
        $id = $this->makeUser($email, self::PASSWORD);
        $this->signIn($email, self::PASSWORD);
        self::assertSame($id, (int) ($_SESSION['uid'] ?? 0), 'the client is signed in');
        return $id;
    }

    /** @return array<string, mixed> */
    private function userRow(int $id): array
    {
        $row = $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
        self::assertNotNull($row);
        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(string $action): array
    {
        return $this->db->all('SELECT * FROM audit_log WHERE action = ? ORDER BY id', [$action]);
    }

    private function ticket(int $userId, string $title, string $status = 'open', string $type = 'bug'): int
    {
        return $this->db->insert('tickets', [
            'user_id' => $userId,
            'type' => $type,
            'product' => 'stagewatch',
            'title' => $title,
            'body' => 'Body of ' . $title,
            'status' => $status,
            'visibility' => 'private',
            'last_activity_at' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function comment(int $ticketId, int $userId, bool $staff, bool $internal = false): int
    {
        return $this->db->insert('ticket_comments', [
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'body' => 'A comment',
            'is_staff' => $staff ? 1 : 0,
            'is_internal' => $internal ? 1 : 0,
            'created_at' => time(),
        ]);
    }

    private function licence(int $userId, string $reference, string $status = 'requested'): int
    {
        return $this->db->insert('licences', [
            'user_id' => $userId,
            'product' => 'stagewatch',
            'tier' => 'pro',
            'status' => $status,
            'reference' => $reference,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function contactMessage(string $status = 'new', string $message = "Hello there.\nSecond line."): int
    {
        return $this->db->insert('contact_messages', [
            'name' => 'Rita Rigger',
            'email' => 'rita@example.test',
            'company' => 'Flown Ltd',
            'topic' => 'support',
            'product' => 'stagewatch',
            'message' => $message,
            'status' => $status,
            'created_at' => time(),
        ]);
    }

    private function conversation(string $id, ?int $userId = null): void
    {
        $this->db->insert('chat_conversations', [
            'id' => $id,
            'user_id' => $userId,
            'ip_hash' => 'x',
            'page' => '/stagewatch/',
            'turns' => 0,
            'created_at' => time(),
            'last_at' => time(),
        ]);
    }

    private function chatMessage(string $conversation, string $role, string $content, ?string $verdict = null, int $in = 0, int $out = 0): void
    {
        $this->db->insert('chat_messages', [
            'conversation_id' => $conversation,
            'role' => $role,
            'content' => $content,
            'verdict' => $verdict,
            'tokens_in' => $in,
            'tokens_out' => $out,
            'created_at' => time(),
        ]);
        $this->db->exec(
            'UPDATE chat_conversations SET tokens_in = tokens_in + ?, tokens_out = tokens_out + ?' . ($role === 'user' ? ', turns = turns + 1' : '') . ' WHERE id = ?',
            [$in, $out, $conversation]
        );
    }

    // ------------------------------------------------------------ who may open the pages

    /**
     * Every GET route under /portal/admin/ in app/routes.php. A transcript is reached here by the list's
     * ?conversation= form; its own path is covered by testTheTranscriptPathIsServedOrAvoided().
     *
     * @return iterable<string, array{string}>
     */
    public static function adminPages(): iterable
    {
        foreach ([
            '/portal/admin/',
            '/portal/admin/messages/',
            '/portal/admin/chats/',
            '/portal/admin/chats/?conversation=' . self::CONVERSATION,
            '/portal/admin/audit/',
            '/portal/admin/users/',
            '/portal/admin/users/1/',
            '/portal/admin/tickets/',
            '/portal/admin/releases/',
            '/portal/admin/releases/new/',
            '/portal/admin/releases/1/',
            '/portal/admin/licences/',
            '/portal/admin/licences/1/',
            '/portal/admin/news/',
            '/portal/admin/news/new/',
            '/portal/admin/news/1/',
            '/portal/admin/news/1/preview/',
            '/portal/admin/news/media/' . str_repeat('a', 32) . '-480.webp',
            '/portal/admin/news/comments/',
            '/portal/admin/news/desk/',
            '/portal/admin/news/desk/settings/',
            '/portal/admin/news/desk/calendar/',
            '/portal/admin/news/desk/prompt/',
            '/portal/admin/news/desk/runs/1/',
            '/portal/admin/news/desk/digest/',
            '/portal/admin/news/desk/digest/preview/',
            '/portal/admin/news/desk/digest/1/',
        ] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('adminPages')]
    public function testSignedOutVisitorsAreSentToSignIn(string $path): void
    {
        $r = $this->request('GET', $path);
        // The sign-in page is told the page to come back to, query included.
        self::assertRedirect($r, '/portal/login/?next=' . rawurlencode($path), 302);
    }

    #[DataProvider('adminPages')]
    public function testClientsAreForbidden(string $path): void
    {
        $this->signInAsClient();
        $r = $this->request('GET', $path);
        self::assertSame(403, $r->status);
        self::assertStringNotContainsString('Admin overview</h1>', $r->body);
    }

    public function testClientsCannotPostAdminActions(): void
    {
        $waiting = $this->makeUser('waiting@example.test', self::PASSWORD, 'client', 'approval');
        $message = $this->contactMessage();
        $this->signInAsClient();

        self::assertSame(403, $this->post('/portal/admin/users/' . $waiting . '/', ['action' => 'approve'])->status);
        self::assertSame(403, $this->post('/portal/admin/users/invite/', ['name' => 'Eve', 'email' => 'eve@example.test', 'role' => 'admin'])->status);
        self::assertSame(403, $this->post('/portal/admin/messages/' . $message . '/', ['status' => 'archived'])->status);

        self::assertSame('approval', $this->userRow($waiting)['status']);
        self::assertNull($this->db->one("SELECT id FROM users WHERE email = 'eve@example.test'"));
        self::assertSame('new', $this->db->value('SELECT status FROM contact_messages WHERE id = ?', [$message]));
        self::assertSame([], $this->mails());
    }

    public function testAdminPostsWithoutCsrfAreRefused(): void
    {
        $this->signInAsAdmin();
        $waiting = $this->makeUser('waiting@example.test', self::PASSWORD, 'client', 'approval');
        $message = $this->contactMessage();

        self::assertSame(419, $this->post('/portal/admin/users/' . $waiting . '/', ['action' => 'approve'], false)->status);
        self::assertSame(419, $this->post('/portal/admin/users/invite/', ['name' => 'Eve', 'email' => 'eve@example.test', 'role' => 'client'], false)->status);
        self::assertSame(419, $this->post('/portal/admin/messages/' . $message . '/', ['status' => 'read'], false)->status);

        self::assertSame('approval', $this->userRow($waiting)['status']);
        self::assertNull($this->db->one("SELECT id FROM users WHERE email = 'eve@example.test'"));
        self::assertSame('new', $this->db->value('SELECT status FROM contact_messages WHERE id = ?', [$message]));
    }

    public function testEveryAdminPageRendersForAnAdministratorWithData(): void
    {
        $adminId = $this->signInAsAdmin();
        $client = $this->makeUser('client@example.test', self::PASSWORD);
        $this->makeUser('waiting@example.test', self::PASSWORD, 'client', 'approval');
        $this->ticket($client, 'Clock drifts');
        $this->licence($client, 'SW-TEST-0001');
        $this->contactMessage();
        $this->conversation(self::CONVERSATION, $client);
        $this->chatMessage(self::CONVERSATION, 'user', 'Hello');
        $this->chatMessage(self::CONVERSATION, 'assistant', 'Hi there', 'in_scope', 10, 5);
        $article = $this->makeArticle();
        $this->db->insert('news_comments', ['article_id' => $article, 'user_id' => $client, 'body' => 'Useful, thanks.', 'status' => 'visible', 'reports' => 1, 'created_at' => time(), 'updated_at' => time()]);
        $run = $this->db->insert('news_runs', ['slot' => '2026-10-22 06:00', 'kind' => 'scheduled', 'status' => 'skipped', 'summary' => 'Slow news', 'created_at' => time()]);
        $digest = $this->db->insert('news_digests', ['week' => '2026-w43', 'send_at' => time() - 3600, 'window_start' => time() - 3600 - 604800, 'window_end' => time() - 3600, 'articles' => json_encode([$article]),
            'left_out' => 0, 'state' => 'sent', 'recipients' => 0, 'composed_at' => time() - 3000, 'finished_at' => time() - 2900]);

        // The article preview renders in the public layout, the digest's preview is the email as its own document
        // (AdminDigestTest holds its own policy) and the media route returns an image, so none of them is here.
        foreach ([
            '/portal/admin/news/',
            '/portal/admin/news/new/',
            '/portal/admin/news/' . $article . '/',
            '/portal/admin/news/comments/',
            '/portal/admin/news/comments/?tab=reported',
            '/portal/admin/news/desk/',
            '/portal/admin/news/desk/settings/',
            '/portal/admin/news/desk/calendar/',
            '/portal/admin/news/desk/prompt/',
            '/portal/admin/news/desk/runs/' . $run . '/',
            '/portal/admin/news/desk/digest/',
            '/portal/admin/news/desk/digest/' . $digest . '/',
            '/portal/admin/',
            '/portal/admin/messages/',
            '/portal/admin/messages/?status=read',
            '/portal/admin/messages/?status=archived',
            '/portal/admin/chats/',
            '/portal/admin/chats/?refused=1',
            $this->transcriptUrl(self::CONVERSATION),
            '/portal/admin/audit/',
            '/portal/admin/audit/?action=login.',
            '/portal/admin/users/',
            '/portal/admin/users/?status=approval&role=client&q=wait',
            '/portal/admin/users/' . $client . '/',
            '/portal/admin/users/' . $adminId . '/',
        ] as $path) {
            $r = $this->request('GET', $path);
            self::assertSame(200, $r->status, $path);
            self::assertSame(1, substr_count($r->body, '<h1 class="pt-title"'), $path . ' has one page title');
            self::assertStringNotContainsString('style="', $this->mainOf($r), $path . ' has no inline styles');
            self::assertStringNotContainsString('<script', $this->mainOf($r), $path . ' has no inline script');
        }
    }

    /** The page's own markup, inside the portal shell's main element. */
    private function mainOf(Response $r): string
    {
        $start = strpos($r->body, '<main class="pt-main"');
        $end = strpos($r->body, '</main>');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        return substr($r->body, (int) $start, (int) $end - (int) $start);
    }

    // ------------------------------------------------------------ the overview

    public function testTheOverviewCountsWhatIsWaiting(): void
    {
        $this->signInAsAdmin();
        $client = $this->makeUser('client@example.test', self::PASSWORD);
        $this->makeUser('waiting@example.test', self::PASSWORD, 'client', 'approval');
        $this->ticket($client, 'Untriaged bug');
        $this->ticket($client, 'Planned idea', 'planned', 'feature');
        $this->ticket($client, 'Finished bug', 'done');
        $this->licence($client, 'SW-AAAA-0001', 'requested');
        $this->licence($client, 'SW-AAAA-0002', 'awaiting_payment');
        $this->licence($client, 'SW-AAAA-0003', 'issued');
        $this->contactMessage('new');
        $this->contactMessage('read');
        $this->db->insert('chat_usage', ['day' => gmdate('Y-m-d'), 'requests' => 3, 'tokens_in' => 1200, 'tokens_out' => 300]);
        $this->db->insert('chat_usage', ['day' => gmdate('Y-m-d', time() - 3 * 86400), 'requests' => 2, 'tokens_in' => 800, 'tokens_out' => 100]);
        $this->db->insert('chat_usage', ['day' => gmdate('Y-m-d', time() - 10 * 86400), 'requests' => 9, 'tokens_in' => 99999, 'tokens_out' => 99999]);
        $this->conversation(self::CONVERSATION);

        self::assertSame(['approval' => 1, 'tickets' => 1, 'licences' => 2, 'messages' => 1], AdminController::waitingCounts($this->db));

        $r = $this->request('GET', '/portal/admin/');
        self::assertSame(200, $r->status);
        // Today: 1 conversation, 3 requests, 1,200 in, 300 out; the week adds the row from three days ago only.
        self::assertMatchesRegularExpression('#<th scope="row">Today</th>\s*<td class="pt-num" data-label="Conversations">1</td>\s*<td class="pt-num" data-label="Requests">3</td>\s*<td class="pt-num" data-label="Tokens in">1,200</td>\s*<td class="pt-num" data-label="Tokens out">300</td>#', $r->body);
        self::assertMatchesRegularExpression('#<th scope="row">Last 7 days</th>\s*<td class="pt-num" data-label="Conversations">1</td>\s*<td class="pt-num" data-label="Requests">5</td>\s*<td class="pt-num" data-label="Tokens in">2,000</td>\s*<td class="pt-num" data-label="Tokens out">400</td>#', $r->body);
        self::assertStringContainsString('Untriaged bug', $r->body);
        self::assertStringNotContainsString('Planned idea', $r->body, 'only untriaged tickets are listed as new');
        self::assertStringContainsString('SW-AAAA-0001', $r->body);
        self::assertStringContainsString('SW-AAAA-0002', $r->body);
        self::assertStringNotContainsString('SW-AAAA-0003', $r->body, 'an issued licence waits on nobody');
    }

    /**
     * A release file larger than post_max_size arrives as an empty request, so the missing form token made it a
     * "Session expired" page. It now goes back to the form saying the file was too large and where large files go.
     */
    public function testAnUploadOverThePostLimitSaysItWasTooLarge(): void
    {
        $this->signInAsAdmin();
        $limit = \Psc\Support\Uploads::iniBytes((string) ini_get('post_max_size'));
        self::assertGreaterThan(0, $limit);
        $r = $this->request('POST', '/portal/admin/releases/', [], ['Content-Length' => (string) ($limit + 1), 'Referer' => 'https://patternsshowcontrol.test/portal/admin/releases/new/']);
        self::assertRedirect($r, '/portal/admin/releases/new/');
        $flash = self::lastFlash();
        self::assertSame('danger', $flash['type']);
        self::assertStringContainsString('larger than the server accepts in one upload', $flash['message']);
        self::assertStringContainsString('downloads-vault/incoming/', $flash['message']);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM releases'));

        // A form without its token that is not over the limit is still refused as an expired session.
        self::assertSame(419, $this->request('POST', '/portal/admin/releases/', ['title' => 'x'], ['Content-Length' => '10'])->status);
        // A foreign Referer is never followed.
        $foreign = $this->request('POST', '/portal/admin/releases/', [], ['Content-Length' => (string) ($limit + 1), 'Referer' => 'https://evil.example/x/']);
        self::assertRedirect($foreign, '/portal/');
    }

    /** The upload control carries its limit, so the browser can refuse an over-large file before sending it. */
    public function testTheReleaseFormTellsTheBrowserItsSizeLimit(): void
    {
        $this->signInAsAdmin();
        $body = $this->request('GET', '/portal/admin/releases/new/')->body;
        self::assertMatchesRegularExpression('#<input type="file"[^>]*data-max-bytes="\d+"[^>]*data-max-message="This file is larger than [^"]+downloads-vault/incoming/#', $body);
    }

    /** A settings file uploaded into the web root after the outside one existed is ignored: the overview says so. */
    public function testTheOverviewWarnsWhenAnUploadedSettingsFileIsIgnored(): void
    {
        $this->signInAsAdmin();
        self::assertStringNotContainsString('config/local.php', $this->request('GET', '/portal/admin/')->body);

        $this->bootShadowed();
        $this->signInAsAdmin();
        $r = $this->request('GET', '/portal/admin/');
        self::assertSame(200, $r->status);
        self::assertTrue($this->app->config->shadowed());
        self::assertMatchesRegularExpression('#<div class="pt-alert pt-alert--warning" role="status">A settings file was uploaded as <code>public_html/config/local.php</code>#', $r->body);
    }

    /**
     * The overview's Server card reads PHP itself, so it shows what is in force: the version and server interface,
     * and each hardening setting with whether it is as the site wants it (docs/DEPLOY.md, PHP).
     */
    public function testTheOverviewShowsThePhpItRanOn(): void
    {
        $facts = AdminController::serverFacts();
        $byLabel = array_column($facts, null, 'label');
        self::assertSame(['PHP', 'Upload progress', 'Arguments in error traces', 'PHP version in responses', 'Largest request', 'Password hashing'], array_keys($byLabel));
        self::assertSame(PHP_VERSION . ' (' . PHP_SAPI . ')', $byLabel['PHP']['value']);
        self::assertSame(PHP_VERSION_ID >= 80400, $byLabel['PHP']['ok']);
        // App::boot hides arguments in traces for every SAPI, the test runner's included.
        self::assertSame('1', ini_get('zend.exception_ignore_args'));
        self::assertSame(['value' => 'Hidden', 'ok' => true], array_intersect_key($byLabel['Arguments in error traces'], ['value' => 0, 'ok' => 0]));
        $progress = filter_var(ini_get('session.upload_progress.enabled'), FILTER_VALIDATE_BOOLEAN);
        self::assertSame(['value' => $progress ? 'On' : 'Off', 'ok' => !$progress], array_intersect_key($byLabel['Upload progress'], ['value' => 0, 'ok' => 0]));

        $this->signInAsAdmin();
        $body = $this->request('GET', '/portal/admin/')->body;
        self::assertStringContainsString('<h2 class="pt-h2" id="ov-server">Server</h2>', $body);
        self::assertStringContainsString('<th scope="row">PHP</th>', $body);
        self::assertStringContainsString('<td>' . e(PHP_VERSION . ' (' . PHP_SAPI . ')') . '</td>', $body);
    }

    // ------------------------------------------------------------ account actions

    public function testAnAdministratorApprovesAWaitingAccount(): void
    {
        $adminId = $this->signInAsAdmin();
        $id = $this->makeUser('waiting@example.test', self::PASSWORD, 'client', 'approval');

        $r = $this->post('/portal/admin/users/' . $id . '/', ['action' => 'approve']);
        self::assertRedirect($r, '/portal/admin/users/' . $id . '/');
        self::assertSame('success', self::lastFlash()['type']);
        self::assertSame('active', $this->userRow($id)['status']);

        $mail = $this->lastMailTo('waiting@example.test');
        self::assertNotNull($mail, 'the account owner is told');
        self::assertSame('account-approved', $mail['template']);
        self::assertSame('Test Waiting', $mail['data']['name']);

        $audit = $this->auditRows('user.approved');
        self::assertCount(1, $audit);
        self::assertSame($adminId, (int) $audit[0]['user_id']);
        self::assertSame((string) $id, $audit[0]['subject']);

        // The approved account can now sign in.
        $this->freshBrowser();
        $this->signIn('waiting@example.test', self::PASSWORD);
        self::assertSame($id, (int) ($_SESSION['uid'] ?? 0));
    }

    public function testApprovingAnAccountThatIsNotWaitingChangesNothing(): void
    {
        $this->signInAsAdmin();
        $id = $this->makeUser('pending@example.test', self::PASSWORD, 'client', 'pending');

        $r = $this->post('/portal/admin/users/' . $id . '/', ['action' => 'approve']);
        self::assertRedirect($r, '/portal/admin/users/' . $id . '/');
        self::assertSame('info', self::lastFlash()['type']);
        self::assertSame('pending', $this->userRow($id)['status']);
        self::assertNull($this->lastMailTo('pending@example.test'));
        self::assertSame([], $this->auditRows('user.approved'));
    }

    public function testAnAdministratorCannotDemoteOrDisableThemself(): void
    {
        $adminId = $this->signInAsAdmin();

        foreach (['make_client', 'disable', 'make_admin', 'enable'] as $action) {
            $_SESSION['_flash'] = [];
            $r = $this->post('/portal/admin/users/' . $adminId . '/', ['action' => $action]);
            self::assertRedirect($r, '/portal/admin/users/' . $adminId . '/');
            self::assertSame('danger', self::lastFlash()['type'], $action);
        }
        $row = $this->userRow($adminId);
        self::assertSame('admin', $row['role']);
        self::assertSame('active', $row['status']);
        self::assertSame([], $this->auditRows('user.role_changed'));
        self::assertSame([], $this->auditRows('user.disabled'));
        self::assertSame(200, $this->request('GET', '/portal/admin/')->status, 'still an administrator');

        $page = $this->request('GET', '/portal/admin/users/' . $adminId . '/');
        self::assertStringContainsString('This is your own account', $page->body);
        self::assertStringNotContainsString('name="action" value="disable"', $page->body);
        self::assertStringNotContainsString('name="action" value="make_client"', $page->body);
    }

    public function testDisableAndEnableAnAccount(): void
    {
        $adminId = $this->signInAsAdmin();
        $id = $this->makeUser('client@example.test', self::PASSWORD);
        $this->db->insert('user_tokens', ['user_id' => $id, 'purpose' => 'remember', 'selector' => 'aa11', 'token_hash' => 'x', 'expires_at' => time() + 3600, 'created_at' => time()]);

        $r = $this->post('/portal/admin/users/' . $id . '/', ['action' => 'disable']);
        self::assertRedirect($r, '/portal/admin/users/' . $id . '/');
        self::assertSame('disabled', $this->userRow($id)['status']);
        self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM user_tokens WHERE user_id = ? AND purpose = 'remember'", [$id]), 'remember-me tokens are dropped');
        $audit = $this->auditRows('user.disabled');
        self::assertCount(1, $audit);
        self::assertSame($adminId, (int) $audit[0]['user_id']);
        self::assertSame(['from' => 'active', 'to' => 'disabled'], json_decode((string) $audit[0]['meta'], true));

        $r = $this->post('/portal/admin/users/' . $id . '/', ['action' => 'enable']);
        self::assertRedirect($r, '/portal/admin/users/' . $id . '/');
        self::assertSame('active', $this->userRow($id)['status'], 'a confirmed account comes back active');
        self::assertCount(1, $this->auditRows('user.enabled'));

        // An account that never confirmed its email goes back to pending, not active.
        $unconfirmed = $this->makeUser('never@example.test', self::PASSWORD, 'client', 'pending');
        $this->db->update('users', ['status' => 'disabled'], ['id' => $unconfirmed]);
        $this->post('/portal/admin/users/' . $unconfirmed . '/', ['action' => 'enable']);
        self::assertSame('pending', $this->userRow($unconfirmed)['status']);

        // Enabling an account that is not disabled is a no-op.
        $_SESSION['_flash'] = [];
        $this->post('/portal/admin/users/' . $id . '/', ['action' => 'enable']);
        self::assertSame('info', self::lastFlash()['type']);
        self::assertCount(2, $this->auditRows('user.enabled'));
    }

    public function testADisabledClientIsSignedOut(): void
    {
        $id = $this->signInAsClient();
        self::assertSame(200, $this->request('GET', '/portal/')->status);
        $clientSession = $_SESSION;

        $this->freshBrowser();
        $this->signInAsAdmin();
        $this->post('/portal/admin/users/' . $id . '/', ['action' => 'disable']);
        self::assertSame('disabled', $this->userRow($id)['status']);

        $_SESSION = $clientSession;
        $this->jar = [];
        $r = $this->request('GET', '/portal/');
        self::assertRedirect($r, '/portal/login/?next=' . rawurlencode('/portal/'), 302);
    }

    public function testRoleChanges(): void
    {
        $adminId = $this->signInAsAdmin();
        $id = $this->makeUser('client@example.test', self::PASSWORD);

        $this->post('/portal/admin/users/' . $id . '/', ['action' => 'make_admin']);
        self::assertSame('admin', $this->userRow($id)['role']);
        $this->post('/portal/admin/users/' . $id . '/', ['action' => 'make_client']);
        self::assertSame('client', $this->userRow($id)['role']);

        $audit = $this->auditRows('user.role_changed');
        self::assertCount(2, $audit);
        self::assertSame(['from' => 'client', 'to' => 'admin'], json_decode((string) $audit[0]['meta'], true));
        self::assertSame(['from' => 'admin', 'to' => 'client'], json_decode((string) $audit[1]['meta'], true));
        self::assertSame($adminId, (int) $audit[1]['user_id']);

        // Making a client a client again changes nothing and logs nothing.
        $_SESSION['_flash'] = [];
        $this->post('/portal/admin/users/' . $id . '/', ['action' => 'make_client']);
        self::assertSame('info', self::lastFlash()['type']);
        self::assertCount(2, $this->auditRows('user.role_changed'));
    }

    public function testDemotingAnAccountOnTheAdministratorListWarnsThatItComesBack(): void
    {
        $this->signInAsAdmin();
        $listed = $this->makeUser('admin@example.test', self::PASSWORD, 'admin');

        $this->post('/portal/admin/users/' . $listed . '/', ['action' => 'make_client']);
        self::assertSame('client', $this->userRow($listed)['role']);
        $flash = self::lastFlash();
        self::assertSame('warning', $flash['type']);
        self::assertStringContainsString('administrator list', $flash['message']);
    }

    public function testAnUnknownActionIsAValidationErrorAndChangesNothing(): void
    {
        $this->signInAsAdmin();
        $id = $this->makeUser('client@example.test', self::PASSWORD);
        $before = $this->userRow($id);

        foreach (['delete', '', 'APPROVE', 'make_admin '] as $action) {
            $_SESSION['_flash'] = [];
            $r = $this->post('/portal/admin/users/' . $id . '/', ['action' => $action]);
            self::assertRedirect($r, '/portal/admin/users/' . $id . '/');
            self::assertSame('danger', self::lastFlash()['type'], var_export($action, true));
            self::assertSame([], $_SESSION['_errors'] ?? [], 'a refusal keeps no form error');
        }
        self::assertSame($before, $this->userRow($id));
        self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'user.%'"));
    }

    public function testAnUnknownAccountIsNotFound(): void
    {
        $this->signInAsAdmin();
        self::assertSame(404, $this->request('GET', '/portal/admin/users/9999/')->status);
        self::assertSame(404, $this->post('/portal/admin/users/9999/', ['action' => 'disable'])->status);
    }

    public function testResendingSendsTheRightLinkForHowTheAccountWasMade(): void
    {
        $adminId = $this->signInAsAdmin();

        // A self-registered account that has not confirmed its address gets the confirmation email again.
        $registered = $this->makeUser('registered@example.test', self::PASSWORD, 'client', 'pending');
        $r = $this->post('/portal/admin/users/' . $registered . '/', ['action' => 'resend_verification']);
        self::assertRedirect($r, '/portal/admin/users/' . $registered . '/');
        $mail = $this->lastMailTo('registered@example.test');
        self::assertNotNull($mail);
        self::assertSame('verify-email', $mail['template']);
        self::assertStringContainsString('/portal/verify/?token=', (string) $mail['data']['url']);
        self::assertSame((string) $registered, $this->auditRows('user.verification_resent')[0]['subject']);

        // An invited account has no password of its own yet, so it gets a fresh invitation instead.
        $this->post('/portal/admin/users/invite/', ['name' => 'Ivy Invited', 'email' => 'ivy@example.test', 'company' => '', 'role' => 'client']);
        $invited = (int) $this->db->value("SELECT id FROM users WHERE email = 'ivy@example.test'");
        $first = (string) $this->lastMailTo('ivy@example.test')['data']['url'];
        $r = $this->post('/portal/admin/users/' . $invited . '/', ['action' => 'resend_verification']);
        self::assertRedirect($r, '/portal/admin/users/' . $invited . '/');
        $mail = $this->lastMailTo('ivy@example.test');
        self::assertSame('invite', $mail['template']);
        self::assertStringStartsWith('https://patternsshowcontrol.test/portal/reset/?invite=1&token=', (string) $mail['data']['url']);
        self::assertNotSame($first, $mail['data']['url']);
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM user_tokens WHERE user_id = ? AND purpose = 'invite'", [$invited]), 'the old link is replaced');
        self::assertSame($adminId, (int) $this->auditRows('user.invite_resent')[0]['user_id']);

        // An active account has nothing to resend.
        $active = $this->makeUser('active@example.test', self::PASSWORD);
        $_SESSION['_flash'] = [];
        $this->post('/portal/admin/users/' . $active . '/', ['action' => 'resend_verification']);
        self::assertSame('info', self::lastFlash()['type']);
        self::assertNull($this->lastMailTo('active@example.test'));
    }

    // ------------------------------------------------------------ inviting

    public function testAnAdministratorInvitesAClient(): void
    {
        $adminId = $this->signInAsAdmin();

        $r = $this->post('/portal/admin/users/invite/', ['name' => '  Nina   Stage ', 'email' => 'Nina@Example.test', 'company' => 'Cue Lights Ltd', 'role' => 'client']);
        $row = $this->db->one("SELECT * FROM users WHERE email = 'nina@example.test'");
        self::assertNotNull($row, 'the account exists');
        self::assertRedirect($r, '/portal/admin/users/' . (int) $row['id'] . '/');
        self::assertSame('pending', $row['status']);
        self::assertSame('client', $row['role']);
        self::assertSame('Nina Stage', $row['name']);
        self::assertSame('Cue Lights Ltd', $row['company']);
        self::assertNull($row['email_verified_at']);

        $mail = $this->lastMailTo('nina@example.test');
        self::assertNotNull($mail);
        self::assertSame('invite', $mail['template']);
        $url = (string) $mail['data']['url'];
        self::assertStringStartsWith('https://patternsshowcontrol.test/portal/reset/?invite=1&token=', $url);
        self::assertStringContainsString('/portal/reset/?invite=1&amp;token=', $mail['html'], 'the link is in the email itself');

        $audit = $this->auditRows('user.invited');
        self::assertCount(1, $audit);
        self::assertSame($adminId, (int) $audit[0]['user_id']);
        self::assertSame((string) $row['id'], $audit[0]['subject']);

        // The link opens the set-a-password form.
        $this->freshBrowser();
        self::assertSame(200, $this->request('GET', (string) parse_url($url, PHP_URL_PATH) . '?' . (string) parse_url($url, PHP_URL_QUERY))->status);
    }

    public function testInvitingAnAdministrator(): void
    {
        $this->signInAsAdmin();
        $this->post('/portal/admin/users/invite/', ['name' => 'Second Admin', 'email' => 'second@example.test', 'role' => 'admin']);
        self::assertSame('admin', $this->db->value("SELECT role FROM users WHERE email = 'second@example.test'"));
    }

    public function testInviteValidationKeepsTheFormAndCreatesNothing(): void
    {
        $this->signInAsAdmin();
        $this->makeUser('taken@example.test', self::PASSWORD);
        $users = (int) $this->db->value('SELECT COUNT(*) FROM users');

        $r = $this->post('/portal/admin/users/invite/', ['name' => 'Eve', 'email' => 'eve@example.test', 'company' => '', 'role' => 'owner']);
        self::assertRedirect($r, '/portal/admin/users/#invite');
        self::assertSame(['role'], array_keys($_SESSION['_errors'] ?? []), 'a role off the list is refused');
        self::assertSame('Eve', $_SESSION['_old']['name'] ?? null);

        $this->post('/portal/admin/users/invite/', ['name' => '', 'email' => 'not-an-email', 'company' => str_repeat('x', 161), 'role' => 'client']);
        self::assertEqualsCanonicalizing(['name', 'email', 'company'], array_keys($_SESSION['_errors'] ?? []));

        $this->post('/portal/admin/users/invite/', ['name' => 'Tess', 'email' => 'TAKEN@example.test', 'role' => 'client']);
        self::assertSame(['email'], array_keys($_SESSION['_errors'] ?? []), 'an email that already has an account');

        self::assertSame($users, (int) $this->db->value('SELECT COUNT(*) FROM users'));
        self::assertSame([], array_filter($this->mails(), static fn (array $m): bool => $m['template'] === 'invite'));

        // The form shows the error beside its field.
        $page = $this->request('GET', '/portal/admin/users/');
        self::assertStringContainsString('id="invite-email-error"', $page->body);
        self::assertMatchesRegularExpression('#id="invite-email"[^>]*aria-invalid="true"#', $page->body);
        self::assertStringContainsString('value="TAKEN@example.test"', $page->body);
    }

    // ------------------------------------------------------------ the user list and page

    public function testTheUserListSearchesAndFilters(): void
    {
        $this->signInAsAdmin();
        $this->makeUser('alpha@example.test', self::PASSWORD);
        $this->makeUser('beta@example.test', self::PASSWORD, 'client', 'approval');
        $this->db->update('users', ['company' => 'Gamma Rentals'], ['email' => 'beta@example.test']);

        $byCompany = $this->request('GET', '/portal/admin/users/?q=gamma');
        self::assertStringContainsString('beta@example.test', $byCompany->body);
        self::assertStringNotContainsString('alpha@example.test', $byCompany->body);

        $byStatus = $this->request('GET', '/portal/admin/users/?status=approval');
        self::assertStringContainsString('beta@example.test', $byStatus->body);
        self::assertStringNotContainsString('alpha@example.test', $byStatus->body);

        $byRole = $this->request('GET', '/portal/admin/users/?role=admin');
        self::assertStringContainsString(self::ADMIN, $byRole->body);
        self::assertStringNotContainsString('alpha@example.test', $byRole->body);

        // LIKE wildcards are matched literally.
        $wild = $this->request('GET', '/portal/admin/users/?q=%25');
        self::assertSame(200, $wild->status);
        self::assertStringContainsString('No accounts match this search.', $wild->body);
        $underscore = $this->request('GET', '/portal/admin/users/?q=alph_');
        self::assertStringNotContainsString('alpha@example.test', $underscore->body);

        // A filter value off the list is ignored, not trusted.
        self::assertSame(200, $this->request('GET', '/portal/admin/users/?status=' . rawurlencode("x' OR 1=1"))->status);
    }

    public function testAUserPageListsTheirTicketsLicencesAndActivity(): void
    {
        $this->signInAsAdmin();
        $id = $this->makeUser('client@example.test', self::PASSWORD);
        $other = $this->makeUser('other@example.test', self::PASSWORD);
        $ticket = $this->ticket($id, 'Their own ticket');
        $this->ticket($other, 'Someone else entirely');
        $licence = $this->licence($id, 'SW-MINE-0001');
        $this->licence($other, 'SW-THEM-0001');
        $this->post('/portal/admin/users/' . $id . '/', ['action' => 'make_admin']);

        $r = $this->request('GET', '/portal/admin/users/' . $id . '/');
        self::assertSame(200, $r->status);
        self::assertStringContainsString('href="/portal/tickets/' . $ticket . '/"', $r->body);
        self::assertStringContainsString('href="/portal/admin/licences/' . $licence . '/"', $r->body);
        self::assertStringNotContainsString('Someone else entirely', $r->body);
        self::assertStringNotContainsString('SW-THEM-0001', $r->body);
        self::assertStringContainsString('user.role_changed', $r->body, 'recent activity about the account');
    }

    // ------------------------------------------------------------ messages

    /** The inbox names the country a visitor chose and says when none was. */
    public function testTheInboxNamesTheCountry(): void
    {
        $this->signInAsAdmin();
        $chosen = $this->contactMessage();
        $this->db->run('UPDATE contact_messages SET country = ? WHERE id = ?', ['GB', $chosen]);
        $none = $this->contactMessage();
        $page = $this->request('GET', '/portal/admin/messages/')->body;
        self::assertSame(1, preg_match('#id="message-' . $chosen . '".*?<dt>Country</dt>\s*<dd>([^\n]*)</dd>#s', $page, $m));
        self::assertSame('United Kingdom', $m[1]);
        self::assertSame(1, preg_match('#id="message-' . $none . '".*?<dt>Country</dt>\s*<dd>([^\n]*)</dd>#s', $page, $m));
        self::assertSame('<span class="pt-muted">Not given</span>', $m[1]);
    }

    public function testMessageStatusChanges(): void
    {
        $adminId = $this->signInAsAdmin();
        $id = $this->contactMessage('new', "Line one <b>bold?</b>\nLine two");

        $page = $this->request('GET', '/portal/admin/messages/');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('id="message-' . $id . '"', $page->body);
        self::assertStringContainsString('Line one &lt;b&gt;bold?&lt;/b&gt;<br />', $page->body, 'plain text, escaped, line breaks kept');
        self::assertStringContainsString('href="mailto:rita@example.test?subject=Re%3A%20Your%20message%20to%20Patterns%20Show%20Control"', $page->body);

        $r = $this->post('/portal/admin/messages/' . $id . '/', ['status' => 'read']);
        self::assertRedirect($r, '/portal/admin/messages/');
        self::assertSame('read', $this->db->value('SELECT status FROM contact_messages WHERE id = ?', [$id]));
        $audit = $this->auditRows('message.status');
        self::assertCount(1, $audit);
        self::assertSame($adminId, (int) $audit[0]['user_id']);
        self::assertSame(['from' => 'new', 'to' => 'read'], json_decode((string) $audit[0]['meta'], true));

        // It has left the new tab and is on the read tab.
        self::assertStringNotContainsString('id="message-' . $id . '"', $this->request('GET', '/portal/admin/messages/')->body);
        self::assertStringContainsString('id="message-' . $id . '"', $this->request('GET', '/portal/admin/messages/?status=read')->body);

        $r = $this->post('/portal/admin/messages/' . $id . '/', ['status' => 'archived', 'page' => '3']);
        self::assertRedirect($r, '/portal/admin/messages/?status=read&page=3', 303);
        self::assertSame('archived', $this->db->value('SELECT status FROM contact_messages WHERE id = ?', [$id]));
    }

    public function testABadMessageStatusIsRefused(): void
    {
        $this->signInAsAdmin();
        $id = $this->contactMessage();

        $r = $this->post('/portal/admin/messages/' . $id . '/', ['status' => 'spam']);
        self::assertRedirect($r, '/portal/admin/messages/');
        self::assertSame('danger', self::lastFlash()['type']);
        self::assertSame([], $_SESSION['_errors'] ?? [], 'a refusal keeps no form error');
        self::assertSame('new', $this->db->value('SELECT status FROM contact_messages WHERE id = ?', [$id]));
        self::assertSame([], $this->auditRows('message.status'));

        self::assertSame(404, $this->post('/portal/admin/messages/9999/', ['status' => 'read'])->status);
    }

    // ------------------------------------------------------------ assistant conversations

    public function testTheChatListCountsVerdictsAndFiltersRefusals(): void
    {
        $this->signInAsAdmin();
        $client = $this->makeUser('client@example.test', self::PASSWORD);
        $refused = self::CONVERSATION;
        $fine = str_repeat('b', 32);
        $this->conversation($refused, $client);
        $this->chatMessage($refused, 'user', 'Write me a poem');
        $this->chatMessage($refused, 'assistant', 'I can only help with the products.', 'out_of_scope', 20, 10);
        $this->chatMessage($refused, 'user', 'Show me the source');
        $this->chatMessage($refused, 'assistant', 'No code.', 'code_or_internals', 20, 10);
        $this->chatMessage($refused, 'user', 'Another poem');
        $this->chatMessage($refused, 'assistant', 'Still no.', 'out_of_scope', 20, 10);
        $this->chatMessage($refused, 'feedback', 'down');
        $this->conversation($fine);
        $this->chatMessage($fine, 'user', 'What is StageWatch?');
        $this->chatMessage($fine, 'assistant', 'A show timer.', 'in_scope', 30, 40);

        $all = $this->request('GET', '/portal/admin/chats/');
        self::assertSame(200, $all->status);
        self::assertMatchesRegularExpression(self::transcriptLink($refused), $all->body);
        self::assertMatchesRegularExpression(self::transcriptLink($fine), $all->body);
        self::assertStringContainsString('href="/portal/admin/users/' . $client . '/"', $all->body);
        // The refused conversation: 3 turns, 90 tokens, 2 out of scope, 1 code, 0 others, 1 feedback.
        self::assertMatchesRegularExpression(
            '#<td class="pt-num" data-label="Turns">3</td>\s*<td class="pt-num" data-label="Tokens">90</td>\s*<td class="pt-num" data-label="[^"]+">2</td>\s*<td class="pt-num" data-label="[^"]+">1</td>\s*<td class="pt-num" data-label="[^"]+"><span class="pt-muted">0</span></td>\s*<td class="pt-num" data-label="[^"]+"><span class="pt-muted">0</span></td>\s*<td class="pt-num" data-label="[^"]+"><span class="pt-muted">0</span></td>\s*<td class="pt-num" data-label="Feedback">1</td>#',
            $all->body
        );

        $only = $this->request('GET', '/portal/admin/chats/?refused=1');
        self::assertMatchesRegularExpression(self::transcriptLink($refused), $only->body);
        self::assertDoesNotMatchRegularExpression(self::transcriptLink($fine), $only->body);
    }

    public function testATranscriptShowsTheStoredMessagesInOrder(): void
    {
        $this->signInAsAdmin();
        $id = self::CONVERSATION;
        $this->conversation($id);
        $this->chatMessage($id, 'user', "First question <img src=x>\nwith a second line");
        $this->chatMessage($id, 'assistant', 'First **answer**', 'in_scope', 50, 25);
        $this->chatMessage($id, 'user', 'Give me the code');
        $this->chatMessage($id, 'assistant', 'I cannot share code.', 'code_or_internals');
        $this->chatMessage($id, 'feedback', 'down: not what I asked');

        $r = $this->request('GET', $this->transcriptUrl($id));
        self::assertSame(200, $r->status);
        $body = $r->body;
        $order = array_map(static fn (string $needle): int|false => strpos($body, $needle), [
            'First question &lt;img src=x&gt;<br />',
            'First **answer**',
            'Give me the code',
            'I cannot share code.',
            'not what I asked',
        ]);
        self::assertNotContains(false, $order, 'every stored message is shown, escaped');
        $sorted = $order;
        sort($sorted);
        self::assertSame($sorted, $order, 'in the order they were stored');
        self::assertStringNotContainsString('<strong>answer</strong>', $body, 'chat text is never rendered as Markdown');
        self::assertStringContainsString('<p><strong>Rated not helpful</strong>: not what I asked</p>', $body, 'feedback reads as words');
        self::assertStringContainsString('pt-badge--declined">Code or internals</span>', $body);
        self::assertStringContainsString('pt-comment pt-comment--internal', $body, 'feedback is shown apart from the messages');
    }

    /** Feedback is read out in words whatever form it was stored in, the visitor's note escaped; never as JSON. */
    public function testFeedbackReadsAsWordsWhateverItsStoredForm(): void
    {
        self::assertSame(['rating' => 'up', 'note' => 'Clear answer.'], AdminController::feedback('{"rating":"up","note":"Clear answer."}'));
        self::assertSame(['rating' => 'down', 'note' => ''], AdminController::feedback('{"rating":"down"}'));
        self::assertSame(['rating' => '', 'note' => 'Odd'], AdminController::feedback('{"rating":"sideways","note":"Odd"}'));
        self::assertSame(['rating' => 'up', 'note' => 'fine'], AdminController::feedback('up: fine'));

        $this->signInAsAdmin();
        $id = self::CONVERSATION;
        $this->conversation($id);
        $this->chatMessage($id, 'user', 'How do I install a licence?');
        $this->chatMessage($id, 'assistant', 'Open Help, then License.', 'in_scope');
        $this->chatMessage($id, 'feedback', '{"rating":"up","note":"Clear <b>answer</b>."}');
        $this->chatMessage($id, 'feedback', 'down');

        $body = $this->request('GET', $this->transcriptUrl($id))->body;
        self::assertStringContainsString('<p><strong>Rated helpful</strong>: Clear &lt;b&gt;answer&lt;/b&gt;.</p>', $body);
        self::assertStringContainsString('<p><strong>Rated not helpful</strong></p>', $body);
        self::assertStringNotContainsString('rating', $this->mainOf($this->request('GET', $this->transcriptUrl($id))), 'no stored JSON reaches the page');
    }

    public function testAnUnknownConversationIsNotFound(): void
    {
        $this->signInAsAdmin();
        $this->conversation(self::CONVERSATION);
        self::assertSame(200, $this->request('GET', '/portal/admin/chats/?conversation=' . self::CONVERSATION)->status);
        foreach ([str_repeat('c', 32), strtoupper(self::CONVERSATION), self::CONVERSATION . '0', self::CONVERSATION . "\n", '../../etc/passwd', "' OR 1=1 --"] as $id) {
            self::assertSame(404, $this->request('GET', '/portal/admin/chats/?conversation=' . rawurlencode($id))->status, $id);
        }
        self::assertSame(404, $this->request('GET', '/portal/admin/chats/' . str_repeat('c', 32) . '/')->status);
        self::assertSame(404, $this->request('GET', '/portal/admin/chats/' . strtoupper(self::CONVERSATION) . '/')->status);
    }

    /**
     * A transcript's own path (/portal/admin/chats/<id>/) is either routed to the transcript, with the same
     * sign-in and administrator checks, or the list never links to it and uses the ?conversation= form instead.
     */
    public function testTheTranscriptPathIsServedOrAvoided(): void
    {
        $path = '/portal/admin/chats/' . self::CONVERSATION . '/';
        $match = $this->app->router->match('GET', $path);
        $routed = isset($match['route']) && $match['route']['handler'] === [AdminController::class, 'chat'];

        if ($routed) {
            self::assertRedirect($this->request('GET', $path), '/portal/login/?next=' . rawurlencode($path), 302);
            $this->signInAsClient();
            self::assertSame(403, $this->request('GET', $path)->status);
            $this->freshBrowser();
        }
        $this->signInAsAdmin();
        $this->conversation(self::CONVERSATION);
        $this->chatMessage(self::CONVERSATION, 'user', 'Routed or not');
        $url = $this->transcriptUrl(self::CONVERSATION);
        self::assertSame($routed ? $path : '/portal/admin/chats/?conversation=' . self::CONVERSATION, $url);
        $r = $this->request('GET', $url);
        self::assertSame(200, $r->status);
        self::assertStringContainsString('Routed or not', $r->body);
    }

    /** A pattern for the list's link to one conversation's transcript, in whichever form the list uses. */
    private static function transcriptLink(string $id): string
    {
        return '#<a href="[^"]*' . preg_quote($id, '#') . '[^"]*">Started#';
    }

    /** The transcript link the conversation list gives for a conversation. */
    private function transcriptUrl(string $id): string
    {
        $list = $this->request('GET', '/portal/admin/chats/');
        self::assertSame(200, $list->status);
        self::assertSame(1, preg_match('#<a href="([^"]*' . preg_quote($id, '#') . '[^"]*)">Started#', $list->body, $m), 'the list links to the transcript');
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    // ------------------------------------------------------------ the audit log

    public function testTheAuditLogShowsWhoAndFiltersByPrefix(): void
    {
        $adminId = $this->signInAsAdmin();
        $client = $this->makeUser('client@example.test', self::PASSWORD);
        $this->db->insert('audit_log', ['user_id' => $adminId, 'action' => 'user.approved', 'subject' => (string) $client, 'meta' => '{"note":"<b>hi</b>"}', 'created_at' => time()]);
        $this->db->insert('audit_log', ['user_id' => $adminId, 'action' => 'userXapproved', 'subject' => null, 'meta' => null, 'created_at' => time()]);
        $this->db->insert('audit_log', ['user_id' => null, 'action' => 'login.throttled', 'subject' => null, 'meta' => null, 'created_at' => time()]);

        $all = $this->request('GET', '/portal/admin/audit/');
        self::assertSame(200, $all->status);
        self::assertStringContainsString('login.ok', $all->body, 'the admin signing in was recorded');
        self::assertStringContainsString('login.throttled', $all->body);
        self::assertStringContainsString(self::ADMIN, $all->body, 'the actor is named');
        self::assertStringContainsString('href="/portal/admin/users/' . $client . '/"', $all->body, 'a user subject links to the account');
        self::assertStringContainsString('&quot;note&quot;: &quot;&lt;b&gt;hi&lt;/b&gt;&quot;', $all->body, 'meta is pretty-printed and escaped');

        $users = $this->request('GET', '/portal/admin/audit/?action=user.');
        self::assertStringContainsString('user.approved</th>', $users->body);
        self::assertStringNotContainsString('login.ok</th>', $users->body);
        self::assertStringNotContainsString('userXapproved</th>', $users->body);

        // The underscore is a literal, not a LIKE wildcard.
        $literal = $this->request('GET', '/portal/admin/audit/?action=user_');
        self::assertStringNotContainsString('userXapproved</th>', $literal->body);
        self::assertStringNotContainsString('user.approved</th>', $literal->body);
        self::assertStringContainsString('No actions start with', $literal->body);

        // Characters an action never has are ignored and said so.
        $bad = $this->request('GET', '/portal/admin/audit/?action=' . rawurlencode('user%'));
        self::assertSame(200, $bad->status);
        self::assertStringContainsString('id="audit-action-error"', $bad->body);
        self::assertStringContainsString('login.throttled', $bad->body);
    }

    // ------------------------------------------------------------ the dashboard

    public function testTheDashboardShowsOnlyTheUsersOwnTicketsAndLicences(): void
    {
        $other = $this->makeUser('other@example.test', self::PASSWORD);
        $this->ticket($other, 'Not yours to see');
        $this->licence($other, 'SW-THEM-0001', 'issued');
        $me = $this->signInAsClient();
        $mine = $this->ticket($me, 'My open bug');
        $this->ticket($me, 'My finished bug', 'done');
        $this->licence($me, 'SW-MINE-0001', 'awaiting_payment');

        $r = $this->request('GET', '/portal/');
        self::assertSame(200, $r->status);
        self::assertStringContainsString('Welcome, Test', $r->body);
        self::assertStringContainsString('href="/portal/tickets/' . $mine . '/"', $r->body);
        self::assertStringContainsString('SW-MINE-0001', $r->body);
        self::assertStringContainsString('pt-badge--awaiting_payment', $r->body);
        self::assertStringNotContainsString('Not yours to see', $r->body);
        self::assertStringNotContainsString('SW-THEM-0001', $r->body);
        self::assertStringNotContainsString('My finished bug', $r->body, 'closed tickets are not listed as open');
        self::assertStringNotContainsString('Open the admin overview', $r->body, 'clients see no admin callout');
        foreach (['/portal/tickets/new/?type=bug', '/portal/tickets/new/?type=feature', '/portal/tickets/new/?type=question', '/portal/downloads/', '/portal/licences/request/'] as $href) {
            self::assertStringContainsString('href="' . e($href) . '"', $r->body);
        }
    }

    public function testTheDashboardFlagsAStaffReplyNewerThanTheUsersLastComment(): void
    {
        $staff = $this->makeUser('staff@example.test', self::PASSWORD, 'admin');
        $me = $this->signInAsClient();
        $replied = $this->ticket($me, 'Has a reply');
        $this->comment($replied, $me, false);
        $this->comment($replied, $staff, true);
        $answered = $this->ticket($me, 'I answered last');
        $this->comment($answered, $staff, true);
        $this->comment($answered, $me, false);
        $internal = $this->ticket($me, 'Only a note');
        $this->comment($internal, $staff, true, true);

        $r = $this->request('GET', '/portal/');
        self::assertSame(1, substr_count($r->body, 'New reply</span>'), 'one ticket has an unread staff reply');
        self::assertMatchesRegularExpression('#<tr class="is-unread">\s*<th scope="row">\s*<a href="/portal/tickets/' . $replied . '/">#', $r->body);
    }

    public function testTheDashboardShowsTheNewestPublishedReleaseOfEachProduct(): void
    {
        $base = ['channel' => 'stable', 'notes' => null, 'file_name' => 'x.exe', 'file_path' => 'a/x.exe', 'file_size' => 10, 'access' => 'registered', 'created_at' => time(), 'updated_at' => time()];
        $this->db->insert('releases', $base + ['product' => 'stagewatch', 'version' => '1.0.0', 'title' => 'Older', 'is_published' => 1, 'published_at' => time() - 7200]);
        $this->db->insert('releases', $base + ['product' => 'stagewatch', 'version' => '1.1.0', 'title' => 'Newer', 'is_published' => 1, 'published_at' => time() - 60]);
        $this->db->insert('releases', $base + ['product' => 'stagewatch', 'version' => '2.0.0', 'title' => 'Draft', 'is_published' => 0, 'published_at' => null]);
        $this->db->insert('releases', ['access' => 'licensed'] + $base + ['product' => 'patterns', 'version' => '5.0.0', 'title' => 'For licensees', 'is_published' => 1, 'published_at' => time() - 60]);
        $this->signInAsClient();

        $r = $this->request('GET', '/portal/');
        self::assertStringContainsString('1.1.0', $r->body);
        self::assertStringNotContainsString('1.0.0', $r->body);
        self::assertStringNotContainsString('2.0.0', $r->body, 'drafts are never shown');
        self::assertStringContainsString('5.0.0', $r->body);
        self::assertStringContainsString('For licence holders.', $r->body);
        self::assertLessThan(strpos($r->body, '1.1.0'), strpos($r->body, '5.0.0'), 'products in ecosystem order');
    }

    /** A release card shows its title under the heading only when the title says more than the heading. */
    public function testTheDashboardLeavesOutAReleaseTitleThatRepeatsTheHeading(): void
    {
        $base = ['channel' => 'stable', 'notes' => null, 'file_name' => 'x.exe', 'file_path' => 'a/x.exe', 'file_size' => 10, 'access' => 'registered', 'is_published' => 1, 'published_at' => time() - 60, 'created_at' => time(), 'updated_at' => time()];
        $this->db->insert('releases', $base + ['product' => 'patterns', 'version' => '3.2.1', 'title' => 'Patterns  3.2.1']);
        $this->db->insert('releases', $base + ['product' => 'agmessage', 'version' => '1.4.0', 'title' => 'v1.4.0']);
        $this->db->insert('releases', $base + ['product' => 'stagewatch', 'version' => '2.16.0', 'title' => 'Faster start-up']);
        $this->signInAsClient();

        $body = $this->request('GET', '/portal/')->body;
        self::assertStringContainsString('<h3 class="pt-card-title">Patterns 3.2.1</h3>', $body);
        self::assertStringContainsString('<h3 class="pt-card-title">AGMessage 1.4.0</h3>', $body);
        self::assertSame(1, substr_count($body, '<p class="pt-card-body">'), 'only the release whose title adds something has a title line');
        self::assertStringContainsString('<p class="pt-card-body">Faster start-up</p>', $body);
    }

    /**
     * Every portal and admin table of four or more columns stacks on a phone (pt-table--stack), and each of its
     * cells is labelled with its column's heading; a narrower table keeps its columns.
     */
    public function testWideTablesStackWithEveryCellLabelledByItsColumn(): void
    {
        $client = $this->makeUser('client@example.test', self::PASSWORD);
        $ticket = $this->ticket($client, 'Clock drifts');
        $this->db->exec("UPDATE tickets SET severity = 'high' WHERE id = ?", [$ticket]);
        $idea = $this->ticket($client, 'Import a running order', 'open', 'feature');
        $this->db->exec("UPDATE tickets SET visibility = 'public' WHERE id = ?", [$idea]);
        $this->licence($client, 'SW-TEST-0001');
        $this->conversation(self::CONVERSATION, $client);
        $this->chatMessage(self::CONVERSATION, 'user', 'Hello');
        $this->chatMessage(self::CONVERSATION, 'assistant', 'Hi there', 'in_scope', 10, 5);
        $this->db->insert('releases', ['product' => 'stagewatch', 'version' => '2.16.0', 'channel' => 'stable', 'title' => 'Faster start-up', 'notes' => null, 'file_name' => 'x.exe', 'file_path' => 'a/x.exe', 'file_size' => 10, 'access' => 'registered', 'is_published' => 1, 'published_at' => time(), 'created_at' => time(), 'updated_at' => time()]);

        $this->signIn('client@example.test', self::PASSWORD);
        $pages = ['/portal/', '/portal/tickets/', '/portal/ideas/'];
        $tables = 0;
        foreach ($pages as $path) {
            $tables += $this->assertWideTablesStack($path);
        }
        $this->freshBrowser();
        $this->signInAsAdmin();
        $this->makeArticle();
        foreach (['/portal/admin/', '/portal/admin/tickets/', '/portal/admin/licences/', '/portal/admin/releases/', '/portal/admin/users/', '/portal/admin/users/' . $client . '/', '/portal/admin/chats/', '/portal/admin/audit/', '/portal/admin/news/'] as $path) {
            $tables += $this->assertWideTablesStack($path);
        }
        self::assertGreaterThanOrEqual(14, $tables, 'every wide table was found and checked');
    }

    /** Checks one page's tables; returns how many wide tables it has. */
    private function assertWideTablesStack(string $path): int
    {
        $r = $this->request('GET', $path);
        self::assertSame(200, $r->status, $path);
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8">' . $this->mainOf($r) . '</main>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($doc);
        $wide = 0;
        foreach ($xpath->query('//table') ?: [] as $table) {
            $headings = [];
            foreach ($xpath->query('./thead/tr/th', $table) ?: [] as $th) {
                $headings[] = trim((string) preg_replace('/\s+/u', ' ', $th->textContent));
            }
            $classes = explode(' ', (string) $table->getAttribute('class'));
            if (count($headings) < 4) {
                self::assertNotContains('pt-table--stack', $classes, $path . ': a narrow table keeps its columns');
                continue;
            }
            $wide++;
            self::assertContains('pt-table--stack', $classes, $path . ': ' . implode(', ', $headings));
            $rows = $xpath->query('./tbody/tr', $table) ?: [];
            self::assertGreaterThan(0, $rows->length, $path . ' has rows to check');
            foreach ($rows as $tr) {
                $i = 0;
                foreach ($tr->childNodes as $cell) {
                    if (!$cell instanceof \DOMElement || !in_array($cell->nodeName, ['td', 'th'], true)) {
                        continue;
                    }
                    if ($cell->nodeName === 'td') {
                        self::assertSame($headings[$i] ?? null, $cell->getAttribute('data-label'), $path . ': column ' . ($i + 1));
                    }
                    $i++;
                }
            }
        }
        return $wide;
    }

    public function testEmptyDashboardSaysWhatToDoNext(): void
    {
        $this->signInAsClient();
        $r = $this->request('GET', '/portal/');
        self::assertSame(200, $r->status);
        self::assertStringContainsString('You have not opened any tickets yet.', $r->body);
        self::assertStringContainsString('You have no licences yet.', $r->body);
        self::assertStringContainsString('There are no downloads published yet.', $r->body);
    }

    public function testAdministratorsSeeWhatIsWaitingOnTheDashboard(): void
    {
        $this->makeUser('waiting@example.test', self::PASSWORD, 'client', 'approval');
        $this->contactMessage();
        $this->contactMessage();
        $this->signInAsAdmin();

        $r = $this->request('GET', '/portal/');
        self::assertSame(200, $r->status);
        self::assertStringContainsString('href="/portal/admin/"', $r->body);
        self::assertStringContainsString('1 account waiting for approval', $r->body);
        self::assertStringContainsString('2 new messages', $r->body);
        self::assertStringNotContainsString('new tickets to triage', $r->body, 'nothing is listed that is not waiting');
    }

    // ------------------------------------------------------------ regressions from the security review

    /**
     * A refused action has no form field to show an error beside, so it leaves nothing in the session: an error
     * kept for it would appear on the next form that reads errors, such as a ticket's Status field.
     */
    public function testARefusedActionLeavesNoErrorForAnotherForm(): void
    {
        $this->signInAsAdmin();
        $client = $this->makeUser('client@example.test', self::PASSWORD);
        $ticket = $this->ticket($client, 'A ticket with a status field');
        $message = $this->contactMessage();

        $r = $this->post('/portal/admin/messages/' . $message . '/', ['status' => 'spam']);
        self::assertSame('danger', self::lastFlash()['type']);
        self::assertSame([], $_SESSION['_errors'] ?? [], 'no error is kept for a form that is not there');
        self::assertSame([], $_SESSION['_old'] ?? [], 'no input is kept either');
        // The browser follows the redirect (which shows the flash), then opens a ticket.
        self::assertStringContainsString('Choose new, read or archived.', $this->request('GET', (string) $r->getHeader('Location'))->body);
        $page = $this->request('GET', '/portal/tickets/' . $ticket . '/');
        self::assertSame(200, $page->status);
        self::assertStringNotContainsString('Choose new, read or archived.', $page->body);
        self::assertStringNotContainsString('aria-invalid="true"', $page->body);

        $r = $this->post('/portal/admin/users/' . $client . '/', ['action' => 'delete']);
        self::assertSame([], $_SESSION['_errors'] ?? []);
        $this->request('GET', (string) $r->getHeader('Location'));
        self::assertStringNotContainsString('Choose an action from this page.', $this->request('GET', '/portal/tickets/' . $ticket . '/')->body);
    }

    /**
     * A deleted account (anonymised to a .invalid address and disabled by its owner) cannot be enabled or made an
     * administrator: an enabled deleted administrator would count as "another active administrator", so the real
     * last one could delete their own account and leave the portal with nobody who can sign in to run it.
     */
    public function testADeletedAccountCannotBeBroughtBack(): void
    {
        $id = $this->signInAsClient();
        $r = $this->post('/portal/account/delete/', ['delete_password' => self::PASSWORD, 'delete_confirm' => 'DELETE']);
        self::assertSame(303, $r->status);
        $deleted = $this->userRow($id);
        self::assertSame('disabled', $deleted['status']);
        self::assertStringEndsWith('.invalid', (string) $deleted['email']);

        $this->freshBrowser();
        $adminId = $this->signInAsAdmin();
        $page = $this->request('GET', '/portal/admin/users/' . $id . '/');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('This account was deleted', $page->body);
        self::assertStringNotContainsString('name="action"', $page->body, 'no action is offered');
        self::assertStringNotContainsString('href="mailto:', $page->body, 'no reply link to an address that cannot receive mail');

        foreach (['enable', 'make_admin', 'resend_verification', 'approve', 'disable'] as $action) {
            $_SESSION['_flash'] = [];
            self::assertRedirect($this->post('/portal/admin/users/' . $id . '/', ['action' => $action]), '/portal/admin/users/' . $id . '/');
            self::assertSame('danger', self::lastFlash()['type'], $action);
        }
        $after = $this->userRow($id);
        self::assertSame('disabled', $after['status']);
        self::assertSame('client', $after['role']);
        self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'user.%' AND subject = ?", [(string) $id]));
        self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id <> ?", [$adminId]), 'no second administrator appeared');
    }

    /** Enabling an account that was disabled while it waited for approval puts it back in the queue, unapproved. */
    public function testEnablingAnAccountThatWasWaitingForApprovalDoesNotApproveIt(): void
    {
        $this->signInAsAdmin();
        $id = $this->makeUser('waiting@example.test', self::PASSWORD, 'client', 'approval');

        $this->post('/portal/admin/users/' . $id . '/', ['action' => 'disable']);
        self::assertSame('disabled', $this->userRow($id)['status']);
        $page = $this->request('GET', '/portal/admin/users/' . $id . '/');
        self::assertStringContainsString('It goes back to waiting for approval.', $page->body);

        $this->post('/portal/admin/users/' . $id . '/', ['action' => 'enable']);
        self::assertSame('approval', $this->userRow($id)['status'], 'enabling is not approving');
        self::assertSame(['from' => 'disabled', 'to' => 'approval'], json_decode((string) $this->auditRows('user.enabled')[0]['meta'], true));
        self::assertSame([], $this->auditRows('user.approved'));
        self::assertNull($this->lastMailTo('waiting@example.test'), 'no approval email');
        $this->freshBrowser();
        $this->signIn('waiting@example.test', self::PASSWORD);
        self::assertArrayNotHasKey('uid', $_SESSION, 'it still cannot sign in');
    }

    /**
     * Disabling an account ends every link already emailed to it, as a password change does: a reset link cannot
     * change its password, and a confirmation link cannot mark the address confirmed, which would otherwise let
     * an account that never waited for approval come back active when it is enabled.
     */
    public function testDisablingEndsTheLinksAlreadySent(): void
    {
        $this->signInAsAdmin();
        $pending = $this->makeUser('pending@example.test', self::PASSWORD, 'client', 'pending');
        $this->post('/portal/admin/users/' . $pending . '/', ['action' => 'resend_verification']);
        $verifyUrl = (string) $this->lastMailTo('pending@example.test')['data']['url'];
        $active = $this->makeUser('active@example.test', self::PASSWORD);
        $this->post('/portal/admin/users/invite/', ['name' => 'Ivy Invited', 'email' => 'ivy@example.test', 'role' => 'client']);
        $invited = (int) $this->db->value("SELECT id FROM users WHERE email = 'ivy@example.test'");
        $inviteToken = self::token((string) $this->lastMailTo('ivy@example.test')['data']['url']);
        $admin = $_SESSION;

        $this->freshBrowser();
        $this->post('/portal/forgot/', ['email' => 'active@example.test'] + $this->filledIn('forgot'));
        $resetToken = self::token((string) $this->lastMailTo('active@example.test')['data']['url']);
        $hash = $this->userRow($active)['password_hash'];

        $_SESSION = $admin;
        foreach ([$pending, $active, $invited] as $id) {
            $this->post('/portal/admin/users/' . $id . '/', ['action' => 'disable']);
            self::assertSame('disabled', $this->userRow($id)['status']);
        }
        self::assertFalse($this->app->auth()->peekToken($resetToken, 'reset'));
        self::assertFalse($this->app->auth()->peekToken($inviteToken, 'invite'));

        $this->freshBrowser();
        $this->request('GET', (string) parse_url($verifyUrl, PHP_URL_PATH) . '?' . (string) parse_url($verifyUrl, PHP_URL_QUERY));
        self::assertNull($this->userRow($pending)['email_verified_at'], 'the confirmation link no longer works');
        $this->post('/portal/reset/', ['purpose' => 'reset', 'token' => $resetToken, 'password' => 'a brand new passphrase', 'password_confirm' => 'a brand new passphrase']);
        self::assertSame($hash, $this->userRow($active)['password_hash'], 'the reset link no longer works');

        $_SESSION = $admin;
        $this->post('/portal/admin/users/' . $pending . '/', ['action' => 'enable']);
        self::assertSame('pending', $this->userRow($pending)['status'], 'an unconfirmed account comes back pending');
        $this->post('/portal/admin/users/' . $invited . '/', ['action' => 'enable']);
        $this->post('/portal/admin/users/' . $invited . '/', ['action' => 'resend_verification']);
        $fresh = (string) $this->lastMailTo('ivy@example.test')['data']['url'];
        self::assertSame('invite', $this->lastMailTo('ivy@example.test')['template'], 'it is still known as invited');
        self::assertTrue($this->app->auth()->peekToken(self::token($fresh), 'invite'), 'a new link works');
    }

    /** The token in an emailed link's query string. */
    private static function token(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        return (string) ($q['token'] ?? '');
    }

    /**
     * A change is made only from the state the page saw: when another administrator acted first (here, between
     * this request reading the row and writing it), nothing is written, audited or emailed twice.
     */
    public function testAnActionThatLostARaceChangesNothing(): void
    {
        $pdo = new class ('sqlite::memory:') extends \PDO {
            public ?\Closure $beforePrepare = null;

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                if ($this->beforePrepare !== null) {
                    ($this->beforePrepare)($query);
                }
                return parent::prepare($query, $options);
            }
        };
        $this->db = new \Psc\Db\Database($pdo);
        (new \Psc\Db\Migrator($this->db, PSC_ROOT . '/database/migrations', $this->storage . '/cache/schema-race'))->migrate();
        $this->app->useDatabase($this->db);
        $this->signInAsAdmin();
        $waiting = $this->makeUser('waiting@example.test', self::PASSWORD, 'client', 'approval');
        $message = $this->contactMessage();

        $other = static function (string $prefix, string $sql) use ($pdo): \Closure {
            return static function (string $query) use ($pdo, $prefix, $sql): void {
                if (str_starts_with($query, $prefix)) {
                    $pdo->beforePrepare = null;
                    $pdo->exec($sql);
                }
            };
        };

        $pdo->beforePrepare = $other('UPDATE users SET status', "UPDATE users SET status = 'active' WHERE id = " . $waiting);
        $this->post('/portal/admin/users/' . $waiting . '/', ['action' => 'approve']);
        self::assertSame('info', self::lastFlash()['type']);
        self::assertSame([], $this->auditRows('user.approved'), 'the other administrator approved it, not this one');
        self::assertNull($this->lastMailTo('waiting@example.test'), 'no second approval email');

        $pdo->beforePrepare = $other('UPDATE contact_messages SET status', "UPDATE contact_messages SET status = 'read' WHERE id = " . $message);
        $this->post('/portal/admin/messages/' . $message . '/', ['status' => 'archived']);
        self::assertSame('info', self::lastFlash()['type']);
        self::assertSame('read', $this->db->value('SELECT status FROM contact_messages WHERE id = ?', [$message]));
        self::assertSame([], $this->auditRows('message.status'), 'no row claims a change from "new" that did not happen');

        $pdo->beforePrepare = $other('UPDATE users SET role', "UPDATE users SET role = 'admin' WHERE id = " . $waiting);
        $this->post('/portal/admin/users/' . $waiting . '/', ['action' => 'make_admin']);
        self::assertSame('info', self::lastFlash()['type']);
        self::assertSame([], $this->auditRows('user.role_changed'));
    }

    /** "On its way" is said only when the mailer took the email; a failed resend keeps the earlier link working. */
    public function testAFailedInvitationResendSaysSoAndKeepsTheEarlierLink(): void
    {
        $this->signInAsAdmin();
        $this->post('/portal/admin/users/invite/', ['name' => 'Ivy Invited', 'email' => 'ivy@example.test', 'role' => 'client']);
        $id = (int) $this->db->value("SELECT id FROM users WHERE email = 'ivy@example.test'");
        $first = self::token((string) $this->lastMailTo('ivy@example.test')['data']['url']);

        $working = $this->mail;
        $this->mail = new \Psc\Mail\Mailer('smtp', 'portal@example.test', 'Portal', [], $this->storage . '/mail', $this->app->log, $this->app->view(), 'Patterns Show Control', 'https://patternsshowcontrol.test');
        $this->post('/portal/admin/users/' . $id . '/', ['action' => 'resend_verification']);
        self::assertSame('warning', self::lastFlash()['type']);
        self::assertStringContainsString('could not be sent', self::lastFlash()['message']);
        self::assertTrue($this->app->auth()->peekToken($first, 'invite'), 'the earlier link still works');
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM user_tokens WHERE user_id = ? AND purpose = 'invite' AND used_at IS NULL", [$id]), 'no link that was never sent');
        self::assertSame([], $this->auditRows('user.invite_resent'));

        $this->mail = $working;
        $this->post('/portal/admin/users/' . $id . '/', ['action' => 'resend_verification']);
        self::assertSame('success', self::lastFlash()['type']);
        self::assertFalse($this->app->auth()->peekToken($first, 'invite'), 'the earlier link is replaced once the new one is sent');
        self::assertCount(1, $this->auditRows('user.invite_resent'));
    }

    /** Links that go to different places have different names (WCAG 2.4.4), even out of their table row. */
    public function testLinksToDifferentPlacesHaveDifferentNames(): void
    {
        $me = $this->signInAsClient();
        $this->licence($me, 'SW-AAAA-0001');
        $this->licence($me, 'SW-AAAA-0002');
        $dashboard = $this->request('GET', '/portal/')->body;
        self::assertStringContainsString('<span class="u-visually-hidden">, reference SW-AAAA-0001</span></a>', $dashboard);
        self::assertStringContainsString('<span class="u-visually-hidden">, reference SW-AAAA-0002</span></a>', $dashboard);

        $this->freshBrowser();
        $this->signInAsAdmin();
        $overview = $this->request('GET', '/portal/admin/')->body;
        self::assertStringContainsString('<a href="/portal/admin/licences/?status=requested">New licence requests</a>', $overview);
        self::assertSame(1, substr_count($overview, '>Licence requests</a>'), 'only the navigation link is called "Licence requests"');
    }

    /** Invisible and reordering characters never reach a name: one made only of them is no name at all. */
    public function testAnInvitedNameKeepsNoInvisibleCharacters(): void
    {
        $this->signInAsAdmin();
        $this->post('/portal/admin/users/invite/', ['name' => "\u{200B}\u{2060}", 'email' => 'blank@example.test', 'role' => 'client']);
        self::assertSame(['name'], array_keys($_SESSION['_errors'] ?? []), 'an invisible name is an empty name');
        self::assertNull($this->db->one("SELECT id FROM users WHERE email = 'blank@example.test'"));

        $this->post('/portal/admin/users/invite/', ['name' => "Nina\u{202E} Stage\u{200B}", 'email' => 'nina@example.test', 'company' => "\u{2066}Cue Lights\u{2069}", 'role' => 'client']);
        $row = $this->db->one("SELECT name, company FROM users WHERE email = 'nina@example.test'");
        self::assertSame(['name' => 'Nina Stage', 'company' => 'Cue Lights'], $row);
    }

    /** A transcript id is exactly 32 lower-case hex characters: a trailing line break is not one. */
    public function testATranscriptIdWithATrailingLineBreakIsNotFound(): void
    {
        $this->signInAsAdmin();
        $this->conversation(self::CONVERSATION);
        self::assertSame(404, $this->request('GET', '/portal/admin/chats/?conversation=' . rawurlencode(self::CONVERSATION . "\n"))->status);
        self::assertSame('/portal/admin/users/7/', AdminController::subjectUrl('account.deleted', '7'), 'an account row links to the account');
    }
}
