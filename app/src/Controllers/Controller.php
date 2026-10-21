<?php

declare(strict_types=1);

namespace Psc\Controllers;

use Psc\App;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\View\Schema;
use Psc\View\Seo;

/** Shared helpers for controllers: rendering in the site or portal layout, redirects with flash messages. */
abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }

    /** @param array<string, mixed> $data */
    protected function page(string $template, array $data, Seo $seo, string $layout = 'layouts/base', int $status = 200): Response
    {
        $session = $this->app->session();
        $data += [
            'seo' => $seo,
            'section' => null,
            'flashes' => $session->isStarted() ? $session->takeFlashes() : [],
            'user' => $session->isStarted() ? $this->app->auth()->user() : null,
        ];
        // Partials (the FAQ accordion, for one) add their structured data to the page's Seo.
        $this->app->view()->share('seo', $data['seo']);
        return Response::html($this->app->view()->page($template, $data, $layout), $status);
    }

    /** Every portal page's description: true of each of them, signed in or not, and 50 to 160 characters long. */
    public const PORTAL_DESCRIPTION = 'The client portal for Patterns Show Control: downloads, licence files, bug reports and feature requests.';

    /**
     * A portal page: the portal layout, never indexed.
     *
     * @param array<string, mixed> $data
     */
    protected function portal(string $template, array $data, string $title, int $status = 200): Response
    {
        $req = $this->app->request();
        $seo = $this->app->seo($title . ' | Client portal | ' . $this->app->name(), self::PORTAL_DESCRIPTION, $req?->path ?? '/portal/')->noindex();
        return $this->page($template, $data + ['title' => $title, 'section' => 'portal'], $seo, 'layouts/portal', $status);
    }

    /** The default graph every public page carries: the organisation, its founder and the website. */
    protected function baseGraph(Seo $seo): Seo
    {
        $base = $this->app->baseUrl();
        $seo->add(Schema::organization($base, $this->app->name(), $this->app->config->string('app.support_email')));
        $seo->add(Schema::person($base, $this->app->config->string('app.publisher')));
        $seo->add(Schema::website($base, $this->app->name(), 'Show control software for live events: Patterns, StageWatch, AGMessage and PlayMap.'));
        return $seo;
    }

    protected function redirect(string $to, ?string $flashType = null, ?string $message = null): Response
    {
        if ($flashType !== null && $message !== null) {
            $this->app->session()->flash($flashType, $message);
        }
        return Response::redirect($to, 303);
    }

    /** Back to the form after a validation failure, with the input and errors kept for one page view. */
    protected function backWithErrors(string $to, Request $request, array $errors, string $message = 'Please check the highlighted fields.'): Response
    {
        $session = $this->app->session();
        $session->keepOld($request->post);
        $session->set('_errors', $errors);
        $session->flash('danger', $message);
        return Response::redirect($to, 303);
    }

    /** @return array<string, string> errors kept by backWithErrors() */
    protected function takeErrors(): array
    {
        $errors = $this->app->session()->get('_errors', []);
        $this->app->session()->forget('_errors');
        return is_array($errors) ? $errors : [];
    }

    protected function notFound(): Response
    {
        $req = $this->app->request() ?? new Request('GET', '/');
        return $this->app->errorResponse($req, 404);
    }

    protected function forbidden(): Response
    {
        $req = $this->app->request() ?? new Request('GET', '/');
        return $this->app->errorResponse($req, 403);
    }

    /**
     * A same-site relative path from a ?next= value, or the fallback (no open redirects). The value is judged as
     * sent and once decoded, because the page it leads to decodes its own path: an encoded backslash or a second
     * slash must not become a protocol-relative address one hop later.
     */
    protected function safeNext(string $next, string $fallback = '/portal/'): string
    {
        foreach ([$next, rawurldecode($next)] as $form) {
            if ($form === '' || !str_starts_with($form, '/') || str_starts_with($form, '//') || str_contains($form, '\\') || preg_match('/[\x00-\x1f\x7f]/', $form)) {
                return $fallback;
            }
        }
        return $next;
    }
}
