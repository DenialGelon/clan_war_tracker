<?php
declare(strict_types=1);

function test_fetch_is_idempotent(): void
{
    $store = fixture_store();
    $pdo = $store->pdo();
    $count = fn (string $t) => (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    $weeks = $count('war_weeks');
    $participants = $count('participants');
    $players = $count('players');

    $fetcher = new Fetcher(new ApiClient('test', __DIR__ . '/fixtures'), $store);
    $fetcher->fetchWarData('JUQRRL8');

    assert_same($weeks, $count('war_weeks'), 'war_weeks unchanged');
    assert_same($participants, $count('participants'), 'participants unchanged');
    assert_same($players, $count('players'), 'players unchanged');
    assert_same(11, $weeks, 'ten logged weeks plus the in-progress one');
}

function test_every_download_is_recorded_raw(): void
{
    $store = fixture_store();
    $rows = $store->pdo()->query('SELECT endpoint FROM raw_fetches ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    assert_same(3, count($rows));
    assert_true(str_ends_with($rows[0], '/members'));
    assert_true(str_ends_with($rows[1], '/riverracelog'));
    assert_true(str_ends_with($rows[2], '/currentriverrace'));
}

function test_provisional_week_never_overwrites_final(): void
{
    $store = new Store(Db::open(':memory:'));
    $final = [
        'season_id' => 1, 'section_index' => 0, 'created_date' => '20260101T100000.000Z',
        'clan_tag' => 'AAA', 'clan_name' => 'Test', 'clan_rank' => 1, 'clan_fame' => 100,
        'clan_trophies_change' => 10, 'provisional' => false,
        'participants' => [['player_tag' => 'P1', 'player_name' => 'One', 'fame' => 3600, 'decks_used' => 16, 'boat_attacks' => 0, 'repair_points' => 0]],
    ];
    $provisional = $final;
    $provisional['provisional'] = true;
    $provisional['clan_fame'] = 50;
    $provisional['participants'][0]['fame'] = 100;

    assert_same(true, $store->saveWeek($provisional), 'first save (provisional)');
    assert_same(true, $store->saveWeek($final), 'final replaces provisional');
    assert_same(false, $store->saveWeek($provisional), 'provisional does not replace final');

    $row = $store->pdo()->query('SELECT clan_fame, provisional FROM war_weeks')->fetch();
    assert_same(100, (int) $row['clan_fame']);
    assert_same(0, (int) $row['provisional']);
    $fame = (int) $store->pdo()->query('SELECT fame FROM participants')->fetchColumn();
    assert_same(3600, $fame);
}

function test_player_name_follows_newest_sighting(): void
{
    $store = new Store(Db::open(':memory:'));
    $store->upsertPlayer('P1', 'New', '2026-02-01T00:00:00Z');
    $store->upsertPlayer('P1', 'Old', '2026-01-01T00:00:00Z');
    $row = $store->pdo()->query('SELECT * FROM players')->fetch();
    assert_same('New', $row['last_known_name']);
    assert_same('2026-01-01T00:00:00Z', $row['first_seen']);
    assert_same('2026-02-01T00:00:00Z', $row['last_seen']);
}

function test_recent_fetch_cache(): void
{
    $store = new Store(Db::open(':memory:'));
    $store->recordFetch('/x', '', '{"a":1}');
    assert_same('{"a":1}', $store->recentFetch('/x', 60));
    assert_same(null, $store->recentFetch('/y', 60));
    $store->recordFetch('/z', '', '{"old":1}', '2000-01-01T00:00:00Z');
    assert_same(null, $store->recentFetch('/z', 60), 'stale entries are ignored');
}
