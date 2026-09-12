# Участие в разработке

Спасибо за интерес к CajeerServers.

## Роли

- Основатель: SkiF4er
- Мейнтейнеры: Cajeer Team
- Спонсоры: DeepLayer Team
- Контрибьюторы: markovav

## Правила вклада

1. Обсуждайте крупные изменения через issues/discussions.
2. Соблюдайте SemVer и структуру changelog.
3. Пишите код с учётом PHP 8.4+ и PHP 8.5 readiness.
4. Не добавляйте OS-level зависимости в ядро: CajeerServers остаётся веб-ресурсом.
5. Новые интеграции оформляйте через connector manifest и permission scopes.
6. Все изменения API должны быть отражены в OpenAPI 3.1.1.
7. Документация пишется на русском языке.

## Проверки перед PR

```bash
composer test
php bin/cajeer doctor
php bin/cajeer openapi:lint
php bin/cajeer self:test
```

## Контакты

- dev@cajeer.ru
- support@cajeer.ru
- contact@cajeer.ru
