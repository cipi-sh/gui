# Local development

Bootstrap a Laravel host app that loads `cipi/gui` from the parent directory via Composer path repository.

## Requirements

- PHP 8.3+
- Composer 2.x
- SQLite (default Laravel driver) or MySQL

## Setup

```bash
chmod +x dev/setup.sh
./dev/setup.sh
```

## Run

```bash
cd dev/host
php artisan serve
```

Open http://127.0.0.1:8000/login

Default credentials (from seed command):

- **Email:** `admin@cipi.local`
- **Password:** `admin`

## Reset

```bash
rm -rf dev/host
./dev/setup.sh
```
