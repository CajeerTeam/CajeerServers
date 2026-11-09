# CajeerServers 0.1.5 — Authentication & API Tokens

CajeerServers работает как веб-ресурс. Авторизация не требует системных агентов или установки ОС. В версии 0.1.5 доступна **локальная** аутентификация администраторов и пользователей, созданных установщиком/в БД; UI для управления пользователями появится позже.

## Перед первым входом

```bash
# Накатить patch поверх существующего 0.1.4, не удаляя .env и storage/app.
php bin/cajeer migrate:status
php bin/cajeer migrate
php bin/cajeer migrate:validate
php tests/auth-smoke.php
```

Требуется `ext-pdo_pgsql`, PostgreSQL 17+, `APP_KEY` не короче 32 случайных байтов и завершённая установка (`storage/app/installed.json`). В production `APP_URL` должен начинаться с `https://`; TLS завершается на доверенном Nginx. В production PHP должен получать `HTTPS=on` от доверенного front proxy; необработанный `X-Forwarded-Proto` не считается доказательством HTTPS. Для local HTTP cookie называется `cajeer_session`; при HTTPS — `__Host-cajeer_session` с `Secure`, `HttpOnly`, `Path=/`, `SameSite=Strict`. Серверный секрет и его хеш **никогда** не отправляются в обычных API-ответах. Сессия фиксированной продолжительности 12 часов, истекает без sliding-expiration.

Важно: пока нет 2FA верификации, пользователь с включённым `two_factor_enabled=true` **не может** войти только по паролю. До 0.1.6 это намеренное ограничение, исключающее обход 2FA.

## Вход, CSRF, выход

```bash
# Использовать shell без записи секретов в историю. Командная строка ниже — учебный пример.
API='https://servers.example.com/api/v1'
curl --fail-with-body -sS -c cookies.txt -X POST "$API/auth/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"REPLACE_WITH_SECRET"}'
# В JSON придёт csrf_token, который надо сохранить приватно (не в localStorage).
curl -sS -b cookies.txt "$API/auth/me"
curl -sS -b cookies.txt "$API/auth/csrf"   # выдаёт новый csrf_token, старый инвалидируется
curl -sS -b cookies.txt -X POST "$API/auth/logout" \
  -H 'X-CSRF-Token: REPLACE_WITH_LATEST_CSRF'
rm -f cookies.txt
```

**Не передавайте пароль в аргументах командной строки в реальном окружении.** Для автоматизации используйте `curl --data-binary @-` с вводом из защищённого stdin/файла с правами `0600` и отключённым shell history, а для доступа к API после входа — ограниченный bearer-токен. Файл cookies.txt тоже содержит секрет: `chmod 600 cookies.txt` и удалите после использования.

Для браузерных POST/DELETE с cookie требуется `X-CSRF-Token` (получается при входе либо GET `/auth/csrf`); Origin / Referer, если переданы, должны совпадать по схеме/хосту/порту с `APP_URL`. `Sec-Fetch-Site: cross-site` запрещён. Login принимает только `application/json`, а ошибки входа не раскрывают существование email.

## Ограниченные API-токены

Создание доступно **только** по cookie-сессии с CSRF:

```http
POST /api/v1/auth/tokens
Cookie: __Host-cajeer_session=...
X-CSRF-Token: ...
Content-Type: application/json

{"name":"monitoring-client","scopes":["profile:read","sessions:read"],"expires_in":86400}
```

Ответ 201 возвращает поле `token` **один раз**, никогда не записывайте его в application logs. API-токен имеет срок 5 минут — 30 дней; на пользователя максимум 25 действующих токенов. Поддерживаются лишь read-scopes: `profile:read`, `sessions:read`, `tokens:read`. Bearer-токен **не может** менять пароль, создавать/отзывать другие токены, завершать сессии и вызывать инфраструктурные действия.

```http
GET /api/v1/auth/me
Authorization: Bearer at1.<uuid>.<secret>
```

`GET /auth/tokens` и `GET /auth/sessions` возвращают метаданные **только владельца** и требуют соответствующего scope при bearer-аутентификации. `DELETE /auth/tokens/{id}` / `DELETE /auth/sessions/{id}` доступны лишь сессионному пользователю с CSRF. Отозванные/истёкшие сессии и токены не принимаются при последующих запросах.

## Смена пароля и отзыв

```http
POST /api/v1/auth/password
Cookie: __Host-cajeer_session=...
X-CSRF-Token: ...
Content-Type: application/json

{"current_password":"OLD","new_password":"NEW_AT_LEAST_14_BYTES"}
```

Успешный ответ 204: PostgreSQL транзакционно обновляет Argon2id хеш и отзывает **все** сессии и bearer-токены пользователя, включая текущую сессию. Нужно войти снова. Записи audit log фиксируют успешные входы, неудачные попытки, выдачу/отзыв токенов, смену пароля и завершение сессий, но не хранят исходные секреты.

## Проверки и ограничения

```bash
php tests/auth-smoke.php
# Только выделенная disposable PostgreSQL 17+ БД, не production:
CAJEER_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_test_auth \
  CAJEER_PG_TEST_USERNAME=cajeer CAJEER_PG_TEST_PASSWORD='***' \
  php tests/auth-postgres-integration.php
```

Интеграционный тест создаёт таблицы и тестового пользователя в указанной БД и очищает созданного пользователя. Используйте одноразовую БД: тест не удаляет саму схему или миграции. Результат нельзя считать подтверждённым, пока этот тест реально не прошёл в вашем окружении. Для Nginx настройте TLS (`examples/nginx/cajeerservers-tls.conf`) и передачу `Authorization` и признака HTTPS в PHP-FPM:

```nginx
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
fastcgi_param HTTPS on; # только для действительно TLS-терминирующего server block
```

Доступ к внутренним API Proxmox/VMmanager и права RBAC отсутствуют до следующих релизов. Существующие флаги `config/security.php` сами по себе не являются работающими проверками прав.
