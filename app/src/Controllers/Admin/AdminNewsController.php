<?php

declare(strict_types=1);

namespace Psc\Controllers\Admin;

use Psc\Controllers\Controller;
use Psc\Http\Request;
use Psc\Http\Response;

/** Industry Updates articles, for administrators: the list, the editor, publishing, images and the preview. */
final class AdminNewsController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->notFound();
    }

    public function create(Request $request): Response
    {
        return $this->notFound();
    }

    public function store(Request $request): Response
    {
        return $this->notFound();
    }

    public function edit(Request $request): Response
    {
        return $this->notFound();
    }

    public function update(Request $request): Response
    {
        return $this->notFound();
    }

    public function images(Request $request): Response
    {
        return $this->notFound();
    }

    public function preview(Request $request): Response
    {
        return $this->notFound();
    }
}
