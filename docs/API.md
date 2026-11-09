# CajeerServers REST API

OpenAPI 3.1.1: [`api/openapi.yaml`](../api/openapi.yaml). Корень API: `/api/v1`.

Доступные HTTP-маршруты: `GET /health`, `GET /system` (публичные), а также вход/выход и управление собственными сессиями, паролем и API-токенами по `/auth/*`.

**Inventory / CMDB** теперь имеет рабочие защищённые CRUD-маршруты `/inventory/assets` и `/inventory/assets/{id}` (GET, POST, PATCH, DELETE, POST restore). Каждая операция требует серверную сессию; write — CSRF, глобальный RBAC и членство owner/manager в workspace. PATCH/DELETE/restore используют ETag/If-Match (`412` при устаревшей версии, `428` при отсутствии заголовка). Полная инструкция: [Inventory.md](Inventory.md).

Коннекторы, управление гипервизорами, исполнение скриптов и автоматическая синхронизация остаются недоступными и не возвращают фиктивный успех. Полный список аутентификации: [Authentication.md](Authentication.md).

Коды ошибок: `400`, `401`, `403`, `404`, `405`, `406`, `413`, `415`, `422`, `429`, `500`, `503`. Ошибки возвращаются как `application/problem+json` с `request_id`. Не передавайте `Authorization`, `Cookie` или `X-CSRF-Token` в URL/query-параметрах.
