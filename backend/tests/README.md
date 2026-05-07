# Testing

## Setup

```bash
# 1. Install dev dependencies
composer install

# 2. Create test DB
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS mailpilot_test CHARACTER SET utf8mb4;"
mysql -uroot mailpilot_test < ../sql/schema.sql
```

## Running

```bash
# All tests
composer test

# Unit only (fast, no DB)
./vendor/bin/phpunit --testsuite=Unit

# Integration (needs MariaDB)
./vendor/bin/phpunit --testsuite=Integration

# Single file
./vendor/bin/phpunit tests/Unit/RedactionServiceTest.php

# With coverage
./vendor/bin/phpunit --coverage-text --coverage-html=coverage/
```

## Test groups

- `@group integration` — needs real DB
- `@group security` — tenant isolation and other security invariants

## Inside Docker

```bash
docker compose exec backend bash -c "cd /app && composer install --dev"
docker compose exec backend /app/vendor/bin/phpunit
```

## Writing new tests

Extend `MailPilot\Tests\TestCase` for DB-backed tests — helpers for insert
and tenant isolation are provided. Use `FakeClaudeClient` to avoid hitting the
real API; script responses with `scriptJson()` or `scriptResponse()`.

Unit tests must not touch the DB. If you need a repository, mock it.
