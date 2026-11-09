export default defineNuxtConfig({
  compatibilityDate: '2025-01-01',
  ssr: true,
  devtools: { enabled: false },
  css: ['~/assets/css/main.css'],
  app: { head: { title: 'CajeerServers — Admin', htmlAttrs: { lang: 'ru' }, meta: [
    { name: 'robots', content: 'noindex,nofollow,noarchive' },
    { name: 'referrer', content: 'no-referrer' },
  ] } },
  runtimeConfig: { public: { apiBase: '/api/v1' } },
  nitro: { routeRules: { '/**': { headers: { 'X-Content-Type-Options': 'nosniff' } } } },
})
