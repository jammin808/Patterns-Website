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
use Psc\Controllers\Admin\AdminReleaseController;
use Psc\Controllers\Admin\AdminTicketController;
use Psc\Controllers\Admin\AdminUserController;
use Psc\Http\Router;

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

    // Every public page in the registry.
    foreach (array_keys(Content::pages()) as $path) {
        if ($path === '/contact/') {
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
    $ad = ['session', 'auth', 'admin', 'nostore'];
    $adPost = ['session', 'csrf', 'auth', 'admin', 'nostore'];
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
