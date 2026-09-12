<?php
declare(strict_types=1);

function test_dashboard_splits_current_and_former_members(): void
{
    $store = fixture_store();
    $q = new Queries($store->pdo());
    $d = $q->dashboard('JUQRRL8');

    $memberCount = count(Parser::parseMembers(fixture('members')));
    assert_same($memberCount, count($d['current']), 'every current member listed');
    assert_same('Adults Only', $d['clan']['name']);
    assert_same(11, count($d['weeks']));

    $currentTags = array_column($d['current'], 'tag');
    $formerTags = array_column($d['former'], 'tag');
    assert_same([], array_values(array_intersect($currentTags, $formerTags)), 'no overlap');
    assert_true(count($formerTags) > 0, 'fixture has people who left');

    // Everyone in participants is in exactly one of the two lists.
    $allParticipants = $store->pdo()->query('SELECT DISTINCT player_tag FROM participants')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($allParticipants as $tag) {
        assert_true(in_array($tag, $currentTags, true) || in_array($tag, $formerTags, true), "$tag is listed");
    }
}

function test_dashboard_current_sorted_by_last_final_week_fame(): void
{
    $q = new Queries(fixture_store()->pdo());
    $d = $q->dashboard('JUQRRL8');
    $fames = array_map(fn ($m) => $m['lastFame'] ?? -1, $d['current']);
    $sorted = $fames;
    rsort($sorted);
    assert_same($sorted, $fames, 'descending by last fame');
}

function test_dashboard_weeks_flag_in_progress(): void
{
    $q = new Queries(fixture_store()->pdo());
    $weeks = $q->weeks('JUQRRL8');
    $last = end($weeks);
    assert_same(true, $last['provisional']);
    assert_same('S136 W1', $last['label']);
    assert_same(false, $weeks[0]['provisional']);
}

function test_player_history_lists_weeks_with_clan_name(): void
{
    $store = fixture_store();
    $q = new Queries($store->pdo());
    $tag = $store->pdo()->query('SELECT player_tag FROM participants GROUP BY player_tag ORDER BY COUNT(*) DESC LIMIT 1')->fetchColumn();
    $history = $q->playerHistory((string) $tag);
    assert_same(11, count($history));
    assert_same('Adults Only', $history[0]['clanName']);
    assert_same('S133 W5', $history[0]['label']);
    assert_true(is_int($history[0]['fame']));
}

function test_player_lookup_for_unknown_tag_is_empty(): void
{
    $q = new Queries(fixture_store()->pdo());
    assert_same([], $q->playerHistory('NOPE'));
    assert_same(null, $q->player('NOPE'));
}
