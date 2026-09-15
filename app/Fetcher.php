<?php
declare(strict_types=1);

// Orchestrates API calls: download, keep the raw response, parse, store.
// Also the single place that decides when a cached response is fresh enough.
final class Fetcher
{
    public function __construct(private ApiClient $api, private Store $store)
    {
    }

    /**
     * Fetches the raw JSON for a path, reusing a stored copy younger than
     * $cacheSeconds when one exists. Every live download is recorded.
     *
     * @return array{json: array<string, mixed>, cached: bool}
     */
    public function getJson(string $path, string $clanTag, int $cacheSeconds = 0): array
    {
        if ($cacheSeconds > 0) {
            $raw = $this->store->recentFetch($path, $cacheSeconds);
            if ($raw !== null) {
                return ['json' => self::decode($raw, $path), 'cached' => true];
            }
        }
        $raw = $this->api->getRaw($path);
        $this->store->recordFetch($path, $clanTag, $raw);
        return ['json' => self::decode($raw, $path), 'cached' => false];
    }

    /**
     * Downloads the member list and stores a snapshot.
     *
     * @return array{members: int, cached: bool}
     */
    public function fetchMembers(string $clanTag, int $cacheSeconds = 0): array
    {
        $result = $this->getJson(ApiClient::membersPath($clanTag), $clanTag, $cacheSeconds);
        $members = Parser::parseMembers($result['json']);
        if (!$result['cached']) {
            $this->store->saveMemberSnapshot($clanTag, $members);
        }
        return ['members' => count($members), 'cached' => $result['cached']];
    }

    /**
     * Downloads the river race log and the in-progress race for a clan and
     * stores every week found. Works for any clan, which the lookup feature
     * relies on.
     *
     * @return array{log_weeks: int, current_saved: bool, cached: bool}
     */
    public function fetchWarData(string $clanTag, int $cacheSeconds = 0): array
    {
        $log = $this->getJson(ApiClient::riverRaceLogPath($clanTag), $clanTag, $cacheSeconds);
        $weeks = Parser::parseRiverRaceLog($log['json'], $clanTag);
        foreach ($weeks as $week) {
            $this->store->saveWeek($week);
        }

        // Newest finished week, used to pin a season on the in-progress race.
        $latestSeason = null;
        $latestSection = null;
        foreach ($weeks as $week) {
            if ($latestSeason === null
                || $week['season_id'] > $latestSeason
                || ($week['season_id'] === $latestSeason && $week['section_index'] > $latestSection)) {
                $latestSeason = $week['season_id'];
                $latestSection = $week['section_index'];
            }
        }

        $current = $this->getJson(ApiClient::currentRiverRacePath($clanTag), $clanTag, $cacheSeconds);
        $currentSection = (int) ($current['json']['sectionIndex'] ?? 0);
        $seasonId = Parser::deriveCurrentSeason($currentSection, $latestSeason, $latestSection);
        $currentWeek = Parser::parseCurrentRiverRace($current['json'], $seasonId);
        $currentSaved = false;
        if ($currentWeek['clan_tag'] === $clanTag) {
            $currentSaved = $this->store->saveWeek($currentWeek);
        }

        return [
            'log_weeks' => count($weeks),
            'current_saved' => $currentSaved,
            'cached' => $log['cached'] && $current['cached'],
        ];
    }

    /**
     * Downloads a player profile. Returns the parts the lookup feature needs.
     *
     * @return array{tag: string, name: string, clan: ?array{tag: string, name: string}, cached: bool}
     */
    public function fetchPlayer(string $playerTag, int $cacheSeconds = 0): array
    {
        $result = $this->getJson(ApiClient::playerPath($playerTag), '', $cacheSeconds);
        $json = $result['json'];
        $clan = null;
        if (!empty($json['clan']['tag'])) {
            $clan = [
                'tag' => Tag::normalize((string) $json['clan']['tag']),
                'name' => (string) ($json['clan']['name'] ?? ''),
            ];
            $this->store->upsertClan($clan['tag'], $clan['name']);
        }
        $name = (string) ($json['name'] ?? '');
        if ($name !== '') {
            $this->store->upsertPlayer($playerTag, $name, Store::now());
        }
        return ['tag' => $playerTag, 'name' => $name, 'clan' => $clan, 'cached' => $result['cached']];
    }

    /**
     * Downloads a player's battle log and lists the clans they fought for,
     * most recent first. The profile endpoint only reports the current clan,
     * so this is the only way to find where a fresh recruit came from.
     * Empty when the player has not fought in a clan recently.
     *
     * @return list<array{tag: string, name: string}>
     */
    public function fetchRecentClans(string $playerTag, int $cacheSeconds = 0): array
    {
        $result = $this->getJson(ApiClient::battleLogPath($playerTag), '', $cacheSeconds);
        $clans = [];
        foreach ($result['json'] as $battle) {
            // team[0] is the player the log belongs to. Battles fought while
            // clanless carry no clan at all.
            $clan = $battle['team'][0]['clan'] ?? null;
            if (!is_array($clan) || empty($clan['tag'])) {
                continue;
            }
            $tag = Tag::normalize((string) $clan['tag']);
            if (isset($clans[$tag])) {
                continue;
            }
            $clans[$tag] = ['tag' => $tag, 'name' => (string) ($clan['name'] ?? '')];
            $this->store->upsertClan($tag, $clans[$tag]['name']);
        }
        return array_values($clans);
    }

    /** @return array<string, mixed> */
    private static function decode(string $raw, string $path): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ApiException("Stored response for $path is not valid JSON", 0);
        }
        return $decoded;
    }
}
