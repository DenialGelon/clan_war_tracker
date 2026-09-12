# clan_war_tracker

A dashboard for one Clash Royale clan that lists clan members and shows each member's clan war (River Race) performance over time. Data comes from the official Supercell Clash Royale API and is stored locally on every fetch so history accumulates beyond the API's short rolling window.

Repo: github.com/DenialGelon/clan_war_tracker
Live URL: https://danteachesmath.net/clan
Host: cPanel shared hosting (Exact Hosting / qualityhostonline.com)

## Decisions

- Language and runtime: PHP 8.x. No frameworks. Required extensions: curl, pdo_sqlite, json.
- Clan tag: `#JUQRRL8` (normalized: `JUQRRL8`)
- Current phase: local development on Dan's machine. The Supercell API key in use is whitelisted for the development IP `97.129.96.115`. A separate key will be created for the host's outbound IP at deploy time.
- Host facts (checked 2026-09-12): home `/home/dancemu`; danteachesmath.net document root `/home/dancemu/public_html/danteachesmath`; domain runs PHP 8.3 (`ea-php83`, CLI `/usr/local/bin/ea-php83`); cron available; server `sardine.exacthosting.com` at `209.59.190.133`. Outbound IP is very likely the same address but is not proven until the first live fetch succeeds. `pdo_sqlite` on ea-php83 is likewise unverified. See README "Deploy to cPanel".
- Local PHP is 8.5; code targets PHP 8.1 syntax so it runs on the host's 8.3 with room to spare.
- Interface (decided 2026-09-12): compact member rows (name, tag, role, last week fame, SVG sparkline). Tapping a row expands the full 0 to 3600 Chart.js chart in place; only one chart exists at a time. Former members are collapsed behind a toggle. No Refresh button in v1; cron is enough.

## Hard requirements

1. **Main list: current clan members.** Pulled from `/clans/{tag}/members`. Show name, tag, role, and a line graph of their weekly war fame.
2. **Second list: historical (former) clan members.** Anyone who appears in stored war data but is not in the current member list. Same graph treatment.
3. **Persist every download.** Every API response is stored raw (JSON, with a fetch timestamp) and then parsed into normalized tables. Never overwrite history. The API only returns roughly the last 10 weeks of River Race log; our stored data is the long-term record.
4. **Line graph with a fixed y-axis from 0 to 3600.** X-axis is war week (season + section). Y-axis is fame (medals) for that week. Do not autoscale.
5. **Player lookup box.** A text input where a player tag can be pasted. On submit, show that player's clan war history only. Used to vet players who want to join. See "Player lookup" below.

## Architecture

Keep it simple and shared-host friendly. No frameworks that need a build step on the server. No Docker.

```
clan_war_tracker/
  CLAUDE.md
  README.md
  config.example.php      # copied to config.php outside web root on the host
  app/                    # fetch, parse, store, query logic (not web accessible)
  data/                   # SQLite database lives here on the host (not web accessible)
  public/                 # everything here maps to public_html/clan on the host
    index.html
    assets/
    api/                  # thin JSON endpoints read by the frontend
  scripts/
    fetch.php             # run by cron; also callable manually
    save_fixtures.php     # one-off: saves live API responses to tests/fixtures
  tests/
```

- **Backend:** fetch script + thin JSON endpoints. The frontend is a static page that reads JSON.
- **Storage:** SQLite, single file in `data/`. Fine for one clan.
- **Frontend:** plain HTML, CSS, and JavaScript. Chart.js from a CDN for the line graph. No build step.
- **Secrets:** the API key lives in a config file outside `public_html`, never in the repo. `config.example.*` is committed; the real config is not.
- **Deploy:** `public/` contents go to `/home/dancemu/public_html/danteachesmath/clan`. `app/`, `data/`, `scripts/`, and config go in `/home/dancemu/clan_war_tracker/`. The endpoints locate `app/` by walking up the tree. Exact steps are in README.

## Supercell API

Base URL: `https://api.clashroyale.com/v1`
Auth: `Authorization: Bearer <token>` header. Token is IP-whitelisted; must be created for the host's outbound IP, not the site's DNS IP.
Tags in URLs must be URL-encoded (`#` becomes `%23`).

Endpoints used:

- `GET /clans/{tag}/members` - current roster
- `GET /clans/{tag}/currentriverrace` - race in progress; participants have `fame`, `decksUsed`, `decksUsedToday`, `boatAttacks`, `repairPoints`
- `GET /clans/{tag}/riverracelog` - past weeks; each item has `seasonId`, `sectionIndex`, `createdDate`, and `standings[]`; our clan's standing has `clan.participants[]` with the same fields as above
- `GET /players/{tag}` - player detail; used by the lookup feature to find the player's current clan

Notes:
- Respect rate limits. Cache responses; the data changes at most a few times a day.
- Handle 403 (bad key or wrong IP), 404 (bad tag), 429 (rate limit), 503 (maintenance) explicitly with clear messages.
- The API returns roughly 10 River Race log entries. Do not assume more.
- `currentriverrace` has no `seasonId`. Derive it from the newest log entry: if the current `sectionIndex` is higher than the last logged one it is the same season, otherwise a new season started. Seasons have a variable number of weeks (4 and 5 both seen).
- Player names can contain colour codes like `<c7>Name`. Store as-is, strip for display.
- Participants in old log entries include players who have since left. Join on player tag, never on name. Names change.

## Data model

```
raw_fetches      id, fetched_at, endpoint, clan_tag, response_json
war_weeks        season_id, section_index, created_date, clan_tag, clan_rank, clan_fame, clan_trophies_change  (PK: season_id, section_index, clan_tag)
participants     season_id, section_index, clan_tag, player_tag, player_name, fame, decks_used, boat_attacks, repair_points  (PK: season_id, section_index, clan_tag, player_tag)
players          player_tag, last_known_name, first_seen, last_seen
member_snapshots snapshot_at, player_tag, name, role, trophies, donations, last_seen_api
```

- Parsing is idempotent. Re-running fetch on the same week upserts; it never duplicates or deletes.
- "Current member" = present in the most recent member snapshot. "Historical member" = in `participants` for our clan but not a current member.
- The in-progress week from `currentriverrace` is stored the same way but flagged as provisional until it appears in `riverracelog`.

## Tag handling

Normalize every tag before use: trim, strip leading `#`, uppercase, replace letter O with digit 0. Store normalized. Display with a leading `#`.

## Player lookup

Input: a player tag pasted into the box.

1. Normalize the tag.
2. If we have stored data for that tag, show it (their weeks in our clan).
3. Also call `/players/{tag}`. If the player is currently in a clan, fetch that clan's `riverracelog` and `currentriverrace`, filter participants to this tag, and show those weeks labeled with that clan's name. This is what makes the feature useful for vetting: it shows recent history from their current clan before they join ours.
4. Store the fetched clan data in `raw_fetches` like any other download.
5. Show the same 0 to 3600 line graph plus a small table (week, clan, fame, decks used, boat attacks).

Show a clear message when the tag is invalid, the player is not in a clan, or no war data is available.

## Fetch schedule

- Cron (cPanel): run `scripts/fetch` once per week, shortly after the River Race resolves (Monday, ~10:00 UTC), plus optionally once per day to capture the in-progress week.
- Manual: the same script is runnable from the shell for testing.
- A "Refresh" control on the page is acceptable but must be rate-limited (no more than once every 15 minutes) so it cannot hammer the API.

## Frontend behavior

- Page title and heading: clan name from the API.
- Section 1: current members, sorted by fame in the most recent completed week (descending), then name.
- Section 2: historical members, sorted by last week seen (most recent first).
- Each member row: name, tag, role (current members only), weeks recorded, line graph.
- Line graph: Chart.js line chart, y-axis fixed min 0 max 3600, x-axis labeled by season and week (e.g. "S133 W4"). Missing weeks are gaps, not zeros, unless the player was a member that week and recorded 0.
- Lookup box at the top of the page.
- Works on mobile. Most clan members will look at this on a phone.

## Local development

- Run the site: `php -S localhost:8000 -t public` and open http://localhost:8000
- Run tests: `php tests/run.php` (plain PHP runner, no Composer)
- Run a fetch: `php scripts/fetch.php`
- Config path is read from the `CWT_CONFIG` environment variable, falling back to `config.php` in the repo root (gitignored). Local and host use different config files with different API keys; never commit either.
- Fixture mode: setting `use_fixtures = true` in config makes the fetch script read saved JSON from `tests/fixtures/` instead of calling the API. Use this for all parser, database, and UI work. Only hit the live API when testing the fetch itself.
- Capture fixtures once with a small helper (`php scripts/save_fixtures.php`) that saves each endpoint's raw response to `tests/fixtures/<endpoint>.json`.
- Match the host's PHP minor version once it is known.

## Style and conventions

- Prefer plain, readable code over cleverness. This is a hobby project that should be easy to come back to after months away.
- Use single dashes, never em dashes, in any prose or comments.
- Small functions with clear names. Comment the non-obvious (API quirks, tag normalization, why 3600).
- Tests for tag normalization, log parsing, and the current/historical member split, using saved sample API responses in `tests/fixtures/`.
- Commit early and often. Do not commit `config.*` (other than the example) or anything in `data/`.

## Out of scope for v1

- Multiple clans
- Authentication
- Card or deck analysis
- Anything requiring a persistent server process
