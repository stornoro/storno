/**
 * Permission constants — mirrors backend Permission.php
 * Auto-imported by Nuxt from utils/
 */
export const P = {
  // Company
  COMPANY_VIEW: 'company.view',
  COMPANY_CREATE: 'company.create',
  COMPANY_EDIT: 'company.edit',
  COMPANY_DELETE: 'company.delete',

  // Client
  CLIENT_VIEW: 'client.view',
  CLIENT_CREATE: 'client.create',
  CLIENT_EDIT: 'client.edit',
  CLIENT_DELETE: 'client.delete',

  // Product
  PRODUCT_VIEW: 'product.view',
  PRODUCT_CREATE: 'product.create',
  PRODUCT_EDIT: 'product.edit',
  PRODUCT_DELETE: 'product.delete',

  // Invoice
  INVOICE_VIEW: 'invoice.view',
  INVOICE_CREATE: 'invoice.create',
  INVOICE_EDIT: 'invoice.edit',
  INVOICE_DELETE: 'invoice.delete',
  INVOICE_ISSUE: 'invoice.issue',
  INVOICE_SEND: 'invoice.send',
  INVOICE_CANCEL: 'invoice.cancel',
  INVOICE_REFUND: 'invoice.refund',

  // Recurring Invoices
  RECURRING_INVOICE_VIEW: 'recurring_invoice.view',
  RECURRING_INVOICE_MANAGE: 'recurring_invoice.manage',

  // Series
  SERIES_VIEW: 'series.view',
  SERIES_MANAGE: 'series.manage',

  // Payments
  PAYMENT_VIEW: 'payment.view',
  PAYMENT_CREATE: 'payment.create',
  PAYMENT_DELETE: 'payment.delete',

  // e-Factura
  EFACTURA_VIEW: 'efactura.view',
  EFACTURA_SUBMIT: 'efactura.submit',

  // Settings
  SETTINGS_VIEW: 'settings.view',
  SETTINGS_MANAGE: 'settings.manage',

  // Organization
  ORG_MANAGE_MEMBERS: 'org.manage_members',
  ORG_MANAGE_BILLING: 'org.manage_billing',
  ORG_VIEW_AUDIT: 'org.view_audit',

  // Import / Export
  IMPORT_MANAGE: 'import.manage',
  EXPORT_DATA: 'export.data',

  // Webhook
  WEBHOOK_VIEW: 'webhook.view',
  WEBHOOK_MANAGE: 'webhook.manage',

  // API Keys
  API_KEY_VIEW: 'api_key.view',
  API_KEY_MANAGE: 'api_key.manage',

  // Backup
  BACKUP_MANAGE: 'backup.manage',

  // Email Templates
  EMAIL_TEMPLATE_VIEW: 'email_template.view',
  EMAIL_TEMPLATE_MANAGE: 'email_template.manage',

  // Reports
  REPORT_VIEW: 'report.view',

  // Borderou / Bank Statements
  BORDEROU_VIEW: 'borderou.view',
  BORDEROU_MANAGE: 'borderou.manage',
} as const

/**
 * Map route paths to required permission(s).
 * Used by the permissions middleware to guard pages.
 */
export const ROUTE_PERMISSIONS: Record<string, string | string[]> = {
  '/settings/billing': P.ORG_MANAGE_BILLING,
  '/settings/license-keys': P.ORG_MANAGE_BILLING,
  '/settings/payments': P.ORG_MANAGE_BILLING,
  '/settings/webhooks': P.WEBHOOK_VIEW,
  '/settings/api-keys': P.API_KEY_VIEW,
  '/settings/backup': P.BACKUP_MANAGE,
  '/settings/import-export': P.IMPORT_MANAGE,
  '/settings/team': P.ORG_MANAGE_MEMBERS,
}
