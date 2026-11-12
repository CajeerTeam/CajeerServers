export interface AdminUser { id: string; name: string; email: string }
export interface AdminSession { user: AdminUser; permissions: string[]; auth_type: 'session' }
export interface ApiFailure { status?: number; code?: string; message: string; requestId?: string }

export function apiError(error: unknown): ApiFailure {
  const err = error as { statusCode?: number; data?: { title?: string; code?: string; request_id?: string } }
  const status = err?.statusCode
  const message = typeof err?.data?.title === 'string' ? err.data.title :
    status === 401 ? 'Войдите в систему.' : status === 403 ? 'Недостаточно прав.' :
    status === 503 ? 'Сервис временно недоступен.' : 'Ошибка запроса. Повторите попытку.'
  return { status, message, code: err?.data?.code, requestId: err?.data?.request_id }
}

/** Same-origin browser transport. No persisted browser credentials. */
export function useApi() {
  const base = useRuntimeConfig().public.apiBase || '/api/v1'
  async function get<T>(path: string): Promise<T> {
    return await $fetch<T>(`${base}${path}`, {
      credentials: 'include', cache: 'no-store', headers: { Accept: 'application/json' },
    }) as T
  }
  async function write<T>(method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', path: string, body?: unknown, csrf = true, extraHeaders: Record<string,string> = {}): Promise<T> {
    const headers: Record<string, string> = { Accept: 'application/json', ...extraHeaders }
    if (csrf) {
      // Rotating CSRF per operation means reloads never need to persist a token.
      const response = await get<{ csrf_token: string }>('/auth/csrf')
      headers['X-CSRF-Token'] = response.csrf_token
    }
    if (body !== undefined) headers['Content-Type'] = 'application/json'
    return await $fetch<T>(`${base}${path}`, {
      method, retry: 0, credentials: 'include', cache: 'no-store', headers,
      ...(body === undefined ? {} : { body }),
    }) as T
  }
  return { get, write }
}
