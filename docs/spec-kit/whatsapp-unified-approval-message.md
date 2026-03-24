# WhatsApp Unified Approval Message Spec

## 1) Problem Summary
- Issue title: Too many WhatsApp messages in approval flow (registration + acceptance + badge)
- Reported by: Operations
- Affected flows:
  - Registration flow (`process.php`)
  - Approval flow (`approve_registration.php`, `admin/generate_acceptance.php`, `admin/resend_approval.php`)

## 2) Expected vs Actual
- Expected:
  - No registration-received message from system.
  - On approval, send one unified message only (acceptance + badge) with permanent code reminder.
  - Keep other message types unchanged (activation, rejection, etc).
- Actual:
  - In approval, two messages are sent close together (acceptance then badge).

## 3) Scope
- In scope:
  - Disable/guard welcome-registration message endpoint.
  - Unify approval+badge into one outbound WhatsApp message.
- Out of scope:
  - Activation/rejection/broadcast message behavior.

## 4) Data & Template Rules
- Unified caption uses acceptance template as base + badge instructions.
- Include registration/permanent code line and note to reuse it in future registrations.
- Message type in queue: `approval_badge_unified`.

## 5) Validation
- Approve participant => exactly one queued WhatsApp message.
- Resend approval => exactly one queued WhatsApp message.
- Registration welcome resend endpoint should not send welcome anymore.
- Rejection and activation still work unchanged.
