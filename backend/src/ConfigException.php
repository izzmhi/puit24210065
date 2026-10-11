<?php

declare(strict_types=1);

namespace App;

/**
 * Raised when the server configuration is missing or unsafe.
 *
 * Messages name the offending setting but never contain its value, so they are
 * safe to write to the server log (they are still never sent to API clients).
 */
final class ConfigException extends \RuntimeException
{
}
