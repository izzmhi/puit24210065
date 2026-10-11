<?php

declare(strict_types=1);

// Front controller: every /api/... request ends up here.
require dirname(__DIR__) . '/src/bootstrap.php';

\App\Application::respond()->emit();
