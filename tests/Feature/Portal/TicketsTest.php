<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\Portal;

use Psc\Http\Response;
use Psc\Tests\TestCase;

/**
 * Tickets and the feature-request board: creation and validation for each type, uploads, who may read, reply,
 * attach and vote, internal notes, the emails each action sends, the admin list and update, and the limits.
 */
final class TicketsTest extends TestCase
{
    private const A = 'client@example.test';
    private const B = 'other@example.test';
    private const ADMIN = 'admin@example.test';
    private const ADMIN_INBOX = 'owner@example.test';

    // ---------------------------------------------------------------------------------------------------------
    // Helpers

    /**
     * Signs in as $email (creating the account) with Auth::login, the step the sign-in form ends with. The form is
     * the accounts unit's to test; through it, every sign-in pays for a password rehash, and this file signs in
     * some sixty times (the whole suite runs under the time limit the assistant's endpoint sets). One test signs
     * in through the form ($throughTheForm) so the two units are still seen working together.
     */
    private function actAs(string $email, string $role = 'client', bool $throughTheForm = false): int
    {
        $_SESSION = [];
        $this->jar = [];
        $id = (int) ($this->db->value('SELECT id FROM users WHERE email = ?', [$email]) ?? 0);
        if ($id === 0) {
            $id = $this->makeUser($email, 'correct horse battery', $role);
        }
        if ($throughTheForm) {
            $this->signIn($email);
        } else {
            $row = $this->app->auth()->find($id);
            self::assertNotNull($row);
            $this->app->auth()->login($row);
        }
        self::assertSame($id, (int) ($_SESSION['uid'] ?? 0));
        return $id;
    }

    /** @return array<string, string> a complete, valid bug report */
    private static function bug(array $over = []): array
    {
        return $over + [
            'type' => 'bug',
            'title' => 'Output goes black after changing displays',
            'product' => 'patterns',
            'app_version' => '2.4.1',
            'environment' => "Windows 11 24H2\nRTX 4070, two outputs",
            'severity' => 'high',
            'what_happened' => 'The output window went black when I unplugged the second display.',
            'expected' => 'The output to move to the display that was left.',
            'steps' => "1. Open a show\n2. Unplug display 2",
        ];
    }

    /** @return array<string, string> */
    private static function feature(array $over = []): array
    {
        return $over + [
            'type' => 'feature',
            'title' => 'Import a running order from a spreadsheet',
            'product' => 'stagewatch',
            'problem' => 'Our running orders live in spreadsheets and retyping them is slow.',
            'idea' => 'Let me pick a file and map its columns to the running order.',
        ];
    }

    /** @return array<string, string> */
    private static function question(array $over = []): array
    {
        return $over + [
            'type' => 'question',
            'title' => 'Can one licence cover a backup PC?',
            'product' => 'stagewatch',
            'question' => 'We run a main and a backup PC at every show. What do we need?',
        ];
    }

    private static function idFrom(Response $r): int
    {
        self::assertSame(303, $r->status);
        $location = (string) $r->getHeader('Location');
        self::assertMatchesRegularExpression('#^/portal/tickets/\d+/$#', $location);
        preg_match('#^/portal/tickets/(\d+)/$#', $location, $m);
        return (int) $m[1];
    }

    /** @param array<string, string> $fields */
    private function open(array $fields): int
    {
        return self::idFrom($this->post('/portal/tickets/', $fields));
    }

    /** @return array<string, mixed> */
    private function ticket(int $id): array
    {
        $row = $this->db->one('SELECT * FROM tickets WHERE id = ?', [$id]);
        self::assertNotNull($row);
        return $row;
    }

    private function rows(string $sql, array $params = []): int
    {
        return (int) $this->db->value($sql, $params);
    }

    /** @return list<array<string, mixed>> */
    private function mailsTo(string $to): array
    {
        return array_values(array_filter($this->mails(), static fn (array $m): bool => $m['to'] === $to));
    }

    /** @return array<string, string> the errors the last validation redirect kept */
    private static function keptErrors(): array
    {
        $errors = $_SESSION['_errors'] ?? [];
        return is_array($errors) ? $errors : [];
    }

    /** @return list<string> files in the ticket upload store */
    private function storedUploads(): array
    {
        return glob($this->storage . '/uploads/tickets/*') ?: [];
    }

    // ---------------------------------------------------------------------------------------------------------
    // Access

    public function testSignedOutVisitorsAreSentToSignIn(): void
    {
        foreach (['/portal/tickets/', '/portal/tickets/new/', '/portal/tickets/1/', '/portal/attachments/1/', '/portal/ideas/'] as $path) {
            $r = $this->request('GET', $path);
            self::assertSame(302, $r->status, $path);
            self::assertSame('/portal/login/?next=' . rawurlencode($path), $r->getHeader('Location'), $path);
        }
        $r = $this->post('/portal/tickets/', self::question());
        self::assertSame(302, $r->status);
        self::assertStringStartsWith('/portal/login/', (string) $r->getHeader('Location'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM tickets'));

        // Signed in through the real form, the same pages open.
        $this->actAs(self::A, 'client', true);
        self::assertSame(200, $this->request('GET', '/portal/tickets/')->status);
        self::assertSame(200, $this->request('GET', '/portal/ideas/')->status);
    }

    public function testEveryPostNeedsTheCsrfToken(): void
    {
        $this->actAs(self::A);
        $id = $this->open(self::feature());
        $this->actAs(self::ADMIN, 'admin');
        foreach ([
            ['/portal/tickets/', self::question()],
            ["/portal/tickets/{$id}/comments/", ['body' => 'Hello there']],
            ["/portal/tickets/{$id}/vote/", ['return' => '/portal/ideas/']],
            ["/portal/admin/tickets/{$id}/", ['status' => 'planned', 'visibility' => 'public']],
        ] as [$uri, $fields]) {
            self::assertSame(419, $this->post($uri, $fields, false)->status, $uri);
        }
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM tickets'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM ticket_comments'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM ticket_votes'));
        self::assertSame('open', $this->ticket($id)['status']);
    }

    public function testClientsGet403OnTheAdminPages(): void
    {
        $this->actAs(self::A);
        $id = $this->open(self::bug());
        self::assertSame(403, $this->request('GET', '/portal/admin/tickets/')->status);
        self::assertSame(403, $this->post("/portal/admin/tickets/{$id}/", ['status' => 'done', 'visibility' => 'public', 'severity' => 'low'])->status);
        $t = $this->ticket($id);
        self::assertSame(['open', 'private', 'high'], [$t['status'], $t['visibility'], $t['severity']]);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Creating tickets

    public function testTheChooserAndEachForm(): void
    {
        $this->actAs(self::A);
        $chooser = $this->request('GET', '/portal/tickets/new/?product=stagewatch');
        self::assertSame(200, $chooser->status);
        foreach (['bug', 'feature', 'question'] as $type) {
            self::assertStringContainsString('/portal/tickets/new/?type=' . $type . '&amp;product=stagewatch', $chooser->body);
        }

        $bug = $this->request('GET', '/portal/tickets/new/?type=bug&product=stagewatch');
        self::assertSame(200, $bug->status);
        self::assertStringContainsString('enctype="multipart/form-data"', $bug->body);
        self::assertStringContainsString('name="severity"', $bug->body);
        self::assertStringContainsString('name="attachments[]"', $bug->body);
        self::assertStringContainsString('<option value="stagewatch" selected>', $bug->body);
        self::assertStringContainsString('name="_csrf"', $bug->body);
        self::assertSame(1, substr_count($bug->body, '<h1'));

        $feature = $this->request('GET', '/portal/tickets/new/?type=feature');
        self::assertStringContainsString('name="problem"', $feature->body);
        self::assertStringNotContainsString('name="attachments[]"', $feature->body);
        self::assertStringContainsString('name="question"', $this->request('GET', '/portal/tickets/new/?type=question')->body);
        // An unknown type shows the chooser rather than a form.
        self::assertStringNotContainsString('name="title"', $this->request('GET', '/portal/tickets/new/?type=../../etc')->body);
    }

    public function testBugValidationErrorsAreKeptAndShown(): void
    {
        $this->actAs(self::A);
        $r = $this->post('/portal/tickets/', ['type' => 'bug', 'title' => 'Hi', 'what_happened' => 'short', 'steps' => str_repeat('x', 5001), 'environment' => str_repeat('e', 256), 'app_version' => str_repeat('9', 41)]);
        self::assertSame(303, $r->status);
        self::assertSame('/portal/tickets/new/?type=bug#form', $r->getHeader('Location'));
        self::assertEqualsCanonicalizing(['title', 'product', 'severity', 'what_happened', 'steps', 'environment', 'app_version'], array_keys(self::keptErrors()));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM tickets'));
        self::assertSame([], $this->mails());

        $form = $this->request('GET', '/portal/tickets/new/?type=bug');
        self::assertStringContainsString('aria-invalid="true"', $form->body);
        self::assertStringContainsString('id="f-title-error"', $form->body);
        self::assertStringContainsString('aria-describedby="f-title-help f-title-error"', $form->body);
        self::assertStringContainsString('value="Hi"', $form->body, 'the input is kept');
        self::assertStringContainsString('Please check the highlighted fields.', $form->body);
    }

    public function testFeatureAndQuestionValidation(): void
    {
        $this->actAs(self::A);
        $r = $this->post('/portal/tickets/', ['type' => 'feature', 'title' => 'A good idea', 'product' => 'patterns', 'problem' => 'too short']);
        self::assertSame('/portal/tickets/new/?type=feature#form', $r->getHeader('Location'));
        self::assertEqualsCanonicalizing(['problem', 'idea'], array_keys(self::keptErrors()));

        $r = $this->post('/portal/tickets/', ['type' => 'question', 'title' => str_repeat('t', 151)]);
        self::assertSame('/portal/tickets/new/?type=question#form', $r->getHeader('Location'));
        self::assertEqualsCanonicalizing(['title', 'product', 'question'], array_keys(self::keptErrors()));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM tickets'));
    }

    public function testUnknownEnumValuesAreRefused(): void
    {
        $this->actAs(self::A);
        $r = $this->post('/portal/tickets/', self::bug(['type' => 'complaint']));
        self::assertSame('/portal/tickets/new/', $r->getHeader('Location'));
        self::assertSame(['type'], array_keys(self::keptErrors()));

        $this->post('/portal/tickets/', self::bug(['severity' => 'urgent']));
        self::assertSame(['severity'], array_keys(self::keptErrors()));

        $this->post('/portal/tickets/', self::feature(['product' => 'photoshop']));
        self::assertSame(['product'], array_keys(self::keptErrors()));

        $this->post('/portal/tickets/', self::question(['product' => ['patterns']]));
        self::assertSame(['product'], array_keys(self::keptErrors()));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM tickets'));
    }

    public function testABugIsStoredPrivatelyWithItsScreenshotAndTheTeamIsEmailed(): void
    {
        $a = $this->actAs(self::A);
        $r = $this->postFiles('/portal/tickets/', self::bug(), ['attachments' => [$this->fakeUpload('screen.png', self::pngBytes())]]);
        $id = self::idFrom($r);
        self::assertSame('success', end($_SESSION['_flash'])['type']);

        $t = $this->ticket($id);
        self::assertSame($a, (int) $t['user_id']);
        self::assertSame(['bug', 'patterns', 'open', 'high', 'private', '2.4.1'], [$t['type'], $t['product'], $t['status'], $t['severity'], $t['visibility'], $t['app_version']]);
        self::assertSame("Windows 11 24H2\nRTX 4070, two outputs", $t['environment']);
        self::assertStringStartsWith("### What happened\n\nThe output window went black", $t['body']);
        self::assertStringContainsString("### What I expected\n\n", $t['body']);
        self::assertStringContainsString("### Steps to reproduce\n\n1. Open a show", $t['body']);
        self::assertSame(0, (int) $t['comment_count']);
        self::assertGreaterThan(0, (int) $t['last_activity_at']);

        $file = $this->db->one('SELECT * FROM ticket_attachments WHERE ticket_id = ?', [$id]);
        self::assertNotNull($file);
        self::assertSame(['screen.png', 'image/png', null], [$file['original_name'], $file['mime'], $file['comment_id']]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $file['stored_name']);
        self::assertFileExists($this->storage . '/uploads/tickets/' . $file['stored_name']);

        $mail = $this->lastMailTo(self::ADMIN_INBOX);
        self::assertNotNull($mail);
        self::assertSame('ticket-new-admin', $mail['template']);
        self::assertSame('New bug report #' . $id . ': Output goes black after changing displays', $mail['subject']);
        self::assertStringContainsString('/portal/tickets/' . $id . '/', $mail['html']);
        self::assertSame(1, $mail['data']['fileCount']);
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM audit_log WHERE action = 'ticket.created' AND subject = ?", [(string) $id]));

        $page = $this->request('GET', "/portal/tickets/{$id}/");
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Output goes black after changing displays', $page->body);
        self::assertStringContainsString('/portal/attachments/' . $file['id'] . '/', $page->body);
        self::assertStringContainsString('pt-badge--open', $page->body);
        self::assertStringContainsString('<h3>What happened</h3>', $page->body, 'body headings nest under the page\'s h2 sections');

        $download = $this->request('GET', '/portal/attachments/' . $file['id'] . '/');
        self::assertSame(200, $download->status);
        self::assertSame('image/png', $download->getHeader('Content-Type'));
        self::assertStringStartsWith('attachment; filename="screen.png"', (string) $download->getHeader('Content-Disposition'));
        self::assertSame('nosniff', $download->getHeader('X-Content-Type-Options'));
        self::assertSame(self::pngBytes(), $download->captureStream());
    }

    public function testDisguisedAndExtraFilesAreRefused(): void
    {
        $this->actAs(self::A);
        $r = $this->postFiles('/portal/tickets/', self::bug(), ['attachments' => [$this->fakeUpload('evil.php', '<?php echo "hi";')]]);
        self::assertSame('/portal/tickets/new/?type=bug#form', $r->getHeader('Location'));
        self::assertStringContainsString('not accepted', self::keptErrors()['attachments'] ?? '');

        $r = $this->postFiles('/portal/tickets/', self::bug(), ['attachments' => [
            $this->fakeUpload('fine.png', self::pngBytes()),
            $this->fakeUpload('shell.png', "<?php system(\$_GET['c']); ?>"),
        ]]);
        self::assertSame('/portal/tickets/new/?type=bug#form', $r->getHeader('Location'));
        self::assertStringContainsString('does not match', self::keptErrors()['attachments'] ?? '');

        $six = [];
        for ($i = 1; $i <= 6; $i++) {
            $six[] = $this->fakeUpload("shot{$i}.png", self::pngBytes());
        }
        $this->postFiles('/portal/tickets/', self::bug(), ['attachments' => $six]);
        self::assertSame('Attach up to 5 files.', self::keptErrors()['attachments'] ?? '');

        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM tickets'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM ticket_attachments'));
        self::assertSame([], $this->storedUploads(), 'a refused upload leaves nothing behind, including the good file before it');
    }

    public function testFeatureRequestsStartPublicAndQuestionsPrivate(): void
    {
        $this->actAs(self::A);
        $feature = $this->ticket($this->open(self::feature()));
        self::assertSame(['public', null, 0], [$feature['visibility'], $feature['severity'], (int) $feature['votes']]);
        self::assertSame("### The problem it solves\n\nOur running orders live in spreadsheets and retyping them is slow.\n\n### The idea\n\nLet me pick a file and map its columns to the running order.", $feature['body']);
        $question = $this->ticket($this->open(self::question(['severity' => 'show_stopper', 'app_version' => '9'])));
        self::assertSame(['private', null, null], [$question['visibility'], $question['severity'], $question['app_version']]);
    }

    public function testTicketTextIsEscapedEverywhere(): void
    {
        $this->actAs(self::A);
        $id = $this->open(self::bug([
            'title' => '<script>alert(1)</script> crash',
            'what_happened' => "<img src=x onerror=alert(2)> and [a link](javascript:alert(3)) **bold**",
        ]));
        $page = $this->request('GET', "/portal/tickets/{$id}/")->body;
        self::assertStringNotContainsString('<script>alert(1)', $page);
        self::assertStringNotContainsString('<img src=x', $page);
        self::assertStringNotContainsString('javascript:', $page);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; crash', $page);
        self::assertStringContainsString('<strong>bold</strong>', $page);
        self::assertStringNotContainsString('<script>alert', $this->lastMailTo(self::ADMIN_INBOX)['html']);
    }

    public function testStoreIsLimitedTo20TicketsAUserADay(): void
    {
        $this->actAs(self::A);
        for ($i = 1; $i <= 20; $i++) {
            $this->open(self::question(['title' => 'Question number ' . $i]));
        }
        $r = $this->post('/portal/tickets/', self::question(['title' => 'One question too many']));
        self::assertSame('/portal/tickets/new/?type=question#form', $r->getHeader('Location'));
        self::assertSame('danger', end($_SESSION['_flash'])['type']);
        self::assertSame(20, $this->rows('SELECT COUNT(*) FROM tickets'));

        // The limit is per user: someone else can still open one.
        $this->actAs(self::B);
        $this->open(self::question());
        self::assertSame(21, $this->rows('SELECT COUNT(*) FROM tickets'));
    }

    // ---------------------------------------------------------------------------------------------------------
    // Who may see what

    public function testAnotherClientCannotReachAPrivateTicketItsFilesOrItsReplies(): void
    {
        $this->actAs(self::A);
        $id = self::idFrom($this->postFiles('/portal/tickets/', self::bug(), ['attachments' => [$this->fakeUpload('screen.png', self::pngBytes())]]));
        $this->post("/portal/tickets/{$id}/comments/", ['body' => 'Still happening after a restart.']);
        $fileId = (int) $this->db->value('SELECT id FROM ticket_attachments WHERE ticket_id = ?', [$id]);
        $before = $this->ticket($id);

        $this->actAs(self::B);
        $missing = $this->request('GET', '/portal/tickets/999999/');
        self::assertSame(404, $missing->status);
        self::assertSame(404, $this->request('GET', "/portal/tickets/{$id}/")->status);
        self::assertSame(404, $this->request('GET', "/portal/attachments/{$fileId}/")->status);
        self::assertSame(404, $this->post("/portal/tickets/{$id}/comments/", ['body' => 'Let me in']) ->status);
        self::assertSame(404, $this->postFiles("/portal/tickets/{$id}/comments/", ['body' => 'Let me in'], ['attachments' => [$this->fakeUpload('x.png', self::pngBytes())]])->status);
        self::assertSame(404, $this->post("/portal/tickets/{$id}/vote/", ['return' => '/portal/ideas/'])->status);
        self::assertStringNotContainsString('Output goes black', $this->request('GET', '/portal/tickets/')->body);
        self::assertStringNotContainsString('Output goes black', $this->request('GET', '/portal/ideas/')->body);

        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = ?', [$id]));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM ticket_attachments'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM ticket_votes'));
        self::assertSame($before, $this->ticket($id));
    }

    public function testAPublicFeatureIsReadableAndVotableByOthersAndVotesStayInStep(): void
    {
        $this->actAs(self::A);
        $id = $this->open(self::feature());

        $this->actAs(self::B);
        $page = $this->request('GET', "/portal/tickets/{$id}/");
        self::assertSame(200, $page->status);
        self::assertStringContainsString('aria-pressed="false"', $page->body);
        self::assertStringContainsString('Test C.', $page->body, 'the owner is shown by first name and initial');
        self::assertStringNotContainsString(self::A, $page->body);
        $board = $this->request('GET', '/portal/ideas/');
        self::assertSame(200, $board->status);
        self::assertStringContainsString('id="ticket-' . $id . '"', $board->body);

        $r = $this->post("/portal/tickets/{$id}/vote/", ['return' => '/portal/ideas/']);
        self::assertSame(303, $r->status);
        self::assertSame('/portal/ideas/#ticket-' . $id, $r->getHeader('Location'));
        self::assertSame(1, (int) $this->ticket($id)['votes']);
        self::assertStringContainsString('aria-pressed="true"', $this->request('GET', '/portal/ideas/')->body);

        $r = $this->post("/portal/tickets/{$id}/vote/", ['return' => "/portal/tickets/{$id}/"]);
        self::assertSame("/portal/tickets/{$id}/#vote", $r->getHeader('Location'));
        self::assertSame(0, (int) $this->ticket($id)['votes'], 'a second vote takes the first back');
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM ticket_votes'));

        $this->post("/portal/tickets/{$id}/vote/", ['return' => '/portal/ideas/']);
        $this->actAs(self::A);
        $this->post("/portal/tickets/{$id}/vote/", ['return' => '/portal/ideas/']);
        self::assertSame(2, (int) $this->ticket($id)['votes']);
        self::assertSame(2, $this->rows('SELECT COUNT(*) FROM ticket_votes WHERE ticket_id = ?', [$id]));
    }

    public function testTheVoteReturnCannotLeaveTheBoardOrTheTicket(): void
    {
        $this->actAs(self::A);
        $id = $this->open(self::feature());
        $cases = [
            'https://evil.test/' => "/portal/tickets/{$id}/#vote",
            '//evil.test/portal/ideas/' => "/portal/tickets/{$id}/#vote",
            '/portal/tickets/1/../../admin/' => "/portal/tickets/{$id}/#vote",
            '' => "/portal/tickets/{$id}/#vote",
            '/portal/ideas/?product=stagewatch&sort=newest&evil=1&page=2' => "/portal/ideas/?product=stagewatch&sort=newest&page=2#ticket-{$id}",
            '/portal/ideas/?product=photoshop&status=open' => "/portal/ideas/?status=open#ticket-{$id}",
        ];
        foreach ($cases as $return => $expected) {
            self::assertSame($expected, $this->post("/portal/tickets/{$id}/vote/", ['return' => $return])->getHeader('Location'), $return);
        }
        self::assertSame(0, (int) $this->ticket($id)['votes'], 'six toggles land back at no vote');
    }

    public function testOnlyPublicFeatureRequestsTakeVotes(): void
    {
        $this->actAs(self::A);
        $bug = $this->open(self::bug());
        self::assertSame(404, $this->post("/portal/tickets/{$bug}/vote/", ['return' => '/portal/ideas/'])->status, 'even the owner cannot vote on a bug');
        $feature = $this->open(self::feature());
        $this->db->update('tickets', ['visibility' => 'private'], ['id' => $feature]);
        self::assertSame(404, $this->post("/portal/tickets/{$feature}/vote/", [])->status);
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM ticket_votes'));
        self::assertSame(200, $this->request('GET', "/portal/tickets/{$feature}/")->status, 'the owner still sees a private feature request');
        $this->actAs(self::B);
        self::assertSame(404, $this->request('GET', "/portal/tickets/{$feature}/")->status);
        self::assertStringNotContainsString('Import a running order', $this->request('GET', '/portal/ideas/')->body);
    }

    public function testAPublicBugCanBeReadButNotRepliedToByOthers(): void
    {
        $this->actAs(self::A);
        $id = $this->open(self::bug());
        $this->db->update('tickets', ['visibility' => 'public'], ['id' => $id]);
        $this->actAs(self::B);
        $page = $this->request('GET', "/portal/tickets/{$id}/");
        self::assertSame(200, $page->status);
        self::assertStringNotContainsString('action="/portal/tickets/' . $id . '/comments/"', $page->body);
        self::assertSame(403, $this->post("/portal/tickets/{$id}/comments/", ['body' => 'Me too'])->status);
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM ticket_comments'));
    }

    // ---------------------------------------------------------------------------------------------------------
    // Replies and internal notes

    public function testRepliesKeepCountsInStepAndEmailTheOtherSide(): void
    {
        $this->actAs(self::A);
        $id = $this->open(self::bug());
        $this->db->update('tickets', ['last_activity_at' => 1000], ['id' => $id]);
        $mailsBefore = count($this->mails());

        $r = $this->post("/portal/tickets/{$id}/comments/", ['body' => 'It also happens with **one** display.', 'form' => 'comment']);
        self::assertSame(303, $r->status);
        self::assertMatchesRegularExpression('#^/portal/tickets/' . $id . '/\#comment-\d+$#', (string) $r->getHeader('Location'));
        $t = $this->ticket($id);
        self::assertSame(1, (int) $t['comment_count']);
        self::assertGreaterThan(1000, (int) $t['last_activity_at']);
        $mail = $this->lastMailTo(self::ADMIN_INBOX);
        self::assertSame('ticket-comment-admin', $mail['template']);
        self::assertTrue($mail['data']['isOwner']);
        self::assertSame($mailsBefore + 1, count($this->mails()));

        $this->actAs(self::ADMIN, 'admin');
        $this->post("/portal/tickets/{$id}/comments/", ['body' => 'Thanks, I can reproduce it.']);
        $staff = $this->db->one('SELECT * FROM ticket_comments WHERE ticket_id = ? ORDER BY id DESC', [$id]);
        self::assertSame([1, 0], [(int) $staff['is_staff'], (int) $staff['is_internal']]);
        self::assertSame(2, (int) $this->ticket($id)['comment_count']);
        $reply = $this->lastMailTo(self::A);
        self::assertNotNull($reply);
        self::assertSame('ticket-reply', $reply['template']);
        self::assertStringContainsString('Thanks, I can reproduce it.', $reply['html']);

        $this->actAs(self::A);
        $empty = $this->post("/portal/tickets/{$id}/comments/", ['body' => '   ']);
        self::assertSame("/portal/tickets/{$id}/#reply", $empty->getHeader('Location'));
        self::assertSame(['body'], array_keys(self::keptErrors()));
        self::assertSame(2, $this->rows('SELECT COUNT(*) FROM ticket_comments'));
    }

    public function testInternalNotesAndTheirFilesStayWithStaffAndAreNeverEmailed(): void
    {
        $this->actAs(self::A);
        $id = $this->open(self::bug());
        // Back-dated, so a note written in the same second would still show as a change.
        $this->db->update('tickets', ['updated_at' => 1000, 'last_activity_at' => 1000], ['id' => $id]);
        $before = $this->ticket($id);

        $this->actAs(self::ADMIN, 'admin');
        $mails = count($this->mailsTo(self::A));
        $r = $this->postFiles("/portal/tickets/{$id}/comments/", ['body' => 'Looks like the driver bug from last month.', 'internal' => '1'], ['attachments' => [$this->fakeUpload('trace.png', self::pngBytes())]]);
        self::assertSame(303, $r->status);
        $note = $this->db->one('SELECT * FROM ticket_comments WHERE ticket_id = ?', [$id]);
        self::assertSame([1, 1], [(int) $note['is_staff'], (int) $note['is_internal']]);
        $fileId = (int) $this->db->value('SELECT id FROM ticket_attachments WHERE comment_id = ?', [(int) $note['id']]);
        self::assertGreaterThan(0, $fileId);
        self::assertCount($mails, $this->mailsTo(self::A), 'no email for an internal note');
        $after = $this->ticket($id);
        self::assertSame(0, (int) $after['comment_count']);
        self::assertSame($before['last_activity_at'], $after['last_activity_at']);
        // The whole row is unchanged: updated_at reaches the owner in their data export.
        self::assertSame($before, $after);
        self::assertStringContainsString('driver bug from last month', $this->request('GET', "/portal/tickets/{$id}/")->body);
        self::assertSame(200, $this->request('GET', "/portal/attachments/{$fileId}/")->status);

        $this->actAs(self::A);
        $page = $this->request('GET', "/portal/tickets/{$id}/");
        self::assertSame(200, $page->status);
        self::assertStringNotContainsString('driver bug from last month', $page->body);
        self::assertStringNotContainsString('/portal/attachments/' . $fileId . '/', $page->body);
        self::assertStringNotContainsString('Internal note', $page->body);
        self::assertSame(404, $this->request('GET', "/portal/attachments/{$fileId}/")->status);

        // A client cannot make their own reply internal.
        $this->post("/portal/tickets/{$id}/comments/", ['body' => 'Is there news?', 'internal' => '1']);
        self::assertSame(0, (int) $this->db->value('SELECT is_internal FROM ticket_comments WHERE body = ?', ['Is there news?']));
    }

    public function testOtherClientsMayReplyToAPublicFeatureButNotAttach(): void
    {
        $this->actAs(self::A);
        $id = $this->open(self::feature());

        $this->actAs(self::B);
        $r = $this->postFiles("/portal/tickets/{$id}/comments/", ['body' => 'We need this too.'], ['attachments' => [$this->fakeUpload('x.png', self::pngBytes())]]);
        self::assertSame("/portal/tickets/{$id}/#reply", $r->getHeader('Location'));
        self::assertSame(['attachments'], array_keys(self::keptErrors()));
        self::assertSame([], $this->storedUploads());

        $this->post("/portal/tickets/{$id}/comments/", ['body' => 'We need this too.']);
        self::assertSame(1, (int) $this->ticket($id)['comment_count']);
        $mail = $this->lastMailTo(self::ADMIN_INBOX);
        self::assertSame('ticket-comment-admin', $mail['template']);
        self::assertFalse($mail['data']['isOwner']);
        self::assertNull($this->lastMailTo(self::A), 'the owner is not emailed about other clients\' replies');
    }

    public function testAttachmentsThatAreNotImagesOrPdfsDownloadAsBytes(): void
    {
        $this->actAs(self::A);
        $id = self::idFrom($this->postFiles('/portal/tickets/', self::bug(), ['attachments' => [$this->fakeUpload('patterns.log', "12:00 started\n12:01 output lost\n")]]));
        $file = $this->db->one('SELECT * FROM ticket_attachments WHERE ticket_id = ?', [$id]);
        $r = $this->request('GET', '/portal/attachments/' . $file['id'] . '/');
        self::assertSame(200, $r->status);
        self::assertSame('application/octet-stream', $r->getHeader('Content-Type'));
        self::assertStringContainsString('sandbox', (string) $r->getHeader('Content-Security-Policy'));
        self::assertSame("12:00 started\n12:01 output lost\n", $r->captureStream());
    }

    public function testReplyRateLimit(): void
    {
        $this->actAs(self::A);
        $id = $this->open(self::question());
        for ($i = 1; $i <= 60; $i++) {
            self::assertStringContainsString('#comment-', (string) $this->post("/portal/tickets/{$id}/comments/", ['body' => 'Reply ' . $i])->getHeader('Location'));
        }
        self::assertSame("/portal/tickets/{$id}/#reply", $this->post("/portal/tickets/{$id}/comments/", ['body' => 'Reply 61'])->getHeader('Location'));
        self::assertSame(60, (int) $this->ticket($id)['comment_count']);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Lists

    public function testMyTicketsTabsAndStatusFilter(): void
    {
        $this->actAs(self::A);
        $bug = $this->open(self::bug());
        $this->open(self::question());
        $this->db->update('tickets', ['status' => 'planned'], ['id' => $bug]);

        $all = $this->request('GET', '/portal/tickets/?status=nonsense');
        self::assertSame(200, $all->status);
        self::assertStringContainsString('Output goes black', $all->body);
        self::assertStringContainsString('Can one licence cover', $all->body);
        self::assertStringContainsString('<caption', $all->body);

        $bugs = $this->request('GET', '/portal/tickets/?type=bug')->body;
        self::assertStringContainsString('Output goes black', $bugs);
        self::assertStringNotContainsString('Can one licence cover', $bugs);
        self::assertStringContainsString('href="/portal/tickets/?type=bug" aria-current="page"', $bugs);

        $open = $this->request('GET', '/portal/tickets/?status=open')->body;
        self::assertStringNotContainsString('Output goes black', $open);
        self::assertStringContainsString('Can one licence cover', $open);

        $this->actAs(self::B);
        self::assertStringContainsString('You have not opened any tickets yet.', $this->request('GET', '/portal/tickets/')->body);
    }

    public function testTheBoardFiltersSearchesWithEscapedWildcardsAndSorts(): void
    {
        $this->actAs(self::A);
        $fade = $this->open(self::feature(['title' => 'Fade 50% faster', 'product' => 'patterns']));
        $this->open(self::feature(['title' => 'Fade 500 times', 'product' => 'patterns']));
        $cue = $this->open(self::feature(['title' => 'Cue stack colours', 'product' => 'stagewatch']));
        $this->open(self::bug(['title' => 'Fade bug that is private']));
        $this->db->update('tickets', ['votes' => 9], ['id' => $cue]);
        $this->db->update('tickets', ['status' => 'planned'], ['id' => $fade]);

        $this->actAs(self::B);
        $all = $this->request('GET', '/portal/ideas/')->body;
        self::assertStringNotContainsString('Fade bug that is private', $all);
        self::assertLessThan(strpos($all, 'Fade 50% faster'), strpos($all, 'Cue stack colours'), 'most votes first');

        $search = $this->request('GET', '/portal/ideas/?q=' . rawurlencode('50%'))->body;
        self::assertStringContainsString('Fade 50% faster', $search);
        self::assertStringNotContainsString('Fade 500 times', $search, '% in a search is a literal');

        $product = $this->request('GET', '/portal/ideas/?product=stagewatch')->body;
        self::assertStringContainsString('Cue stack colours', $product);
        self::assertStringNotContainsString('Fade 50', $product);

        $status = $this->request('GET', '/portal/ideas/?status=planned')->body;
        self::assertStringContainsString('Fade 50% faster', $status);
        self::assertStringNotContainsString('Cue stack colours', $status);

        $newest = $this->request('GET', '/portal/ideas/?sort=newest')->body;
        self::assertLessThan(strpos($newest, 'Fade 50% faster'), strpos($newest, 'Cue stack colours'));

        $odd = $this->request('GET', '/portal/ideas/?sort=drop&status=nope&product=../x');
        self::assertSame(200, $odd->status);
        self::assertStringContainsString('Fade 500 times', $odd->body);
        self::assertStringContainsString('No feature requests match', $this->request('GET', '/portal/ideas/?q=nothing+like+this')->body);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Administration

    public function testTheAdminListFiltersEveryTicket(): void
    {
        $this->actAs(self::A);
        $bug = $this->open(self::bug());
        $this->open(self::feature());
        $this->actAs(self::B);
        $this->open(self::question());

        $this->actAs(self::ADMIN, 'admin');
        $all = $this->request('GET', '/portal/admin/tickets/');
        self::assertSame(200, $all->status);
        foreach (['Output goes black', 'Import a running order', 'Can one licence cover', self::B] as $text) {
            self::assertStringContainsString($text, $all->body);
        }
        $bugs = $this->request('GET', '/portal/admin/tickets/?type=bug')->body;
        self::assertStringContainsString('Output goes black', $bugs);
        self::assertStringNotContainsString('Import a running order', $bugs);
        self::assertStringNotContainsString('Can one licence cover', $this->request('GET', '/portal/admin/tickets/?visibility=private&q=' . rawurlencode(self::A))->body);
        $byNumber = $this->request('GET', '/portal/admin/tickets/?q=%23' . $bug)->body;
        self::assertStringContainsString('Output goes black', $byNumber);
        self::assertStringNotContainsString('Import a running order', $byNumber);
        self::assertSame(200, $this->request('GET', '/portal/admin/tickets/?status=active&sort=nonsense&type=evil')->status);
        self::assertStringContainsString('name="status"', $this->request('GET', "/portal/tickets/{$bug}/")->body, 'staff see the manage form on the ticket page');
    }

    public function testAStatusChangeEmailsTheOwnerAndIsAudited(): void
    {
        $a = $this->actAs(self::A);
        $id = $this->open(self::bug());
        $admin = $this->actAs(self::ADMIN, 'admin');

        $r = $this->post("/portal/admin/tickets/{$id}/", ['form' => 'manage', 'status' => 'in_progress', 'visibility' => 'private', 'severity' => 'show_stopper', 'reply' => 'Found it. A fix is being tested.']);
        self::assertSame(303, $r->status);
        self::assertMatchesRegularExpression('#^/portal/tickets/' . $id . '/\#comment-\d+$#', (string) $r->getHeader('Location'));
        $t = $this->ticket($id);
        self::assertSame(['in_progress', 'show_stopper', 1, null], [$t['status'], $t['severity'], (int) $t['comment_count'], $t['closed_at']]);

        $mail = $this->lastMailTo(self::A);
        self::assertSame('ticket-status', $mail['template']);
        self::assertSame('In progress', $mail['data']['newLabel']);
        self::assertSame('Open', $mail['data']['oldLabel']);
        self::assertStringContainsString('Found it. A fix is being tested.', $mail['html']);
        self::assertCount(1, $this->mailsTo(self::A), 'one email for a status change with a reply');

        $audit = $this->db->one("SELECT * FROM audit_log WHERE action = 'ticket.updated' AND subject = ?", [(string) $id]);
        self::assertSame($admin, (int) $audit['user_id']);
        $meta = json_decode((string) $audit['meta'], true);
        self::assertSame(['open', 'in_progress'], $meta['status']);
        self::assertSame(['high', 'show_stopper'], $meta['severity']);

        $this->post("/portal/admin/tickets/{$id}/", ['status' => 'done', 'visibility' => 'private', 'severity' => 'show_stopper']);
        self::assertGreaterThan(0, (int) $this->ticket($id)['closed_at']);
        self::assertSame('Done', $this->lastMailTo(self::A)['data']['newLabel']);
        $this->post("/portal/admin/tickets/{$id}/", ['status' => 'open', 'visibility' => 'private', 'severity' => 'show_stopper']);
        self::assertNull($this->ticket($id)['closed_at']);
        self::assertCount(3, $this->mailsTo(self::A));
        self::assertSame($a, (int) $this->ticket($id)['user_id']);
    }

    public function testAnInternalNoteWithTheUpdateIsNotEmailed(): void
    {
        $this->actAs(self::A);
        $id = $this->open(self::bug());
        $this->actAs(self::ADMIN, 'admin');

        $r = $this->post("/portal/admin/tickets/{$id}/", ['status' => 'open', 'visibility' => 'private', 'severity' => 'high', 'reply' => 'Check the GPU driver first.', 'internal' => '1']);
        self::assertSame(303, $r->status);
        self::assertSame([], $this->mailsTo(self::A));
        self::assertSame(1, (int) $this->db->value('SELECT is_internal FROM ticket_comments WHERE ticket_id = ?', [$id]));
        self::assertSame(0, (int) $this->ticket($id)['comment_count']);

        // A status change still tells the owner, but never carries the internal note.
        $this->post("/portal/admin/tickets/{$id}/", ['status' => 'triaged', 'visibility' => 'private', 'severity' => 'high', 'reply' => 'Secret: probably our fault.', 'internal' => '1']);
        $mail = $this->lastMailTo(self::A);
        self::assertSame('ticket-status', $mail['template']);
        self::assertSame('', $mail['data']['reply']);
        self::assertStringNotContainsString('Secret', $mail['html']);

        $this->actAs(self::A);
        self::assertStringNotContainsString('Secret', $this->request('GET', "/portal/tickets/{$id}/")->body);
    }

    public function testAReplyWithoutAStatusChangeSendsTheReplyEmail(): void
    {
        $this->actAs(self::A);
        $id = $this->open(self::question());
        $this->actAs(self::ADMIN, 'admin');
        $this->post("/portal/admin/tickets/{$id}/", ['status' => 'open', 'visibility' => 'private', 'reply' => 'Production covers a main and a backup PC.']);
        $mails = $this->mailsTo(self::A);
        self::assertCount(1, $mails);
        self::assertSame('ticket-reply', $mails[0]['template']);
        self::assertSame(1, (int) $this->ticket($id)['comment_count']);
    }

    public function testAdminUpdateRefusesUnknownValuesAndEmptyChanges(): void
    {
        $this->actAs(self::A);
        $id = $this->open(self::bug());
        $this->actAs(self::ADMIN, 'admin');
        $before = $this->ticket($id);

        $r = $this->post("/portal/admin/tickets/{$id}/", ['form' => 'manage', 'status' => 'bogus', 'visibility' => 'everyone', 'severity' => 'meh']);
        self::assertSame("/portal/tickets/{$id}/#manage", $r->getHeader('Location'));
        self::assertEqualsCanonicalizing(['status', 'visibility', 'severity'], array_keys(self::keptErrors()));
        $form = $this->request('GET', "/portal/tickets/{$id}/");
        self::assertStringContainsString('id="manage-status-error"', $form->body);

        $r = $this->post("/portal/admin/tickets/{$id}/", ['status' => 'open', 'visibility' => 'private', 'severity' => 'high']);
        self::assertSame("/portal/tickets/{$id}/#manage", $r->getHeader('Location'));
        self::assertSame('info', end($_SESSION['_flash'])['type']);
        self::assertSame($before, $this->ticket($id));
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM audit_log WHERE action = 'ticket.updated'"));
        self::assertSame(404, $this->post('/portal/admin/tickets/999999/', ['status' => 'open', 'visibility' => 'private'])->status);
        self::assertSame([], $this->mailsTo(self::A));
    }

    public function testMakingABugPublicOpensItToOtherClients(): void
    {
        $this->actAs(self::A);
        $id = $this->open(self::bug());
        $this->actAs(self::ADMIN, 'admin');
        $this->post("/portal/admin/tickets/{$id}/", ['status' => 'open', 'visibility' => 'public', 'severity' => 'high']);
        self::assertSame('public', $this->ticket($id)['visibility']);
        self::assertSame([], $this->mailsTo(self::A), 'a visibility change alone sends no email');

        $this->actAs(self::B);
        self::assertSame(200, $this->request('GET', "/portal/tickets/{$id}/")->status);
        self::assertStringNotContainsString('Output goes black', $this->request('GET', '/portal/ideas/')->body, 'the board lists feature requests only');
    }

    // ---------------------------------------------------------------------------------------------------------
    // Review: regressions for defects found by trying to break the unit

    public function testANameTypedAsAnEmailAddressIsNeverShownToOtherClients(): void
    {
        $a = $this->actAs(self::A);
        $this->db->update('users', ['name' => 'jane.doe@corp.test'], ['id' => $a]);
        $id = $this->open(self::feature());
        $commenter = $this->actAs(self::B);
        $this->db->update('users', ['name' => 'Ada (ada@corp.test) Lovelace'], ['id' => $commenter]);
        $this->post("/portal/tickets/{$id}/comments/", ['body' => 'We would use this every week.']);

        $this->actAs('third@example.test');
        $page = $this->request('GET', "/portal/tickets/{$id}/");
        self::assertSame(200, $page->status);
        self::assertStringNotContainsString('corp.test', $page->body, 'no part of an email address reaches another client');
        self::assertStringContainsString('<bdi>A client</bdi>', $page->body, 'a name that is only an address becomes "A client"');
        self::assertStringContainsString('<bdi>Ada L.</bdi>', $page->body);
        self::assertSame('Ada L.', \Psc\Controllers\Portal\TicketController::shortName('Ada (ada@corp.test) Lovelace'));
        self::assertSame('A client', \Psc\Controllers\Portal\TicketController::shortName('jane.doe@corp.test'));

        // Staff still see the full name, which is theirs to see.
        $this->actAs(self::ADMIN, 'admin');
        self::assertStringContainsString('jane.doe@corp.test', $this->request('GET', "/portal/tickets/{$id}/")->body);
    }

    public function testADeletedAccountShowsAsAFormerClient(): void
    {
        $a = $this->actAs(self::A);
        $id = $this->open(self::feature());
        $this->post("/portal/tickets/{$id}/comments/", ['body' => 'One more thought on this idea.']);
        // What deleting an account does (AccountController::delete): the row stays, anonymised.
        $this->db->update('users', ['name' => 'Deleted user', 'status' => 'disabled', 'email' => 'deleted-' . $a . '@deleted.invalid'], ['id' => $a]);

        $this->actAs(self::B);
        $page = $this->request('GET', "/portal/tickets/{$id}/")->body;
        self::assertStringNotContainsString('Deleted U.', $page);
        self::assertSame(2, substr_count($page, 'A former client'), 'as the owner and as the comment author');
    }

    public function testInvisibleAndReorderingCharactersLeaveTitlesAndFileNames(): void
    {
        $this->actAs(self::A);
        $rlo = "\u{202E}";
        $id = self::idFrom($this->postFiles('/portal/tickets/', self::bug(['title' => "Output {$rlo}kcalb\u{200B} goes", 'app_version' => "2.4{$rlo}.1"]), [
            'attachments' => [$this->fakeUpload("photo{$rlo}gnp.zip", "PK\x05\x06" . str_repeat("\0", 18))],
        ]));
        $t = $this->ticket($id);
        self::assertSame('Output kcalb goes', $t['title']);
        self::assertSame('2.4.1', $t['app_version']);
        $file = $this->db->one('SELECT * FROM ticket_attachments WHERE ticket_id = ?', [$id]);
        self::assertSame('photognp.zip', $file['original_name'], 'a zip cannot be dressed up to read as a PNG');
        $download = $this->request('GET', '/portal/attachments/' . $file['id'] . '/');
        self::assertStringNotContainsString('%E2%80%AE', (string) $download->getHeader('Content-Disposition'));

        // A title made only of invisible characters is empty, not five characters long.
        $r = $this->post('/portal/tickets/', self::question(['title' => str_repeat("\u{200B}", 6)]));
        self::assertSame('/portal/tickets/new/?type=question#form', $r->getHeader('Location'));
        self::assertSame(['title'], array_keys(self::keptErrors()));
    }

    public function testNestedFileFieldsAreLeftOutQuietly(): void
    {
        $this->actAs(self::A);
        $tmp = (string) tempnam(sys_get_temp_dir(), 'psc-up');
        file_put_contents($tmp, self::pngBytes());
        // attachments[a][] — no form of ours sends this shape; reading it as strings raised PHP warnings.
        $files = ['attachments' => ['name' => [['a.png']], 'type' => [['image/png']], 'tmp_name' => [[$tmp]], 'error' => [[UPLOAD_ERR_OK]], 'size' => [[67]]]];
        $r = $this->request('POST', '/portal/tickets/', self::bug() + ['_csrf' => $this->csrf()], [], null, true, $files);
        $id = self::idFrom($r);
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM ticket_attachments WHERE ticket_id = ?', [$id]));
        self::assertSame([], $this->storedUploads());
        @unlink($tmp);
    }

    public function testVotesAreLimitedPerHour(): void
    {
        $this->actAs(self::A);
        $id = $this->open(self::feature());
        $this->actAs(self::B);
        for ($i = 1; $i <= 100; $i++) {
            $this->post("/portal/tickets/{$id}/vote/", ['return' => '/portal/ideas/']);
        }
        self::assertSame(0, (int) $this->ticket($id)['votes'], 'an even number of toggles');
        $r = $this->post("/portal/tickets/{$id}/vote/", ['return' => '/portal/ideas/']);
        self::assertSame(303, $r->status);
        self::assertSame('/portal/ideas/#ticket-' . $id, $r->getHeader('Location'));
        self::assertSame('warning', end($_SESSION['_flash'])['type']);
        self::assertSame(0, (int) $this->ticket($id)['votes'], 'the 101st toggle in an hour changes nothing');
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM ticket_votes'));

        // The limit is per user.
        $this->actAs(self::A);
        $this->post("/portal/tickets/{$id}/vote/", ['return' => '/portal/ideas/']);
        self::assertSame(1, (int) $this->ticket($id)['votes']);
    }

    public function testAVoteThatFailsToSaveIsNotReportedAsAVote(): void
    {
        $this->actAs(self::A);
        $id = $this->open(self::feature());
        // A failure that is not a duplicate key: a trigger that reads a table that does not exist.
        $this->db->exec('CREATE TRIGGER ticket_votes_fail BEFORE INSERT ON ticket_votes BEGIN SELECT * FROM no_such_table; END');
        $this->actAs(self::B);
        $_SESSION['_flash'] = [];
        $r = $this->post("/portal/tickets/{$id}/vote/", ['return' => '/portal/ideas/']);
        self::assertSame(500, $r->status);
        self::assertNotContains('success', array_column($_SESSION['_flash'] ?? [], 'type'), 'no "your vote is in" for a vote that was not saved');
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM ticket_votes'));
        self::assertSame(0, (int) $this->ticket($id)['votes']);

        // A duplicate key is still the race it stands for: the vote lands on "voted".
        $this->db->exec('DROP TRIGGER ticket_votes_fail');
        $b = (int) $this->db->value('SELECT id FROM users WHERE email = ?', [self::B]);
        $this->db->exec('CREATE TRIGGER ticket_votes_race BEFORE DELETE ON ticket_votes BEGIN SELECT RAISE(IGNORE); END');
        $this->db->insert('ticket_votes', ['ticket_id' => $id, 'user_id' => $b, 'created_at' => time()]);
        $r = $this->post("/portal/tickets/{$id}/vote/", ['return' => '/portal/ideas/']);
        self::assertSame(303, $r->status);
        self::assertSame('success', end($_SESSION['_flash'])['type']);
        self::assertSame(1, (int) $this->ticket($id)['votes']);
    }

    public function testStatusHelpPromisesNothingTheStatusDoesNot(): void
    {
        $this->actAs(self::A);
        $id = $this->open(self::question());
        $this->actAs(self::ADMIN, 'admin');
        $this->post("/portal/admin/tickets/{$id}/", ['status' => 'done', 'visibility' => 'private']);
        $mail = $this->lastMailTo(self::A);
        self::assertSame('ticket-status', $mail['template']);
        self::assertSame(\Psc\Controllers\Portal\TicketController::STATUS_HELP['done'], $mail['data']['statusHelp']);
        self::assertStringNotContainsString('Downloads', $mail['html'], 'an answered question has no new version to download');
        foreach (\Psc\Controllers\Portal\TicketController::STATUS_HELP as $help) {
            self::assertStringNotContainsString('Downloads', $help);
            self::assertStringNotContainsString('says why', $help);
        }
    }

    public function testAdminSearchByABareNumberAlsoFindsText(): void
    {
        $this->actAs(self::A);
        $this->open(self::feature(['title' => 'Support 2024 timecode']));
        $this->actAs(self::ADMIN, 'admin');
        self::assertStringContainsString('Support 2024 timecode', $this->request('GET', '/portal/admin/tickets/?q=2024')->body);
        self::assertStringNotContainsString('Support 2024 timecode', $this->request('GET', '/portal/admin/tickets/?q=%232024')->body, '#2024 is that ticket only');
    }

    public function testHeadingsWrittenInTicketsNestUnderThePageSections(): void
    {
        $this->actAs(self::A);
        $id = $this->open(self::feature(['problem' => "## My own big heading\n\nSome words about the problem.", 'idea' => "#### A deep heading\n\nThe idea itself, described."]));
        $this->post("/portal/tickets/{$id}/comments/", ['body' => "## Reply heading\n\nThe reply text."]);
        $page = $this->request('GET', "/portal/tickets/{$id}/")->body;
        foreach (['My own big heading', 'A deep heading', 'Reply heading'] as $text) {
            self::assertStringContainsString('<h3>' . $text . '</h3>', $page);
        }
        self::assertSame(0, preg_match('#<h[24]>#', $page), 'every h2 on the page is a pt-h2 section heading');
    }

    public function testPublicTicketsSayThatTheirRepliesAndFilesAreSeenByOthers(): void
    {
        $this->actAs(self::A);
        $feature = $this->open(self::feature());
        $bug = $this->open(self::bug());
        self::assertStringContainsString('other clients can read your reply and open any files you attach', $this->request('GET', "/portal/tickets/{$feature}/")->body);
        self::assertStringNotContainsString('other clients can read your reply', $this->request('GET', "/portal/tickets/{$bug}/")->body);

        $this->actAs(self::B);
        $other = $this->request('GET', "/portal/tickets/{$feature}/")->body;
        self::assertStringContainsString('other clients can read your reply.', $other, 'no mention of files to someone who cannot attach any');

        $this->actAs(self::ADMIN, 'admin');
        self::assertStringContainsString('every signed-in client can read the ticket, its replies and its files', $this->request('GET', "/portal/tickets/{$bug}/")->body);

        // The forms promise privacy only as far as it goes: staff can make a bug or a question public.
        foreach (['/portal/tickets/new/', '/portal/tickets/new/?type=bug', '/portal/tickets/new/?type=question'] as $url) {
            self::assertStringContainsString('unless the team makes it public', $this->request('GET', $url)->body, $url);
        }
    }
}
