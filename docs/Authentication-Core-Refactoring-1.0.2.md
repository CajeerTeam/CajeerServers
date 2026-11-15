# 1.0.2 — Authentication Core Refactoring

## Рабочий код и граница доверия

- `AuthenticationService::loginOrReject()` сохраняет локальный email/password (Argon2id), прежние HTTP-ответы и 12-часовую cookie. Успешная первичная проверка передаётся в `SessionService`, который заново читает заблокированную запись пользователя, проверяет активность и **локальную** MFA, создаёт `cs1` credential, CSRF и аудит в той же PostgreSQL-транзакции. Провал MFA не выдаёт сессию; запись неудачного входа коммитится. Заблокированная учётная запись не расходует recovery code.
- `AuthenticationService::loginVerifiedExternal()` — внутренний, исполняемый серверный вход для **уже криптографически проверенного** внешнего подтверждения. Сопоставление только по точной паре `issuer + subject`, не по email. Привязка должна уже существовать и быть включена. Не принимаются внешние роли/MFA, access token как сессионная cookie, необработанный JSON или пользовательский UUID. `VerifiedExternalIdentity` проверяет форму и свежесть, **не является JWT-верификатором**. Доверенный адаптер обязан проверить signature, issuer/audience/azp, expiry/iat, nonce, PKCE/state и погасить OAuth-транзакцию, затем передать SHA-256 точного проверенного ID token. Нельзя создавать объект из непроверенных claims или HTTP body. В 1.0.2 нет публичного маршрута/кнопки внешнего входа, зарегистрированного провайдера, UI привязки или автоматического создания пользователей. Эти возможности относятся к 1.0.3–1.0.5 и требуют реального SkiF4er ID.
- Повторное использование подтверждения блокируется атомарным `INSERT ... ON CONFLICT DO NOTHING` до MFA. Срок обмена максимум пять минут с `iat`, с допустимым смещением часов 30 секунд в будущее. Повторный внешний вход после ошибочной MFA требует свежего подтверждения. Локальные MFA-коды и recovery codes проверяются прежним `TwoFactorManager`. IP-ограничение и ограничение по внешней идентичности сохраняются в PostgreSQL. Счётчик локальных неудачных входов сериализуется даже для ещё не существующей записи.
- Оба способа используют одинаковые `SessionCookie`, `TokenCodec`, CSRF, отзыв, локальный UUID, RBAC и workspace permissions. `auth_method`, `authenticated_at` (время завершения локального входа, не время внешней SSO), `mfa_verified`, `auth_level` возвращаются в существующем `/auth/sessions` и отображаются в профиле. API-токены остаются самостоятельными read-only credentials; их прежний жизненный цикл и отзыв при смене пароля/настройках MFA не меняются.
- Отключение привязки атомарно отзывает связанные сессии; её повторное включение их не восстанавливает. Удаление привязки каскадно удаляет связанные сессии. Локальные сессии не затрагиваются. `issuer`, `subject`, `id` и `user_id` привязки неизменяемы. Просроченные хэши подтверждений удаляются существующим security cleanup. Полные токены провайдера не сохраняются и не логируются. Для production-интеграции привязку должен создавать будущий защищённый процесс account linking, не произвольный клиент.

## Обновление

1. Сохранить отдельный полный релиз 1.0.1; применить ZIP поверх отдельной копии исходников 1.0.1. Собрать зависимости и Admin/SDK штатным способом (`scripts/build-release.sh`), не перезаписывать `.env`, ключи, installed marker или storage.
2. Остановить старые web/worker процессы, включить maintenance. Запускать из нового релиза с настроенной БД, installation marker 1.0.1 и доступным прежним релизом. Указать новый абсолютный путь резервного архива и существующий ключ резервирования.

```sh
php bin/cajeer maintenance:enable
php bin/cajeer upgrade:apply --previous=/opt/cajeer-releases/1.0.1 --archive=/secure/pre-upgrade-1.0.1.cjdr --key-file=/secure/backup.key --offline
php bin/cajeer release:check --strict --json
php bin/cajeer inventory:integrity --json
php bin/cajeer maintenance:disable
```

Миграция `0065_authentication_core.sql` добавляется к 64 неизменённым миграциям; существующие сессии получают `auth_method=password`, `authenticated_at=created_at`, а идентификаторы, хэши, сроки и MFA остаются прежними. При ошибке оставить maintenance включённым и возобновить upgrade или восстановить проверенный backup в отдельную пустую БД/хранилище штатным `upgrade:restore`. Нельзя запускать прежний код на обновлённой БД без предусмотренного восстановления.

## Приёмка

```sh
php tests/auth-smoke.php
php tests/upgrade-smoke.php
php scripts/verify-release.php
# Реальный PostgreSQL 17+ и disposable cajeer_test_* database,
# настройки CAJEER_PG_TEST_* и Redis test из существующей CI:
bash scripts/acceptance-postgres.sh php tests/auth-postgres-integration.php
bash scripts/acceptance-postgres.sh php tests/security-postgres-integration.php
bash scripts/acceptance-postgres.sh php tests/upgrade-postgres-integration.php
```

Новые сценарии входят в существующие обязательные PostgreSQL steps, а строгий релиз проверяет 65 миграций и version-bound format 3 acceptance. Для выпуска stable по-прежнему обязательны реальные PostgreSQL/Redis, locked Composer/Admin build, browser/HTTP, live-device, DR/capacity и 72h soak плюс независимое подписанное approval. Проверка с реальным SkiF4er ID относится к последующим версиям. Не публиковать `stable` по результатам только native smoke.
