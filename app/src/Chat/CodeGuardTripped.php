<?php

declare(strict_types=1);

namespace Psc\Chat;

/** Thrown from the stream callback when the answer starts to contain code or commands. */
final class CodeGuardTripped extends \RuntimeException
{
}
