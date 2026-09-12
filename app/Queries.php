<?php
declare(strict_types=1);

// Read-only queries that shape stored data for the JSON endpoints.
final class Queries
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{tag: string, name: string}|null */
    public function clan(string $clanTag): ?array
    {
        $stmt = $this->pdo->prepare('SELECT clan_tag, name FROM clans WHERE clan_tag = ?');
        $stmt->execute([$clanTag]);
        $row = $stmt->fetch();
        return $row ? ['tag' => $row['clan_tag'], 'name' => $row['name']] : null;
    }

    /**
     * Every stored week for a clan, oldest first.
     *
     * @return list<array{key: string, season: int, section: int, label: string, createdDate: ?string, provisional: bool, clanFame: int, clanRank: ?int}>
     */
    public function weeks(string $clanTag): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM war_weeks WHERE clan_tag = ? ORDER BY season_id, section_index'
        );
        $stmt->execute([$clanTag]);
        $weeks = [];
        foreach ($stmt->fetchAll() as $row) {
            $weeks[] = [
                'key' => Parser::weekKey((int) $row['season_id'], (int) $row['section_index']),
                'season' => (int) $row['season_id'],
                'section' => (int) $row['section_index'],
                'label' => Parser::weekLabel((int) $row['season_id'], (int) $row['section_index']),
                'createdDate' => $row['created_date'],
                'provisional' => (int) $row['provisional'] === 1,
                'clanFame' => (int) $row['clan_fame'],
                'clanRank' => $row['clan_rank'] === null ? null : (int) $row['clan_rank'],
            ];
        }
        return $weeks;
    }

    /**
     * Rows from the most recent member snapshot.
     *
     * @return array{snapshotAt: ?string, members: list<array<string, mixed>>}
     */
    public function currentMembers(string $clanTag): array
    {
        $stmt = $this->pdo->prepare('SELECT MAX(snapshot_at) AS at FROM member_snapshots WHERE clan_tag = ?');
        $stmt->execute([$clanTag]);
        $at = $stmt->fetch()['at'] ?? null;
        if ($at === null) {
            return ['snapshotAt' => null, 'members' => []];
        }
        $stmt = $this->pdo->prepare(
            'SELECT player_tag, name, role, trophies, donations, last_seen_api FROM member_snapshots WHERE clan_tag = ? AND snapshot_at = ?'
        );
        $stmt->execute([$clanTag, $at]);
        return ['snapshotAt' => $at, 'members' => $stmt->fetchAll()];
    }

    /**
     * Per-player, per-week war stats for one clan, keyed by player tag then week key.
     *
     * @return array<string, array{name: string, weeks: array<string, array{fame: int, decks: int, boats: int}>}>
     */
    public function participationByPlayer(string $clanTag): array
    {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT p.player_tag, p.player_name, p.season_id, p.section_index, p.fame, p.decks_used, p.boat_attacks
            FROM participants p
            WHERE p.clan_tag = ?
            ORDER BY p.season_id, p.section_index
        SQL);
        $stmt->execute([$clanTag]);
        $byPlayer = [];
        foreach ($stmt->fetchAll() as $row) {
            $tag = $row['player_tag'];
            $key = Parser::weekKey((int) $row['season_id'], (int) $row['section_index']);
            $byPlayer[$tag]['name'] = $row['player_name']; // rows are ordered, so this ends as the newest name
            $byPlayer[$tag]['weeks'][$key] = [
                'fame' => (int) $row['fame'],
                'decks' => (int) $row['decks_used'],
                'boats' => (int) $row['boat_attacks'],
            ];
        }
        return $byPlayer;
    }

    /**
     * Everything the main page needs in one payload.
     *
     * @return array<string, mixed>
     */
    public function dashboard(string $clanTag): array
    {
        $weeks = $this->weeks($clanTag);
        $snapshot = $this->currentMembers($clanTag);
        $participation = $this->participationByPlayer($clanTag);

        // Newest finished week drives the default sort of the current members list.
        $latestFinalKey = null;
        foreach ($weeks as $w) {
            if (!$w['provisional']) {
                $latestFinalKey = $w['key'];
            }
        }

        $current = [];
        $currentTags = [];
        foreach ($snapshot['members'] as $m) {
            $tag = $m['player_tag'];
            $currentTags[$tag] = true;
            $stats = $participation[$tag]['weeks'] ?? [];
            $current[] = [
                'tag' => $tag,
                'displayTag' => Tag::display($tag),
                'name' => $m['name'],
                'role' => $m['role'],
                'trophies' => (int) $m['trophies'],
                'weeksRecorded' => count($stats),
                'lastFame' => $latestFinalKey !== null && isset($stats[$latestFinalKey]) ? $stats[$latestFinalKey]['fame'] : null,
                'weeks' => $stats,
            ];
        }
        usort($current, static function (array $a, array $b): int {
            $fa = $a['lastFame'] ?? -1;
            $fb = $b['lastFame'] ?? -1;
            return $fb <=> $fa ?: strcasecmp($a['name'], $b['name']);
        });

        $former = [];
        foreach ($participation as $tag => $info) {
            if (isset($currentTags[$tag])) {
                continue;
            }
            $keys = array_keys($info['weeks']);
            $lastKey = end($keys);
            $former[] = [
                'tag' => $tag,
                'displayTag' => Tag::display($tag),
                'name' => $info['name'],
                'weeksRecorded' => count($info['weeks']),
                'lastSeenKey' => $lastKey,
                'weeks' => $info['weeks'],
            ];
        }
        // Most recently seen first, then by name.
        $weekOrder = array_flip(array_column($weeks, 'key'));
        usort($former, static function (array $a, array $b) use ($weekOrder): int {
            $oa = $weekOrder[$a['lastSeenKey']] ?? -1;
            $ob = $weekOrder[$b['lastSeenKey']] ?? -1;
            return $ob <=> $oa ?: strcasecmp($a['name'], $b['name']);
        });

        return [
            'clan' => $this->clan($clanTag) ?? ['tag' => $clanTag, 'name' => Tag::display($clanTag)],
            'snapshotAt' => $snapshot['snapshotAt'],
            'weeks' => $weeks,
            'current' => $current,
            'former' => $former,
        ];
    }

    /**
     * Every stored week for one player across all clans, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function playerHistory(string $playerTag): array
    {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT p.season_id, p.section_index, p.clan_tag, p.fame, p.decks_used, p.boat_attacks,
                   w.provisional, w.created_date, c.name AS clan_name
            FROM participants p
            JOIN war_weeks w ON w.season_id = p.season_id AND w.section_index = p.section_index AND w.clan_tag = p.clan_tag
            LEFT JOIN clans c ON c.clan_tag = p.clan_tag
            WHERE p.player_tag = ?
            ORDER BY p.season_id, p.section_index, p.clan_tag
        SQL);
        $stmt->execute([$playerTag]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $season = (int) $row['season_id'];
            $section = (int) $row['section_index'];
            $rows[] = [
                'key' => Parser::weekKey($season, $section),
                'label' => Parser::weekLabel($season, $section),
                'clanTag' => $row['clan_tag'],
                'clanName' => $row['clan_name'] ?? Tag::display($row['clan_tag']),
                'fame' => (int) $row['fame'],
                'decks' => (int) $row['decks_used'],
                'boats' => (int) $row['boat_attacks'],
                'provisional' => (int) $row['provisional'] === 1,
                'createdDate' => $row['created_date'],
            ];
        }
        return $rows;
    }

    /**
     * Union of all stored weeks across several clans, oldest first. Used as the
     * x-axis for a player's lookup chart so weeks they sat out show as gaps.
     *
     * @param list<string> $clanTags
     * @return list<array{key: string, label: string, provisional: bool}>
     */
    public function weeksForClans(array $clanTags): array
    {
        if ($clanTags === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($clanTags), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT season_id, section_index, MIN(provisional) AS provisional
             FROM war_weeks WHERE clan_tag IN ($placeholders)
             GROUP BY season_id, section_index ORDER BY season_id, section_index"
        );
        $stmt->execute(array_values($clanTags));
        $weeks = [];
        foreach ($stmt->fetchAll() as $row) {
            $weeks[] = [
                'key' => Parser::weekKey((int) $row['season_id'], (int) $row['section_index']),
                'label' => Parser::weekLabel((int) $row['season_id'], (int) $row['section_index']),
                'provisional' => (int) $row['provisional'] === 1,
            ];
        }
        return $weeks;
    }

    /** @return array{tag: string, name: string}|null */
    public function player(string $playerTag): ?array
    {
        $stmt = $this->pdo->prepare('SELECT player_tag, last_known_name FROM players WHERE player_tag = ?');
        $stmt->execute([$playerTag]);
        $row = $stmt->fetch();
        return $row ? ['tag' => $row['player_tag'], 'name' => $row['last_known_name']] : null;
    }
}
