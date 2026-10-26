# Yummy Pizza!

Nearby pizza places from [snaterlicious](https://github.com/snaterlicious)' [Pizza map](https://www.google.com/maps/d/viewer?mid=z2Qplwtk3f0Q.kaiyuAkHjF00).

The site is PHP 8.5 and MySQL/MariaDB. The page asks for your location, then lists up to 10 places. Scores are school grades: 1 is best.

## Run it locally

PHP 8.5 with `pdo_mysql`, plus MySQL or MariaDB:

```powershell
copy config.yml.sample config.yml
php update.php
php -S localhost:8000
```

Open http://localhost:8000 and allow location, or use Alexanderplatz.

`db_name` can be omitted. The importer then uses `db_user` as the database name, which is what the old config did.

Docker Compose runs PHP 8.5 and a database without a local PHP install:

```powershell
docker compose up --build -d
docker compose exec web php update.php
```

Open http://localhost:8095.

`php update.php --dry-run` downloads and parses the map without writing to the database. The same command is the hourly refresh.

## Database

The `location` and `visit` tables stay. `schema.sql` creates them on a new database and does nothing if they already exist.

On import, two adjustments are applied only when the old table is too strict:

- `visit.date` becomes nullable, because one place has no visit date
- `visit.description` is widened to `TEXT` when it is a short `VARCHAR`

Places with several visits now get one `visit` row each. The list uses the latest visit. Re-running the import replaces rows that disappeared from the map.

## Where the data comes from

KML is still the right source. The quality score, value score, and pin colour live in that My Map as description text and icon style. Google Places does not have those notes. The importer reads:

https://www.google.com/maps/d/kml?mid=z2Qplwtk3f0Q.kaiyuAkHjF00&forcekml=1

A description can contain more than one visit, separated by `|` or `<br>`, including a non-breaking space before `Q:` / `PL:`. Each scored visit is stored. A visit without both scores is reported and skipped.
