# Yummy Pizza!

Nearby pizza places from [snaterlicious](https://github.com/snaterlicious)' [Pizza map](https://www.google.com/maps/d/viewer?mid=z2Qplwtk3f0Q.kaiyuAkHjF00).

The page asks for your location, then lists up to 10 places. Scores are school grades: 1 is best.

## Run it locally

PHP 8.5 with `pdo_mysql`, plus MySQL or MariaDB:

```powershell
copy config.yml.sample config.yml
php update.php
php -S localhost:8000
```

Open http://localhost:8000 and allow location, or use pre-defined Alexanderplatz.

Docker Compose runs PHP 8.5 and a database without a local PHP install:

```powershell
docker compose up --build -d
docker compose exec web php update.php
```

Open http://localhost:8095.

## Where the data comes from

The importer reads https://www.google.com/maps/d/kml?mid=z2Qplwtk3f0Q.kaiyuAkHjF00&forcekml=1