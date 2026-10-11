<?php

// Entry point used only on Vercel (vercel-php runtime). Every /api/... request is sent here
// and handled by the same front controller used locally. Settings come from the
// Environment Variables of the Vercel project instead of backend/.env.
require __DIR__ . '/../backend/public/index.php';
