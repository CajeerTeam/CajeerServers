import type { NitroRouteConfig } from 'nitropack/types'

// Nuxt 3's server compatibility types omit Nitro's supported headers rule.
declare module '@nuxt/schema' {
  interface RouteRuleConfigExtensions { headers?: NitroRouteConfig['headers'] }
}
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
