<?php
// Shared setup for the JSON endpoints. Finds app/ whether we are running from
// the repo (public/api -> app) or from the host (public_html/clan/api -> app
// one level above public_html), loads config, and provides JSON helpers.
declare(strict_types=1);

// Walk upward from this file looking for app/ either directly (repo layout:
// public/api -> app) or inside a clan_war_tracker/ folder (host layout:
// public_html/danteachesmath/clan/api -> /home/USER/clan_war_tracker/app).
$appDir = null;
$env = getenv('CWT_APP_DIR');
if (is_string($env) && $env !== '' && is_file($env . '/bootstrap.php')) {
    $appDir = $env;
} else {
    $dir = __DIR__;
    for ($i = 0; $i < 7 && $appDir === null; $i++) {
        $dir = dirname($dir);
        foreach ([$dir . '/app', $dir . '/clan_war_tracker/app'] as $candidate) {
            if (is_file($candidate . '/bootstrap.php')) {
                $appDir = $candidate;
                break;
            }
        }
    }
}
if ($appDir === null) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Cannot locate app directory. Set CWT_APP_DIR.']);
    exit;
}
require $appDir . '/bootstrap.php';

function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 500): never
{
    json_out(['error' => $message], $status);
}

set_exception_handler(static function (Throwable $e): void {
    error_log('clan_war_tracker: ' . $e->getMessage());
    json_error('Server error: ' . $e->getMessage(), 500);
});

/** @return array{config: array<string, mixed>, pdo: PDO} */
function cwt_open(): array
{
    $config = Config::load();
    $pdo = Db::open((string) $config['db_path']);
    return ['config' => $config, 'pdo' => $pdo];
}
