<?php

declare(strict_types=1);

namespace Psc\Controllers\Admin;

use Psc\Content\Content;
use Psc\Controllers\Controller;
use Psc\Controllers\NewsController;
use Psc\Db\Database;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\News\ArticleStore;
use Psc\News\Categories;
use Psc\News\Draft;
use Psc\News\Images;
use Psc\News\Paths;
use Psc\News\Result;
use Psc\News\Settings;
use Psc\News\Validator;
use Psc\Support\Audit;
use Psc\Support\Paginator;
use Psc\Support\Uploads;

/**
 * Industry Updates articles, for administrators (docs/NEWS.md §11; the routes carry the admin middleware): the list
 * in five tabs, the editor for new and existing articles, publishing, scheduling, unpublishing and deleting, the
 * images and the preview. Every save goes through the Validator and ArticleStore the AI desk and the importer use,
 * as a manual article: a draft needs only its headline; publishing, scheduling and any change to an article that is
 * published or scheduled take the full rules.
 *
 * A refused save comes back to the editor with everything that was typed, from a copy of its own in the session
 * (news_draft.new or news_draft.<id>: keepOld() keeps neither lists nor long text), and each error names its row
 * (the error sources.2.url belongs to the control sources-2-url). Until an article has been live its address is the
 * administrator's to choose: the slug typed in the editor, or with none the one its SEO title or headline makes
 * (Draft::slugBase(), as the store makes it), which it then follows; once it has been live it never changes
 * (ArticleStore::hasBeenLive()). A takedown (unpublish, delete, an image deleted from a live article) says how long
 * the CDN may go on showing it.
 *
 * The editor posts the updated_at of the article it was opened on (loaded_at), and a save over a later change is
 * refused with the form kept, so a stale tab never overwrites someone else's work. Unpublishing is a takedown: it
 * happens before the form is checked, so neither a rule the stored article now fails nor such a change keeps an
 * article on the site.
 */
final class AdminNewsController extends Controller
{
    public const TABS = ['all' => 'All', 'live' => 'Live', 'scheduled' => 'Scheduled', 'drafts' => 'Drafts', 'held' => 'Held'];

    public const ORIGINS = ['manual' => 'Written here', 'ai' => 'AI desk', 'import' => 'Imported'];

    /** An article's state: its badge variant and its words. Held is a draft the AI desk wrote, waiting for a person. */
    public const STATES = [
        'live' => ['active', 'Live'],
        'scheduled' => ['planned', 'Scheduled'],
        'held' => ['pending', 'Held for review'],
        'draft' => ['private', 'Draft'],
    ];

    /** Empty rows the editor adds to each list beyond the filled ones (admin-news.js shows them one at a time). */
    public const SPARE_ROWS = 6;

    /** The articles the follow-up list offers, the newest first. */
    public const FOLLOW_UP_CHOICES = 60;

    /** Schedules are in the site's own time, whatever the server's. */
    public const TIMEZONE = 'Europe/London';

    public const UNKNOWN_ACTION = 'That action is not available. Use one of the buttons on this page.';

    /** A save from a form opened before the article's last change (another administrator's, or another tab's). */
    public const CHANGED_SINCE = 'Someone changed this article after you opened it, so your changes were not saved. What you typed is below: copy what you need, then reload the page to see the article as it is now.';

    private const ACTIONS = ['save', 'publish', 'schedule', 'unpublish', 'delete'];
    private const IMAGE_ACTIONS = ['add', 'update', 'delete', 'lead'];
    private const PER_PAGE = 25;
    private const SCHEDULE_DAYS = 365;

    /** The most of a text kept for the editor after a refused save, and the most rows a list may send. */
    private const KEEP_MAX = 70000;
    private const ROWS_MAX = 40;

    private const LIST_COLUMNS = 'a.id, a.slug, a.title, a.status, a.published_at, a.origin, a.review_reason, a.comment_count, a.created_at, a.updated_at,'
        . ' (SELECT c.category FROM news_article_categories c WHERE c.article_id = a.id ORDER BY c.position, c.category LIMIT 1) AS category';

    // ------------------------------------------------------------------------------------------------- the list

    public function index(Request $request): Response
    {
        $db = $this->app->db();
        $now = time();
        $tab = AdminController::pick($request->queryString('tab'), self::TABS) ?: 'all';
        $f = [
            'category' => AdminController::pick($request->queryString('category'), Categories::all()),
            'origin' => AdminController::pick($request->queryString('origin'), self::ORIGINS),
            'q' => self::searchText($request->queryString('q')),
        ];
        $where = ['1 = 1'];
        $params = [];
        if ($f['category'] !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM news_article_categories c WHERE c.article_id = a.id AND c.category = ?)';
            $params[] = $f['category'];
        }
        if ($f['origin'] !== '') {
            $where[] = 'a.origin = ?';
            $params[] = $f['origin'];
        }
        if ($f['q'] !== '') {
            $where[] = "(a.title LIKE ? ESCAPE '!' OR a.dek LIKE ? ESCAPE '!')";
            $params[] = Database::like($f['q']);
            $params[] = Database::like($f['q']);
        }
        $counts = [];
        foreach (array_keys(self::TABS) as $t) {
            [$tw, $tp] = self::tabWhere($t, $now);
            $counts[$t] = (int) $db->value('SELECT COUNT(*) FROM news_articles a WHERE ' . implode(' AND ', [...$where, $tw]), [...$params, ...$tp]);
        }
        [$tw, $tp] = self::tabWhere($tab, $now);
        $order = match ($tab) {
            'scheduled' => 'a.published_at, a.id',
            'drafts', 'held' => 'a.updated_at DESC, a.id DESC',
            default => 'COALESCE(a.published_at, a.created_at) DESC, a.id DESC',
        };
        $query = array_filter(['tab' => $tab !== 'all' ? $tab : ''] + $f + ['page' => $request->queryString('page')], static fn (string $v): bool => $v !== '');
        $pager = Paginator::query($db, 'SELECT ' . self::LIST_COLUMNS . ' FROM news_articles a WHERE ' . implode(' AND ', [...$where, $tw]) . ' ORDER BY ' . $order, [...$params, ...$tp], $query, '/portal/admin/news/', self::PER_PAGE);
        return $this->portal('admin/news/index', [
            'tab' => $tab,
            'filters' => $f,
            'filtered' => array_filter($f) !== [],
            'counts' => $counts,
            'pager' => $pager,
            'now' => $now,
            'categories' => Categories::all(),
        ], 'Industry Updates');
    }

    // ----------------------------------------------------------------------------------------------- the editor

    public function create(Request $request): Response
    {
        $admin = $this->currentUser();
        $kept = $this->takeKept(null);
        $values = $kept ?? self::withDefaults(['byline' => (string) $admin['name']]);
        return $this->portal('admin/news/create', $this->editorData(null, $values, $kept !== null), 'Write an article');
    }

    public function store(Request $request): Response
    {
        $admin = $this->currentUser();
        $back = '/portal/admin/news/new/';
        $action = $request->input('action');
        if (!in_array($action, ['save', 'publish', 'schedule'], true)) {
            return $this->redirect($back, 'danger', self::UNKNOWN_ACTION);
        }
        $values = self::formValues($request);
        [$draft, $result, $at] = $this->check($values, $action, null);
        if (!$result->ok() || $at === false) {
            return $this->keep($back, null, $values, $draft, $result);
        }
        $clean = $result->draft;
        $clean->status = $action === 'save' ? 'draft' : 'published';
        $clean->publishAt = $action === 'schedule' ? (int) $at : null;
        $store = new ArticleStore($this->app);
        $id = $store->create($clean, (int) $admin['id']);
        $row = (array) $store->find($id);
        $status = ['save' => 'draft', 'publish' => 'published', 'schedule' => 'scheduled'][$action];
        Audit::log($this->app, (int) $admin['id'], 'news.created', (string) $id, ['title' => (string) $row['title'], 'slug' => (string) $row['slug'], 'status' => $status]);
        if ($action === 'publish') {
            Audit::log($this->app, (int) $admin['id'], 'news.published', (string) $id, ['at' => date(DATE_ATOM, (int) $row['published_at'])]);
        } elseif ($action === 'schedule') {
            Audit::log($this->app, (int) $admin['id'], 'news.scheduled', (string) $id, ['at' => date(DATE_ATOM, (int) $at)]);
        }
        $message = match ($action) {
            'publish' => 'Published. The article is live at ' . Paths::article($row) . '.',
            'schedule' => 'Scheduled. The article goes live on ' . self::london((int) $at) . ' (London time).',
            default => 'Saved as a draft. Only administrators can see it until you publish it.',
        };
        $this->app->session()->flash('success', $message);
        $this->flashWarnings($result);
        return Response::redirect('/portal/admin/news/' . $id . '/', 303);
    }

    public function edit(Request $request): Response
    {
        $store = new ArticleStore($this->app);
        $article = $store->find($request->attrInt('id'));
        if ($article === null) {
            return $this->notFound();
        }
        $id = (int) $article['id'];
        $kept = $this->takeKept($id);
        $values = $kept ?? self::valuesOf($store->draft($id) ?? new Draft());
        // A kept form keeps the version it was first opened on, so showing it again never makes it current.
        if ($values['loaded_at'] === '') {
            $values['loaded_at'] = (string) (int) $article['updated_at'];
        }
        $run = $article['run_id'] !== null ? $this->app->db()->one('SELECT id, slot, kind, status, created_at FROM news_runs WHERE id = ?', [(int) $article['run_id']]) : null;
        $data = $this->editorData($article, $values, $kept !== null) + [
            'state' => self::state($article),
            'images' => $store->images($id),
            'links' => $this->linkClicks($store, $id),
            'run' => $run,
            'old' => $this->app->session()->takeOld(),
            'maxBytes' => (new Images($this->app))->maxBytes(),
            'imagesSupported' => Images::supported(),
        ];
        return $this->portal('admin/news/edit', $data, 'Edit an article');
    }

    public function update(Request $request): Response
    {
        $admin = $this->currentUser();
        $store = new ArticleStore($this->app);
        $article = $store->find($request->attrInt('id'));
        if ($article === null) {
            return $this->notFound();
        }
        $id = (int) $article['id'];
        $path = '/portal/admin/news/' . $id . '/';
        $action = $request->input('action');
        if (!in_array($action, self::ACTIONS, true)) {
            return $this->redirect($path, 'danger', self::UNKNOWN_ACTION);
        }
        $state = self::state($article);
        if ($action === 'delete') {
            return $this->delete($request, $store, $article, (int) $admin['id']);
        }
        if ($action === 'schedule' && $state === 'live') {
            return $this->redirect($path, 'danger', 'This article is live, so it cannot be scheduled. Unpublish it first, then schedule it.');
        }
        $session = $this->app->session();
        $loaded = ctype_digit($request->input('loaded_at')) ? (int) $request->input('loaded_at') : null;
        // A form opened before the article's last change is refused whole (ArticleStore::update() checks again as it
        // writes, for a change made while this request runs).
        $stale = $loaded !== null && $loaded !== (int) $article['updated_at'];

        // The takedown first: nothing in the form, and no change since it was opened, can keep the article live.
        $unpublished = false;
        if ($action === 'unpublish' && $article['status'] === 'published') {
            $unpublished = $store->unpublish($id);
            if ($unpublished) {
                Audit::log($this->app, (int) $admin['id'], 'news.unpublished', (string) $id, ['was' => $state]);
                // The takedown is this form's own change: a form opened on the current article stays current.
                if ($loaded !== null && !$stale) {
                    $loaded = (int) $this->app->db()->value('SELECT updated_at FROM news_articles WHERE id = ?', [$id]);
                }
            }
        }
        $takedown = $state === 'live' ? ' ' . AdminNewsCommentController::TAKEDOWN : '';
        $notSaved = 'Unpublished. The article is a draft again, but your other changes were not saved.' . $takedown;

        $values = self::formValues($request);
        $values['loaded_at'] = $loaded !== null ? (string) $loaded : '';
        [$draft, $result, $at] = $this->check($values, $action, $article);
        if ($stale || !$result->ok() || $at === false) {
            $response = $stale ? $this->conflict($path, $id, $values, $draft) : $this->keep($path, $id, $values, $draft, $result);
            if ($unpublished) {
                $session->flash('success', $notSaved);
            }
            return $response;
        }
        $clean = $result->draft;
        // Until it has been live the address is the one typed, or with none the one its words make, as the store
        // makes it (an address it already has, or the same one numbered to be unique, is kept as it is); after that
        // it is the address it went live at, whatever was posted.
        if (ArticleStore::hasBeenLive($article)) {
            $clean->slug = null;
        } elseif ($clean->slug === null && !Paths::isNumberedFrom((string) $article['slug'], $clean->slugBase())) {
            $clean->slug = $clean->slugBase();
        }
        $changed = $store->update($id, $clean, (int) $admin['id'], $loaded);
        if ($changed === null) {
            $response = $this->conflict($path, $id, $values, $draft);
            if ($unpublished) {
                $session->flash('success', $notSaved);
            }
            return $response;
        }
        if ($changed !== []) {
            Audit::log($this->app, (int) $admin['id'], 'news.updated', (string) $id, ['fields' => $changed]);
        }
        $saved = $changed !== [] ? 'Changes saved. ' : '';
        if ($action === 'save') {
            if ($changed === []) {
                $session->flash('info', 'Nothing changed, so nothing was saved.');
            } else {
                $session->flash('success', 'Changes saved.' . ($state === 'live' ? ' It can take a few minutes for every reader to see them.' : ''));
            }
        } elseif ($action === 'publish') {
            if ($state === 'live') {
                $session->flash($changed !== [] ? 'success' : 'info', $saved . 'The article was already live.');
            } else {
                $store->publish($id);
                $row = (array) $store->find($id);
                Audit::log($this->app, (int) $admin['id'], 'news.published', (string) $id, ['at' => date(DATE_ATOM, (int) $row['published_at'])]);
                $session->flash('success', $saved . 'Published. The article is live at ' . Paths::article($row) . '.');
            }
        } elseif ($action === 'schedule') {
            $store->publish($id, (int) $at);
            Audit::log($this->app, (int) $admin['id'], 'news.scheduled', (string) $id, ['at' => date(DATE_ATOM, (int) $at)]);
            $session->flash('success', $saved . 'Scheduled. The article goes live on ' . self::london((int) $at) . ' (London time).');
        } elseif ($unpublished) {
            $session->flash('success', $saved . 'Unpublished. The article is a draft again.' . $takedown);
        } else {
            $session->flash($changed !== [] ? 'success' : 'info', $saved . 'The article was already a draft.');
        }
        $this->flashWarnings($result);
        return Response::redirect($path, 303);
    }

    // ------------------------------------------------------------------------------------------------- images

    public function images(Request $request): Response
    {
        $admin = $this->currentUser();
        $store = new ArticleStore($this->app);
        $article = $store->find($request->attrInt('id'));
        if ($article === null) {
            return $this->notFound();
        }
        $id = (int) $article['id'];
        $path = '/portal/admin/news/' . $id . '/';
        $action = $request->input('action');
        if (!in_array($action, self::IMAGE_ACTIONS, true)) {
            return $this->redirect($path . '#images', 'danger', 'That image action is not available. Use one of the buttons beside the images.');
        }
        $images = new Images($this->app);
        $banned = (new Settings($this->app))->get('banned_domains');
        $live = $store->isLive($article);
        $soon = $live ? ' It can take a few minutes for every reader to see it.' : '';

        if ($action === 'add') {
            $files = Uploads::files(is_array($request->files['image'] ?? null) ? $request->files['image'] : null);
            if (count($files) !== 1) {
                return $this->backWithErrors($path . '#image-new-summary', $request, ['image.new.file' => $files === [] ? 'Choose an image to upload.' : 'Upload one image at a time.'], 'The image was not added.');
            }
            $r = $images->store($files[0], $id, $request->post, (int) $admin['id'], $banned);
            if (!$r['ok']) {
                return $this->backWithErrors($path . '#image-new-summary', $request, self::scoped('new', $r['errors']), 'The image was not added.');
            }
            $image = $r['image'];
            Audit::log($this->app, (int) $admin['id'], 'news.image_added', (string) $id, ['image' => (int) $image['id'], 'name' => (string) $image['original_name'], 'lead' => (int) $image['position'] === 0]);
            $lead = $store->images($id)[0]['id'] ?? null;
            return $this->redirect($path . '#image-' . (int) $image['id'], 'success', 'Image added.' . ((int) $lead === (int) $image['id'] ? ' It is the lead image.' : '') . $soon);
        }

        $image = $images->find((int) $request->input('image'));
        if ($image === null || (int) ($image['article_id'] ?? 0) !== $id) {
            return $this->redirect($path . '#images', 'info', 'That image is no longer there, so nothing changed.');
        }
        $imageId = (int) $image['id'];
        if ($action === 'update') {
            $r = $images->update($imageId, $request->post, $banned);
            if ($r['errors'] !== []) {
                return $this->backWithErrors($path . '#image-' . $imageId . '-summary', $request, self::scoped((string) $imageId, $r['errors']), 'The image details were not saved.');
            }
            if ($r['changed'] === []) {
                return $this->redirect($path . '#image-' . $imageId, 'info', 'Nothing changed, so nothing was saved.');
            }
            Audit::log($this->app, (int) $admin['id'], 'news.image_updated', (string) $id, ['image' => $imageId, 'fields' => $r['changed']]);
            return $this->redirect($path . '#image-' . $imageId, 'success', 'Image details saved.' . $soon);
        }
        if ($action === 'lead') {
            if (!$images->makeLead($id, $imageId)) {
                return $this->redirect($path . '#image-' . $imageId, 'info', 'That image already leads the article.');
            }
            Audit::log($this->app, (int) $admin['id'], 'news.image_updated', (string) $id, ['image' => $imageId, 'fields' => ['lead']]);
            return $this->redirect($path . '#image-' . $imageId, 'success', 'That image now leads the article.' . $soon);
        }
        $images->delete($image);
        Audit::log($this->app, (int) $admin['id'], 'news.image_deleted', (string) $id, ['image' => $imageId, 'name' => (string) $image['original_name'], 'alt' => (string) $image['alt']]);
        return $this->redirect($path . '#images', 'success', 'Image deleted.' . ($live ? ' ' . AdminNewsCommentController::TAKEDOWN : ''));
    }

    // ------------------------------------------------------------------------------------------------ preview

    /** The article as readers will see it, whatever its state: NewsController's own page, marked as a preview. */
    public function preview(Request $request): Response
    {
        $article = (new ArticleStore($this->app))->find($request->attrInt('id'));
        return $article !== null ? (new NewsController($this->app))->renderArticle($article, true) : $this->notFound();
    }

    // ------------------------------------------------------------------------------------------------ helpers

    /**
     * An article's state on the admin pages: live, scheduled (published with a later time), held (a draft the AI desk
     * wrote) or draft.
     *
     * @param array<string, mixed> $row
     */
    public static function state(array $row, ?int $now = null): string
    {
        if (($row['status'] ?? '') === 'published') {
            return $row['published_at'] !== null && (int) $row['published_at'] <= ($now ?? time()) ? 'live' : 'scheduled';
        }
        return ($row['origin'] ?? '') === 'ai' ? 'held' : 'draft';
    }

    /** A time as London reads it: "Thursday 22 October 2026 at 14:30". */
    public static function london(int $ts): string
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('l j F Y \a\t H:i');
    }

    /** @return array{0: string, 1: list<int>} the SQL and parameters of a tab */
    private static function tabWhere(string $tab, int $now): array
    {
        return match ($tab) {
            'live' => ["a.status = 'published' AND a.published_at <= ?", [$now]],
            'scheduled' => ["a.status = 'published' AND a.published_at > ?", [$now]],
            'drafts' => ["a.status = 'draft'", []],
            'held' => ["a.status = 'draft' AND a.origin = 'ai'", []],
            default => ['1 = 1', []],
        };
    }

    private static function searchText(string $q): string
    {
        $q = trim((string) preg_replace('/\s+/u', ' ', $q));
        return mb_check_encoding($q, 'UTF-8') ? mb_substr($q, 0, 100) : '';
    }

    /**
     * Everything the editor shows besides the article itself: the values, their errors, the category order, the
     * follow-up choices and the words it uses.
     *
     * @param array<string, mixed>|null $article
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function editorData(?array $article, array $values, bool $kept): array
    {
        $copy = Content::get('news');
        $chosen = array_values(array_filter($values['categories'], static fn (mixed $c): bool => is_string($c) && Categories::has($c)));
        return [
            'article' => $article,
            'values' => $values,
            'errors' => $this->takeErrors(),
            'kept' => $kept,
            // The ticked categories come first, in their order, so the main one (the first ticked) stays first.
            'categoryOrder' => array_values(array_unique([...$chosen, ...Categories::KEYS])),
            'categories' => Categories::all(),
            'choices' => $this->followUpChoices($article !== null ? (int) $article['id'] : null, $values['follow_up_of']),
            'verdicts' => is_array($copy['verdicts'] ?? null) ? $copy['verdicts'] : [],
            'kinds' => is_array($copy['kinds'] ?? null) ? $copy['kinds'] : array_combine(Draft::KINDS, array_map('ucfirst', Draft::KINDS)),
            'sections' => is_array($copy['sections'] ?? null) ? $copy['sections'] : [],
            'deskByline' => Draft::deskByline(),
            'spare' => self::SPARE_ROWS,
        ];
    }

    /**
     * The form's fields as the editor holds them: texts, rows of the three lists, the ticked categories, the chosen
     * follow-ups, the ticks, the schedule and the version of the article it was opened on (loaded_at).
     *
     * @return array<string, mixed>
     */
    private static function formValues(Request $request): array
    {
        $text = static fn (string $key): string => mb_substr($request->input($key), 0, self::KEEP_MAX);
        $rows = static function (mixed $list, array $fields): array {
            $out = [];
            foreach (is_array($list) ? array_slice($list, 0, self::ROWS_MAX) : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $r = [];
                foreach ($fields as $f) {
                    $r[$f] = is_string($row[$f] ?? null) ? mb_substr($row[$f], 0, 2100) : '';
                }
                $out[] = $r;
            }
            return $out;
        };
        $strings = static fn (mixed $list): array => array_values(array_filter(is_array($list) ? $list : [], 'is_string'));
        $values = [];
        foreach (['title', 'seo_title', 'slug', 'summary', 'dek', 'body', 'impact', 'verdict', 'verdict_note', 'tags', 'byline', 'publish_date', 'publish_time'] as $k) {
            $values[$k] = $text($k);
        }
        $values['confirmed'] = $rows($request->post['confirmed'] ?? null, ['text', 'url']);
        $values['speculation'] = $rows($request->post['speculation'] ?? null, ['text', 'url']);
        $values['sources'] = $rows($request->post['sources'] ?? null, ['url', 'title', 'publisher', 'kind']);
        $values['categories'] = array_slice(array_values(array_unique($strings($request->post['categories'] ?? null))), 0, count(Categories::KEYS));
        $values['follow_up_of'] = array_slice(array_values(array_unique(array_map('intval', array_filter($strings($request->post['follow_up_of'] ?? null), 'ctype_digit')))), 0, self::FOLLOW_UP_CHOICES);
        $values['comments_open'] = $request->input('comments_open') === '1';
        $values['ai_written'] = $request->input('ai_written') === '1';
        $values['loaded_at'] = ctype_digit($request->input('loaded_at')) ? $request->input('loaded_at') : '';
        return $values;
    }

    /**
     * Every editor value, filled in where $values lacks it.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function withDefaults(array $values): array
    {
        $defaults = array_fill_keys(['title', 'seo_title', 'slug', 'summary', 'dek', 'body', 'impact', 'verdict', 'verdict_note', 'tags', 'byline', 'publish_date', 'publish_time', 'loaded_at'], '')
            + array_fill_keys(['confirmed', 'speculation', 'sources', 'categories', 'follow_up_of'], [])
            + ['comments_open' => true, 'ai_written' => false];
        $out = [];
        foreach ($defaults as $k => $default) {
            $v = $values[$k] ?? $default;
            $out[$k] = match (true) {
                is_bool($default) => (bool) $v,
                is_array($default) => is_array($v) ? array_values($v) : [],
                default => is_scalar($v) ? (string) $v : '',
            };
        }
        return $out;
    }

    /**
     * A stored article as the editor's values. An SEO title, meta description or address that is only what an empty
     * field becomes shows as empty, so it goes on following the headline, the standfirst and the words.
     *
     * @return array<string, mixed>
     */
    private static function valuesOf(Draft $d): array
    {
        $empty = clone $d;
        $empty->seoTitle = '';
        $empty->summary = '';
        $at = $d->status === 'published' && $d->publishAt !== null && $d->publishAt > time()
            ? (new \DateTimeImmutable('@' . $d->publishAt))->setTimezone(new \DateTimeZone(self::TIMEZONE))
            : null;
        return self::withDefaults([
            'title' => $d->title,
            'seo_title' => $d->seoTitle === $empty->seoTitleOrDefault() ? '' : $d->seoTitle,
            'slug' => $d->slug === null || Paths::isNumberedFrom($d->slug, $d->slugBase()) ? '' : $d->slug,
            'summary' => $d->summary === $empty->summaryOrDefault() ? '' : $d->summary,
            'dek' => $d->dek,
            'body' => $d->body,
            'confirmed' => $d->confirmed,
            'speculation' => $d->speculation,
            'impact' => $d->impact,
            'verdict' => $d->verdict,
            'verdict_note' => $d->verdictNote,
            'categories' => $d->categories,
            'tags' => implode(', ', $d->tags),
            'sources' => $d->sources,
            'follow_up_of' => array_map('intval', $d->followUps),
            'byline' => $d->byline,
            'comments_open' => $d->commentsOpen,
            'ai_written' => $d->aiWritten,
            'publish_date' => $at?->format('Y-m-d') ?? '',
            'publish_time' => $at?->format('H:i') ?? '',
        ]);
    }

    /**
     * The form as a draft, checked by the rules for $action: a draft's for saving one, the full rules for
     * publishing, scheduling, and saving an article that is published or scheduled. The third value is the schedule
     * time (null when not scheduling, false when it was refused, with its error on the result).
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed>|null $article
     * @return array{0: Draft, 1: Result, 2: int|false|null}
     */
    private function check(array $values, string $action, ?array $article): array
    {
        $draft = Draft::fromArray([
            'title' => $values['title'],
            'seo_title' => $values['seo_title'],
            'slug' => $values['slug'],
            'summary' => $values['summary'],
            'dek' => $values['dek'],
            'body' => $values['body'],
            'confirmed' => $values['confirmed'],
            'speculation' => $values['speculation'],
            'impact' => $values['impact'],
            'verdict' => $values['verdict'],
            'verdict_note' => $values['verdict_note'],
            'categories' => $values['categories'],
            'tags' => $values['tags'],
            'sources' => $values['sources'],
            'follow_up_of' => $values['follow_up_of'],
            'byline' => $values['byline'],
            'comments_open' => $values['comments_open'],
            'ai_written' => $values['ai_written'],
            'status' => 'draft',
        ], 'manual');
        if ($article !== null) {
            // What the editor does not show is the article's own: the desk's story key.
            $draft->storyKey = (string) ($article['story_key'] ?? '');
        }
        $full = in_array($action, ['publish', 'schedule'], true) || ($action === 'save' && ($article['status'] ?? '') === 'published');
        $settings = new Settings($this->app);
        $result = (new Validator(new ArticleStore($this->app)))->check($draft, $settings->context('manual', $full ? 'publish' : 'draft', $article !== null ? (int) $article['id'] : null));
        $at = null;
        if ($action === 'schedule') {
            [$at, $error] = self::scheduleTime((string) $values['publish_date'], (string) $values['publish_time'], time());
            if ($error !== null) {
                $result->error('publish_at', $error);
                $at = false;
            }
        }
        foreach ($result->rejections as $rejection) {
            $result->error('article', $rejection['detail']);
        }
        return [$draft, $result, $at];
    }

    /**
     * A schedule's date (Y-m-d) and time (H:i) in London as Unix seconds, or why it cannot be used.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private static function scheduleTime(string $date, string $time, int $now): array
    {
        $date = trim($date);
        $time = substr(trim($time), 0, 5);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            return [null, 'Enter the date and time to publish, in London time, for example ' . date('Y-m-d', $now + 86400) . ' and 09:00.'];
        }
        $zone = new \DateTimeZone(self::TIMEZONE);
        $when = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $zone);
        // A date that does not exist (31 June), or an hour the clocks skip, reads back differently.
        if ($when === false || $when->format('Y-m-d H:i') !== $date . ' ' . $time) {
            return [null, 'That date and time does not exist in London. Choose another.'];
        }
        $at = $when->getTimestamp();
        if ($at <= $now + 60) {
            return [null, 'Choose a time in the future, or use Publish now.'];
        }
        if ($at > $now + self::SCHEDULE_DAYS * 86400) {
            return [null, 'Choose a time within the next year.'];
        }
        return [$at, null];
    }

    /**
     * Back to the editor after a refused save, with what was typed kept in the session (the lists as the draft read
     * them, so each error's row number is the row shown) and the errors.
     *
     * @param array<string, mixed> $values
     */
    private function keep(string $path, ?int $id, array $values, Draft $draft, Result $result): Response
    {
        $values['confirmed'] = $draft->confirmed;
        $values['speculation'] = $draft->speculation;
        $values['sources'] = $draft->sources;
        $session = $this->app->session();
        $session->set(self::draftKey($id), $values);
        $session->set('_errors', $result->errors);
        $fields = array_diff(array_keys($result->errors), ['article']);
        $session->flash('danger', $fields !== [] ? self::CHECK_FIELDS : (string) ($result->errors['article'] ?? self::CHECK_FIELDS));
        return Response::redirect($path . '#error-summary', 303);
    }

    /**
     * Back to the editor after a save from a form opened before the article's last change: nothing of it is written,
     * published or scheduled, and what was typed is kept with the version it was opened on, so it stays refused.
     *
     * @param array<string, mixed> $values
     */
    private function conflict(string $path, int $id, array $values, Draft $draft): Response
    {
        $result = new Result($draft);
        $result->error('article', self::CHANGED_SINCE);
        return $this->keep($path, $id, $values, $draft, $result);
    }

    /** @return array<string, mixed>|null the editor's kept copy after a refused save, taken once */
    private function takeKept(?int $id): ?array
    {
        $session = $this->app->session();
        $kept = $session->get(self::draftKey($id));
        $session->forget(self::draftKey($id));
        return is_array($kept) ? self::withDefaults($kept) : null;
    }

    private static function draftKey(?int $id): string
    {
        return 'news_draft.' . ($id ?? 'new');
    }

    /** The validator's warnings (dashes replaced, a banned topic mentioned) as warning flashes. */
    private function flashWarnings(Result $result): void
    {
        foreach (array_unique($result->warnings) as $warning) {
            $this->app->session()->flash('warning', $warning);
        }
    }

    /**
     * An image form's errors under its own names (image.new.alt, image.12.credit), so each form shows only its own.
     *
     * @param array<string, string> $errors
     * @return array<string, string>
     */
    private static function scoped(string $form, array $errors): array
    {
        $out = [];
        foreach ($errors as $field => $message) {
            $out['image.' . $form . '.' . $field] = $message;
        }
        return $out;
    }

    /**
     * The articles the follow-up list offers: the newest, and any already chosen, never the article itself.
     *
     * @param list<int> $selected
     * @return array<int, string> id => label
     */
    private function followUpChoices(?int $exceptId, array $selected): array
    {
        $db = $this->app->db();
        $rows = $db->all('SELECT id, title, status, published_at, created_at FROM news_articles WHERE id <> ? ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT ' . self::FOLLOW_UP_CHOICES, [$exceptId ?? 0]);
        $missing = array_values(array_diff(array_map('intval', $selected), array_map('intval', array_column($rows, 'id')), [$exceptId ?? 0]));
        if ($missing !== []) {
            $rows = array_merge($rows, $db->all('SELECT id, title, status, published_at, created_at FROM news_articles WHERE id IN (' . implode(', ', array_fill(0, count($missing), '?')) . ')', $missing));
        }
        $out = [];
        $now = time();
        foreach ($rows as $r) {
            $state = self::state($r + ['origin' => ''], $now);
            $out[(int) $r['id']] = (string) $r['title'] . ' (' . date_short((int) ($r['published_at'] ?? $r['created_at'])) . ($state !== 'live' ? ', ' . ($state === 'scheduled' ? 'scheduled' : 'draft') : '') . ')';
        }
        return $out;
    }

    /**
     * The article's links with their clicks, in all and over the last 30 days (UTC days, today included).
     *
     * @return list<array<string, mixed>>
     */
    private function linkClicks(ArticleStore $store, int $id): array
    {
        $links = $store->links($id);
        if ($links === []) {
            return [];
        }
        $ids = array_map(static fn (array $l): int => (int) $l['id'], $links);
        $recent = [];
        foreach ($this->app->db()->all(
            'SELECT link_id, SUM(clicks) AS n FROM news_link_clicks WHERE day >= ? AND link_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') GROUP BY link_id',
            [gmdate('Y-m-d', time() - 29 * 86400), ...$ids]
        ) as $row) {
            $recent[(int) $row['link_id']] = (int) $row['n'];
        }
        return array_map(static fn (array $l): array => $l + ['recent' => $recent[(int) $l['id']] ?? 0], $links);
    }

    /**
     * Deletes the article after its confirm box was ticked, with its images, links and comments.
     *
     * @param array<string, mixed> $article
     */
    private function delete(Request $request, ArticleStore $store, array $article, int $adminId): Response
    {
        $id = (int) $article['id'];
        if ($request->input('confirm') !== '1') {
            return $this->backWithErrors('/portal/admin/news/' . $id . '/#delete-summary', $request, ['confirm' => 'Tick the box to confirm you want to delete this article.'], 'The article was not deleted.');
        }
        $state = self::state($article);
        $images = count($store->images($id));
        $row = $store->delete($id);
        if ($row === null) {
            return $this->redirect('/portal/admin/news/', 'info', 'That article was already deleted.');
        }
        $this->app->session()->forget(self::draftKey($id));
        // The row is gone, so the audit keeps enough to say what it was.
        Audit::log($this->app, $adminId, 'news.deleted', (string) $id, [
            'title' => (string) $row['title'],
            'slug' => (string) $row['slug'],
            'state' => $state,
            'origin' => (string) $row['origin'],
            'images' => $images,
            'comments' => (int) $row['comment_count'],
        ]);
        return $this->redirect('/portal/admin/news/', 'success', '“' . $row['title'] . '” is deleted.' . ($state === 'live' ? ' ' . AdminNewsCommentController::TAKEDOWN : ''));
    }

    /** @return array<string, mixed> the signed-in administrator (the middleware guarantees one) */
    private function currentUser(): array
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            throw new \LogicException('Admin pages need the auth middleware');
        }
        return $user;
    }
}
