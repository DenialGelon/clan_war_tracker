<?php
// Entry point for everything in app/. Include this once from scripts and endpoints.
// Loads config, registers the small set of classes, and sets sane error reporting.

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');
date_default_timezone_set('UTC');

require_once __DIR__ . '/Tag.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/ApiClient.php';
require_once __DIR__ . '/Parser.php';
require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/Fetcher.php';
require_once __DIR__ . '/Queries.php';
