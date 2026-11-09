export default defineNuxtRouteMiddleware(async (to) => {
  // No personal data is rendered into SSR HTML; authentication is verified by PHP on the client.
  if (import.meta.server) return
  const auth = useAuth()
  const ok = await auth.refresh()
  if (!ok && to.path !== '/login') return navigateTo('/login', { replace: true })
  if (ok && to.path === '/login') return navigateTo('/', { replace: true })
})
