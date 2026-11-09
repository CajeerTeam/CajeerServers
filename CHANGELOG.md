# Changelog

## [0.1.3] — Database & Migration Engine (2026-11-09)

### Added
- Настоящие PostgreSQL PDO connections, транзакции/savepoints и безопасный DSN builder.
- Атомарное выполнение SQL-миграций под PostgreSQL advisory lock; SHA-256 journal, контроль истории, dry-run.
- SQL statement parser с поддержкой PL/pgSQL dollar quoting и вложенных комментариев.
- CLI database:check, migrate, migrate:status, migrate:pending, migrate:validate и совместимые standalone entrypoints.
- Миграция role/permission associations и индексов; native database smoke-tests и изолированный PostgreSQL integration test.

### Security
- Предотвращение незаметного изменения/пропажи применённых миграций.
- Запрет незадекларированного baseline и out-of-order SQL; запрет миграций MySQL до достижения parity.

### Known limitations
- PostgreSQL integration test требует отдельного реального PostgreSQL 17/18 и ext-pdo_pgsql; CI без DB не подтверждает integration.
- Отсутствует Composer lockfile; дополнительные API/аутентификация не публикуются.

## 0.1.2 — HTTP Kernel & REST API (2026-11-09)

- Работающий router, middleware pipeline, динамические параметры пути, HEAD/OPTIONS/405 и консистентные problem+json ответы.
- Реальные endpoints `/api/v1/health` (проверка runtime readiness) и `/api/v1/system` (публичные метаданные); незавершённые endpoints не публикуются.
- Symfony HttpFoundation request/response adapter при наличии пакета; native fallback для самостоятельной диагностики без Composer.
- Ограничение входного тела до 1 МиБ, строгий JSON parser/validator, защищённые HTTP-заголовки, request ID, структурированные access logs.
- Native HTTP smoke и PHPUnit feature tests; OpenAPI 3.1.1 отражает только реально существующие API-операции.


Формат основан на Keep a Changelog, версия проекта следует SemVer.

## [0.1.1] - 2026-11-09

### Added
- Исполняемый PHP bootstrap и жизненный цикл приложения (boot/handle/terminate).
- Dotenv loader с приоритетом process environment и типизированными значениями.
- Ленивая конфигурация по вложенным ключам с кешированием и защитой путей.
- DI: instance, singleton, transient, alias, autowire, обнаружение циклов.
- JSONL runtime logging с редактированием секретов и обработкой ошибок.
- HTTP runtime: root JSON, 404, 405, 503, request ID, HEAD support.
- CLI: version, about, config:get, doctor, config:check, self:test с реальными exit codes.
- Native smoke tests, PHPUnit runtime tests, GitFlic CI, документация запуска.

### Changed
- Неисполняемые команды `migrate`, `queue:work` и другие завершаются кодом 3 вместо ложного успеха.
- Не реализованный установщик явно сообщает о недоступности.
- `.env.example` больше не содержит фиктивных ключей/паролей.

### Known limitations
- HTTP `/api/v1/*`, auth, БД, установка, очереди и коннекторы — не реализованы в 0.1.1.
- `composer.lock` не генерировался без Composer/доступа к репозиториям; перед воспроизводимой поставкой его нужно создать и проверить.

## [0.1.0] - 2026-06-15

### Added
- Начальный skeleton CajeerServers.
- README, ROADMAP, SECURITY, CONTRIBUTING, CODE_OF_CONDUCT.
- OpenAPI 3.1.1 skeleton.
- PHP backend core skeleton.
- Nuxt/Vue admin skeleton.
- Inventory, connectors, collectors, scripts, sync engine и action dispatcher placeholders.
- PostgreSQL/MySQL migration skeleton.
- GitFlic Wiki стартовые страницы.
