import { defineStore } from 'pinia'
import type { ExpiryItem, ExpiryKindInfo, ExpiryRow, Vehicle, VehicleDetail, VehicleExpirySummary, VehicleListResponse } from '~/types'

/**
 * Parc auto: the company's vehicles and everything with an expiry date — vehicle documents
 * (RCA, ITP, rovinietă, CASCO, tahograf …) and company-level items (certificat digital,
 * contracts, autorizații). Renewing an item creates the next one and closes the old one.
 */
export const useFleetStore = defineStore('fleet', () => {
  const vehicles = ref<Vehicle[]>([])
  const vehicleExpiries = ref<Record<string, VehicleExpirySummary>>({})
  const expiries = ref<ExpiryItem[]>([])
  const expiryCounts = ref({ total: 0, expired: 0, due: 0, ok: 0 })
  const upcoming = ref<ExpiryRow[]>([])
  const upcomingCounts = ref({ total: 0, expired: 0, due: 0, ok: 0 })
  const kinds = ref<ExpiryKindInfo[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchVehicles(params: { active?: boolean, search?: string } = {}): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null
    try {
      const res = await get<VehicleListResponse>('/v1/vehicles', params)
      vehicles.value = res.data
      vehicleExpiries.value = res.expiries
    }
    catch (err: any) {
      error.value = err?.data?.error ?? 'Nu s-au putut incarca vehiculele.'
      vehicles.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function fetchVehicle(id: string): Promise<VehicleDetail> {
    const { get } = useApi()
    return await get<VehicleDetail>(`/v1/vehicles/${id}`)
  }

  async function createVehicle(payload: Partial<Vehicle>): Promise<VehicleDetail> {
    const { post } = useApi()
    const detail = await post<VehicleDetail>('/v1/vehicles', payload)
    await fetchVehicles()
    return detail
  }

  async function updateVehicle(id: string, payload: Partial<Vehicle>): Promise<VehicleDetail> {
    const { patch } = useApi()
    const detail = await patch<VehicleDetail>(`/v1/vehicles/${id}`, payload)
    const i = vehicles.value.findIndex(v => v.id === id)
    if (i >= 0) vehicles.value[i] = detail.vehicle
    return detail
  }

  async function deleteVehicle(id: string): Promise<void> {
    const { del } = useApi()
    await del(`/v1/vehicles/${id}`)
    vehicles.value = vehicles.value.filter(v => v.id !== id)
  }

  async function fetchExpiries(params: { kind?: string, vehicleId?: string, companyLevel?: boolean, includeClosed?: boolean } = {}): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null
    try {
      const res = await get<{ data: ExpiryItem[], counts: typeof expiryCounts.value, total: number }>('/v1/expiries', params)
      expiries.value = res.data
      expiryCounts.value = res.counts
    }
    catch (err: any) {
      error.value = err?.data?.error ?? 'Nu s-au putut incarca expirarile.'
      expiries.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function fetchUpcoming(days = 60): Promise<void> {
    const { get } = useApi()
    const res = await get<{ data: ExpiryRow[], counts: typeof upcomingCounts.value }>('/v1/expiries/upcoming', { days })
    upcoming.value = res.data
    upcomingCounts.value = res.counts
  }

  async function fetchKinds(): Promise<ExpiryKindInfo[]> {
    if (kinds.value.length) return kinds.value
    const { get } = useApi()
    const res = await get<{ data: ExpiryKindInfo[] }>('/v1/expiries/kinds')
    kinds.value = res.data
    return kinds.value
  }

  async function createExpiry(payload: Record<string, any>): Promise<ExpiryItem> {
    const { post } = useApi()
    return await post<ExpiryItem>('/v1/expiries', payload)
  }

  async function updateExpiry(id: string, payload: Record<string, any>): Promise<{ item: ExpiryItem, history: ExpiryItem[] }> {
    const { patch } = useApi()
    return await patch<{ item: ExpiryItem, history: ExpiryItem[] }>(`/v1/expiries/${id}`, payload)
  }

  async function renewExpiry(id: string, payload: Record<string, any> = {}): Promise<{ item: ExpiryItem, previous: ExpiryItem }> {
    const { post } = useApi()
    return await post<{ item: ExpiryItem, previous: ExpiryItem }>(`/v1/expiries/${id}/renew`, payload)
  }

  async function deleteExpiry(id: string): Promise<void> {
    const { del } = useApi()
    await del(`/v1/expiries/${id}`)
    expiries.value = expiries.value.filter(e => e.id !== id)
  }

  return {
    vehicles, vehicleExpiries, expiries, expiryCounts, upcoming, upcomingCounts, kinds, loading, error,
    fetchVehicles, fetchVehicle, createVehicle, updateVehicle, deleteVehicle,
    fetchExpiries, fetchUpcoming, fetchKinds, createExpiry, updateExpiry, renewExpiry, deleteExpiry,
  }
})
