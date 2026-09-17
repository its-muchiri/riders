<?php

/**
 * Vercel's PHP runtime requires function files to live under api/ — this is
 * a thin shim so the real front controller stays at public/index.php
 * (unchanged for local `php -S` / Apache use). __DIR__ inside the required
 * file still resolves to public/, so its existing relative paths
 * (../vendor/autoload.php, ../.env, ../routes/*.php) are unaffected.
 */
require __DIR__ . '/../public/index.php';
