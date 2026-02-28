<?php

namespace App\Security;

final class Permission
{
    // Company
    public const COMPANY_VIEW = 'company.view';
    public const COMPANY_CREATE = 'company.create';
    public const COMPANY_EDIT = 'company.edit';
    public const COMPANY_DELETE = 'company.delete';

    // Client
    public const CLIENT_VIEW = 'client.view';
    public const CLIENT_CREATE = 'client.create';
    public const CLIENT_EDIT = 'client.edit';
    public const CLIENT_DELETE = 'client.delete';

    // Product
    public const PRODUCT_VIEW = 'product.view';
    public const PRODUCT_CREATE = 'product.create';
    public const PRODUCT_EDIT = 'product.edit';
    public const PRODUCT_DELETE = 'product.delete';

    // Invoice
    public const INVOICE_VIEW = 'invoice.view';
    public const INVOICE_CREATE = 'invoice.create';
    public const INVOICE_EDIT = 'invoice.edit';
    public const INVOICE_DELETE = 'invoice.delete';
    public const INVOICE_ISSUE = 'invoice.issue';
    public const INVOICE_SEND = 'invoice.send';
    public const INVOICE_CANCEL = 'invoice.cancel';
    public const INVOICE_REFUND = 'invoice.refund';

    // Recurring Invoices
    public const RECURRING_INVOICE_VIEW = 'recurring_invoice.view';
    public const RECURRING_INVOICE_MANAGE = 'recurring_invoice.manage';

    // Series
    public const SERIES_VIEW = 'series.view';
    public const SERIES_MANAGE = 'series.manage';

    // Payments
    public const PAYMENT_VIEW = 'payment.view';
    public const PAYMENT_CREATE = 'payment.create';
    public const PAYMENT_DELETE = 'payment.delete';

    // e-Factura
    public const EFACTURA_VIEW = 'efactura.view';
    public const EFACTURA_SUBMIT = 'efactura.submit';

    // Settings
    public const SETTINGS_VIEW = 'settings.view';
    public const SETTINGS_MANAGE = 'settings.manage';

    // Organization
    public const ORG_MANAGE_MEMBERS = 'org.manage_members';
    public const ORG_MANAGE_BILLING = 'org.manage_billing';
    public const ORG_VIEW_AUDIT = 'org.view_audit';

    // Import / Export
    public const IMPORT_MANAGE = 'import.manage';
    public const EXPORT_DATA = 'export.data';

    // Webhook
    public const WEBHOOK_VIEW = 'webhook.view';
    public const WEBHOOK_MANAGE = 'webhook.manage';

    // API Keys
    public const API_KEY_VIEW = 'api_key.view';
    public const API_KEY_MANAGE = 'api_key.manage';

    // Backup
    public const BACKUP_MANAGE = 'backup.manage';

    // Email Templates
    public const EMAIL_TEMPLATE_VIEW = 'email_template.view';
    public const EMAIL_TEMPLATE_MANAGE = 'email_template.manage';

    // Reports
    public const REPORT_VIEW = 'report.view';

    // Borderou / Bank Statements
    public const BORDEROU_VIEW = 'borderou.view';
    public const BORDEROU_MANAGE = 'borderou.manage';

    public static function all(): array
    {
        return [
            self::COMPANY_VIEW, self::COMPANY_CREATE, self::COMPANY_EDIT, self::COMPANY_DELETE,
            self::CLIENT_VIEW, self::CLIENT_CREATE, self::CLIENT_EDIT, self::CLIENT_DELETE,
            self::PRODUCT_VIEW, self::PRODUCT_CREATE, self::PRODUCT_EDIT, self::PRODUCT_DELETE,
            self::INVOICE_VIEW, self::INVOICE_CREATE, self::INVOICE_EDIT, self::INVOICE_DELETE,
            self::INVOICE_ISSUE, self::INVOICE_SEND, self::INVOICE_CANCEL, self::INVOICE_REFUND,
            self::RECURRING_INVOICE_VIEW, self::RECURRING_INVOICE_MANAGE,
            self::SERIES_VIEW, self::SERIES_MANAGE,
            self::PAYMENT_VIEW, self::PAYMENT_CREATE, self::PAYMENT_DELETE,
            self::EFACTURA_VIEW, self::EFACTURA_SUBMIT,
            self::SETTINGS_VIEW, self::SETTINGS_MANAGE,
            self::ORG_MANAGE_MEMBERS, self::ORG_MANAGE_BILLING, self::ORG_VIEW_AUDIT,
            self::IMPORT_MANAGE, self::EXPORT_DATA,
            self::WEBHOOK_VIEW, self::WEBHOOK_MANAGE,
            self::API_KEY_VIEW, self::API_KEY_MANAGE,
            self::BACKUP_MANAGE,
            self::EMAIL_TEMPLATE_VIEW, self::EMAIL_TEMPLATE_MANAGE,
            self::REPORT_VIEW,
            self::BORDEROU_VIEW, self::BORDEROU_MANAGE,
        ];
    }
}
