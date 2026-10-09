<?php

// Vercel entrypoint: every request is routed here (vercel.json) and handed to Laravel's front controller.
// Pretend the request came through public/index.php; otherwise Laravel treats "/api" (this file's folder)
// as the base path and strips it, turning /api/login into /login.
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/../public/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require __DIR__ . '/../public/index.php';
