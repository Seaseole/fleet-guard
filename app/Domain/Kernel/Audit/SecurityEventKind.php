<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Audit;

/**
 * §4.4 SECURITY_EVENT.kind — the closed list the DB CHECK enforces.
 *
 * Closed on purpose. A security trail that accepts any string becomes a log file: nobody can query
 * it for "who disabled MFA" because the answer is spread over four spellings of the same event.
 * Adding a kind here, to this enum and to the `security_events_kind_known` CHECK together is the
 * intended cost, and it is what keeps the Owner's security screen a list of questions with answers.
 *
 * The document kinds are the reason this enum exists in the Kernel rather than in Security: the
 * download trail (§14.4) is written by Documents, and a trail the app cannot write is a trail that
 * gets "implemented" later as a log line.
 */
enum SecurityEventKind: string
{
    case MfaEnabled = 'mfa_enabled';
    case MfaDisabled = 'mfa_disabled';
    case PasswordChanged = 'password_changed';
    case ReauthOk = 'reauth_ok';
    case ReauthFail = 'reauth_fail';
    case SessionRevoked = 'session_revoked';
    case InviteAccepted = 'invite_accepted';
    case KioskTokenIssued = 'kiosk_token_issued';
    case KioskTokenRevoked = 'kiosk_token_revoked';
    case DocumentUploaded = 'document_uploaded';
    case DocumentDownloaded = 'document_downloaded';
    case DocumentVersionDeleted = 'document_version_deleted';
    case DocumentVoided = 'document_voided';

    public function label(): string
    {
        return match ($this) {
            self::MfaEnabled => 'MFA enabled',
            self::MfaDisabled => 'MFA disabled',
            self::PasswordChanged => 'Password changed',
            self::ReauthOk => 'Re-authentication accepted',
            self::ReauthFail => 'Re-authentication refused',
            self::SessionRevoked => 'Session revoked',
            self::InviteAccepted => 'Invitation accepted',
            self::KioskTokenIssued => 'Kiosk token issued',
            self::KioskTokenRevoked => 'Kiosk token revoked',
            self::DocumentUploaded => 'Document uploaded',
            self::DocumentDownloaded => 'Document downloaded',
            self::DocumentVersionDeleted => 'Document version deleted',
            self::DocumentVoided => 'Document voided',
        };
    }
}
