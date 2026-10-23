<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\News\Digest;

use Psc\Db\Database;
use Psc\News\Digest\Subscribers;
use Psc\News\Digest\Tokens;
use Psc\Tests\TestCase;

/**
 * The digest's consent and suppression records (docs/NEWS.md §14.2, §14.3 Subscribers): what each sign-up, confirm
 * and unsubscribe changes and keeps, the account's tick verified and not (a request either way, which only its
 * button confirms), a request that is never confirmed leaving the record of consent as it was, the account deleted
 * or its address changed, the copy of their data, the bounces, the desk's counts and Housekeeping's rule. Every
 * answer is read from the rows.
 */
final class SubscribersTest extends TestCase
{
    private const DAY = 86400;
    private const NOW = 1_800_000_000;

    private function subscribers(): Subscribers
    {
        return new Subscribers($this->app);
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $row = $this->subscribers()->find($id);
        self::assertNotNull($row);
        return $row;
    }

    /** A confirmed sign-up from the page; its id. */
    private function confirmed(string $email, int $at = self::NOW): int
    {
        $s = $this->subscribers();
        $id = (int) $s->request($email, '2026-10-23', '2026-10-23', $at)['subscriber']['id'];
        self::assertSame('confirmed', $s->confirm((string) $this->row($id)['selector'], 0, $at));
        return $id;
    }

    /** An account's tick confirmed by the button its confirmation leads to; the row's id. */
    private function subscribedAccount(array $user, int $at = self::NOW): int
    {
        $s = $this->subscribers();
        self::assertSame('pending', $s->subscribeAccount($user, 'account', 'c-1', 'p-1', $at));
        $row = $s->forAccount($user);
        self::assertNotNull($row);
        self::assertSame('confirmed', $s->confirm((string) $row['selector'], (int) $row['generation'], $at));
        return (int) $row['id'];
    }

    /** @return array<string, mixed> what a row says of the consent and its withdrawal */
    private function record(int $id): array
    {
        $row = $this->row($id);
        return array_intersect_key($row, array_flip(['source', 'consent_version', 'privacy_version', 'requested_at', 'confirmed_at', 'unsubscribed_at', 'unsubscribe_method']));
    }

    /** @return array<string, mixed> a users row */
    private function user(string $email, bool $verified = true): array
    {
        $id = $this->makeUser($email, 'correct horse battery', 'client', $verified ? 'active' : 'pending');
        $row = $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
        self::assertNotNull($row);
        return $row;
    }

    public function testASignUpFromThePageHasFourOutcomes(): void
    {
        $s = $this->subscribers();
        $new = $s->request('  Reader@Example.TEST ', 'c-1', 'p-1', self::NOW);
        self::assertSame('new', $new['outcome']);
        $row = $new['subscriber'];
        self::assertNotNull($row);
        self::assertSame(['reader@example.test', null, 'pending', 'page', 'c-1', 'p-1', self::NOW, null], [$row['email'], $row['user_id'], $row['state'], $row['source'], $row['consent_version'], $row['privacy_version'], $row['requested_at'], $row['confirmed_at']]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{24}$/', $row['selector']);
        self::assertNotSame(Tokens::TEST_SELECTOR, $row['selector']);
        self::assertSame(Tokens::fromApp($this->app)->emailHash('reader@example.test'), $row['email_hash']);
        self::assertNotSame(hash('sha256', 'reader@example.test'), $row['email_hash'], 'a keyed hash, never a plain one');

        $again = $s->request('reader@example.test', 'c-2', 'p-2', self::NOW + 3 * self::DAY);
        self::assertSame('again', $again['outcome']);
        self::assertSame([self::NOW + 3 * self::DAY, 'c-2', 'p-2', $row['selector'], $row['id']], [$again['subscriber']['requested_at'], $again['subscriber']['consent_version'], $again['subscriber']['privacy_version'], $again['subscriber']['selector'], $again['subscriber']['id']]);

        self::assertSame('confirmed', $s->confirm($row['selector'], 0, self::NOW + 3 * self::DAY));
        $before = $this->row($row['id']);
        $confirmed = $s->request('READER@example.test', 'c-3', 'p-3', self::NOW + 4 * self::DAY);
        self::assertSame('confirmed', $confirmed['outcome']);
        self::assertSame($before, $this->row($row['id']), 'nothing changes for an address that gets the digest');

        self::assertSame('done', $s->unsubscribe($row['selector'], 'page', self::NOW + 5 * self::DAY));
        $returning = $s->request('reader@example.test', 'c-4', 'p-4', self::NOW + 6 * self::DAY);
        self::assertSame('returning', $returning['outcome']);
        $back = $returning['subscriber'];
        self::assertSame(['reader@example.test', 'pending', 'page', 'c-4', 'p-4', self::NOW + 6 * self::DAY, self::NOW + 5 * self::DAY, 'page', $row['selector']], [$back['email'], $back['state'], $back['source'], $back['consent_version'], $back['privacy_version'], $back['requested_at'], $back['unsubscribed_at'], $back['unsubscribe_method'], $back['selector']]);
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM news_digest_subscribers'), 'one row per address, ever');

        $this->expectException(\InvalidArgumentException::class);
        $s->request('not an address', 'c', 'p', self::NOW);
    }

    /** Asked twice in the same second, a row already holds the values: still again (MySQL counts it unchanged). */
    public function testTheSameRequestTwiceInASecondIsAgain(): void
    {
        $s = $this->subscribers();
        self::assertSame('new', $s->request('twice@example.test', 'c', 'p', self::NOW)['outcome']);
        self::assertSame('again', $s->request('twice@example.test', 'c', 'p', self::NOW)['outcome']);
        self::assertSame('again', $s->request('twice@example.test', 'c', 'p', self::NOW)['outcome']);
        $user = $this->user('pending@example.test', false);
        self::assertSame('pending', $s->subscribeAccount($user, 'registration', 'c', 'p', self::NOW));
        self::assertSame('pending', $s->subscribeAccount($user, 'registration', 'c', 'p', self::NOW));
    }

    public function testConfirmingHasFiveAnswersAndALinkWorksForSevenDaysToTheSecond(): void
    {
        $s = $this->subscribers();
        $a = $s->request('a@example.test', 'c', 'p', self::NOW)['subscriber'];
        $b = $s->request('b@example.test', 'c', 'p', self::NOW)['subscriber'];
        self::assertSame('expired', $s->confirm($a['selector'], 0, self::NOW + Tokens::CONFIRM_SECONDS));
        self::assertSame('pending', $this->row($a['id'])['state']);
        self::assertSame('confirmed', $s->confirm($b['selector'], 0, self::NOW + Tokens::CONFIRM_SECONDS - 1));
        $row = $this->row($b['id']);
        self::assertSame(['confirmed', self::NOW + Tokens::CONFIRM_SECONDS - 1], [$row['state'], $row['confirmed_at']]);
        self::assertSame('already', $s->confirm($b['selector'], 0, self::NOW + 10));
        self::assertSame('done', $s->unsubscribe($b['selector'], 'one_click', self::NOW + 20));
        self::assertSame('gone', $s->confirm($b['selector'], 0, self::NOW + 30), 'unsubscribed since the link was sent');
        self::assertSame('missing', $s->confirm(str_repeat('f', 24), 0, self::NOW));
        self::assertSame('missing', $s->confirm('not-a-selector', 0, self::NOW));

        // Asking again restarts the 7 days for a link of the new generation only (the old link is gone for good), and
        // confirming a returning row clears how it last ended and its bounces.
        $s->request('b@example.test', 'c', 'p', self::NOW + self::DAY);
        self::assertSame(1, $this->row($b['id'])['generation'], 'asked again after unsubscribing: the generation moved on');
        self::assertSame('gone', $s->confirm($b['selector'], 0, self::NOW + self::DAY + 30), 'a link from before the withdrawal never confirms the new request');
        self::assertSame('pending', $this->row($b['id'])['state']);
        $this->db->exec('UPDATE news_digest_subscribers SET bounces = 1, bounced_at = ? WHERE id = ?', [self::NOW, $b['id']]);
        self::assertSame('confirmed', $s->confirm($b['selector'], 1, self::NOW + self::DAY + 60));
        $row = $this->row($b['id']);
        self::assertSame([null, null, 0, null, self::NOW + self::DAY + 60], [$row['unsubscribed_at'], $row['unsubscribe_method'], $row['bounces'], $row['bounced_at'], $row['confirmed_at']]);
        self::assertSame('expired', $s->confirm($a['selector'], 0, self::NOW + 30 * self::DAY));
    }

    public function testUnsubscribingKeepsTheRecordAndNothingThatCanBeMailed(): void
    {
        $s = $this->subscribers();
        $user = $this->user('member@example.test');
        $this->subscribedAccount($user);
        $before = $s->forAccount($user);
        self::assertNotNull($before);
        $this->db->exec('UPDATE news_digest_subscribers SET bounces = 1, bounced_at = ? WHERE id = ?', [self::NOW + 10, $before['id']]);

        self::assertSame('done', $s->unsubscribe($before['selector'], 'one_click', self::NOW + 100));
        $after = $this->row($before['id']);
        self::assertSame([null, null, 0, null, 'unsubscribed', self::NOW + 100, 'one_click'], [$after['email'], $after['user_id'], $after['bounces'], $after['bounced_at'], $after['state'], $after['unsubscribed_at'], $after['unsubscribe_method']]);
        foreach (['id', 'email_hash', 'selector', 'source', 'consent_version', 'privacy_version', 'requested_at', 'confirmed_at', 'created_at'] as $kept) {
            self::assertSame($before[$kept], $after[$kept], $kept . ' is kept');
        }
        self::assertSame('already', $s->unsubscribe($before['selector'], 'page', self::NOW + 200), 'idempotent');
        self::assertSame(self::NOW + 100, $this->row($before['id'])['unsubscribed_at'], 'the first withdrawal stands');
        self::assertSame('missing', $s->unsubscribe(str_repeat('e', 24), 'page', self::NOW));
        self::assertSame('test', $s->unsubscribe(Tokens::TEST_SELECTOR, 'one_click', self::NOW));
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM news_digest_subscribers'), 'the test selector changes nothing');
        self::assertSame('already', $s->unsubscribeEmail('MEMBER@example.test', 'mailto', self::NOW), 'found by the address\'s hash');
        self::assertSame('missing', $s->unsubscribeEmail('nobody@example.test', 'mailto', self::NOW));

        $this->expectException(\InvalidArgumentException::class);
        $s->unsubscribe($before['selector'], 'unsubscribe', self::NOW);
    }

    /**
     * The account page's tick is a request, as the public page's is: a verified address is no proof that its mailbox
     * asked, so only the button the digest's own confirmation leads to confirms it.
     */
    public function testAnAccountTickIsARequestThatOnlyItsButtonConfirms(): void
    {
        $s = $this->subscribers();
        // New.
        $one = $this->user('one@example.test');
        self::assertSame('pending', $s->subscribeAccount($one, 'account', 'c-1', 'p-1', self::NOW));
        $row = $s->forAccount($one);
        self::assertNotNull($row);
        self::assertSame(['one@example.test', (int) $one['id'], 'pending', 'account', 'c-1', 'p-1', self::NOW, null], [$row['email'], $row['user_id'], $row['state'], $row['source'], $row['consent_version'], $row['privacy_version'], $row['requested_at'], $row['confirmed_at']]);
        self::assertFalse($s->isSubscribed($one), 'a tick is not consent');
        self::assertSame('pending', $s->subscribeAccount($one, 'account', 'c-2', 'p-2', self::NOW + 60), 'ticked again: asked again');
        self::assertSame([self::NOW + 60, 'c-2', 0], [$this->row($row['id'])['requested_at'], $this->row($row['id'])['consent_version'], $this->row($row['id'])['generation']]);
        self::assertSame('confirmed', $s->confirm((string) $row['selector'], 0, self::NOW + 120));
        self::assertSame(['confirmed', 'account', 'c-2', self::NOW + 120], [$this->row($row['id'])['state'], $this->row($row['id'])['source'], $this->row($row['id'])['consent_version'], $this->row($row['id'])['confirmed_at']]);
        self::assertSame('already', $s->subscribeAccount($one, 'account', 'c-3', 'p-3', self::NOW + 180));
        self::assertSame('c-2', $this->row($row['id'])['consent_version'], 'an existing consent is not rewritten');

        // A confirmed page row at the account's address is linked to the account, its consent unchanged.
        $page = $this->confirmed('two@example.test', self::NOW - self::DAY);
        $two = $this->user('two@example.test');
        self::assertTrue($s->isSubscribed($two), 'the page subscription at the account\'s address counts');
        self::assertSame('already', $s->subscribeAccount($two, 'account', 'c-2', 'p-2', self::NOW));
        self::assertSame([(int) $two['id'], 'page', self::NOW - self::DAY], [$this->row($page)['user_id'], $this->row($page)['source'], $this->row($page)['confirmed_at']]);

        // A pending page row at a verified account's address becomes the account's request, waiting for its button.
        $pending = (int) $s->request('three@example.test', 'c-0', 'p-0', self::NOW - 60)['subscriber']['id'];
        $three = $this->user('three@example.test');
        self::assertSame('pending', $s->subscribeAccount($three, 'account', 'c-3', 'p-3', self::NOW));
        self::assertSame(['pending', (int) $three['id'], 'account', 'c-3', self::NOW, null], [$this->row($pending)['state'], $this->row($pending)['user_id'], $this->row($pending)['source'], $this->row($pending)['consent_version'], $this->row($pending)['requested_at'], $this->row($pending)['confirmed_at']]);
        self::assertSame('confirmed', $s->confirm((string) $this->row($pending)['selector'], 0, self::NOW + 10));

        // An unsubscribed record of the address asks again, its generation moved on; its button gives the new consent.
        self::assertSame('done', $s->unsubscribeAccount($three, self::NOW + 20));
        self::assertFalse($s->isSubscribed($three));
        self::assertSame('pending', $s->subscribeAccount($three, 'account', 'c-4', 'p-4', self::NOW + 30));
        self::assertSame(['pending', 1, self::NOW + 20, 'account'], [$this->row($pending)['state'], $this->row($pending)['generation'], $this->row($pending)['unsubscribed_at'], $this->row($pending)['unsubscribe_method']]);
        self::assertSame('gone', $s->confirm((string) $this->row($pending)['selector'], 0, self::NOW + 40), 'a link from before the withdrawal');
        self::assertSame('confirmed', $s->confirm((string) $this->row($pending)['selector'], 1, self::NOW + 40));
        self::assertSame(['confirmed', null, null, 'c-4', self::NOW + 40], [$this->row($pending)['state'], $this->row($pending)['unsubscribed_at'], $this->row($pending)['unsubscribe_method'], $this->row($pending)['consent_version'], $this->row($pending)['confirmed_at']]);
        self::assertSame(3, (int) $this->db->value('SELECT COUNT(*) FROM news_digest_subscribers'));

        $this->expectException(\InvalidArgumentException::class);
        $s->subscribeAccount($one, 'page', 'c', 'p', self::NOW);
    }

    /**
     * A request costs nothing and proves nothing, so one that is never confirmed (it lapses, it is withdrawn, or its
     * account is deleted) leaves the suppression record saying where, when and with which words consent was given and
     * when it was withdrawn, whoever asked: the public page, a stranger's unproven registration or the account page.
     */
    public function testARequestNeverConfirmedLeavesTheRecordOfConsentAsItWas(): void
    {
        $s = $this->subscribers();
        $id = (int) $s->request('kept@example.test', 'c-old', 'p-old', self::NOW)['subscriber']['id'];
        self::assertSame('confirmed', $s->confirm((string) $this->row($id)['selector'], 0, self::NOW + 60));
        self::assertSame('done', $s->unsubscribe((string) $this->row($id)['selector'], 'one_click', self::NOW + self::DAY));
        $record = $this->record($id);
        self::assertSame(['page', 'c-old', 'p-old', self::NOW, self::NOW + 60, self::NOW + self::DAY, 'one_click'], array_values($record));

        // Anyone types the address into the page; nobody presses the button, and the request lapses.
        $at = self::NOW + 10 * self::DAY;
        self::assertSame('returning', $s->request('kept@example.test', 'c-new', 'p-new', $at)['outcome']);
        self::assertSame(['page', 'c-new', 'p-new', $at, 'page', 'c-old', 'p-old', self::NOW], [$this->row($id)['source'], $this->row($id)['consent_version'], $this->row($id)['privacy_version'], $this->row($id)['requested_at'], $this->row($id)['prior_source'], $this->row($id)['prior_consent_version'], $this->row($id)['prior_privacy_version'], $this->row($id)['prior_requested_at']], 'the request, and the record it asked over');
        self::assertSame(['removed' => 0, 'lapsed' => 1], $s->expire($at + Tokens::CONFIRM_SECONDS));
        self::assertSame($record, $this->record($id), 'lapsed: the record as it was');
        self::assertSame([null, null, null, null, 'unsubscribed', null], [$this->row($id)['prior_source'], $this->row($id)['prior_consent_version'], $this->row($id)['prior_privacy_version'], $this->row($id)['prior_requested_at'], $this->row($id)['state'], $this->row($id)['email']]);

        // A stranger registers the address with the tick: it waits, unproven, and lapses.
        $at += 20 * self::DAY;
        $stranger = $this->user('kept@example.test', false);
        self::assertSame('pending', $s->subscribeAccount($stranger, 'registration', 'c-reg', 'p-reg', $at));
        self::assertSame(['registration', (int) $stranger['id']], [$this->row($id)['source'], $this->row($id)['user_id']]);
        self::assertSame(['removed' => 0, 'lapsed' => 1], $s->expire($at + Tokens::CONFIRM_SECONDS));
        self::assertSame($record, $this->record($id), 'the stranger\'s request left nothing behind');
        self::assertNull($this->row($id)['user_id']);

        // Asked again and withdrawn before its button (an old digest's unsubscribe link): the first withdrawal stands.
        $at += 20 * self::DAY;
        $s->request('kept@example.test', 'c-new', 'p-new', $at);
        self::assertSame('done', $s->unsubscribe((string) $this->row($id)['selector'], 'page', $at + 60));
        self::assertSame($record, $this->record($id), 'withdrawn: nobody was subscribed in between');

        // Asked again from the address's account page and the account deleted while it waits.
        $this->db->exec('DELETE FROM users WHERE id = ?', [$stranger['id']]);
        $owner = $this->user('kept@example.test');
        self::assertSame('pending', $s->subscribeAccount($owner, 'account', 'c-acc', 'p-acc', $at + 120));
        $export = $s->forExport($owner);
        self::assertSame(['pending', 'account', 'c-acc'], [$export['state'], $export['source'], $export['consent_version']]);
        self::assertSame(['source' => 'page', 'consent_version' => 'c-old', 'privacy_version' => 'p-old', 'requested_at' => self::NOW], $export['prior'], 'the copy of the data holds the record the request waits over');
        self::assertSame('removed', $s->accountDeleted($owner, $at + 180));
        self::assertSame($record, $this->record($id), 'the account deleted: the record as it was');

        // Only its button replaces the record, with the new consent.
        $s->request('kept@example.test', 'c-new', 'p-new', $at + 240);
        self::assertSame('confirmed', $s->confirm((string) $this->row($id)['selector'], (int) $this->row($id)['generation'], $at + 300));
        self::assertSame(['page', 'c-new', 'p-new', $at + 240, $at + 300, null, null], array_values($this->record($id)));
        self::assertNull($this->row($id)['prior_source'], 'the record it replaced is not kept');
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM news_digest_subscribers'));
    }

    public function testARegistrationTickWaitsForTheAddressToBeProven(): void
    {
        $s = $this->subscribers();
        $new = $this->user('newcomer@example.test', false);
        self::assertSame('pending', $s->subscribeAccount($new, 'registration', 'c-1', 'p-1', self::NOW));
        $row = $s->forAccount($new);
        self::assertNotNull($row);
        self::assertSame(['pending', 'registration', (int) $new['id'], null], [$row['state'], $row['source'], $row['user_id'], $row['confirmed_at']]);
        self::assertFalse($s->isSubscribed($new));

        // Proving the address asks again (its link works for 7 days from then) but never confirms: only the button
        // on the page the digest's own confirmation leads to does.
        $renewed = $s->renewAccountRequest((int) $new['id'], self::NOW + self::DAY);
        self::assertNotNull($renewed);
        self::assertSame([$row['id'], 'pending', self::NOW + self::DAY, null, 0], [$renewed['id'], $renewed['state'], $renewed['requested_at'], $renewed['confirmed_at'], $renewed['generation']]);
        self::assertSame('pending', $this->row($row['id'])['state'], 'proving an address for an account is not consent to the digest');
        self::assertSame('confirmed', $s->confirm((string) $renewed['selector'], 0, self::NOW + 2 * self::DAY));
        self::assertSame(['confirmed', 'registration', self::NOW + 2 * self::DAY], [$this->row($row['id'])['state'], $this->row($row['id'])['source'], $this->row($row['id'])['confirmed_at']]);
        self::assertNull($s->renewAccountRequest((int) $new['id'], self::NOW + 2 * self::DAY), 'nothing waits once it is confirmed');

        // Proven after 7 days, the request has lapsed.
        $late = $this->user('late@example.test', false);
        self::assertSame('pending', $s->subscribeAccount($late, 'registration', 'c-1', 'p-1', self::NOW));
        self::assertNull($s->renewAccountRequest((int) $late['id'], self::NOW + Tokens::CONFIRM_SECONDS));
        self::assertSame(['pending', self::NOW], [$s->forAccount($late)['state'], $s->forAccount($late)['requested_at']]);

        // Someone else's confirmed subscription is never linked to an account whose address is not proven.
        $theirs = $this->confirmed('victim@example.test', self::NOW - self::DAY);
        $stranger = $this->user('victim@example.test', false);
        self::assertSame('already', $s->subscribeAccount($stranger, 'registration', 'c-9', 'p-9', self::NOW));
        $kept = $this->row($theirs);
        self::assertSame([null, 'page', 'confirmed', '2026-10-23'], [$kept['user_id'], $kept['source'], $kept['state'], $kept['consent_version']]);
        self::assertNull($s->renewAccountRequest((int) $stranger['id'], self::NOW + 60));
        self::assertNull($this->row($theirs)['user_id']);
    }

    /** A stranger's unverified registration never rewrites someone's waiting request from the page. */
    public function testAnUnprovenRegistrationLeavesSomeoneElsesWaitingRequestAsItIs(): void
    {
        $s = $this->subscribers();
        $theirs = $s->request('owner@example.test', 'c-page', 'p-page', self::NOW)['subscriber'];
        $before = $this->row($theirs['id']);
        $stranger = $this->user('owner@example.test', false);
        self::assertSame('pending', $s->subscribeAccount($stranger, 'registration', 'c-reg', 'p-reg', self::NOW + 60));
        self::assertSame($before, $this->row($theirs['id']), 'not its source, its versions, its time or its account');
        self::assertNull($s->renewAccountRequest((int) $stranger['id'], self::NOW + 120));
        self::assertSame('confirmed', $s->confirm((string) $theirs['selector'], 0, self::NOW + 180));
        self::assertSame(['page', null, 'c-page'], [$this->row($theirs['id'])['source'], $this->row($theirs['id'])['user_id'], $this->row($theirs['id'])['consent_version']]);

        // An unsubscribed record asks again from an unproven registration as it could from the page: pending, its
        // generation moved on, nothing sent until the address is proven.
        $gone = $this->confirmed('left@example.test', self::NOW);
        $s->unsubscribe((string) $this->row($gone)['selector'], 'page', self::NOW + 10);
        $back = $this->user('left@example.test', false);
        self::assertSame('pending', $s->subscribeAccount($back, 'registration', 'c', 'p', self::NOW + 20));
        self::assertSame(['pending', 'registration', (int) $back['id'], 1], [$this->row($gone)['state'], $this->row($gone)['source'], $this->row($gone)['user_id'], $this->row($gone)['generation']]);
    }

    /** The page's sign-up over a registration's waiting tick is the page's request now: the latest ask is recorded. */
    public function testAPageSignUpOverAWaitingRegistrationTickIsThePagesRequest(): void
    {
        $s = $this->subscribers();
        $user = $this->user('nina@example.test', false);
        self::assertSame('pending', $s->subscribeAccount($user, 'registration', 'c', 'p', self::NOW));
        $again = $s->request('nina@example.test', 'c-2', 'p-2', self::NOW + 60);
        self::assertSame('again', $again['outcome']);
        self::assertSame(['page', null, 'c-2', 0], [$again['subscriber']['source'], $again['subscriber']['user_id'], $again['subscriber']['consent_version'], $again['subscriber']['generation']]);
        self::assertNull($s->renewAccountRequest((int) $user['id'], self::NOW + 120), 'the account no longer holds it');
        self::assertSame('confirmed', $s->confirm((string) $again['subscriber']['selector'], 0, self::NOW + 180));
        self::assertSame('page', $this->row($again['subscriber']['id'])['source'], 'the page\'s button gave the consent');
    }

    public function testAnAccountUntickedDeletedOrMovedToAnotherAddress(): void
    {
        $s = $this->subscribers();
        $user = $this->user('member@example.test');
        self::assertSame('missing', $s->unsubscribeAccount($user, self::NOW));
        self::assertSame('none', $s->accountDeleted($user, self::NOW));
        $this->subscribedAccount($user);
        self::assertSame('done', $s->unsubscribeAccount($user, self::NOW + 1));
        self::assertSame('account', $s->forAccount($user)['unsubscribe_method']);
        self::assertSame('already', $s->unsubscribeAccount($user, self::NOW + 2));

        // Deleting the account, inside the deletion's transaction: a confirmed subscription ends, the record kept.
        $id = $this->subscribedAccount($user, self::NOW + 3);
        self::assertSame('unsubscribed', $this->db->transaction(fn (Database $db): string => $s->accountDeleted($user, self::NOW + 4)));
        self::assertSame(['unsubscribed', null, null, 'account'], [$this->row($id)['state'], $this->row($id)['email'], $this->row($id)['user_id'], $this->row($id)['unsubscribe_method']]);

        // A pending one goes.
        $pending = $this->user('waiting@example.test', false);
        $s->subscribeAccount($pending, 'registration', 'c', 'p', self::NOW);
        self::assertSame('removed', $this->db->transaction(fn (Database $db): string => $s->accountDeleted($pending, self::NOW + 5)));
        self::assertNull($s->byEmail('waiting@example.test'));

        // A pending request to come back returns to its suppression record.
        $back = $this->confirmed('back@example.test');
        $s->unsubscribe((string) $this->row($back)['selector'], 'page', self::NOW + 10);
        $s->request('back@example.test', 'c', 'p', self::NOW + 20);
        $owner = $this->user('back@example.test');
        self::assertSame('removed', $s->accountDeleted($owner, self::NOW + 30));
        self::assertSame(['unsubscribed', null, self::NOW + 10, 'page'], [$this->row($back)['state'], $this->row($back)['email'], $this->row($back)['unsubscribed_at'], $this->row($back)['unsubscribe_method']]);

        // A deletion rolled back leaves the subscription as it was: no method commits on its own.
        $kept = $this->user('kept@example.test');
        $this->subscribedAccount($kept);
        try {
            $this->db->transaction(function (Database $db) use ($s, $kept): void {
                $s->accountDeleted($kept, self::NOW + 1);
                throw new \RuntimeException('the deletion failed');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('the deletion failed', $e->getMessage());
        }
        self::assertTrue($s->isSubscribed($kept));

        // A change of the account's address ends its subscription; the new address is ticked again by hand.
        self::assertSame('done', $s->accountEmailChanged((int) $kept['id'], self::NOW + 2));
        self::assertSame('missing', $s->accountEmailChanged((int) $kept['id'], self::NOW + 3));
        self::assertSame('account', $s->byEmail('kept@example.test')['unsubscribe_method']);
        $this->db->exec('UPDATE users SET email = ? WHERE id = ?', ['moved@example.test', $kept['id']]);
        $moved = $this->db->one('SELECT * FROM users WHERE id = ?', [$kept['id']]);
        self::assertFalse($s->isSubscribed((array) $moved));

        // A tick that finds the account's row holding an older address ends it first and asks for the new address's.
        $this->subscribedAccount((array) $moved, self::NOW + 4);
        $this->db->exec('UPDATE users SET email = ? WHERE id = ?', ['again@example.test', $kept['id']]);
        $again = (array) $this->db->one('SELECT * FROM users WHERE id = ?', [$kept['id']]);
        self::assertSame('pending', $s->subscribeAccount($again, 'account', 'c', 'p', self::NOW + 5));
        self::assertSame(['unsubscribed', 'account'], [$s->byEmail('moved@example.test')['state'], $s->byEmail('moved@example.test')['unsubscribe_method']]);
        self::assertSame(['again@example.test', 'pending'], [$s->forAccount($again)['email'], $s->forAccount($again)['state']]);
    }

    public function testTheCopyOfTheirDataHoldsNoIdSelectorOrHash(): void
    {
        $s = $this->subscribers();
        $user = $this->user('member@example.test');
        self::assertNull($s->forExport($user));
        $this->subscribedAccount($user);
        $row = $s->forAccount($user);
        $digest = $this->db->insert('news_digests', ['week' => '2026-w43', 'send_at' => self::NOW, 'window_start' => self::NOW - 604800, 'window_end' => self::NOW, 'articles' => '[1]', 'left_out' => 0, 'state' => 'sent', 'recipients' => 1, 'composed_at' => self::NOW, 'finished_at' => self::NOW]);
        $this->db->insert('news_digest_sends', ['digest_id' => $digest, 'subscriber_id' => $row['id'], 'state' => 'sent', 'attempts' => 1, 'claimed_at' => self::NOW, 'sent_at' => self::NOW + 5]);

        $export = $s->forExport($user);
        self::assertSame(['email', 'state', 'source', 'consent_version', 'privacy_version', 'requested_at', 'confirmed_at', 'unsubscribed_at', 'unsubscribe_method', 'prior', 'digests'], array_keys((array) $export));
        self::assertNull($export['prior'], 'no request waits over the record');
        self::assertSame([['week' => '2026-w43', 'state' => 'sent', 'sent_at' => self::NOW + 5]], $export['digests']);
        self::assertSame(['member@example.test', 'confirmed', 'account'], [$export['email'], $export['state'], $export['source']]);
        $json = (string) json_encode($export);
        self::assertStringNotContainsString((string) $row['selector'], $json);
        self::assertStringNotContainsString((string) $row['email_hash'], $json);
    }

    public function testTheDesksCounts(): void
    {
        $s = $this->subscribers();
        $this->confirmed('a@example.test', self::NOW - 10 * self::DAY);
        $this->confirmed('b@example.test', self::NOW - self::DAY);
        $c = $this->confirmed('c@example.test', self::NOW - 20 * self::DAY);
        $s->unsubscribe((string) $this->row($c)['selector'], 'page', self::NOW - 2 * self::DAY);
        $d = $this->confirmed('d@example.test', self::NOW - 30 * self::DAY);
        $s->unsubscribe((string) $this->row($d)['selector'], 'page', self::NOW - 9 * self::DAY);
        $s->request('e@example.test', 'c', 'p', self::NOW);
        self::assertSame(['confirmed' => 2, 'pending' => 1, 'unsubscribed' => 2, 'joined' => 1, 'left' => 1], $s->counts(self::NOW));
    }

    public function testHardBouncesInARowUnsubscribeAfterTwoWeeks(): void
    {
        $s = $this->subscribers();
        $id = $this->confirmed('gone@example.test');
        self::assertFalse($s->bounced($id, self::NOW));
        self::assertFalse($s->bounced($id, self::NOW + 7 * self::DAY), 'two a week apart do nothing');
        self::assertSame([2, self::NOW], [$this->row($id)['bounces'], $this->row($id)['bounced_at']]);
        self::assertTrue($s->bounced($id, self::NOW + Subscribers::BOUNCE_SPAN), 'a third at 14 days unsubscribes');
        self::assertSame(['unsubscribed', 'bounce', null, 0], [$this->row($id)['state'], $this->row($id)['unsubscribe_method'], $this->row($id)['email'], $this->row($id)['bounces']]);
        self::assertFalse($s->bounced($id, self::NOW + 30 * self::DAY));

        // A delivery between them starts the run again.
        $other = $this->confirmed('flaky@example.test');
        $s->bounced($other, self::NOW);
        $s->delivered($other);
        self::assertSame([0, null], [$this->row($other)['bounces'], $this->row($other)['bounced_at']]);
        self::assertFalse($s->bounced($other, self::NOW + 14 * self::DAY));
        self::assertFalse($s->bounced($other, self::NOW + 15 * self::DAY), 'the run began a day ago');
        self::assertSame('confirmed', $this->row($other)['state']);
    }

    public function testUnconfirmedRequestsExpireAfterSevenDays(): void
    {
        $s = $this->subscribers();
        $old = (int) $s->request('old@example.test', 'c', 'p', self::NOW - Tokens::CONFIRM_SECONDS)['subscriber']['id'];
        $fresh = (int) $s->request('fresh@example.test', 'c', 'p', self::NOW - Tokens::CONFIRM_SECONDS + 1)['subscriber']['id'];
        $back = $this->confirmed('back@example.test', self::NOW - 30 * self::DAY);
        $s->unsubscribe((string) $this->row($back)['selector'], 'one_click', self::NOW - 20 * self::DAY);
        $s->request('back@example.test', 'c', 'p', self::NOW - 8 * self::DAY);
        $kept = $this->confirmed('kept@example.test', self::NOW - 90 * self::DAY);

        self::assertSame(['removed' => 1, 'lapsed' => 1], $s->expire(self::NOW));
        self::assertNull($s->find($old));
        self::assertSame('pending', $this->row($fresh)['state']);
        self::assertSame(['unsubscribed', null, null, self::NOW - 20 * self::DAY, 'one_click'], [$this->row($back)['state'], $this->row($back)['email'], $this->row($back)['user_id'], $this->row($back)['unsubscribed_at'], $this->row($back)['unsubscribe_method']]);
        self::assertSame('confirmed', $this->row($kept)['state']);
        self::assertSame(['removed' => 0, 'lapsed' => 0], $s->expire(self::NOW));
    }
}
