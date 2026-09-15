<?php
// GET /api/lookup.php?tag=%23ABC123 - one player's war history.
//
// Shows what we already have stored, then asks the API for the player's
// current clan and pulls that clan's recent war weeks so a recruit can be
// vetted. The profile only names the current clan, so for someone who just
// joined us the battle log is scanned for clans they fought for recently and
// those clans' war logs are pulled too.
// Live calls are cached for 15 minutes per endpoint so a page full of
// curious clanmates cannot hammer the API.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

const LOOKUP_CACHE_SECONDS = 15 * 60;
// Battle logs are short, but cap the extra clan downloads anyway.
const LOOKUP_MAX_EXTRA_CLANS = 3;

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
        $notes[] = 'This player is not in a clan right now.';
    }

    // Clans whose war logs to pull: the current clan, then anything the
    // battle log shows them fighting for.
    $clansToFetch = [];
    if ($clan !== null) {
        $clansToFetch[$clan['tag']] = $clan['name'];
    }
    try {
        foreach ($fetcher->fetchRecentClans($tag, LOOKUP_CACHE_SECONDS) as $recent) {
            if (count($clansToFetch) >= LOOKUP_MAX_EXTRA_CLANS + 1) {
                break;
            }
            $clansToFetch[$recent['tag']] ??= $recent['name'];
        }
    } catch (ApiException $e) {
        $notes[] = 'Could not read the battle log: ' . $e->getMessage();
    }

    foreach ($clansToFetch as $clanTag => $clanName) {
        try {
            $fetcher->fetchWarData((string) $clanTag, LOOKUP_CACHE_SECONDS);
        } catch (ApiException $e) {
            $notes[] = 'Could not load war data for ' . $clanName . ': ' . $e->getMessage();
        }
    }

    $previous = array_keys(array_diff_key($clansToFetch, $clan !== null ? [$clan['tag'] => 1] : []));
    if ($previous !== []) {
        $names = array_map(static fn ($t) => $clansToFetch[$t] !== '' ? $clansToFetch[$t] : Tag::display((string) $t), $previous);
        $notes[] = 'Also checked ' . implode(', ', $names) . ' from their recent battles.';
    } elseif ($clan !== null && $clan['tag'] === $config['clan_tag']) {
        $notes[] = 'No other clan found in their recent battles.';
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
// Axis covers every clan the player has weeks in, plus their current clan, so
// skipped weeks show as gaps rather than vanishing.
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
