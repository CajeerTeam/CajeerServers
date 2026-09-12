export type AssetType = 'server' | 'vm' | 'container' | 'router' | 'workstation' | 'network'

export interface InfrastructureAsset {
  id: string
  type: AssetType
  name: string
  status: string
  source: string
}
