/** Workspace context is carried in URLs, never in localStorage or browser secrets. */
export type InventorySection = 'inventory' | 'ipam' | 'topology' | 'catalog' | 'history' | 'transfer' | 'workspaces' | 'connectors' | 'vault' | 'collections' | 'scripts'
const sections: Record<InventorySection, string> = {
  inventory: '/inventory', ipam: '/ipam', topology: '/topology', catalog: '/catalog',
  history: '/history', transfer: '/transfer', workspaces: '/workspaces', connectors: '/connectors', vault: '/vault', collections: '/collections', scripts: '/scripts',
}
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
export function inventoryContextUrl(section: InventorySection, workspaceId?: string, assetId?: string): string {
  const params = new URLSearchParams()
  if (workspaceId && UUID.test(workspaceId)) params.set('workspace_id', workspaceId)
  if (assetId && UUID.test(assetId)) params.set('asset_id', assetId)
  const suffix = params.toString()
  return `${sections[section]}${suffix ? `?${suffix}` : ''}`
}
export function inventoryAssetUrl(id: string, workspaceId?: string): string {
  if (!UUID.test(id)) return inventoryContextUrl('inventory', workspaceId)
  const params = new URLSearchParams()
  if (workspaceId && UUID.test(workspaceId)) params.set('workspace_id', workspaceId)
  return `/inventory/${encodeURIComponent(id)}${params.size ? `?${params}` : ''}`
}
export function queryUuid(value: unknown): string {
  return typeof value === 'string' && UUID.test(value) ? value : ''
}
export function selectWorkspace<T extends { id: string }>(spaces: T[], requested: unknown): string {
  const id = queryUuid(requested)
  return spaces.some(space => space.id === id) ? id : (spaces[0]?.id || '')
}
