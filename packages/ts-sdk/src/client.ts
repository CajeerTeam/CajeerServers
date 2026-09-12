import type { InfrastructureAsset } from './types'

export class CajeerServersClient {
  constructor(private readonly baseUrl: string, private readonly token: string) {}

  async assets(): Promise<InfrastructureAsset[]> {
    const res = await fetch(`${this.baseUrl}/inventory/assets`, { headers: { Authorization: `Bearer ${this.token}` } })
    const json = await res.json()
    return json.data ?? []
  }
}
