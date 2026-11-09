import type { AdminSession, AdminUser } from './useApi'

export function useAuth() {
  const user = useState<AdminUser | null>('auth:user', () => null)
  const permissions = useState<string[]>('auth:permissions', () => [])
  const ready = useState<boolean>('auth:ready', () => false)
  const pending = useState<boolean>('auth:pending', () => false)
  const api = useApi()
  const can = (permission: string) => permissions.value.includes(permission)
  async function refresh() {
    if (import.meta.server) return false
    pending.value = true
    try {
      const result = await api.get<AdminSession>('/admin/session')
      user.value = result.user
      permissions.value = result.permissions
      return true
    } catch {
      user.value = null
      permissions.value = []
      return false
    } finally { ready.value = true; pending.value = false }
  }
  async function signIn(email: string, password: string, mfa_code: string) {
    const data = { email, password, ...(mfa_code.trim() ? { mfa_code: mfa_code.trim() } : {}) }
    await api.write('POST', '/auth/login', data, false)
    if (!(await refresh())) throw new Error('Сессия создана, но не удалось получить профиль.')
  }
  async function signOut() {
    await api.write('POST', '/auth/logout')
    user.value = null; permissions.value = []; ready.value = true
  }
  return { user, permissions, can, ready, pending, refresh, signIn, signOut }
}
