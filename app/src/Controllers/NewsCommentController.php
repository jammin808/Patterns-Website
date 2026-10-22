<?php

declare(strict_types=1);

namespace Psc\Controllers;

use Psc\Content\Content;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\News\ArticleStore;
use Psc\News\Comments;
use Psc\News\Prose;

/**
 * Industry Updates' comments: the JSON API news-comments.js uses on a cached article page, and the comment page with
 * its forms for readers without scripting (show, not page: Controller::page() renders a template). The rules live in
 * Psc\News\Comments, so both routes post, delete and report alike.
 *
 * Every route answers 404 for an article that is not live, but for deleting one's own comments: the API's delete
 * takes the person's own comment wherever its article stands, and the comment page of an article that is not live
 * shows a person who commented there their own comments with delete forms (anyone else gets 404). The API is
 * same-origin fetch only (the api middleware's X-Requested-With check is its CSRF defence) and its answers are JSON
 * with no-store. The comment page's forms answer with a 303 back to the comment page, never to the cached article,
 * where a flash could not show.
 */
final class NewsCommentController extends Controller
{
    /** The most comment ids a page may ask about in one state call (Comments::PAGE_MAX). */
    private const STATE_IDS = Comments::PAGE_MAX;

    /**
     * GET /news/{slug}/comment/: the box, the person's own comments with delete forms, report forms on the others. On
     * an article that is not live, only the person's own comments with their delete forms, and never its title.
     */
    public function show(Request $request): Response
    {
        $user = $this->currentUser();
        [$article, $live] = $this->pageArticle($request, $user) ?? [null, false];
        if ($article === null) {
            return $this->notFound();
        }
        $uid = (int) $user['id'];
        $id = (int) $article['id'];
        $comments = new Comments($this->app);
        // The visible comments and the person's own pending and hidden ones, in the order they were posted.
        $list = $live ? array_merge($comments->visible($id), $comments->authored($id, $uid, true)) : $comments->authored($id, $uid);
        usort($list, static fn (array $a, array $b): int => [(int) $a['created_at'], (int) $a['id']] <=> [(int) $b['created_at'], (int) $b['id']]);

        $path = self::pagePath($article);
        $title = (string) $article['title'];
        $seo = $live
            ? $this->app->seo('Comments: ' . Prose::cut($title, 40) . ' | Industry Updates', 'Comments on ' . Prose::cut($title, 120) . ', an Industry Updates article.', $path)->noindex()
            : $this->app->seo('Your comments | Industry Updates', 'Your comments on an Industry Updates article that is not published at the moment.', $path)->noindex();
        $seo->breadcrumbs = Content::trail('/news/');
        if ($live) {
            $seo->crumb((string) ($article['seo_title'] ?: $title), self::articlePath($article));
        }
        $seo->crumb($live ? 'Comments' : 'Your comments', $path);
        $idle = $this->app->config->int('security.session_idle_seconds', 7200);
        return $this->page('news/comment', [
            'section' => 'news',
            'article' => $article,
            'live' => $live,
            'comments' => $list,
            'viewer' => $user,
            'name' => Comments::publicName($user),
            'staff' => Comments::isStaff($user),
            'closed' => $live ? $comments->closedReason($article) : Comments::NOT_LIVE_OWN,
            'old' => $this->app->session()->takeOld(),
            'errors' => $this->takeErrors(),
            'idleSeconds' => $idle,
        ], $seo);
    }

    /**
     * POST /news/{slug}/comment/ (action: post, delete or report), then a 303 back to the comment page. On an article
     * that is not live only delete is taken; the last of the person's comments there deleted, the answer goes to
     * their account page, since the comment page is theirs only while they have comments on it.
     */
    public function submit(Request $request): Response
    {
        $user = $this->currentUser();
        [$article, $live] = $this->pageArticle($request, $user) ?? [null, false];
        $action = $request->input('action');
        if ($article === null || (!$live && $action !== 'delete')) {
            return $this->notFound();
        }
        $path = self::pagePath($article);
        $comments = new Comments($this->app);
        switch ($action) {
            case 'post':
                $result = $comments->post((int) $article['id'], $user, $request->input('body'));
                if (!$result['ok']) {
                    return $result['errors'] !== []
                        ? $this->backWithErrors($path . '#comment-form', $request, $result['errors'])
                        : $this->backWithErrors($path . '#comment-form', $request, [], $result['message']);
                }
                $commentId = (int) $result['comment']['id'];
                $message = match (true) {
                    $result['repeat'] ?? false => 'That comment was already posted, so it was not posted again.',
                    $result['status'] === 'pending' => 'Your comment is posted. ' . Comments::PENDING_NOTE,
                    default => 'Your comment is posted.',
                };
                return $this->redirect($path . '#comment-' . $commentId, 'success', $message);

            case 'delete':
                $comment = $this->commentOn($article, $request->input('comment'));
                // Someone else's comment is answered like a missing one: the form only ever offers the person's own.
                if ($comment === null || (int) $comment['user_id'] !== (int) $user['id'] || !$comments->remove((int) $comment['id'], $user)) {
                    return $this->redirect($path . '#comments', 'danger', 'That comment was not deleted. It may have been deleted already; the list below is up to date.');
                }
                if (!$live && $comments->own((int) $article['id'], (int) $user['id']) === []) {
                    return $this->redirect('/portal/account/', 'success', 'Your comment is deleted. You have no other comments on that article.');
                }
                return $this->redirect($path . '#comments', 'success', 'Your comment is deleted.');

            case 'report':
                $comment = $this->commentOn($article, $request->input('comment'));
                $outcome = $comment !== null ? $comments->report((int) $comment['id'], $user) : 'missing';
                [$type, $message] = self::reportAnswer($outcome);
                return $this->redirect($path . ($comment !== null && $outcome !== 'missing' ? '#comment-' . (int) $comment['id'] : '#comments'), $type, $message);
        }
        // The buttons post fixed values, so anything else is refused with a message only, keeping no field error.
        return $this->redirect($path, 'danger', 'That action is not available. Use one of the buttons on this page.');
    }

    /**
     * GET /api/news/{id}/state?since=&ids=: what the person viewing a page drawn at since may do, and what changed
     * for them since: {signed_in, can_comment, closed_reason, name, mine, removed, own, staff}. mine is their pending
     * comments, the ones a moderator hid (status hidden, so the page can mark them and offer Delete) and their
     * visible ones posted or approved since; removed is which of ids (the comments the page shows) are no longer
     * visible; own is the ids of their comments, in every status. Signed out, only removed is filled in.
     */
    public function state(Request $request): Response
    {
        $comments = new Comments($this->app);
        $article = $comments->liveArticle($request->attrInt('id'));
        if ($article === null) {
            return $this->notFound();
        }
        $id = (int) $article['id'];
        $user = $this->app->auth()->user();
        $since = (int) $request->queryString('since');
        $since = $since > 0 && $since <= time() ? $since : 0;
        $ids = array_slice(array_map('intval', array_filter(explode(',', substr($request->queryString('ids'), 0, 8 * self::STATE_IDS)), 'ctype_digit')), 0, self::STATE_IDS);
        $closed = $comments->closedReason($article);
        $uid = $user !== null ? (int) $user['id'] : 0;
        return Response::json([
            'signed_in' => $user !== null,
            'can_comment' => $user !== null && $closed === null,
            'closed_reason' => $closed,
            'name' => $user !== null ? Comments::publicName($user) : null,
            'mine' => $user !== null ? array_map(static fn (array $c): array => Comments::toPublic($c, $uid), $comments->mine($id, $uid, $since)) : [],
            'removed' => $comments->removedOf($id, $ids),
            'own' => $user !== null ? $comments->own($id, $uid) : [],
            'staff' => $user !== null && Comments::isStaff($user),
        ]);
    }

    /** POST /api/news/{id}/comments, JSON {body}: 201 with the comment, or the refusal with its message. */
    public function create(Request $request): Response
    {
        $comments = new Comments($this->app);
        $article = $comments->liveArticle($request->attrInt('id'));
        if ($article === null) {
            return $this->notFound();
        }
        $user = $this->currentUser();
        $body = $request->json()['body'] ?? '';
        $result = $comments->post((int) $article['id'], $user, is_string($body) ? $body : '');
        if ($result['ok']) {
            return Response::json([
                'ok' => true,
                'status' => $result['status'],
                'repeat' => $result['repeat'] ?? false,
                'comment' => Comments::toPublic($result['comment'], (int) $user['id']),
            ], ($result['repeat'] ?? false) ? 200 : 201);
        }
        $codes = ['invalid' => 422, 'closed' => 403, 'rate_limited' => 429, 'hidden' => 409, 'not_found' => 404];
        return Response::json(['error' => $result['status'], 'message' => $result['message'], 'errors' => (object) $result['errors']], $codes[$result['status']] ?? 400);
    }

    /**
     * POST /api/news/comments/{id}/delete: the person's own comment, in any status and whether or not its article is
     * still live (a page cached before an unpublish still offers Delete); anything else is 404.
     */
    public function delete(Request $request): Response
    {
        $user = $this->currentUser();
        $comments = new Comments($this->app);
        $comment = $comments->find($request->attrInt('id'));
        if ($comment === null || (int) $comment['user_id'] !== (int) $user['id']) {
            return $this->notFound();
        }
        if (!$comments->remove((int) $comment['id'], $user)) {
            return $this->notFound();
        }
        return Response::json(['ok' => true, 'id' => (int) $comment['id']]);
    }

    /** POST /api/news/comments/{id}/report: {ok, result: reported|already}, or the refusal with its message. */
    public function report(Request $request): Response
    {
        $user = $this->currentUser();
        $comments = new Comments($this->app);
        $comment = $comments->find($request->attrInt('id'));
        if ($comment === null || $comments->liveArticle((int) $comment['article_id']) === null) {
            return $this->notFound();
        }
        $outcome = $comments->report((int) $comment['id'], $user);
        [, $message] = self::reportAnswer($outcome);
        if ($outcome === 'reported' || $outcome === 'already') {
            return Response::json(['ok' => true, 'result' => $outcome, 'message' => $message]);
        }
        $codes = ['own' => 422, 'staff' => 403, 'limited' => 429, 'missing' => 404];
        return Response::json(['error' => $outcome, 'message' => $message], $codes[$outcome] ?? 400);
    }

    /**
     * The comment page: /news/{slug}/comment/.
     *
     * @param array<string, mixed> $article
     */
    public static function pagePath(array $article): string
    {
        return self::articlePath($article) . 'comment/';
    }

    /**
     * The article: /news/{slug}/.
     *
     * @param array<string, mixed> $article
     */
    public static function articlePath(array $article): string
    {
        return '/news/' . $article['slug'] . '/';
    }

    /**
     * The flash type and words for a report's outcome (Comments::report()).
     *
     * @return array{0: string, 1: string}
     */
    public static function reportAnswer(string $outcome): array
    {
        return match ($outcome) {
            'reported' => ['success', 'Thank you. The comment is reported, and the team will take a look.'],
            'already' => ['info', 'You have already reported this comment. The team will take a look.'],
            'own' => ['danger', 'You cannot report your own comment. You can delete it instead.'],
            'staff' => ['info', 'Administrators hide comments from the moderation page instead of reporting them.'],
            'limited' => ['danger', Comments::REPORT_LIMIT],
            default => ['danger', 'That comment is no longer shown, so there is nothing to report.'],
        };
    }

    /**
     * The article the comment page names, and whether it is live: a live one, or one that is not live on which the
     * person has comments (so they can still delete them). Null for anything else, which is answered with 404, so a
     * draft's address tells no one else anything.
     *
     * @param array<string, mixed> $user the signed-in account
     * @return array{0: array<string, mixed>, 1: bool}|null
     */
    private function pageArticle(Request $request, array $user): ?array
    {
        $store = new ArticleStore($this->app);
        $article = $store->findBySlug((string) $request->attr('slug'), false);
        if ($article === null) {
            return null;
        }
        if ($store->isLive($article)) {
            return [$article, true];
        }
        return (new Comments($this->app))->own((int) $article['id'], (int) $user['id']) !== [] ? [$article, false] : null;
    }

    /**
     * The comment $id on this article (the comment page's forms name one), or null.
     *
     * @param array<string, mixed> $article
     * @return array<string, mixed>|null
     */
    private function commentOn(array $article, string $id): ?array
    {
        if (!ctype_digit($id)) {
            return null;
        }
        $comment = (new Comments($this->app))->find((int) $id);
        return $comment !== null && (int) $comment['article_id'] === (int) $article['id'] ? $comment : null;
    }

    /** @return array<string, mixed> the signed-in account (the auth middleware has already refused anyone else) */
    private function currentUser(): array
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            throw new \LogicException('Comment routes need the auth middleware');
        }
        return $user;
    }
}
