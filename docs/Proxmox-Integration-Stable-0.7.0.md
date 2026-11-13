# 0.7.0 — Proxmox Integration Stable

## Обновление

Распакуйте изменения поверх 0.6.9, остановите workers на время миграции, выполните `php bin/cajeer migrate`, перезапустите API и workers. Миграция 0045 добавляет индекс для повторной проверки неизвестных UPID; прежние миграции не изменены. Пересоберите Admin UI при развёртывании нового релиза. Убедитесь, что для каждого изменяемого PVE target действует план `proxmox.tasks` (inspect) с подходящим интервалом, например 60 секунд.

## Стабилизация

- Единая карта worker-типов для всех 14 power/snapshot действий. Сверка истории проверяет workspace, target, версию, UPID, тип гостя, VMID и worker. Delete/rollback больше не интерпретируются как create.
- История ограниченно опрашивает running, unknown и ожидающие action UPID. Подтверждённые терминальные результаты и первый известный endtime не регрессируют. Неизвестный POST без UPID не повторяется автоматически.
- PBS сначала получает идентичности datastores и ограниченные списки каждого типа jobs, затем capacity, GC и глубокий backup inventory. Это не даёт первому крупному datastore вытеснить все остальные из метаданных. Ограничения сохраняются: до 2500 строк, 100 запросов, около 700 КБ; 256 datastores и 128 записей каждого из sync/verify/prune за запуск. Усечённый результат явно неполный; полного инкрементального PBS inventory нет.

## Приёмка реального PVE/PBS

`php scripts/verify-proxmox-live.php /etc/cajeer/proxmox-acceptance.json` выполняет read-only запросы через действующие драйверы, проверенный TLS, pin и явный сетевой allowlist. Нужны операторские лабораторные PVE и PBS, доступные с хоста проверки. Укажите реальный ожидаемый гость с диском на указанном storage и NIC на указанном bridge, datastore с существующей резервной копией и ожидаемые виды заданий. Пример конфигурации (адреса, pin и идентификаторы заменить):

```json
{
  "allowed_networks": ["192.0.2.10/32", "192.0.2.20/32"],
  "pve": {
    "host": "pve.lab.example", "port": 8006,
    "ca_file": "/etc/cajeer/pve-ca.pem", "credential_type": "proxmox_api",
    "secret_file": "/etc/cajeer/pve.secret",
    "profile": {"cluster": null, "anchor_node": "pve", "anchor_id": null,
      "primary_pin": "REPLACE_WITH_64_HEX_SHA256_CERTIFICATE_PIN", "fallbacks": []}
  },
  "pbs": {"host": "pbs.lab.example", "port": 8007,
    "ca_file": "/etc/cajeer/pbs-ca.pem", "secret_file": "/etc/cajeer/pbs.secret"},
  "expect": {"node": "pve", "guest": "qemu:101", "storage": "local-lvm",
    "bridge": "vmbr0", "datastore": "lab", "namespace": "", "backup_type": "vm",
    "backup_id": "101", "job_kinds": ["sync", "verify", "prune", "gc"]}
}
```

PVE secret: JSON `{"token_id":"user@realm!token","token_secret":"..."}`; PBS secret: `user@realm!token:secret`. Файлы — обычные, не symlink, права 0600/0400, вне репозитория. Для кластера задайте действительные cluster и anchor_id, как в профиле 0.6.1. Не передавайте credentials в аргументах команды. Используйте отдельные токены с правами чтения на проверяемые ресурсы. CLI не изменяет состояние гостей или backups, не сохраняет токены или удалённые payload в отчёт. Отчёт содержит 11 проверок; exit 0 — все успешны, 1 — нет доказательства/сбой API, 2 — некорректная конфигурация. Неполная PBS coverage не проходит приёмку. Проба console проверяет ссылку, не браузерную сессию. Для больших кластеров ограниченные выборки могут требовать отдельного небольшого лабораторного target.

## Release gate

Native: `php scripts/verify-release.php`. TLS fixture: `python3 tests/proxmox-admin-fixture-integration.py`, `python3 tests/proxmox-profile-https-integration.py`, `python3 tests/proxmox-snapshots-https-integration.py`, `python3 tests/pbs-backup-https-integration.py`. Строгий gate: `CAJEER_PROXMOX_ACCEPTANCE_CONFIG=/etc/cajeer/proxmox-acceptance.json php scripts/verify-release.php --strict` вместе с существующими переменными PostgreSQL/Redis/deployment/E2E (см. `scripts/verify-release.php`). Не подменяйте real acceptance синтетическим fixture при выпуске stable. Изолированный браузерный E2E выполняет изменяющие запросы только к fixture. Реальные write/rollback сценарии проверяются отдельно оператором на одноразовых гостях; на production-ресурсах автоматическая приёмка их не запускает.

Канал development сохраняется до успешной проверки lockfiles, сборки Nuxt, PostgreSQL, Redis, браузерного staging и реального PVE/PBS. Наличие CLI и пройденные локальные fixture-тесты не являются доказательством работы на конкретном гипервизоре. PBS остаётся read-only; HA-managed guests и опасные RAM rollback по-прежнему не поддерживаются.
