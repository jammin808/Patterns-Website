<?php

declare(strict_types=1);

namespace Psc\Chat;

/** Thrown from a stream callback when the visitor has gone, so the model call stops spending tokens. */
final class StreamAborted extends \RuntimeException
{
}
