# clan_war_tracker

A small dashboard for one Clash Royale clan: current members, former members, and each person's weekly clan war (River Race) fame over time. Data comes from the official Supercell API and is stored locally on every fetch, so history keeps growing past the API's ten-week window.

Live: https://danteachesmath.net/clan

## Requirements

- PHP 8.1 or newer with the `curl`, `pdo_sqlite`, and `json` extensions
- A Supercell developer API key (https://developer.clashroyale.com), whitelisted for the IP the fetch runs from

## Local setup

```sh
cp config.example.php config.php   # then paste your API key into config.php
php scripts/fetch.php              # downloads roster + war data into data/clan_war_tracker.sqlite
php -S localhost:8000 -t public    # open http://localhost:8000
```

`config.php` and everything in `data/` are gitignored. The config path can be overridden with the `CWT_CONFIG` environment variable.

## Tests

```sh
php tests/run.php
```

Tests use saved API responses in `tests/fixtures/` and an in-memory SQLite database, so they never touch the network. To refresh the fixtures from the live API:

```sh
php scripts/save_fixtures.php
```

Setting `'use_fixtures' => true` in `config.php` makes `scripts/fetch.php` read those fixtures instead of calling the API. Useful for UI work.

## Layout

```
app/        fetch, parse, store, and query logic (not web accessible)
data/       SQLite database (not web accessible)
public/     the website: index.html, assets/, and api/ JSON endpoints
scripts/    fetch.php (cron) and save_fixtures.php (one-off)
tests/      plain PHP tests plus fixtures/
```

JSON endpoints read by the page:

- `api/dashboard.php` - clan, weeks, current and former members. Database only.
- `api/lookup.php?tag=%23TAG` - one player's history. Calls the live API for the player and their current clan, cached 15 minutes per endpoint.

## Deploy to cPanel

Host facts (checked 2026-09-12 in cPanel):

| Item | Value |
| --- | --- |
| Account home | `/home/dancemu` |
| Document root for danteachesmath.net | `/home/dancemu/public_html/danteachesmath` |
| PHP for that domain | 8.3 (`ea-php83`), CLI at `/usr/local/bin/ea-php83` |
| Server | `sardine.exacthosting.com`, shared IP `209.59.190.133` |
| Cron jobs | Available. Output is emailed to the address set on the Cron Jobs page. |
| Terminal | Present in cPanel but the WebSocket would not connect from the browser extension. |

Target layout on the host:

```
/home/dancemu/clan_war_tracker/          <- not web accessible
  app/
  scripts/
  data/                                   <- must be writable; SQLite file lives here
  config.php                              <- host API key, db_path pointing at data/
/home/dancemu/public_html/danteachesmath/clan/   <- contents of public/
  index.html
  assets/
  api/
```

Steps:

1. **API key.** In the Supercell developer portal create a second key whitelisted for `209.59.190.133`. That is the server's own address (its hostname and SPF record both point at it), so it is almost certainly the outbound IP too. If the first fetch returns 403, the host is NATed: run `php -r 'echo file_get_contents("https://api.ipify.org");'` on the server via cron with email output, and re-create the key for whatever it prints.
2. **Upload** `app/`, `scripts/`, and an empty `data/` to `/home/dancemu/clan_war_tracker/`. Create `config.php` there from `config.example.php` with the new key and `'db_path' => __DIR__ . '/data/clan_war_tracker.sqlite'`.
3. **Upload** the contents of `public/` to `/home/dancemu/public_html/danteachesmath/clan/`. The endpoints find `app/` by walking up the directory tree, so no path edits are needed for this layout. If it ever moves, set `CWT_APP_DIR` or edit `public/api/_bootstrap.php`.
4. **First fetch.** Add a one-off cron job for a minute or two from now, wait for the email, then delete it:

   ```
   CWT_CONFIG=/home/dancemu/clan_war_tracker/config.php /usr/local/bin/ea-php83 /home/dancemu/clan_war_tracker/scripts/fetch.php
   ```

   The email should read `members: N`, `log weeks: 10`, `in-progress week saved`. A 403 means the key's IP is wrong. A "could not find driver" error means `pdo_sqlite` is missing from ea-php83, in which case switch the domain to a version that has it in MultiPHP Manager, or ask the host to enable it.
5. **Schedule.** Add two cron jobs. Monday 10:30 UTC catches the finished week; a daily run keeps the in-progress week fresh. Redirect output so cron does not email every run:

   ```
   30 10 * * 1 CWT_CONFIG=/home/dancemu/clan_war_tracker/config.php /usr/local/bin/ea-php83 /home/dancemu/clan_war_tracker/scripts/fetch.php >> /home/dancemu/clan_war_tracker/data/fetch.log 2>&1
   0 4 * * * CWT_CONFIG=/home/dancemu/clan_war_tracker/config.php /usr/local/bin/ea-php83 /home/dancemu/clan_war_tracker/scripts/fetch.php >> /home/dancemu/clan_war_tracker/data/fetch.log 2>&1
   ```

   cPanel cron runs in the server's local time zone, so adjust the hour if the server is not on UTC.
6. Open https://danteachesmath.net/clan.

## How the data fits together

- `raw_fetches` keeps every API response verbatim with a timestamp.
- `war_weeks` has one row per clan per River Race week. The in-progress week comes from `currentriverrace` and is marked provisional until it appears in `riverracelog`.
- `participants` has one row per player per week per clan. Joined on player tag, never name.
- `member_snapshots` records the roster on each fetch. "Current" means present in the newest snapshot; "former" means in `participants` for our clan but not current.
- Weekly fame maxes out at 3600 (16 decks, 225 per win), which is why every chart uses a fixed 0 to 3600 axis.
