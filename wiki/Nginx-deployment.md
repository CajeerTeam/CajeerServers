# Nginx deployment

Начиная с 0.1.9, панель Nuxt SSR и PHP API обслуживаются на одном домене.

- Production + HTTPS: `examples/nginx/cajeerservers-tls.conf`.
- Только локальная разработка: `examples/nginx/cajeerservers.conf`.
- PHP FPM принимает `/api/v1/` и `/install`; остальные пути проходят в Nuxt на loopback `127.0.0.1:3000`.
- `APP_URL` должен соответствовать тому адресу, по которому браузер открывает Admin UI, иначе CSRF/Origin-проверка отклонит изменения.
- Запуск `nginx -t` обязателен перед перезагрузкой конфигурации. Nuxt, PHP-FPM, worker и scheduler должны запускаться под отдельными контролируемыми сервисами.

Подробнее: [Admin UI](Admin-UI.md).
