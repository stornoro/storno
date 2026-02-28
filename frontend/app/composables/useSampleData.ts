/**
 * useSampleData — generates pre-filled demo data for the onboarding wizard.
 * The data is used purely for UX preview/pre-fill purposes and does NOT
 * create real records unless the user explicitly confirms.
 */

export interface SampleClient {
  name: string
  type: 'company'
  cui: string
  registrationNumber: string
  address: string
  city: string
  county: string
  country: string
  isVatPayer: boolean
  email: string
  phone: string
}

export interface SampleProduct {
  description: string
  quantity: string
  unitOfMeasure: string
  unitPrice: string
  vatRate: string
  vatCategoryCode: string
  discount: string
  discountPercent: string
}

export interface SampleInvoice {
  client: SampleClient
  lines: SampleProduct[]
  currency: string
  issueDate: string
  dueDate: string
  notes: string
}

export function useSampleData() {
  function generateSampleClient(): SampleClient {
    return {
      name: 'SC Demo SRL',
      type: 'company',
      cui: '12345678',
      registrationNumber: 'J40/1234/2020',
      address: 'Str. Exemplu, Nr. 1',
      city: 'Bucuresti',
      county: 'B',
      country: 'RO',
      isVatPayer: true,
      email: 'contact@demo.ro',
      phone: '+40 721 000 000',
    }
  }

  function generateSampleProduct(): SampleProduct {
    return {
      description: 'Servicii consultanta IT',
      quantity: '1',
      unitOfMeasure: 'ora',
      unitPrice: '500.00',
      vatRate: '19.00',
      vatCategoryCode: 'S',
      discount: '0',
      discountPercent: '0',
    }
  }

  function generateSampleInvoice(): SampleInvoice {
    const today = new Date()
    const due = new Date(today)
    due.setDate(due.getDate() + 30)

    const pad = (n: number) => String(n).padStart(2, '0')
    const fmt = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`

    return {
      client: generateSampleClient(),
      lines: [generateSampleProduct()],
      currency: 'RON',
      issueDate: fmt(today),
      dueDate: fmt(due),
      notes: 'Factura demo generata pentru testare.',
    }
  }

  return {
    generateSampleClient,
    generateSampleProduct,
    generateSampleInvoice,
  }
}
