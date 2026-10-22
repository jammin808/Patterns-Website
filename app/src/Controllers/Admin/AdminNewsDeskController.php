<?php

declare(strict_types=1);

namespace Psc\Controllers\Admin;

use Psc\Controllers\Controller;
use Psc\Http\Request;
use Psc\Http\Response;

/** The Industry Updates AI desk, for administrators: its state and runs, settings, calendar and prompts. */
final class AdminNewsDeskController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->notFound();
    }

    public function control(Request $request): Response
    {
        return $this->notFound();
    }

    public function settings(Request $request): Response
    {
        return $this->notFound();
    }

    public function saveSettings(Request $request): Response
    {
        return $this->notFound();
    }

    public function calendar(Request $request): Response
    {
        return $this->notFound();
    }

    public function saveCalendar(Request $request): Response
    {
        return $this->notFound();
    }

    public function prompt(Request $request): Response
    {
        return $this->notFound();
    }

    public function savePrompt(Request $request): Response
    {
        return $this->notFound();
    }

    public function run(Request $request): Response
    {
        return $this->notFound();
    }
}
