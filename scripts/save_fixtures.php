<?php
// One-off helper: saves live API responses to tests/fixtures/ so parser, DB,
// and UI work can run offline. Uses the first clan member as the sample player.
//
// Usage: php scripts/save_fixtures.php

declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$config = Config::load();
$config['use_fixtures'] = false; // always hit the live API here
$api = ApiClient::fromConfig($config);
$clanTag = $config['clan_tag'];
$dir = __DIR__ . '/../tests/fixtures';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

$paths = [
    'clan.json' => ApiClient::clanPath($clanTag),
    'members.json' => ApiClient::membersPath($clanTag),
    'currentriverrace.json' => ApiClient::currentRiverRacePath($clanTag),
    'riverracelog.json' => ApiClient::riverRaceLogPath($clanTag),
];

$members = null;
foreach ($paths as $file => $path) {
    $raw = $api->getRaw($path);
    file_put_contents("$dir/$file", $raw . "\n");
    echo "Saved $file (" . strlen($raw) . " bytes)\n";
    if ($file === 'members.json') {
        $members = json_decode($raw, true);
    }
}

$sampleTag = $members['items'][0]['tag'] ?? null;
if ($sampleTag === null) {
    echo "No members found; skipping player.json\n";
    exit(0);
}
$raw = $api->getRaw(ApiClient::playerPath(Tag::normalize($sampleTag)));
file_put_contents("$dir/player.json", $raw . "\n");
echo "Saved player.json for $sampleTag (" . strlen($raw) . " bytes)\n";
$raw = $api->getRaw(ApiClient::battleLogPath(Tag::normalize($sampleTag)));
file_put_contents("$dir/battlelog.json", $raw . "\n");
echo "Saved battlelog.json for $sampleTag (" . strlen($raw) . " bytes)\n";
