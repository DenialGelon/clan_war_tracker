<?php
// GET /api/dashboard.php - clan, weeks, current members, former members.
// Reads the database only; never calls the Supercell API.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

['config' => $config, 'pdo' => $pdo] = cwt_open();
$queries = new Queries($pdo);
$payload = $queries->dashboard($config['clan_tag']);
$payload['maxFame'] = Parser::MAX_WEEKLY_FAME;
$payload['generatedAt'] = Store::now();
json_out($payload);
