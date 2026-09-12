<?php
// GET /api/lookup.php?tag=%23ABC123 - one player's war history.
//
// Shows what we already have stored, then asks the API for the player's
// current clan and pulls that clan's recent war weeks so a recruit can be
// vetted. Live calls are cached for 15 minutes per endpoint so a page full
// of curious clanmates cannot hammer the API.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

const LOOKUP_CACHE_SECONDS = 15 * 60;

$tag = Tag::normalize((string) ($_GET['tag'] ?? ''));
if (!Tag::isValid($tag)) {
    json_error('That does not look like a player tag. Tags use the characters 0 2 8 9 P Y L Q G R J C U V.', 400);
}

['config' => $config, 'pdo' => $pdo] = cwt_open();
$store = new Store($pdo);
$queries = new Queries($pdo);
$fetcher = new Fetcher(ApiClient::fromConfig($config), $store);

$notes = [];
$name = '';
$clan = null;
$found = true;

try {
    $player = $fetcher->fetchPlayer($tag, LOOKUP_CACHE_SECONDS);
    $name = $player['name'];
    $clan = $player['clan'];
    if ($clan === null) {
        $notes[] = 'This player is not in a clan right now, so only history stored here is shown.';
    } elseif ($clan['tag'] !== $config['clan_tag']) {
        try {
            $fetcher->fetchWarData($clan['tag'], LOOKUP_CACHE_SECONDS);
        } catch (ApiException $e) {
            $notes[] = 'Could not load war data for ' . $clan['name'] . ': ' . $e->getMessage();
        }
    }
} catch (ApiException $e) {
    if ($e->status === 404) {
        $found = false;
        $notes[] = 'No player exists with the tag ' . Tag::display($tag) . '.';
    } else {
        $notes[] = 'Could not reach the Clash Royale API: ' . $e->getMessage();
    }
}

$weeks = $queries->playerHistory($tag);
$clanTags = array_values(array_unique(array_column($weeks, 'clanTag')));
if ($clan !== null) {
    $clanTags[] = $clan['tag'];
}
$axis = $queries->weeksForClans(array_values(array_unique($clanTags)));
if ($name === '') {
    $name = $queries->player($tag)['name'] ?? '';
}
if ($found && $weeks === []) {
    $notes[] = 'No clan war data is available for this player yet.';
}

json_out([
    'tag' => $tag,
    'displayTag' => Tag::display($tag),
    'name' => $name,
    'found' => $found,
    'clan' => $clan,
    'ourClanTag' => $config['clan_tag'],
    'weeks' => $weeks,
    'axis' => $axis,
    'notes' => $notes,
    'maxFame' => Parser::MAX_WEEKLY_FAME,
]);
