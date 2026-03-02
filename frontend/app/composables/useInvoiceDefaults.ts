interface VatRateOption {
  rate: string
  label: string
  categoryCode: string
  default: boolean
}

interface UnitOfMeasureOption {
  value: string
  label: string
  code: string
}

interface DocumentSeriesTypeOption {
  value: string
  label: string
}

interface PaymentMethodOption {
  value: string
  label: string
}

interface CountryOption {
  code: string
  label: string
}

interface CountyOption {
  code: string
  label: string
}

interface InvoiceDefaultsResponse {
  vatRates: VatRateOption[]
  currencies: string[]
  defaultCurrency: string
  defaultPaymentTermDays: number
  defaultUnitOfMeasure: string
  unitsOfMeasure: UnitOfMeasureOption[]
  documentSeriesTypes: DocumentSeriesTypeOption[]
  paymentMethods: PaymentMethodOption[]
  exchangeRates: Record<string, number>
  exchangeRateDate: string | null
  isVatPayer: boolean
  reverseCharge?: boolean
  countries: CountryOption[]
  counties: CountyOption[]
}

interface ClientDefaultsResponse extends InvoiceDefaultsResponse {
  reverseCharge: boolean
  ossApplicable: boolean
  ossVatRate: { rate: string; label: string; categoryCode: string } | null
  ossVatRates: { rate: string; label: string; categoryCode: string; default: boolean }[]
}

// Shared state across all instances — fetched once per app lifecycle
const defaults = ref<InvoiceDefaultsResponse | null>(null)
const loading = ref(false)
let fetchPromise: Promise<void> | null = null

export function useInvoiceDefaults() {
  const { get } = useApi()

  async function fetchDefaults() {
    if (defaults.value) return // already fetched
    if (fetchPromise) return fetchPromise // request in flight
    loading.value = true
    fetchPromise = get<InvoiceDefaultsResponse>('/v1/invoice-defaults')
      .then(data => { defaults.value = data })
      .catch(() => { /* Use fallbacks on error */ })
      .finally(() => { loading.value = false; fetchPromise = null })
    return fetchPromise
  }

  async function fetchDefaultsForClient(clientId: string): Promise<ClientDefaultsResponse | null> {
    try {
      return await get<ClientDefaultsResponse>('/v1/invoice-defaults', { clientId })
    } catch {
      return null
    }
  }

  const vatRateOptions = computed(() => {
    if (defaults.value?.vatRates) {
      return defaults.value.vatRates.map(vr => ({
        label: vr.label,
        value: parseFloat(vr.rate).toFixed(2),
        categoryCode: vr.categoryCode,
      }))
    }
    return [
      { label: '21% - Standard', value: '21.00', categoryCode: 'S' },
      { label: '9% - Redus', value: '9.00', categoryCode: 'S' },
      { label: '5% - Redus', value: '5.00', categoryCode: 'S' },
      { label: '0% - Scutit', value: '0.00', categoryCode: 'Z' },
    ]
  })

  const currencyOptions = computed(() => {
    const currencies = defaults.value?.currencies ?? ['RON', 'EUR', 'USD']
    return currencies.map(c => ({ label: c, value: c }))
  })

  const unitOfMeasureOptions = computed(() => {
    if (defaults.value?.unitsOfMeasure) {
      return defaults.value.unitsOfMeasure.map(u => ({
        label: u.label,
        value: u.value,
      }))
    }
    return [
      { label: 'buc (Bucata)', value: 'buc' },
      { label: 'kg (Kilogram)', value: 'kg' },
      { label: 'l (Litru)', value: 'l' },
      { label: 'm (Metru)', value: 'm' },
      { label: 'ora (Ora)', value: 'ora' },
      { label: 'zi (Zi)', value: 'zi' },
      { label: 'luna (Luna)', value: 'luna' },
      { label: 'set (Set)', value: 'set' },
      { label: 'pachet (Pachet)', value: 'pachet' },
    ]
  })

  const defaultCurrency = computed(() => defaults.value?.defaultCurrency ?? 'RON')
  const defaultPaymentTermDays = computed(() => defaults.value?.defaultPaymentTermDays ?? 30)
  const defaultUnitOfMeasure = computed(() => defaults.value?.defaultUnitOfMeasure ?? 'buc')
  const defaultVatRate = computed(() => {
    const def = defaults.value?.vatRates?.find(v => v.default)
    return def ? parseFloat(def.rate).toFixed(2) : '21.00'
  })
  const documentSeriesTypeOptions = computed(() => {
    if (defaults.value?.documentSeriesTypes) {
      return defaults.value.documentSeriesTypes.map(t => ({
        label: t.label,
        value: t.value,
      }))
    }
    return [
      { label: 'Factura', value: 'invoice' },
      { label: 'Proforma', value: 'proforma' },
      { label: 'Nota de credit', value: 'credit_note' },
      { label: 'Aviz de insotire', value: 'delivery_note' },
    ]
  })

  const paymentMethodOptions = computed(() => {
    if (defaults.value?.paymentMethods) {
      return defaults.value.paymentMethods.map(pm => ({
        label: pm.label,
        value: pm.value,
      }))
    }
    return [
      { label: 'Transfer bancar', value: 'bank_transfer' },
      { label: 'Numerar', value: 'cash' },
      { label: 'Card', value: 'card' },
      { label: 'Cec / Bilet la ordin', value: 'cheque' },
      { label: 'Altele', value: 'other' },
    ]
  })

  const exchangeRates = computed(() => defaults.value?.exchangeRates ?? {})
  const isVatPayer = computed(() => defaults.value?.isVatPayer ?? true)

  const countryOptions = computed(() => {
    if (defaults.value?.countries) {
      return defaults.value.countries.map(c => ({
        label: `${c.label} (${c.code})`,
        value: c.code,
      }))
    }
    return [{ label: 'Romania (RO)', value: 'RO' }]
  })

  const countyOptions = computed(() => {
    if (defaults.value?.counties) {
      return defaults.value.counties.map(c => ({
        label: c.label,
        value: c.code,
      }))
    }
    return []
  })

  return {
    defaults,
    loading,
    fetchDefaults,
    fetchDefaultsForClient,
    vatRateOptions,
    currencyOptions,
    unitOfMeasureOptions,
    defaultCurrency,
    defaultPaymentTermDays,
    defaultUnitOfMeasure,
    defaultVatRate,
    documentSeriesTypeOptions,
    paymentMethodOptions,
    exchangeRates,
    isVatPayer,
    countryOptions,
    countyOptions,
  }
}
