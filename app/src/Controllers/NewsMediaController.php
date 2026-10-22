<?php

declare(strict_types=1);

namespace Psc\Controllers;

use Psc\Http\Request;
use Psc\Http\Response;

/** Industry Updates' article images: /news/media/{file} for live articles, and the admin's preview copies of any. */
final class NewsMediaController extends Controller
{
    public function public(Request $request): Response
    {
        return $this->notFound();
    }

    public function admin(Request $request): Response
    {
        return $this->notFound();
    }
}
