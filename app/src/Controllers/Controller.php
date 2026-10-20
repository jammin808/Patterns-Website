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
        return Response::html($this->app->view()->page($template, $data, $layout), $status);
    }

    /**
     * A portal page: the portal layout, never indexed.
     *
     * @param array<string, mixed> $data
     */
    protected function portal(string $template, array $data, string $title, int $status = 200): Response
    {
        $req = $this->app->request();
        $seo = $this->app->seo($title . ' | Client portal | ' . $this->app->name(), 'The Patterns Show Control client portal.', $req?->path ?? '/portal/')->noindex();
        return $this->page($template, $data + ['title' => $title, 'section' => 'portal'], $seo, 'layouts/portal', $status);
    }

    /** The default graph every public page carries: the organisation and the website. */
    protected function baseGraph(Seo $seo): Seo
    {
        $base = $this->app->baseUrl();
        $seo->add(Schema::organization($base, $this->app->name(), $this->app->config->string('app.publisher'), $this->app->config->string('app.support_email')));
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

    /** A same-site relative path from a ?next= value, or the fallback (no open redirects). */
    protected function safeNext(string $next, string $fallback = '/portal/'): string
    {
        if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, '\\') || preg_match('/[\x00-\x1f]/', $next)) {
            return $fallback;
        }
        return $next;
    }
}
