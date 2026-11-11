import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { vaultSecretPayload, clearVaultDraft, vaultFieldIsSensitive, vaultFieldOptions } from '../composables/vaultProfiles.ts'
import { inventoryContextUrl } from '../composables/inventoryNavigation.ts'
const page = readFileSync(new URL('../pages/vault.vue', import.meta.url), 'utf8')
let checks = 0
function ok(condition, label) { assert.equal(!!condition, true, label); checks++ }
const scalar = { type: 'api_token', format: 'string', description: '', required_fields: [], optional_fields: [] }
const object = { type: 'proxmox_api', format: 'object', description: '', required_fields: ['token_id', 'token_secret'], optional_fields: [] }
ok(vaultSecretPayload(scalar, 'new-token', {}) === 'new-token', 'scalar pass-through')
ok(JSON.stringify(vaultSecretPayload(object, '', { token_id: 'root@pam!e2e', token_secret: 'secret' })) ===
  '{"token_id":"root@pam!e2e","token_secret":"secret"}', 'typed payload')
for (const [profile, text, fields] of [[scalar, '', {}], [object, '', { token_id: 'foo' }]]) {
  assert.throws(() => vaultSecretPayload(profile, text, fields)); checks++
}
const snmp = { type: 'snmp_v3', format: 'object', description: '', required_fields: ['username', 'auth_protocol', 'auth_passphrase'], optional_fields: ['privacy_protocol','privacy_passphrase'] }
assert.throws(() => vaultSecretPayload(snmp,'',{username:'monitor',auth_protocol:'sha256',auth_passphrase:'longpassphrase',privacy_protocol:'aes256'})); checks++
const draft = { username: 'foo', password: 'sensitive', private_key: 'private' }
clearVaultDraft(draft)
ok(Object.keys(draft).length === 0, 'clear volatile state')
ok(vaultFieldIsSensitive('password'), 'password masked')
ok(vaultFieldIsSensitive('token_secret'), 'token secret masked')
ok(!vaultFieldIsSensitive('username'), 'username editable')
ok(vaultFieldOptions('privacy_protocol').includes('aes256'), 'snmp option list')
ok(inventoryContextUrl('vault','00000000-0000-4000-8000-000000000001') ===
  '/vault?workspace_id=00000000-0000-4000-8000-000000000001', 'workspace navigation')
for (const [pattern,label] of [
  ['/vault/credentials','live Vault endpoints'],
  ['/vault/profiles','server-supplied descriptors'],
  ['/versions','rotation history'],
  ['/rotation','two-stage rotation'],
  ['/secret','direct replacement'],
  ["'If-Match'",'ETag on mutations'],
  ['dropCreateSecret()','clear secrets after submit'],
  ['dropOperationSecret()','clear operation secrets after submit'],
  ['canDelete','role + permission for archive'],
  ['canUpdate','role + permission for mutation'],
  ['confirmation','operator attestation'],
]) ok(page.includes(pattern), label)
ok(!page.includes('localStorage') && !page.includes('sessionStorage'), 'no local persistent secret storage')
ok(!page.includes('console.log') && !page.includes('JSON.stringify(secret'), 'no logging secret')
console.log(`Vault UI contract: ${checks} PASS`)
