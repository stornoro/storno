<?php

namespace App\Enum;

final class MessageKey
{
    // Notification titles
    const TITLE_INVOICE_VALIDATED = 'notification.invoice_validated.title';
    const TITLE_INVOICE_REJECTED = 'notification.invoice_rejected.title';
    const TITLE_SYNC_ERROR = 'notification.sync_error.title';
    const TITLE_NEW_DOCUMENTS = 'notification.new_documents.title';
    const TITLE_TOKEN_EXPIRING = 'notification.token_expiring.title';
    const TITLE_TOKEN_REFRESH_FAILED = 'notification.token_refresh_failed.title';
    const TITLE_EXPORT_READY = 'notification.export_ready.title';
    const TITLE_BACKUP_READY = 'notification.backup_ready.title';
    const TITLE_RESTORE_COMPLETED = 'notification.restore_completed.title';
    const TITLE_ANAF_DEADLINE = 'notification.anaf_deadline.title';
    const TITLE_PAYMENT_RECEIVED = 'notification.payment_received.title';

    // Notification messages
    const MSG_INVOICE_VALIDATED = 'notification.invoice_validated.message';
    const MSG_INVOICE_REJECTED = 'notification.invoice_rejected.message';
    const MSG_SYNC_ERROR_SINGLE = 'notification.sync_error.single';
    const MSG_SYNC_ERROR_MULTIPLE = 'notification.sync_error.multiple';
    const MSG_NEW_DOCUMENTS = 'notification.new_documents.message';
    const MSG_TOKEN_EXPIRING = 'notification.token_expiring.message';
    const MSG_TOKEN_REFRESH_FAILED = 'notification.token_refresh_failed.message';
    const MSG_EXPORT_READY = 'notification.export_ready.message';
    const MSG_BACKUP_READY = 'notification.backup_ready.message';
    const MSG_RESTORE_COMPLETED = 'notification.restore_completed.message';
    const MSG_ANAF_DEADLINE = 'notification.anaf_deadline.message';
    const MSG_PAYMENT_RECEIVED_TITLE = 'notification.payment_received.title_format';
    const MSG_PAYMENT_RECEIVED = 'notification.payment_received.message';

    // Sync errors (sanitized)
    const ERR_INTERNAL_SAVE = 'error.sync.internal_save';
    const ERR_DATABASE = 'error.sync.database';
    const ERR_INTERNAL_PROCESSING = 'error.sync.internal_processing';
    const ERR_ANAF_TIMEOUT = 'error.sync.anaf_timeout';
    const ERR_ANAF_CONNECTION = 'error.sync.anaf_connection';
    const ERR_ANAF_UNAVAILABLE = 'error.sync.anaf_unavailable';
    const ERR_ANAF_MESSAGE_PROCESSING = 'error.sync.message_processing';
    const ERR_ANAF_DETAILS_UNAVAILABLE = 'error.sync.details_unavailable';

    // API errors
    const ERR_NO_ANAF_TOKEN = 'error.anaf.no_token';
    const ERR_NO_ANAF_TOKEN_DETAIL = 'error.anaf.no_token_detail';
    const ERR_ANAF_RATE_LIMIT = 'error.anaf.rate_limit';
    const ERR_SYNC_COOLDOWN = 'error.sync.cooldown';
    const ERR_SYNC_NO_TOKEN = 'error.sync.no_token';
    const ERR_SYNC_ENABLE_NO_TOKEN = 'error.sync.enable_no_token';
    const ERR_PASSWORD_INCORRECT = 'error.auth.password_incorrect';
    const ERR_NO_INVOICES_FOR_PERIOD = 'error.invoice.no_invoices_for_period';
    const ERR_COMPANY_ALREADY_ADDED = 'error.company.already_added';
    const ERR_LINK_EXPIRED = 'error.link.expired';
    const ERR_LINK_USED = 'error.link.already_used';
    const ERR_INVITATION_EXPIRED = 'error.invitation.expired';
    const ERR_SESSION_EXPIRED = 'error.session.expired';
    const ERR_INVOICE_NOT_FOUND = 'error.invoice.not_found';
    const ERR_NO_PERMISSION = 'error.permission.denied';

    // Member errors
    const ERR_MEMBER_CANNOT_MODIFY_SUPERADMIN = 'error.member.cannot_modify_superadmin';
    const ERR_MEMBER_CANNOT_CHANGE_OWN_ROLE = 'error.member.cannot_change_own_role';
    const ERR_MEMBER_ONLY_OWNER_CAN_PROMOTE = 'error.member.only_owner_can_promote';
    const ERR_MEMBER_CANNOT_MODIFY_OWNER = 'error.member.cannot_modify_owner';
    const ERR_MEMBER_MUST_HAVE_OWNER = 'error.member.must_have_owner';
    const ERR_MEMBER_CANNOT_DEACTIVATE_SELF = 'error.member.cannot_deactivate_self';
    const ERR_MEMBER_CANNOT_GRANT_PERMISSION = 'error.member.cannot_grant_permission';
    const ERR_MEMBER_CANNOT_DEACTIVATE_SUPERADMIN = 'error.member.cannot_deactivate_superadmin';
    const ERR_MEMBER_CANNOT_DEACTIVATE_OWNER = 'error.member.cannot_deactivate_owner';

    // ANAF OAuth callback
    const ANAF_CONNECT_SUCCESS = 'anaf.connect_success';
    const ANAF_CONNECT_ERROR = 'anaf.connect_error';
}
