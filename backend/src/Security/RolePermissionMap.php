<?php

namespace App\Security;

use App\Enum\OrganizationRole;

/**
 * Maps organization roles to their permissions.
 *
 * Hierarchy: Employee → Accountant → Admin → Owner
 *
 * Employee:    View-only access to core data
 * Accountant:  Day-to-day invoicing, imports, email templates, reports, borderou
 *              NO webhooks, NO API keys, NO backup, NO member management
 * Admin:       Full operational control including webhooks, API keys, backup, members
 *              NO billing
 * Owner:       Everything including billing and licensing
 */
final class RolePermissionMap
{
    private static array $map = [];

    public static function getPermissions(OrganizationRole $role): array
    {
        if (empty(self::$map)) {
            self::buildMap();
        }

        return self::$map[$role->value] ?? [];
    }

    private static function buildMap(): void
    {
        // ── Employee: view-only ──────────────────────────────────────────
        $employeePerms = [
            Permission::COMPANY_VIEW,
            Permission::CLIENT_VIEW,
            Permission::PRODUCT_VIEW,
            Permission::INVOICE_VIEW,
            Permission::RECURRING_INVOICE_VIEW,
            Permission::SERIES_VIEW,
            Permission::PAYMENT_VIEW,
            Permission::EFACTURA_VIEW,
            Permission::SETTINGS_VIEW,
            Permission::REPORT_VIEW,
            Permission::EMAIL_TEMPLATE_VIEW,
            Permission::BORDEROU_VIEW,
        ];

        // ── Accountant: daily invoicing work ─────────────────────────────
        // Everything Employee has + create/edit/delete for invoicing workflow
        // NO: webhooks, API keys, backup, member management, billing
        $accountantPerms = array_merge($employeePerms, [
            Permission::CLIENT_CREATE, Permission::CLIENT_EDIT, Permission::CLIENT_DELETE,
            Permission::PRODUCT_CREATE, Permission::PRODUCT_EDIT, Permission::PRODUCT_DELETE,
            Permission::INVOICE_CREATE, Permission::INVOICE_EDIT, Permission::INVOICE_DELETE,
            Permission::INVOICE_ISSUE, Permission::INVOICE_SEND, Permission::INVOICE_CANCEL,
            Permission::INVOICE_REFUND,
            Permission::RECURRING_INVOICE_MANAGE,
            Permission::SERIES_MANAGE,
            Permission::PAYMENT_CREATE, Permission::PAYMENT_DELETE,
            Permission::EFACTURA_SUBMIT,
            Permission::IMPORT_MANAGE,
            Permission::EXPORT_DATA,
            Permission::EMAIL_TEMPLATE_MANAGE,
            Permission::BORDEROU_MANAGE,
        ]);

        // ── Admin: full operational control ──────────────────────────────
        // Everything Accountant has + company management, webhooks, API keys,
        // backup, members, settings, audit
        // NO: billing
        $adminPerms = array_merge($accountantPerms, [
            Permission::COMPANY_CREATE, Permission::COMPANY_EDIT, Permission::COMPANY_DELETE,
            Permission::SETTINGS_MANAGE,
            Permission::ORG_MANAGE_MEMBERS,
            Permission::ORG_VIEW_AUDIT,
            Permission::WEBHOOK_VIEW, Permission::WEBHOOK_MANAGE,
            Permission::API_KEY_VIEW, Permission::API_KEY_MANAGE,
            Permission::BACKUP_MANAGE,
        ]);

        // ── Owner: everything ────────────────────────────────────────────
        $ownerPerms = array_merge($adminPerms, [
            Permission::ORG_MANAGE_BILLING,
        ]);

        self::$map = [
            OrganizationRole::EMPLOYEE->value => $employeePerms,
            OrganizationRole::ACCOUNTANT->value => $accountantPerms,
            OrganizationRole::ADMIN->value => $adminPerms,
            OrganizationRole::OWNER->value => $ownerPerms,
        ];
    }
}
