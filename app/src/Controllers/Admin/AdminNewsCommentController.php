<?php

declare(strict_types=1);

namespace Psc\Controllers\Admin;

use Psc\Controllers\Controller;
use Psc\Http\Request;
use Psc\Http\Response;

/** Moderation of Industry Updates comments, for administrators: reported, pending, visible and hidden. */
final class AdminNewsCommentController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->notFound();
    }

    public function update(Request $request): Response
    {
        return $this->notFound();
    }
}
