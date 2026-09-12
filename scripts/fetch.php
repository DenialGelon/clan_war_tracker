<?php
// Downloads the clan roster and war data and stores everything. Run by cron
// on the host and by hand during development.
//
// Usage: php scripts/fetch.php
//        CWT_CONFIG=/path/to/config.php php scripts/fetch.php

declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

try {
    $config = Config::load();
    $api = ApiClient::fromConfig($config);
    $store = new Store(Db::open((string) $config['db_path']));
    $fetcher = new Fetcher($api, $store);
    $clanTag = $config['clan_tag'];

    $mode = $config['use_fixtures'] ? 'fixtures' : 'live API';
    echo Store::now() . " fetching " . Tag::display($clanTag) . " from $mode\n";

    $members = $fetcher->fetchMembers($clanTag);
    echo "  members: {$members['members']}\n";

    $war = $fetcher->fetchWarData($clanTag);
    echo "  log weeks: {$war['log_weeks']}, in-progress week " . ($war['current_saved'] ? 'saved' : 'skipped') . "\n";

    $q = new Queries($store->pdo());
    $weeks = $q->weeks($clanTag);
    echo "  weeks stored in total: " . count($weeks) . "\n";
    exit(0);
} catch (ApiException $e) {
    fwrite(STDERR, "API error: " . $e->getMessage() . "\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
