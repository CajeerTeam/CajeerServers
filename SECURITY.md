# Политика безопасности

CajeerServers управляет инфраструктурными данными и доступами, поэтому безопасность является обязательным слоем архитектуры.

## Контакты

Сообщения о проблемах безопасности: security@cajeer.ru

Дополнительные адреса:

- abuse@cajeer.ru
- legal@cajeer.ru
- admin@cajeer.ru

## Базовые требования

- RBAC для пользователей и сервисных токенов.
- Permission scopes для API и коннекторов.
- 2FA для административных учётных записей.
- Rate limiting для API и login flow.
- CSRF protection для web UI.
- Security headers для публичного контура.
- Signed payloads для webhooks и Event API.
- Audit log для всех действий с инфраструктурой.
- Encrypted credentials vault для токенов, ключей и секретов.

## Что считается уязвимостью

- Обход RBAC или permission scopes.
- Доступ к credentials vault без разрешения.
- Подмена webhook/event payload.
- RCE через scripts/collectors/connectors.
- Утечка API token, SSH key, SNMP community или WinRM credentials.
- SSRF в connector layer.
- SQL injection, XSS, CSRF, path traversal.
- Возможность выполнить destructive action без подтверждения или аудита.

## Security releases

Критические исправления выпускаются отдельными patch-релизами и документируются в CHANGELOG.md.
