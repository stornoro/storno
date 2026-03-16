export enum InvoiceDirection {
  INCOMING = 'incoming',
  OUTGOING = 'outgoing',
}

export enum DocumentStatus {
  DRAFT = 'draft',
  SYNCED = 'synced',
  ISSUED = 'issued',
  SENT_TO_PROVIDER = 'sent_to_provider',
  VALIDATED = 'validated',
  REJECTED = 'rejected',
  CANCELLED = 'cancelled',
  PAID = 'paid',
  PARTIALLY_PAID = 'partially_paid',
  OVERDUE = 'overdue',
  CONVERTED = 'converted',
  REFUND = 'refund',
  REFUNDED = 'refunded',
}

export const DocumentStatusColor: Record<DocumentStatus, string> = {
  [DocumentStatus.DRAFT]: 'neutral',
  [DocumentStatus.SYNCED]: 'info',
  [DocumentStatus.ISSUED]: 'primary',
  [DocumentStatus.SENT_TO_PROVIDER]: 'warning',
  [DocumentStatus.VALIDATED]: 'success',
  [DocumentStatus.REJECTED]: 'error',
  [DocumentStatus.CANCELLED]: 'neutral',
  [DocumentStatus.PAID]: 'success',
  [DocumentStatus.PARTIALLY_PAID]: 'warning',
  [DocumentStatus.OVERDUE]: 'error',
  [DocumentStatus.CONVERTED]: 'info',
  [DocumentStatus.REFUND]: 'warning',
  [DocumentStatus.REFUNDED]: 'error',
}

export const InvoiceDirectionColor: Record<InvoiceDirection, string> = {
  [InvoiceDirection.INCOMING]: 'info',
  [InvoiceDirection.OUTGOING]: 'success',
}

export enum DocumentType {
  INVOICE = 'invoice',
  CREDIT_NOTE = 'credit_note',
}

export enum ProformaStatus {
  DRAFT = 'draft',
  SENT = 'sent',
  ACCEPTED = 'accepted',
  REJECTED = 'rejected',
  CONVERTED = 'converted',
  CANCELLED = 'cancelled',
  EXPIRED = 'expired',
}

export const ProformaStatusColor: Record<ProformaStatus, string> = {
  [ProformaStatus.DRAFT]: 'neutral',
  [ProformaStatus.SENT]: 'primary',
  [ProformaStatus.ACCEPTED]: 'success',
  [ProformaStatus.REJECTED]: 'error',
  [ProformaStatus.CONVERTED]: 'info',
  [ProformaStatus.CANCELLED]: 'neutral',
  [ProformaStatus.EXPIRED]: 'warning',
}

export enum DeliveryNoteStatus {
  DRAFT = 'draft',
  ISSUED = 'issued',
  CONVERTED = 'converted',
  CANCELLED = 'cancelled',
}

export const DeliveryNoteStatusColor: Record<DeliveryNoteStatus, string> = {
  [DeliveryNoteStatus.DRAFT]: 'neutral',
  [DeliveryNoteStatus.ISSUED]: 'primary',
  [DeliveryNoteStatus.CONVERTED]: 'info',
  [DeliveryNoteStatus.CANCELLED]: 'neutral',
}

export enum ReceiptStatus {
  DRAFT = 'draft',
  ISSUED = 'issued',
  INVOICED = 'invoiced',
  CANCELLED = 'cancelled',
}

export const ReceiptStatusColor: Record<ReceiptStatus, string> = {
  [ReceiptStatus.DRAFT]: 'neutral',
  [ReceiptStatus.ISSUED]: 'primary',
  [ReceiptStatus.INVOICED]: 'info',
  [ReceiptStatus.CANCELLED]: 'neutral',
}

export enum EInvoiceSubmissionStatus {
  PENDING = 'pending',
  SUBMITTED = 'submitted',
  ACCEPTED = 'accepted',
  REJECTED = 'rejected',
  ERROR = 'error',
}

export const EInvoiceSubmissionStatusColor: Record<EInvoiceSubmissionStatus, string> = {
  [EInvoiceSubmissionStatus.PENDING]: 'gray',
  [EInvoiceSubmissionStatus.SUBMITTED]: 'blue',
  [EInvoiceSubmissionStatus.ACCEPTED]: 'green',
  [EInvoiceSubmissionStatus.REJECTED]: 'red',
  [EInvoiceSubmissionStatus.ERROR]: 'red',
}

export enum DeclarationType {
  D394 = 'd394',
  D300 = 'd300',
  D390 = 'd390',
  D392 = 'd392',
  D393 = 'd393',
  D100 = 'd100',
  D101 = 'd101',
  D106 = 'd106',
  D112 = 'd112',
  D120 = 'd120',
  D130 = 'd130',
  D180 = 'd180',
  D205 = 'd205',
  D208 = 'd208',
  D212 = 'd212',
  D301 = 'd301',
  D311 = 'd311',
}

export enum DeclarationStatus {
  DRAFT = 'draft',
  VALIDATED = 'validated',
  SUBMITTED = 'submitted',
  PROCESSING = 'processing',
  ACCEPTED = 'accepted',
  REJECTED = 'rejected',
  ERROR = 'error',
}

export const DeclarationStatusColor: Record<DeclarationStatus, string> = {
  [DeclarationStatus.DRAFT]: 'neutral',
  [DeclarationStatus.VALIDATED]: 'info',
  [DeclarationStatus.SUBMITTED]: 'warning',
  [DeclarationStatus.PROCESSING]: 'warning',
  [DeclarationStatus.ACCEPTED]: 'success',
  [DeclarationStatus.REJECTED]: 'error',
  [DeclarationStatus.ERROR]: 'error',
}

export const DeclarationTypeLabel: Record<DeclarationType, string> = {
  [DeclarationType.D394]: 'D394',
  [DeclarationType.D300]: 'D300',
  [DeclarationType.D390]: 'D390',
  [DeclarationType.D392]: 'D392',
  [DeclarationType.D393]: 'D393',
  [DeclarationType.D100]: 'D100',
  [DeclarationType.D101]: 'D101',
  [DeclarationType.D106]: 'D106',
  [DeclarationType.D112]: 'D112',
  [DeclarationType.D120]: 'D120',
  [DeclarationType.D130]: 'D130',
  [DeclarationType.D180]: 'D180',
  [DeclarationType.D205]: 'D205',
  [DeclarationType.D208]: 'D208',
  [DeclarationType.D212]: 'D212',
  [DeclarationType.D301]: 'D301',
  [DeclarationType.D311]: 'D311',
}

export const AUTO_POPULATED_TYPES = [
  DeclarationType.D394,
  DeclarationType.D300,
  DeclarationType.D390,
  DeclarationType.D392,
  DeclarationType.D393,
]
