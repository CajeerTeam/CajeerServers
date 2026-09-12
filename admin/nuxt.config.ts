export default defineNuxtConfig({
  ssr: true,
  devtools: { enabled: true },
  app: { head: { title: 'CajeerServers' } },
  runtimeConfig: { public: { apiBase: process.env.NUXT_PUBLIC_API_BASE || '/api/v1' } }
})
