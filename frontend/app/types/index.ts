import type { DocumentStatus, DocumentType, InvoiceDirection, ProformaStatus, DeliveryNoteStatus, ReceiptStatus } from './enums'

// ── Paginated API response ──────────────────────────────────────────
export interface PaginatedResponse<T> {
  data: T[]
  meta: {
    currentPage: number
    lastPage: number
    perPage: number
    total: number
  }
}

// ── Organization ────────────────────────────────────────────────────
export interface UserOrganization {
  id: string
  name: string
  slug: string
  createdAt: string
}

// ── Plan status ─────────────────────────────────────────────────────
export interface PlanFeatures {
  maxCompanies: number
  maxUsersPerOrg: number
  autoSync: boolean
  syncIntervalSeconds: number
  maxInvoicesPerMonth: number
  mobileApp: boolean
  pdfGeneration: boolean
  signatureVerification: boolean
  apiAccess: boolean
  realtimeNotifications: boolean
  paymentLinks: boolean
  emailSending: boolean
  emailTemplates: boolean
  reports: boolean
  recurringInvoices: boolean
  importExport: boolean
  backupRestore: boolean
  bankStatements: boolean
  exchangeRates: boolean
  webhooks: boolean
  selfHostingLicense: boolean
  prioritySupport: boolean
}

export interface PlanStatus {
  plan: 'free' | 'freemium' | 'starter' | 'professional' | 'business' | 'trial'
  expired: boolean
  features: PlanFeatures
  selfHosted: boolean
  communityEdition: boolean
  trialEndsAt?: string
  trialActive?: boolean
  trialDaysLeft?: number
}

// ── User Membership ─────────────────────────────────────────────────
export interface UserMembership {
  id: string
  role: string
  organization: {
    id: string
    name: string
    slug: string
  }
}

// ── User ────────────────────────────────────────────────────────────
export interface User {
  id: string
  email: string
  firstName: string | null
  lastName: string | null
  phone: string | null
  locale: string
  timezone: string
  roles: string[]
  active: boolean
  emailVerified: boolean
  mfaEnabled?: boolean
  production: boolean
  createdAt: string
  updatedAt: string | null
  lastConnectedAt: string | null
  preferences?: {
    primaryColor?: string
    neutralColor?: string
    colorMode?: 'light' | 'dark'
  } | null
  organization?: UserOrganization
  plan?: PlanStatus
  memberships?: UserMembership[]
  permissions?: string[]
  currentRole?: string | null
  impersonating?: boolean
  impersonator?: { id: string; email: string; fullName: string }
}

// ── MFA ──────────────────────────────────────────────────────────────
export interface MfaStatus {
  totpEnabled: boolean
  backupCodesRemaining: number
  passkeysCount: number
}

export interface TotpSetupResponse {
  secret: string
  qrCode: string
  otpauthUri: string
}

export interface TotpEnableResponse {
  enabled: boolean
  backupCodes: string[]
}

export interface MfaChallengeResponse {
  mfa_required: true
  mfa_token: string
  mfa_methods: string[]
}

// ── Company ─────────────────────────────────────────────────────────
export interface Company {
  id: string
  name: string
  cif: number
  registrationNumber: string | null
  vatPayer: boolean
  vatCode: string | null
  address: string | null
  city: string
  state: string
  country: string
  sector: string | null
  phone: string | null
  email: string | null
  website: string | null
  capitalSocial: string | null
  vatOnCollection: boolean
  oss: boolean
  vatIn: string | null
  eoriCode: string | null
  representative: string | null
  representativeRole: string | null
  bankName: string | null
  bankAccount: string | null
  bankBic: string | null
  defaultCurrency: string
  syncEnabled: boolean
  lastSyncedAt: string | null
  syncDaysBack: number
  archiveEnabled: boolean
  archiveRetentionYears: number | null
  efacturaDelayHours: number | null
  enabledModules?: string[] | null
  isReadOnly?: boolean
  deletedAt?: string | null
  hardDeleteAt?: string | null
  stripeConnect?: {
    connected: boolean
    chargesEnabled: boolean
    paymentEnabledByDefault: boolean
  } | null
}

// ── Invoice CRUD Payloads ──────────────────────────────────────────
export interface InvoiceLinePayload {
  description: string
  quantity: string
  unitOfMeasure: string
  unitPrice: string
  vatRate: string
  vatCategoryCode: string
  discount: string
  discountPercent: string
  productCode?: string | null
  lineNote?: string | null
  buyerAccountingRef?: string | null
  buyerItemIdentification?: string | null
  standardItemIdentification?: string | null
  cpvCode?: string | null
}

export interface CreateInvoicePayload {
  documentSeriesId?: string
  documentType: string
  invoiceTypeCode?: string | null
  clientId?: string
  receiverName?: string
  receiverCif?: string
  parentDocumentId?: string
  issueDate: string
  dueDate?: string
  currency: string
  language?: string
  notes?: string
  paymentTerms?: string
  deliveryLocation?: string
  projectReference?: string
  // Options
  tvaLaIncasare?: boolean
  platitorTva?: boolean
  plataOnline?: boolean
  // Client balance
  showClientBalance?: boolean
  clientBalanceExisting?: string
  clientBalanceOverdue?: string
  // e-Factura BT fields
  taxPointDate?: string
  taxPointDateCode?: string
  buyerReference?: string
  orderNumber?: string
  contractNumber?: string
  receivingAdviceReference?: string
  despatchAdviceReference?: string
  tenderOrLotReference?: string
  invoicedObjectIdentifier?: string
  buyerAccountingReference?: string
  businessProcessType?: string
  payeeName?: string
  payeeIdentifier?: string
  payeeLegalRegistrationIdentifier?: string
  lines: InvoiceLinePayload[]
}

export interface UpdateInvoicePayload {
  documentSeriesId?: string
  documentType?: string
  invoiceTypeCode?: string | null
  clientId?: string
  receiverName?: string
  receiverCif?: string
  issueDate?: string
  dueDate?: string
  currency?: string
  language?: string
  notes?: string | null
  paymentTerms?: string | null
  deliveryLocation?: string | null
  projectReference?: string | null
  // Options
  tvaLaIncasare?: boolean
  platitorTva?: boolean
  plataOnline?: boolean
  // Client balance
  showClientBalance?: boolean
  clientBalanceExisting?: string | null
  clientBalanceOverdue?: string | null
  // e-Factura BT fields
  taxPointDate?: string | null
  taxPointDateCode?: string | null
  buyerReference?: string | null
  orderNumber?: string | null
  contractNumber?: string | null
  receivingAdviceReference?: string | null
  despatchAdviceReference?: string | null
  tenderOrLotReference?: string | null
  invoicedObjectIdentifier?: string | null
  buyerAccountingReference?: string | null
  businessProcessType?: string | null
  payeeName?: string | null
  payeeIdentifier?: string | null
  payeeLegalRegistrationIdentifier?: string | null
  lines?: InvoiceLinePayload[]
}

// ── Client ──────────────────────────────────────────────────────────
export interface Client {
  id: string
  type: 'company' | 'individual'
  name: string
  cui: string | null
  cnp: string | null
  vatCode: string | null
  isVatPayer: boolean
  registrationNumber: string | null
  address: string | null
  city: string | null
  county: string | null
  country: string
  postalCode: string | null
  email: string | null
  phone: string | null
  bankName: string | null
  bankAccount: string | null
  defaultPaymentTermDays: number | null
  notes: string | null
  source: string
  lastSyncedAt: string | null
  createdAt: string
  updatedAt: string | null
  invoiceCount?: number
  invoiceTotal?: number
}

// ── Product ─────────────────────────────────────────────────────────
export interface Product {
  id: string
  name: string
  code: string | null
  description: string | null
  unitOfMeasure: string
  defaultPrice: string
  currency: string
  vatRate: string
  vatCategoryCode: string
  isService: boolean
  isActive: boolean
  usage: string
  ncCode: string | null
  cpvCode: string | null
  source: string
  lastSyncedAt: string | null
  createdAt: string
  updatedAt: string | null
}

export interface NcCode {
  cod: string
  denumire: string
}

export interface CpvCode {
  cod: string
  denumire: string
}

// ── Invoice line ────────────────────────────────────────────────────
export interface InvoiceLine {
  id: string
  position: number
  description: string
  quantity: string
  unitOfMeasure: string
  unitPrice: string
  vatRate: string
  vatCategoryCode: string
  vatAmount: string
  lineTotal: string
  discount: string
  discountPercent: string
  productCode?: string | null
  lineNote?: string | null
  buyerAccountingRef?: string | null
  buyerItemIdentification?: string | null
  standardItemIdentification?: string | null
  cpvCode?: string | null
}

// ── Supplier ────────────────────────────────────────────────────────
export interface Supplier {
  id: string
  name: string
  cif: string | null
  vatCode: string | null
  isVatPayer: boolean
  registrationNumber: string | null
  address: string | null
  city: string | null
  county: string | null
  country: string
  postalCode: string | null
  email: string | null
  phone: string | null
  bankName: string | null
  bankAccount: string | null
  notes: string | null
  source: string
  lastSyncedAt: string | null
  createdAt: string
  updatedAt: string | null
}

// ── Document Series ─────────────────────────────────────────────────
export interface DocumentSeries {
  id: string
  prefix: string
  currentNumber: number
  nextNumber: string
  type: string
  active: boolean
  isDefault: boolean
  source: string
  createdAt: string
  updatedAt: string | null
}

export interface ValidationError {
  message: string
  source: string
  ruleId?: string
  location?: string
}

export interface ValidationResponse {
  valid: boolean
  errors: ValidationError[]
  warnings: string[]
  schematronAvailable: boolean
}

// ── Email Template ──────────────────────────────────────────────────
export interface EmailTemplate {
  id: string
  name: string
  subject: string
  body: string
  isDefault: boolean
}

// ── Email Log ───────────────────────────────────────────────────────
export interface EmailLog {
  id: string
  invoiceId: string | null
  invoiceNumber: string | null
  toEmail: string
  ccEmails: string[] | null
  bccEmails: string[] | null
  subject: string
  sentAt: string
  status: string
  templateUsed: string | null
  sentByEmail: string | null
  errorMessage: string | null
}

// ── VAT Rate ────────────────────────────────────────────────────────
export interface VatRate {
  id: string
  rate: string
  label: string
  categoryCode: string
  isDefault: boolean
  isActive: boolean
  position: number
}

// ── Bank Account ────────────────────────────────────────────────────
export interface BankAccount {
  id: string
  iban: string
  bankName: string | null
  currency: string
  isDefault: boolean
  showOnInvoice: boolean
  source: string
  createdAt: string
  updatedAt: string | null
}

// ── Invoice Attachment ──────────────────────────────────────────────
export interface InvoiceAttachment {
  id: string
  filename: string
  mimeType: string | null
  description: string | null
  size: number | null
}

// ── Payment ─────────────────────────────────────────────────────────
export interface Payment {
  id: string
  amount: string
  currency: string
  paymentDate: string
  paymentMethod: string
  reference: string | null
  notes: string | null
  isReconciled: boolean
  createdAt: string
}

// ── Invoice ─────────────────────────────────────────────────────────
export interface Invoice {
  id: string
  documentType: DocumentType
  status: DocumentStatus
  direction: InvoiceDirection | null
  number: string
  senderCif: string | null
  receiverCif: string | null
  senderName: string | null
  receiverName: string | null
  clientName: string | null
  subtotal: string
  vatTotal: string
  total: string
  discount: string
  currency: string
  issueDate: string
  dueDate: string | null
  notes: string | null
  paymentTerms: string | null
  exchangeRate: string | null
  language: string
  anafMessageId: string | null
  anafUploadId: string | null
  anafDownloadId: string | null
  anafStatus: string | null
  syncedAt: string | null
  // New fields
  isDuplicate: boolean
  isLateSubmission: boolean
  amountPaid: string
  balance: string
  paidAt: string | null
  paymentMethod: string | null
  payments?: Payment[]
  parentDocumentId: string | null
  cancelledAt: string | null
  cancellationReason: string | null
  invoiceTypeCode: string | null
  scheduledSendAt: string | null
  deliveryLocation: string | null
  projectReference: string | null
  // Options
  tvaLaIncasare: boolean
  platitorTva: boolean
  plataOnline: boolean
  // Client balance
  showClientBalance: boolean
  clientBalanceExisting: string | null
  clientBalanceOverdue: string | null
  // e-Factura BT fields
  taxPointDate: string | null
  taxPointDateCode: string | null
  buyerReference: string | null
  orderNumber: string | null
  contractNumber: string | null
  receivingAdviceReference: string | null
  despatchAdviceReference: string | null
  tenderOrLotReference: string | null
  invoicedObjectIdentifier: string | null
  buyerAccountingReference: string | null
  businessProcessType: string | null
  payeeName: string | null
  payeeIdentifier: string | null
  payeeLegalRegistrationIdentifier: string | null
  lines: InvoiceLine[]
  client: Client | null
  supplier: Supplier | null
  documentSeries: DocumentSeries | null
  attachments: InvoiceAttachment[]
  createdAt: string
  updatedAt: string | null
}

// ── e-Factura Message ───────────────────────────────────────────────
export interface EFacturaMessage {
  id: string
  anafMessageId: string
  messageType: string
  cif: string
  details: string | null
  uploadId: string | null
  status: string
  errorMessage: string | null
  invoice: { id: string, number: string } | null
  createdAt: string
}

// ── VAT Report ──────────────────────────────────────────────────────
export interface VatBucket {
  taxableBase: string
  vatAmount: string
}

export interface VatTotalBucket {
  taxableBase: string
  vatAmount: string
  total: string
}

export interface VatReport {
  period: string
  outgoing: Record<string, VatBucket>
  incoming: Record<string, VatBucket>
  totals: {
    outgoing: VatTotalBucket
    incoming: VatTotalBucket
  }
  netVat: string
  invoiceCount: {
    outgoing: number
    incoming: number
  }
}

// ── Sales Analysis Report ────────────────────────────────────────────
export interface SalesKpiAnnualTotal {
  amount: string
  year: number
  prevAmount: string
  prevYear: number
}

export interface SalesKpiPeriodBlock {
  subtotal: string
  vatTotal: string
  total: string
  count: number
}

export interface SalesKpiSummary {
  annualTotal: SalesKpiAnnualTotal
  periodInvoiced: SalesKpiPeriodBlock
  periodCollected: SalesKpiPeriodBlock
  periodOutstanding: SalesKpiPeriodBlock
}

export interface MonthlyRevenueItem {
  month: string
  invoiced: string
  collected: string
}

export interface SalesRecentInvoice {
  id: string
  number: string
  issueDate: string
  clientName: string | null
  total: string
  currency: string
  status: string
  paidAt: string | null
}

export interface TopClientItem {
  clientId: string | null
  clientName: string | null
  total: string
  count: number
}

export interface TopProductItem {
  description: string
  productCode: string | null
  total: string
  quantity: string
}

export interface SalesAnalysisReport {
  period: { dateFrom: string; dateTo: string }
  kpiSummary: SalesKpiSummary
  monthlyRevenue: MonthlyRevenueItem[]
  recentInvoices: SalesRecentInvoice[]
  topClients: TopClientItem[]
  topProducts: TopProductItem[]
}

// ── ANAF Token ──────────────────────────────────────────────────────
export interface AnafToken {
  id: string
  label: string | null
  expiresAt: string | null
  isExpired: boolean
  lastUsedAt: string | null
  createdAt: string | null
  validatedCifs: number[]
}

export interface AnafTokenLink {
  linkToken: string
  expiresAt: string
}

export interface AnafTokenLinkStatus {
  expired: boolean
  used: boolean
  completed: boolean
  createdAt: string
  expiresAt: string
}

export interface AnafStatus {
  connected: boolean
  hasToken: boolean
  tokenCount: number
  nearestExpiry?: string | null
  hasExpiredTokens?: boolean
}

// ── Sync status ─────────────────────────────────────────────────────
export interface SyncStatus {
  companyId: string
  companyName: string
  syncEnabled: boolean
  lastSyncedAt: string | null
  syncDaysBack: number
  invoicesSynced: number
  clientsSynced: number
  productsSynced: number
  isRunning: boolean
  lastError: string | null
}

// ── Team Member ────────────────────────────────────────────────────
export interface TeamMemberUser {
  id: string
  email: string
  firstName: string | null
  lastName: string | null
  lastConnectedAt: string | null
}

export interface TeamMemberCompany {
  id: string
  name: string
  cif: number
}

export interface TeamMember {
  id: string
  user: TeamMemberUser
  role: string
  isActive: boolean
  joinedAt: string
  allowedCompanies: TeamMemberCompany[]
  isCurrentUser: boolean
  isSuperAdmin: boolean
  permissions: string[]
  hasCustomPermissions: boolean
}

export interface PermissionsReference {
  permissions: Record<string, string[]>
  roleDefaults: Record<string, string[]>
}

export interface TeamMeta {
  canManage: boolean
  maxUsers: number
  currentCount: number
}

// ── Invitation ─────────────────────────────────────────────────────
export interface Invitation {
  id: string
  email: string
  role: string
  status: string
  createdAt: string
  expiresAt: string
  invitedBy: {
    id: string
    firstName: string
    lastName: string
  }
  allowedCompanies: { id: string; name: string; cif: number }[]
}

export interface InvitationDetails {
  email: string
  organizationName: string
  role: string
  expiresAt: string
  hasAccount: boolean
}

// ── Proforma Invoice Line ──────────────────────────────────────────
export interface ProformaInvoiceLine {
  id: string
  position: number
  description: string
  quantity: string
  unitOfMeasure: string
  unitPrice: string
  vatRate: string
  vatCategoryCode: string
  vatAmount: string
  lineTotal: string
  discount: string
  discountPercent: string
}

// ── Proforma Invoice ──────────────────────────────────────────────
export interface ProformaInvoice {
  id: string
  number: string
  status: ProformaStatus
  currency: string
  language: string
  issueDate: string
  dueDate: string | null
  validUntil: string | null
  subtotal: string
  vatTotal: string
  total: string
  discount: string
  notes: string | null
  paymentTerms: string | null
  invoiceTypeCode: string | null
  deliveryLocation: string | null
  projectReference: string | null
  client: Client | null
  documentSeries: DocumentSeries | null
  convertedInvoice: { id: string; number: string; status: string } | null
  sentAt: string | null
  acceptedAt: string | null
  rejectedAt: string | null
  cancelledAt: string | null
  expiredAt: string | null
  lines: ProformaInvoiceLine[]
  clientName: string | null
  createdAt: string
  updatedAt: string | null
}

export interface CreateProformaPayload {
  documentSeriesId?: string
  clientId?: string
  issueDate: string
  dueDate?: string
  validUntil?: string
  currency: string
  language?: string
  notes?: string
  paymentTerms?: string
  invoiceTypeCode?: string | null
  deliveryLocation?: string
  projectReference?: string
  lines: InvoiceLinePayload[]
}

export interface UpdateProformaPayload {
  clientId?: string
  issueDate?: string
  dueDate?: string
  validUntil?: string
  currency?: string
  language?: string
  notes?: string | null
  paymentTerms?: string | null
  invoiceTypeCode?: string | null
  deliveryLocation?: string | null
  projectReference?: string | null
  lines?: InvoiceLinePayload[]
}

// ── Delivery Note Line ───────────────────────────────────────────────
export interface DeliveryNoteLine {
  id: string
  position: number
  description: string
  quantity: string
  unitOfMeasure: string
  unitPrice: string
  vatRate: string
  vatCategoryCode: string
  vatAmount: string
  lineTotal: string
  discount: string
  discountPercent: string
  tariffCode?: string | null
  purposeCode?: number | null
  unitOfMeasureCode?: string | null
  netWeight?: string | null
  grossWeight?: string | null
  valueWithoutVat?: string | null
}

// ── Delivery Note ───────────────────────────────────────────────────
export interface DeliveryNote {
  id: string
  number: string
  status: DeliveryNoteStatus
  currency: string
  issueDate: string
  dueDate: string | null
  subtotal: string
  vatTotal: string
  total: string
  discount: string
  notes: string | null
  mentions: string | null
  internalNote: string | null
  deliveryLocation: string | null
  projectReference: string | null
  issuerName: string | null
  issuerId: string | null
  salesAgent: string | null
  deputyName: string | null
  deputyIdentityCard: string | null
  deputyAuto: string | null
  exchangeRate: string | null
  // e-Transport fields
  etransportOperationType?: number | null
  etransportVehicleNumber?: string | null
  etransportTrailer1?: string | null
  etransportTrailer2?: string | null
  etransportTransporterCountry?: string | null
  etransportTransporterCode?: string | null
  etransportTransporterName?: string | null
  etransportTransportDate?: string | null
  etransportStartCounty?: number | null
  etransportStartLocality?: string | null
  etransportStartStreet?: string | null
  etransportStartNumber?: string | null
  etransportStartOtherInfo?: string | null
  etransportStartPostalCode?: string | null
  etransportEndCounty?: number | null
  etransportEndLocality?: string | null
  etransportEndStreet?: string | null
  etransportEndNumber?: string | null
  etransportEndOtherInfo?: string | null
  etransportEndPostalCode?: string | null
  etransportUit?: string | null
  etransportStatus?: string | null
  etransportErrorMessage?: string | null
  etransportSubmittedAt?: string | null
  client: Client | null
  documentSeries: DocumentSeries | null
  convertedInvoice: { id: string; number: string; status: string } | null
  issuedAt: string | null
  cancelledAt: string | null
  lines: DeliveryNoteLine[]
  clientName: string | null
  createdAt: string
  updatedAt: string | null
}

export interface DeliveryNoteLinePayload extends InvoiceLinePayload {
  tariffCode?: string | null
  purposeCode?: number | null
  unitOfMeasureCode?: string | null
  netWeight?: string | null
  grossWeight?: string | null
  valueWithoutVat?: string | null
}

export interface CreateDeliveryNotePayload {
  documentSeriesId?: string
  clientId?: string
  issueDate: string
  dueDate?: string
  currency: string
  notes?: string
  mentions?: string
  internalNote?: string
  deliveryLocation?: string
  projectReference?: string
  issuerName?: string
  issuerId?: string
  salesAgent?: string
  deputyName?: string
  deputyIdentityCard?: string
  deputyAuto?: string
  etransportOperationType?: number | null
  etransportVehicleNumber?: string | null
  etransportTrailer1?: string | null
  etransportTrailer2?: string | null
  etransportTransporterCountry?: string | null
  etransportTransporterCode?: string | null
  etransportTransporterName?: string | null
  etransportTransportDate?: string | null
  etransportStartCounty?: number | null
  etransportStartLocality?: string | null
  etransportStartStreet?: string | null
  etransportStartNumber?: string | null
  etransportStartOtherInfo?: string | null
  etransportStartPostalCode?: string | null
  etransportEndCounty?: number | null
  etransportEndLocality?: string | null
  etransportEndStreet?: string | null
  etransportEndNumber?: string | null
  etransportEndOtherInfo?: string | null
  etransportEndPostalCode?: string | null
  lines: DeliveryNoteLinePayload[]
}

export interface UpdateDeliveryNotePayload {
  clientId?: string
  issueDate?: string
  dueDate?: string
  currency?: string
  notes?: string | null
  mentions?: string | null
  internalNote?: string | null
  deliveryLocation?: string | null
  projectReference?: string | null
  issuerName?: string | null
  issuerId?: string | null
  salesAgent?: string | null
  deputyName?: string | null
  deputyIdentityCard?: string | null
  deputyAuto?: string | null
  etransportOperationType?: number | null
  etransportVehicleNumber?: string | null
  etransportTrailer1?: string | null
  etransportTrailer2?: string | null
  etransportTransporterCountry?: string | null
  etransportTransporterCode?: string | null
  etransportTransporterName?: string | null
  etransportTransportDate?: string | null
  etransportStartCounty?: number | null
  etransportStartLocality?: string | null
  etransportStartStreet?: string | null
  etransportStartNumber?: string | null
  etransportStartOtherInfo?: string | null
  etransportStartPostalCode?: string | null
  etransportEndCounty?: number | null
  etransportEndLocality?: string | null
  etransportEndStreet?: string | null
  etransportEndNumber?: string | null
  etransportEndOtherInfo?: string | null
  etransportEndPostalCode?: string | null
  lines?: DeliveryNoteLinePayload[]
}

// ── Receipt Line ────────────────────────────────────────────────────
export interface ReceiptLine {
  id: string
  position: number
  description: string
  quantity: string
  unitOfMeasure: string
  unitPrice: string
  vatRate: string
  vatCategoryCode: string
  vatAmount: string
  lineTotal: string
  discount: string
  discountPercent: string
}

// ── Receipt ─────────────────────────────────────────────────────────
export interface Receipt {
  id: string
  number: string
  status: ReceiptStatus
  currency: string
  issueDate: string
  subtotal: string
  vatTotal: string
  total: string
  discount: string
  notes: string | null
  mentions: string | null
  internalNote: string | null
  projectReference: string | null
  issuerName: string | null
  issuerId: string | null
  salesAgent: string | null
  exchangeRate: string | null
  paymentMethod: string | null
  cashPayment: string | null
  cardPayment: string | null
  otherPayment: string | null
  cashRegisterName: string | null
  fiscalNumber: string | null
  customerName: string | null
  customerCif: string | null
  client: Client | null
  documentSeries: DocumentSeries | null
  convertedInvoice: { id: string; number: string; status: string } | null
  issuedAt: string | null
  cancelledAt: string | null
  lines: ReceiptLine[]
  clientName: string | null
  createdAt: string
  updatedAt: string | null
}

export interface CreateReceiptPayload {
  documentSeriesId?: string
  clientId?: string
  issueDate: string
  currency: string
  notes?: string
  mentions?: string
  internalNote?: string
  projectReference?: string
  issuerName?: string
  issuerId?: string
  salesAgent?: string
  paymentMethod?: string
  cashPayment?: string
  cardPayment?: string
  otherPayment?: string
  cashRegisterName?: string
  fiscalNumber?: string
  customerName?: string
  customerCif?: string
  lines: InvoiceLinePayload[]
}

export interface UpdateReceiptPayload {
  clientId?: string
  issueDate?: string
  currency?: string
  notes?: string | null
  mentions?: string | null
  internalNote?: string | null
  projectReference?: string | null
  issuerName?: string | null
  issuerId?: string | null
  salesAgent?: string | null
  paymentMethod?: string | null
  cashPayment?: string | null
  cardPayment?: string | null
  otherPayment?: string | null
  cashRegisterName?: string | null
  fiscalNumber?: string | null
  customerName?: string | null
  customerCif?: string | null
  lines?: InvoiceLinePayload[]
}

// ── Dashboard stats ─────────────────────────────────────────────────
// ── Recurring Invoice Line ─────────────────────────────────────────
export interface RecurringInvoiceLine {
  id: string
  position: number
  description: string
  quantity: string
  unitOfMeasure: string
  unitPrice: string
  vatRate: string
  vatCategoryCode: string
  vatAmount: string
  lineTotal: string
  discount: string
  discountPercent: string
  referenceCurrency: string | null
  markupPercent: string | null
  priceRule: string
  productId: string | null
  productName: string | null
}

// ── Recurring Invoice ─────────────────────────────────────────────
export interface RecurringInvoice {
  id: string
  reference: string | null
  isActive: boolean
  documentType: string
  currency: string
  invoiceTypeCode: string | null
  dueDateType: string | null
  dueDateDays: number | null
  dueDateFixedDay: number | null
  frequency: string
  frequencyDay: number
  frequencyMonth: number | null
  nextIssuanceDate: string
  stopDate: string | null
  lastIssuedAt: string | null
  lastInvoiceNumber: string | null
  notes: string | null
  paymentTerms: string | null
  autoEmailEnabled: boolean
  autoEmailTime: string | null
  autoEmailDayOffset: number
  penaltyEnabled: boolean
  penaltyPercentPerDay: string | null
  penaltyGraceDays: number | null
  subtotal: string
  vatTotal: string
  total: string
  estimatedSubtotal: string | null
  estimatedVatTotal: string | null
  estimatedTotal: string | null
  receiverName: string | null
  receiverCif: string | null
  clientName: string | null
  client: Client | null
  documentSeries: DocumentSeries | null
  lines: RecurringInvoiceLine[]
  createdAt: string
  updatedAt: string | null
}

export interface RecurringInvoiceLinePayload {
  description: string
  quantity: string
  unitOfMeasure: string
  unitPrice: string
  vatRate: string
  vatCategoryCode: string
  discount: string
  discountPercent: string
  referenceCurrency?: string | null
  markupPercent?: string | null
  priceRule?: string
  productId?: string | null
}

export interface CreateRecurringInvoicePayload {
  reference?: string | null
  documentType?: string
  currency?: string
  invoiceTypeCode?: string | null
  clientId?: string | null
  receiverName?: string
  receiverCif?: string
  documentSeriesId?: string | null
  dueDateType?: string | null
  dueDateDays?: number | null
  dueDateFixedDay?: number | null
  frequency: string
  frequencyDay: number
  frequencyMonth?: number | null
  nextIssuanceDate: string
  stopDate?: string | null
  notes?: string | null
  paymentTerms?: string | null
  autoEmailEnabled?: boolean
  autoEmailTime?: string | null
  autoEmailDayOffset?: number
  penaltyEnabled?: boolean
  penaltyPercentPerDay?: string | null
  penaltyGraceDays?: number | null
  lines: RecurringInvoiceLinePayload[]
}

export interface UpdateRecurringInvoicePayload {
  reference?: string | null
  documentType?: string
  currency?: string
  invoiceTypeCode?: string | null
  clientId?: string | null
  receiverName?: string
  receiverCif?: string
  dueDateType?: string | null
  dueDateDays?: number | null
  dueDateFixedDay?: number | null
  frequency?: string
  frequencyDay?: number
  frequencyMonth?: number | null
  nextIssuanceDate?: string
  stopDate?: string | null
  notes?: string | null
  paymentTerms?: string | null
  autoEmailEnabled?: boolean
  autoEmailTime?: string | null
  autoEmailDayOffset?: number
  penaltyEnabled?: boolean
  penaltyPercentPerDay?: string | null
  penaltyGraceDays?: number | null
  lines?: RecurringInvoiceLinePayload[]
}

// ── Dashboard stats ─────────────────────────────────────────────────
export interface PaymentSummary {
  outstandingCount: number
  outstandingAmount: string
  overdueCount: number
  overdueAmount: string
}

export interface DashboardStats {
  totalInvoices: number
  totalClients: number
  totalProducts: number
  totalRevenue: string
  totalExpenses: string
  invoicesByStatus: Record<DocumentStatus, number>
  invoicesByDirection: Record<InvoiceDirection, number>
  recentInvoices: Invoice[]
  monthlyTotals: {
    month: string
    incoming: string
    outgoing: string
  }[]
  payments?: PaymentSummary
}

// ── API Key ────────────────────────────────────────────────────
export interface ApiKey {
  id: string
  name: string
  token?: string
  tokenPrefix: string
  scopes: string[]
  lastUsedAt: string | null
  expireAt: string | null
  revokedAt: string | null
  createdAt: string
}

export interface ApiKeyScope {
  value: string
  label: string
  category: string
}

// ── Webhook ────────────────────────────────────────────────────
export interface WebhookEvent {
  event: string
  category: string
  description: string
}

export interface WebhookEndpoint {
  id: string
  url: string
  description: string | null
  events: string[]
  secret: string
  isActive: boolean
  createdAt: string
  updatedAt: string | null
}

// ── License Key ────────────────────────────────────────────────
export interface LicenseKey {
  id: string
  licenseKey: string
  instanceName: string | null
  instanceUrl: string | null
  active: boolean
  lastValidatedAt: string | null
  activatedAt: string | null
  createdAt: string
  instanceMetrics: {
    orgCount: number
    userCount: number
    companyCount: number
    invoicesThisMonth: number
  } | null
  lastViolations: string[] | null
}

export interface WebhookDelivery {
  id: string
  eventType: string
  status: 'pending' | 'success' | 'failed' | 'retrying'
  payload?: Record<string, unknown>
  responseStatusCode: number | null
  responseBody?: string | null
  durationMs: number | null
  attempt: number
  errorMessage: string | null
  triggeredAt: string
  completedAt: string | null
  nextRetryAt?: string | null
}

// ── Storage Config ──────────────────────────────────────────────
export interface StorageProviderField {
  key: string
  label: string
  type: 'text' | 'password'
  required: boolean
  placeholder?: string
}

export interface StorageProvider {
  value: string
  name: string
  fields: StorageProviderField[]
  supportsRegion: boolean
  supportsEndpoint: boolean
  defaultForcePathStyle: boolean
}

export interface StorageConfig {
  id: string
  provider: string
  bucket: string
  region: string | null
  endpoint: string | null
  prefix: string | null
  forcePathStyle: boolean
  isActive: boolean
  lastTestedAt: string | null
  createdAt: string | null
  updatedAt: string | null
}

export interface PdfTemplateInfo {
  slug: string
  name: string
  description: string
  defaultColor: string
}

export interface PdfTemplateConfig {
  id: string
  templateSlug: string
  primaryColor: string | null
  fontFamily: string | null
  showLogo: boolean
  showBankInfo: boolean
  bankDisplaySection: 'supplier' | 'payment' | 'both'
  bankDisplayMode: 'stacked' | 'inline'
  defaultNotes: string | null
  defaultPaymentTerms: string | null
  defaultPaymentMethod: string | null
  footerText: string | null
  customCss: string | null
}

// ── Balance Analysis ───────────────────────────────────────────────
export interface TrialBalance {
  id: string
  year: number
  month: number
  originalFilename: string
  status: 'pending' | 'processing' | 'completed' | 'failed'
  totalAccounts: number
  error: string | null
  processedAt: string | null
  createdAt: string
}

export interface BalanceIndicators {
  revenue: string
  expenses: string
  netProfit: string
  turnover: string
  salaries: string
  profitTax: string
  supplierDebts: string
  clientReceivables: string
  bankBalance: string
  cashBalance: string
}

export interface BalanceMonthlyEvolution {
  month: number
  revenue: string
  expenses: string
  profit: string
}

export interface BalanceProfitability {
  profitMargin: number
  expenseRatio: number
  salaryRatio: number
}

export interface BalanceTopExpense {
  accountCode: string
  accountName: string
  amount: string
  percentage: number
}

export interface BalanceYoyComparison {
  currentYear: number
  previousYear: number
  current: { revenue: string; expenses: string; profit: string }
  previous: { revenue: string; expenses: string; profit: string }
  changes: { revenue: number; expenses: number; profit: number }
}

export interface BalanceAnalysisReport {
  year: number
  balances: TrialBalance[]
  indicators: BalanceIndicators
  monthlyEvolution: BalanceMonthlyEvolution[]
  profitability: BalanceProfitability
  topExpenses: BalanceTopExpense[]
  yoyComparison: BalanceYoyComparison
}
