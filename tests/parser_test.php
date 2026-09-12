<?php
declare(strict_types=1);

function test_parse_members_reads_fixture(): void
{
    $members = Parser::parseMembers(fixture('members'));
    assert_true(count($members) > 0, 'has members');
    $first = $members[0];
    assert_same('RG0GUVJG', $first['tag']);
    assert_same('coLeader', $first['role']);
    assert_true($first['name'] !== '');
    assert_true(str_starts_with((string) $first['last_seen'], '2026'));
}

function test_parse_river_race_log_keeps_only_our_clan(): void
{
    $weeks = Parser::parseRiverRaceLog(fixture('riverracelog'), 'JUQRRL8');
    assert_same(10, count($weeks), 'API returns ten weeks');
    foreach ($weeks as $week) {
        assert_same('JUQRRL8', $week['clan_tag']);
        assert_same(false, $week['provisional']);
        assert_true($week['clan_rank'] >= 1 && $week['clan_rank'] <= 5, 'rank in 1..5');
        assert_true(count($week['participants']) > 0, 'week has participants');
    }
    $newest = $weeks[0];
    assert_same(135, $newest['season_id']);
    assert_same(4, $newest['section_index']);
    assert_same('20260907T095103.000Z', $newest['created_date']);
}

function test_parse_river_race_log_ignores_other_clan_tag(): void
{
    $weeks = Parser::parseRiverRaceLog(fixture('riverracelog'), 'NOPE');
    assert_same(0, count($weeks));
}

function test_parse_participant_fields(): void
{
    $weeks = Parser::parseRiverRaceLog(fixture('riverracelog'), 'JUQRRL8');
    $p = $weeks[0]['participants'][0];
    foreach (['player_tag', 'player_name', 'fame', 'decks_used', 'boat_attacks', 'repair_points'] as $field) {
        assert_true(array_key_exists($field, $p), "participant has $field");
    }
    assert_true($p['fame'] >= 0 && $p['fame'] <= Parser::MAX_WEEKLY_FAME, 'fame within 0..3600');
    assert_true(!str_starts_with($p['player_tag'], '#'), 'tag normalized');
}

function test_parse_current_river_race_is_provisional(): void
{
    $week = Parser::parseCurrentRiverRace(fixture('currentriverrace'), 136);
    assert_same(136, $week['season_id']);
    assert_same(0, $week['section_index']);
    assert_same(true, $week['provisional']);
    assert_same(null, $week['created_date']);
    assert_same('JUQRRL8', $week['clan_tag']);
    assert_true(count($week['participants']) > 0);
}

function test_derive_current_season(): void
{
    // Same season while the section keeps climbing.
    assert_same(135, Parser::deriveCurrentSeason(3, 135, 2));
    // Section reset means a new season.
    assert_same(136, Parser::deriveCurrentSeason(0, 135, 4));
    assert_same(136, Parser::deriveCurrentSeason(0, 135, 3));
    // Nothing logged yet.
    assert_same(0, Parser::deriveCurrentSeason(2, null, null));
}

function test_week_labels(): void
{
    assert_same('135-4', Parser::weekKey(135, 4));
    assert_same('S135 W5', Parser::weekLabel(135, 4));
}
