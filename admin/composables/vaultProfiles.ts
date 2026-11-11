/** Public Vault profile descriptors; secret values are NEVER stored outside volatile form state. */
export interface VaultProfile {
  type: string
  format: 'string' | 'object'
  description: string
  required_fields: string[]
  optional_fields: string[]
}

export interface VaultCredential {
  id: string
  workspace_id: string
  name: string
  description: string
  type: string
  state: 'active' | 'disabled' | 'archived'
  version: number
  secret_version: number
  etag: string
  created_at: string
  updated_at: string
  deleted_at: string | null
}

export interface VaultVersion {
  secret_version: number
  state: 'active' | 'pending' | 'retired' | 'discarded'
  created_at: string
  activated_at: string | null
  retired_at: string | null
  required_targets?: number
  validated_targets?: number
  activation_ready?: boolean
}

const enums: Record<string, string[]> = {
  auth: ['negotiate', 'ntlm', 'kerberos', 'basic'],
  auth_protocol: ['sha256', 'sha512'],
  privacy_protocol: ['aes128', 'aes256'],
}

export function vaultFieldOptions(field: string): readonly string[] {
  return enums[field] || []
}

/** Browser never displays secret fields in cleartext; identifiers are still editable. */
export function vaultFieldIsSensitive(field: string): boolean {
  return !['username', 'token_id'].includes(field) && !vaultFieldOptions(field).length
}

export function vaultSecretPayload(profile: VaultProfile, scalar: string, fields: Record<string, string>): string | Record<string, string> {
  if (profile.format === 'string') {
    if (!scalar.length) throw new Error('Введите значение секрета.')
    return scalar
  }
  const result: Record<string, string> = {}
  for (const field of profile.required_fields) {
    if (!fields[field]?.length) throw new Error(`Заполните обязательное поле: ${field}.`)
    result[field] = fields[field]
  }
  for (const field of profile.optional_fields) {
    if (fields[field]?.length) result[field] = fields[field]
  }
  if (profile.type === 'snmp_v3' && Boolean(result.privacy_protocol) !== Boolean(result.privacy_passphrase)) {
    throw new Error('Для SNMPv3 укажите одновременно privacy_protocol и privacy_passphrase.')
  }
  return result
}

/** Drop all references held by the form after an attempt or context change. */
export function clearVaultDraft(fields: Record<string, string>): void {
  for (const field of Object.keys(fields)) {
    fields[field] = ''
    delete fields[field]
  }
}
