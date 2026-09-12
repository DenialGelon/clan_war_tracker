<?php
declare(strict_types=1);

// Pure functions that turn decoded API JSON into flat arrays ready for Store.
// No database access here, so the parsers are easy to test against fixtures.
final class Parser
{
    // Highest fame a single player can earn in one war week: 4 decks a day for
    // 4 war days, 225 fame per win = 3600. The chart's fixed y-axis uses this.
    public const MAX_WEEKLY_FAME = 3600;

    /**
     * @param array<string, mixed> $json  decoded /clans/{tag}/members response
     * @return list<array{tag: string, name: string, role: string, trophies: int, donations: int, last_seen: ?string}>
     */
    public static function parseMembers(array $json): array
    {
        $members = [];
        foreach ($json['items'] ?? [] as $item) {
            $members[] = [
                'tag' => Tag::normalize((string) $item['tag']),
                'name' => (string) ($item['name'] ?? ''),
                'role' => (string) ($item['role'] ?? ''),
                'trophies' => (int) ($item['trophies'] ?? 0),
                'donations' => (int) ($item['donations'] ?? 0),
                'last_seen' => isset($item['lastSeen']) ? (string) $item['lastSeen'] : null,
            ];
        }
        return $members;
    }

    /**
     * Parses /clans/{tag}/riverracelog for one clan. Each log item lists every
     * clan in that race; we keep only the standing for the clan we asked about.
     *
     * @param array<string, mixed> $json
     * @return list<array<string, mixed>>  one entry per week, see weekFromStanding()
     */
    public static function parseRiverRaceLog(array $json, string $clanTag): array
    {
        $weeks = [];
        foreach ($json['items'] ?? [] as $item) {
            foreach ($item['standings'] ?? [] as $standing) {
                $standingTag = Tag::normalize((string) ($standing['clan']['tag'] ?? ''));
                if ($standingTag !== $clanTag) {
                    continue;
                }
                $weeks[] = self::weekFromStanding(
                    (int) $item['seasonId'],
                    (int) $item['sectionIndex'],
                    (string) ($item['createdDate'] ?? ''),
                    $standing,
                    false,
                );
            }
        }
        return $weeks;
    }

    /**
     * Parses /clans/{tag}/currentriverrace. The response carries no seasonId,
     * so the caller works it out with deriveCurrentSeason() and passes it in.
     *
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    public static function parseCurrentRiverRace(array $json, int $seasonId): array
    {
        $standing = [
            'rank' => null,
            'trophyChange' => null,
            'clan' => $json['clan'] ?? [],
        ];
        return self::weekFromStanding($seasonId, (int) ($json['sectionIndex'] ?? 0), null, $standing, true);
    }

    /**
     * Works out the season of the in-progress race from the newest finished
     * week in the log. Sections restart at 0 each season and a season has a
     * variable number of weeks (4 or 5 have both been seen), so the rule is:
     * if the current section index is higher than the last logged one, it is
     * the same season; otherwise a new season has started.
     */
    public static function deriveCurrentSeason(int $currentSection, ?int $latestSeason, ?int $latestSection): int
    {
        if ($latestSeason === null || $latestSection === null) {
            // Nothing logged yet. Season 0 would be wrong but harmless; the row
            // gets replaced once the week shows up in the log.
            return 0;
        }
        return $currentSection > $latestSection ? $latestSeason : $latestSeason + 1;
    }

    /**
     * @param array<string, mixed> $standing  one element of standings[] (rank, trophyChange, clan)
     * @return array<string, mixed>
     */
    private static function weekFromStanding(int $seasonId, int $sectionIndex, ?string $createdDate, array $standing, bool $provisional): array
    {
        $clan = $standing['clan'] ?? [];
        $participants = [];
        foreach ($clan['participants'] ?? [] as $p) {
            $participants[] = [
                'player_tag' => Tag::normalize((string) $p['tag']),
                'player_name' => (string) ($p['name'] ?? ''),
                'fame' => (int) ($p['fame'] ?? 0),
                'decks_used' => (int) ($p['decksUsed'] ?? 0),
                'boat_attacks' => (int) ($p['boatAttacks'] ?? 0),
                'repair_points' => (int) ($p['repairPoints'] ?? 0),
            ];
        }
        return [
            'season_id' => $seasonId,
            'section_index' => $sectionIndex,
            'created_date' => $createdDate !== '' ? $createdDate : null,
            'clan_tag' => Tag::normalize((string) ($clan['tag'] ?? '')),
            'clan_name' => (string) ($clan['name'] ?? ''),
            'clan_rank' => isset($standing['rank']) ? (int) $standing['rank'] : null,
            'clan_fame' => (int) ($clan['fame'] ?? 0),
            'clan_trophies_change' => isset($standing['trophyChange']) ? (int) $standing['trophyChange'] : null,
            'provisional' => $provisional,
            'participants' => $participants,
        ];
    }

    // "135-4" - used as the week key in JSON output and the frontend.
    public static function weekKey(int $seasonId, int $sectionIndex): string
    {
        return $seasonId . '-' . $sectionIndex;
    }

    // "S135 W5" - sectionIndex is zero-based, people count weeks from 1.
    public static function weekLabel(int $seasonId, int $sectionIndex): string
    {
        return 'S' . $seasonId . ' W' . ($sectionIndex + 1);
    }
}
