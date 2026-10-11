<?php

declare(strict_types=1);

/** Prints a random 64-character secret suitable for APP_PEPPER:  php bin/generate-secret.php */
echo bin2hex(random_bytes(32)), PHP_EOL;
