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

export enum DocumentType {
  INVOICE = 'invoice',
  CREDIT_NOTE = 'credit_note',
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
