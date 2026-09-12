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

1. Create an API key whitelisted for the host's outbound IP (not the site's DNS IP). Find it with `curl -s https://api.ipify.org` from a cPanel terminal or a cron job that emails its output.
2. Above `public_html`, create `clan_war_tracker/` and upload `app/`, `scripts/`, `tests/` (optional), and a filled-in `config.php` with `db_path` pointing to a writable `data/` directory there.
3. Upload the contents of `public/` to `public_html/clan/`.
4. The endpoints find `app/` automatically when it sits at `../../../app` relative to `public_html/clan/api/`. If the layout differs, set the `CWT_APP_DIR` environment variable, or edit the candidate list in `public/api/_bootstrap.php`.
5. Add a cron job. Monday 10:30 UTC catches the finished week; a daily run keeps the in-progress week fresh:

   ```
   30 10 * * 1 CWT_CONFIG=/home/USER/clan_war_tracker/config.php php /home/USER/clan_war_tracker/scripts/fetch.php >> /home/USER/clan_war_tracker/data/fetch.log 2>&1
   0 4 * * * CWT_CONFIG=/home/USER/clan_war_tracker/config.php php /home/USER/clan_war_tracker/scripts/fetch.php >> /home/USER/clan_war_tracker/data/fetch.log 2>&1
   ```

6. Run `fetch.php` once by hand and load the page.

Still to confirm before the first deploy: the host's outbound IP, its PHP version, and that cPanel cron is available.

## How the data fits together

- `raw_fetches` keeps every API response verbatim with a timestamp.
- `war_weeks` has one row per clan per River Race week. The in-progress week comes from `currentriverrace` and is marked provisional until it appears in `riverracelog`.
- `participants` has one row per player per week per clan. Joined on player tag, never name.
- `member_snapshots` records the roster on each fetch. "Current" means present in the newest snapshot; "former" means in `participants` for our clan but not current.
- Weekly fame maxes out at 3600 (16 decks, 225 per win), which is why every chart uses a fixed 0 to 3600 axis.
