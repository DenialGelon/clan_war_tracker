<?php
declare(strict_types=1);

// Opens the SQLite database and makes sure the schema exists.
final class Db
{
    public static function open(string $path): PDO
    {
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        self::migrate($pdo);
        return $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS raw_fetches (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                fetched_at    TEXT NOT NULL,
                endpoint      TEXT NOT NULL,
                clan_tag      TEXT NOT NULL,
                response_json TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS raw_fetches_endpoint ON raw_fetches (endpoint, fetched_at);

            CREATE TABLE IF NOT EXISTS clans (
                clan_tag  TEXT PRIMARY KEY,
                name      TEXT NOT NULL,
                last_seen TEXT NOT NULL
            );

            -- One row per River Race week per clan. provisional=1 means the row
            -- came from currentriverrace and the week has not finished yet.
            CREATE TABLE IF NOT EXISTS war_weeks (
                season_id            INTEGER NOT NULL,
                section_index        INTEGER NOT NULL,
                clan_tag             TEXT NOT NULL,
                created_date         TEXT,
                clan_rank            INTEGER,
                clan_fame            INTEGER,
                clan_trophies_change INTEGER,
                provisional          INTEGER NOT NULL DEFAULT 0,
                updated_at           TEXT NOT NULL,
                PRIMARY KEY (season_id, section_index, clan_tag)
            );

            CREATE TABLE IF NOT EXISTS participants (
                season_id     INTEGER NOT NULL,
                section_index INTEGER NOT NULL,
                clan_tag      TEXT NOT NULL,
                player_tag    TEXT NOT NULL,
                player_name   TEXT NOT NULL,
                fame          INTEGER NOT NULL DEFAULT 0,
                decks_used    INTEGER NOT NULL DEFAULT 0,
                boat_attacks  INTEGER NOT NULL DEFAULT 0,
                repair_points INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (season_id, section_index, clan_tag, player_tag)
            );
            CREATE INDEX IF NOT EXISTS participants_player ON participants (player_tag);

            CREATE TABLE IF NOT EXISTS players (
                player_tag      TEXT PRIMARY KEY,
                last_known_name TEXT NOT NULL,
                first_seen      TEXT NOT NULL,
                last_seen       TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS member_snapshots (
                snapshot_at   TEXT NOT NULL,
                clan_tag      TEXT NOT NULL,
                player_tag    TEXT NOT NULL,
                name          TEXT NOT NULL,
                role          TEXT NOT NULL,
                trophies      INTEGER,
                donations     INTEGER,
                last_seen_api TEXT,
                PRIMARY KEY (snapshot_at, clan_tag, player_tag)
            );
        SQL);
    }
}
