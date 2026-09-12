<?php
declare(strict_types=1);

// All writes to the database go through here. Every method is idempotent:
// re-running a fetch upserts, never duplicates, never deletes.
final class Store
{
    public function __construct(private PDO $pdo)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    public function recordFetch(string $endpoint, string $clanTag, string $rawJson, ?string $at = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO raw_fetches (fetched_at, endpoint, clan_tag, response_json) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$at ?? self::now(), $endpoint, $clanTag, $rawJson]);
        return (int) $this->pdo->lastInsertId();
    }

    // Returns the most recent stored response for an endpoint if it is younger
    // than $maxAgeSeconds. Used to cache lookups and throttle manual refreshes.
    public function recentFetch(string $endpoint, int $maxAgeSeconds): ?string
    {
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $maxAgeSeconds);
        $stmt = $this->pdo->prepare(
            'SELECT response_json FROM raw_fetches WHERE endpoint = ? AND fetched_at >= ? ORDER BY fetched_at DESC LIMIT 1'
        );
        $stmt->execute([$endpoint, $cutoff]);
        $row = $stmt->fetch();
        return $row ? (string) $row['response_json'] : null;
    }

    public function upsertClan(string $clanTag, string $name, ?string $at = null): void
    {
        if ($clanTag === '' || $name === '') {
            return;
        }
        $stmt = $this->pdo->prepare(<<<SQL
            INSERT INTO clans (clan_tag, name, last_seen) VALUES (?, ?, ?)
            ON CONFLICT (clan_tag) DO UPDATE SET name = excluded.name, last_seen = excluded.last_seen
        SQL);
        $stmt->execute([$clanTag, $name, $at ?? self::now()]);
    }

    /**
     * Saves one week (from Parser) with its participants. A provisional week
     * never overwrites a final one; a final week always replaces a provisional.
     *
     * @param array<string, mixed> $week
     */
    public function saveWeek(array $week, ?string $at = null): bool
    {
        $at = $at ?? self::now();
        $existing = $this->pdo->prepare(
            'SELECT provisional FROM war_weeks WHERE season_id = ? AND section_index = ? AND clan_tag = ?'
        );
        $existing->execute([$week['season_id'], $week['section_index'], $week['clan_tag']]);
        $row = $existing->fetch();
        if ($row && (int) $row['provisional'] === 0 && $week['provisional']) {
            return false;
        }

        $this->pdo->beginTransaction();
        try {
            $this->upsertClan($week['clan_tag'], $week['clan_name'], $at);

            $stmt = $this->pdo->prepare(<<<SQL
                INSERT INTO war_weeks
                    (season_id, section_index, clan_tag, created_date, clan_rank, clan_fame, clan_trophies_change, provisional, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (season_id, section_index, clan_tag) DO UPDATE SET
                    created_date = excluded.created_date,
                    clan_rank = excluded.clan_rank,
                    clan_fame = excluded.clan_fame,
                    clan_trophies_change = excluded.clan_trophies_change,
                    provisional = excluded.provisional,
                    updated_at = excluded.updated_at
            SQL);
            $stmt->execute([
                $week['season_id'], $week['section_index'], $week['clan_tag'],
                $week['created_date'], $week['clan_rank'], $week['clan_fame'],
                $week['clan_trophies_change'], $week['provisional'] ? 1 : 0, $at,
            ]);

            $pStmt = $this->pdo->prepare(<<<SQL
                INSERT INTO participants
                    (season_id, section_index, clan_tag, player_tag, player_name, fame, decks_used, boat_attacks, repair_points)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (season_id, section_index, clan_tag, player_tag) DO UPDATE SET
                    player_name = excluded.player_name,
                    fame = excluded.fame,
                    decks_used = excluded.decks_used,
                    boat_attacks = excluded.boat_attacks,
                    repair_points = excluded.repair_points
            SQL);
            // Seen-at for the players table: the week end date if known, else now.
            $seenAt = $week['created_date'] ?? $at;
            foreach ($week['participants'] as $p) {
                $pStmt->execute([
                    $week['season_id'], $week['section_index'], $week['clan_tag'],
                    $p['player_tag'], $p['player_name'], $p['fame'],
                    $p['decks_used'], $p['boat_attacks'], $p['repair_points'],
                ]);
                $this->upsertPlayer($p['player_tag'], $p['player_name'], $seenAt);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return true;
    }

    public function upsertPlayer(string $playerTag, string $name, string $seenAt): void
    {
        $stmt = $this->pdo->prepare(<<<SQL
            INSERT INTO players (player_tag, last_known_name, first_seen, last_seen) VALUES (?, ?, ?, ?)
            ON CONFLICT (player_tag) DO UPDATE SET
                last_known_name = CASE WHEN excluded.last_seen >= players.last_seen THEN excluded.last_known_name ELSE players.last_known_name END,
                first_seen = MIN(players.first_seen, excluded.first_seen),
                last_seen = MAX(players.last_seen, excluded.last_seen)
        SQL);
        $stmt->execute([$playerTag, $name, $seenAt, $seenAt]);
    }

    /**
     * @param list<array{tag: string, name: string, role: string, trophies: int, donations: int, last_seen: ?string}> $members
     */
    public function saveMemberSnapshot(string $clanTag, array $members, ?string $at = null): string
    {
        $at = $at ?? self::now();
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(<<<SQL
                INSERT OR REPLACE INTO member_snapshots
                    (snapshot_at, clan_tag, player_tag, name, role, trophies, donations, last_seen_api)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            SQL);
            foreach ($members as $m) {
                $stmt->execute([$at, $clanTag, $m['tag'], $m['name'], $m['role'], $m['trophies'], $m['donations'], $m['last_seen']]);
                $this->upsertPlayer($m['tag'], $m['name'], $at);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $at;
    }
}
