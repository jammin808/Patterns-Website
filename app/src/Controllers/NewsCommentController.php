<?php

declare(strict_types=1);

namespace Psc\Controllers;

use Psc\Http\Request;
use Psc\Http\Response;

/**
 * Industry Updates' comments: the JSON API news.js uses, and the comment page with its forms for readers without
 * scripting (show, not page: Controller::page() renders a template).
 */
final class NewsCommentController extends Controller
{
    public function show(Request $request): Response
    {
        return $this->notFound();
    }

    public function submit(Request $request): Response
    {
        return $this->notFound();
    }

    public function state(Request $request): Response
    {
        return $this->notFound();
    }

    public function create(Request $request): Response
    {
        return $this->notFound();
    }

    public function delete(Request $request): Response
    {
        return $this->notFound();
    }

    public function report(Request $request): Response
    {
        return $this->notFound();
    }
}
