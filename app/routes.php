<?php

declare(strict_types=1);

/*
 * The route table. Public pages come from content/pages.php and render without the database or a session.
 * Portal routes carry: session (cookie), csrf (on POST), auth/admin, nostore. JSON endpoints carry api.
 */

use Psc\App;
use Psc\Content\Content;
use Psc\Controllers\ChatController;
use Psc\Controllers\ContactController;
use Psc\Controllers\NewsCommentController;
use Psc\Controllers\NewsController;
use Psc\Controllers\NewsMediaController;
use Psc\Controllers\PageController;
use Psc\Controllers\SeoController;
use Psc\Controllers\Portal\AccountController;
use Psc\Controllers\Portal\AuthController;
use Psc\Controllers\Portal\DashboardController;
use Psc\Controllers\Portal\DownloadController;
use Psc\Controllers\Portal\LicenceController;
use Psc\Controllers\Portal\TicketController;
use Psc\Controllers\Admin\AdminController;
use Psc\Controllers\Admin\AdminLicenceController;
use Psc\Controllers\Admin\AdminNewsCommentController;
use Psc\Controllers\Admin\AdminNewsController;
use Psc\Controllers\Admin\AdminNewsDeskController;
use Psc\Controllers\Admin\AdminReleaseController;
use Psc\Controllers\Admin\AdminTicketController;
use Psc\Controllers\Admin\AdminUserController;
use Psc\Http\Router;
use Psc\News\Paths;

return static function (Router $r, App $app): void {
    // Machine-readable files.
    $r->get('/sitemap.xml', [SeoController::class, 'sitemap']);
    $r->get('/robots.txt', [SeoController::class, 'robots']);
    $r->get('/llms.txt', [SeoController::class, 'llms']);
    $r->get('/manifest.webmanifest', [SeoController::class, 'manifest']);
    $r->get('/.well-known/security.txt', [SeoController::class, 'security']);
    $r->get('/health', [SeoController::class, 'health']);

    // Contact (a form, so it carries a session for its CSRF token).
    $r->get('/contact/', [ContactController::class, 'show'], ['session', 'nostore']);
    $r->post('/contact/', [ContactController::class, 'submit'], ['session', 'csrf', 'nostore']);

    // The support assistant.
    $r->post('/api/chat', [ChatController::class, 'message'], ['api', 'session', 'nostore']);
    $r->post('/api/chat/reset', [ChatController::class, 'reset'], ['api', 'session', 'nostore']);
    $r->post('/api/chat/feedback', [ChatController::class, 'feedback'], ['api', 'session', 'nostore']);

    $ad = ['session', 'auth', 'admin', 'nostore'];
    $adPost = ['session', 'csrf', 'auth', 'admin', 'nostore'];

    // Industry Updates (docs/NEWS.md §5), one silo under /news/: the hub and its pages, the section's feed and
    // sitemaps, the old category address, the tracked links and the media first, then each category's hub, pages and
    // feed, then the articles under their category and the lasting /news/{slug}/ (which answers 301 to where its
    // category puts it). The first match wins, so every word these use is a reserved slug (Paths::RESERVED), and
    // {category} is only ever one of the fourteen keys. Its pages are the same for every visitor: no session, no
    // cookie, except the comment page and the API, which carry their own; news-address sends a comment page asked
    // for at an old address to its own before any session starts. /news/ is in the registry for its words.
    $slug = '{slug:[a-z0-9-]+}';
    $category = '{category:' . Paths::categoryPattern() . '}';
    $page = '{page:[1-9][0-9]{0,5}}';
    $media = '{file:[a-f0-9]{32}-[a-z0-9]+\.(?:webp|jpg)}';
    $r->get('/news/', [NewsController::class, 'index']);
    $r->get('/news/page/' . $page . '/', [NewsController::class, 'index']);
    $r->get('/news/feed.xml', [NewsController::class, 'feed']);
    $r->get('/news/sitemap.xml', [SeoController::class, 'newsSitemap']);
    $r->get('/news/sitemap-news.xml', [SeoController::class, 'newsSitemapRecent']);
    $r->get('/news/sitemap-{month:[0-9]{4}-(?:0[1-9]|1[0-2])}.xml', [SeoController::class, 'newsSitemapMonth']);
    $r->get('/news/category/{key:[a-z]+}/', [NewsController::class, 'oldCategory']);
    $r->get('/news/go/{id:\d+}/', [NewsController::class, 'go'], ['nostore']);
    $r->get('/news/media/' . $media, [NewsMediaController::class, 'public']);
    $r->get('/news/' . $category . '/', [NewsController::class, 'category']);
    $r->get('/news/' . $category . '/page/' . $page . '/', [NewsController::class, 'category']);
    $r->get('/news/' . $category . '/feed.xml', [NewsController::class, 'feed']);
    foreach (['/news/' . $category . '/' . $slug . '/', '/news/' . $slug . '/'] as $article) {
        $r->get($article . 'comment/', [NewsCommentController::class, 'show'], ['news-address', 'session', 'auth', 'nostore']);
        $r->post($article . 'comment/', [NewsCommentController::class, 'submit'], ['news-address', 'session', 'csrf', 'auth', 'nostore']);
        $r->get($article, [NewsController::class, 'show']);
    }
    $r->get('/api/news/{id:\d+}/state', [NewsCommentController::class, 'state'], ['api', 'session', 'nostore']);
    $r->post('/api/news/{id:\d+}/comments', [NewsCommentController::class, 'create'], ['api', 'session', 'auth', 'nostore']);
    $r->post('/api/news/comments/{id:\d+}/delete', [NewsCommentController::class, 'delete'], ['api', 'session', 'auth', 'nostore']);
    $r->post('/api/news/comments/{id:\d+}/report', [NewsCommentController::class, 'report'], ['api', 'session', 'auth', 'nostore']);
    $r->post('/api/news/links/{id:\d+}/click', [NewsController::class, 'click'], ['api', 'nostore']);

    $r->get('/portal/admin/news/', [AdminNewsController::class, 'index'], $ad);
    $r->get('/portal/admin/news/new/', [AdminNewsController::class, 'create'], $ad);
    $r->post('/portal/admin/news/', [AdminNewsController::class, 'store'], $adPost);
    $r->get('/portal/admin/news/{id:\d+}/', [AdminNewsController::class, 'edit'], $ad);
    $r->post('/portal/admin/news/{id:\d+}/', [AdminNewsController::class, 'update'], $adPost);
    $r->post('/portal/admin/news/{id:\d+}/images/', [AdminNewsController::class, 'images'], $adPost);
    $r->get('/portal/admin/news/{id:\d+}/preview/', [AdminNewsController::class, 'preview'], $ad);
    $r->get('/portal/admin/news/media/' . $media, [NewsMediaController::class, 'admin'], $ad);
    $r->get('/portal/admin/news/comments/', [AdminNewsCommentController::class, 'index'], $ad);
    $r->post('/portal/admin/news/comments/{id:\d+}/', [AdminNewsCommentController::class, 'update'], $adPost);
    $r->get('/portal/admin/news/desk/', [AdminNewsDeskController::class, 'index'], $ad);
    $r->post('/portal/admin/news/desk/', [AdminNewsDeskController::class, 'control'], $adPost);
    $r->get('/portal/admin/news/desk/settings/', [AdminNewsDeskController::class, 'settings'], $ad);
    $r->post('/portal/admin/news/desk/settings/', [AdminNewsDeskController::class, 'saveSettings'], $adPost);
    $r->get('/portal/admin/news/desk/calendar/', [AdminNewsDeskController::class, 'calendar'], $ad);
    $r->post('/portal/admin/news/desk/calendar/', [AdminNewsDeskController::class, 'saveCalendar'], $adPost);
    $r->get('/portal/admin/news/desk/prompt/', [AdminNewsDeskController::class, 'prompt'], $ad);
    $r->post('/portal/admin/news/desk/prompt/', [AdminNewsDeskController::class, 'savePrompt'], $adPost);
    $r->get('/portal/admin/news/desk/runs/{id:\d+}/', [AdminNewsDeskController::class, 'run'], $ad);

    // Every public page in the registry (the contact page and the news index have their own controllers above).
    foreach (array_keys(Content::pages()) as $path) {
        if ($path === '/contact/' || $path === '/news/') {
            continue;
        }
        $r->get((string) $path, [PageController::class, 'show']);
    }

    // Client portal: signing in and out.
    $guest = ['session', 'guest', 'nostore'];
    $guestPost = ['session', 'csrf', 'guest', 'nostore'];
    $r->get('/portal/login/', [AuthController::class, 'loginForm'], $guest);
    $r->post('/portal/login/', [AuthController::class, 'login'], $guestPost);
    $r->get('/portal/register/', [AuthController::class, 'registerForm'], $guest);
    $r->post('/portal/register/', [AuthController::class, 'register'], $guestPost);
    $r->get('/portal/verify/', [AuthController::class, 'verify'], ['session', 'nostore']);
    $r->get('/portal/verify/resend/', [AuthController::class, 'resendForm'], $guest);
    $r->post('/portal/verify/resend/', [AuthController::class, 'resend'], $guestPost);
    $r->get('/portal/forgot/', [AuthController::class, 'forgotForm'], $guest);
    $r->post('/portal/forgot/', [AuthController::class, 'forgot'], $guestPost);
    $r->get('/portal/reset/', [AuthController::class, 'resetForm'], ['session', 'nostore']);
    $r->post('/portal/reset/', [AuthController::class, 'reset'], ['session', 'csrf', 'nostore']);
    $r->post('/portal/logout/', [AuthController::class, 'logout'], ['session', 'csrf', 'nostore']);

    // Client portal: signed in.
    $in = ['session', 'auth', 'nostore'];
    $inPost = ['session', 'csrf', 'auth', 'nostore'];
    $r->get('/portal/', [DashboardController::class, 'index'], $in);
    $r->get('/portal/account/', [AccountController::class, 'show'], $in);
    $r->post('/portal/account/profile/', [AccountController::class, 'profile'], $inPost);
    $r->post('/portal/account/password/', [AccountController::class, 'password'], $inPost);
    $r->post('/portal/account/delete/', [AccountController::class, 'delete'], $inPost);
    $r->get('/portal/account/export/', [AccountController::class, 'export'], $in);

    $r->get('/portal/downloads/', [DownloadController::class, 'index'], $in);
    $r->get('/portal/downloads/{id:\d+}/', [DownloadController::class, 'file'], $in);

    $r->get('/portal/licences/', [LicenceController::class, 'index'], $in);
    $r->get('/portal/licences/request/', [LicenceController::class, 'requestForm'], $in);
    $r->post('/portal/licences/request/', [LicenceController::class, 'request'], $inPost);
    $r->get('/portal/licences/{id:\d+}/', [LicenceController::class, 'show'], $in);
    $r->get('/portal/licences/{id:\d+}/files/{file:\d+}/', [LicenceController::class, 'file'], $in);

    $r->get('/portal/tickets/', [TicketController::class, 'index'], $in);
    $r->get('/portal/tickets/new/', [TicketController::class, 'create'], $in);
    $r->post('/portal/tickets/', [TicketController::class, 'store'], $inPost);
    $r->get('/portal/tickets/{id:\d+}/', [TicketController::class, 'show'], $in);
    $r->post('/portal/tickets/{id:\d+}/comments/', [TicketController::class, 'comment'], $inPost);
    $r->post('/portal/tickets/{id:\d+}/vote/', [TicketController::class, 'vote'], $inPost);
    $r->get('/portal/attachments/{id:\d+}/', [TicketController::class, 'attachment'], $in);
    $r->get('/portal/ideas/', [TicketController::class, 'board'], $in);

    // Administration.
    $r->get('/portal/admin/', [AdminController::class, 'index'], $ad);
    $r->get('/portal/admin/messages/', [AdminController::class, 'messages'], $ad);
    $r->post('/portal/admin/messages/{id:\d+}/', [AdminController::class, 'message'], $adPost);
    $r->get('/portal/admin/chats/', [AdminController::class, 'chats'], $ad);
    $r->get('/portal/admin/chats/{id:[a-f0-9]{32}}/', [AdminController::class, 'chat'], $ad);
    $r->get('/portal/admin/audit/', [AdminController::class, 'audit'], $ad);

    $r->get('/portal/admin/users/', [AdminUserController::class, 'index'], $ad);
    $r->post('/portal/admin/users/invite/', [AdminUserController::class, 'invite'], $adPost);
    $r->get('/portal/admin/users/{id:\d+}/', [AdminUserController::class, 'show'], $ad);
    $r->post('/portal/admin/users/{id:\d+}/', [AdminUserController::class, 'update'], $adPost);

    $r->get('/portal/admin/tickets/', [AdminTicketController::class, 'index'], $ad);
    $r->post('/portal/admin/tickets/{id:\d+}/', [AdminTicketController::class, 'update'], $adPost);

    $r->get('/portal/admin/releases/', [AdminReleaseController::class, 'index'], $ad);
    $r->get('/portal/admin/releases/new/', [AdminReleaseController::class, 'create'], $ad);
    $r->post('/portal/admin/releases/', [AdminReleaseController::class, 'store'], $adPost);
    $r->get('/portal/admin/releases/{id:\d+}/', [AdminReleaseController::class, 'edit'], $ad);
    $r->post('/portal/admin/releases/{id:\d+}/', [AdminReleaseController::class, 'update'], $adPost);

    $r->get('/portal/admin/licences/', [AdminLicenceController::class, 'index'], $ad);
    $r->get('/portal/admin/licences/{id:\d+}/', [AdminLicenceController::class, 'show'], $ad);
    $r->post('/portal/admin/licences/{id:\d+}/', [AdminLicenceController::class, 'update'], $adPost);
};
