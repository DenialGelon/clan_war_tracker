<?php
declare(strict_types=1);

final class ApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message, $status);
    }
}

// Thin client for the Supercell Clash Royale API. In fixture mode it reads
// saved JSON from tests/fixtures instead of going over the network.
final class ApiClient
{
    private const BASE_URL = 'https://api.clashroyale.com/v1';

    public function __construct(
        private string $apiKey,
        private ?string $fixtureDir = null,
    ) {
    }

    public static function fromConfig(array $config): self
    {
        return new self(
            (string) $config['api_key'],
            $config['use_fixtures'] ? (string) $config['fixture_dir'] : null,
        );
    }

    // Convenience paths. All take normalized tags.
    public static function clanPath(string $clanTag): string
    {
        return '/clans/' . Tag::urlEncode($clanTag);
    }

    public static function membersPath(string $clanTag): string
    {
        return self::clanPath($clanTag) . '/members';
    }

    public static function currentRiverRacePath(string $clanTag): string
    {
        return self::clanPath($clanTag) . '/currentriverrace';
    }

    public static function riverRaceLogPath(string $clanTag): string
    {
        return self::clanPath($clanTag) . '/riverracelog';
    }

    public static function playerPath(string $playerTag): string
    {
        return '/players/' . Tag::urlEncode($playerTag);
    }

    // Returns the raw JSON body. Throws ApiException on any non-200 status.
    public function getRaw(string $path): string
    {
        if ($this->fixtureDir !== null) {
            return $this->readFixture($path);
        }

        $ch = curl_init(self::BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);

        if ($body === false) {
            throw new ApiException("Network error talking to the Clash Royale API: $curlError", 0);
        }
        if ($status !== 200) {
            throw new ApiException(self::describeError($status, (string) $body, $path), $status);
        }
        return (string) $body;
    }

    /** @return array<string, mixed> */
    public function get(string $path): array
    {
        $decoded = json_decode($this->getRaw($path), true);
        if (!is_array($decoded)) {
            throw new ApiException("The API returned something that was not JSON for $path", 0);
        }
        return $decoded;
    }

    private static function describeError(int $status, string $body, string $path): string
    {
        $reason = '';
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $reason = (string) ($decoded['message'] ?? $decoded['reason'] ?? '');
        }
        $suffix = $reason !== '' ? " ($reason)" : '';
        return match ($status) {
            400 => "Bad request to $path$suffix",
            403 => "API key rejected (wrong key, or this machine's IP is not whitelisted for it)$suffix",
            404 => "Not found: $path$suffix",
            429 => "Rate limited by the Clash Royale API - try again in a minute$suffix",
            500 => "The Clash Royale API had an internal error$suffix",
            503 => "The Clash Royale API is down for maintenance$suffix",
            default => "Unexpected HTTP $status from $path$suffix",
        };
    }

    // Fixture files are named after the endpoint kind, ignoring the tag, so one
    // set of saved responses serves any clan or player during tests.
    public static function fixtureName(string $path): string
    {
        if (str_starts_with($path, '/players/')) {
            return 'player.json';
        }
        if (preg_match('#^/clans/[^/]+/(members|currentriverrace|riverracelog)$#', $path, $m)) {
            return $m[1] . '.json';
        }
        if (preg_match('#^/clans/[^/]+$#', $path)) {
            return 'clan.json';
        }
        throw new InvalidArgumentException("No fixture mapping for path $path");
    }

    private function readFixture(string $path): string
    {
        $file = rtrim((string) $this->fixtureDir, '/') . '/' . self::fixtureName($path);
        if (!is_file($file)) {
            throw new ApiException("Fixture not found: $file (run scripts/save_fixtures.php)", 404);
        }
        return (string) file_get_contents($file);
    }
}
