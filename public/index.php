<?php

declare(strict_types=1);

/*
 * Front controller. Every request that is not a real file under public/ arrives here.
 * The application lives one level up; nothing outside public/ is web-reachable.
 */

$app = require dirname(__DIR__) . '/app/bootstrap.php';
$app->handle(Psc\Http\Request::fromGlobals())->send();
$app->finish();
