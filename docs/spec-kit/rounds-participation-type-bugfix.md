# Rounds Participation Type Bugfix Spec

## 1) Problem Summary
- Issue title: QR rounds scanner reads stale participation type after reject/edit/re-approve
- Reported by: Operations team
- Affected screen/API: `admin/rounds_scanner.php` via `verify_round.php`
- Environment: production

## 2) Expected vs Actual
- Expected behavior: after participant is corrected to free-show and approved, rounds scan should allow entry.
- Actual behavior: scan returns `ACCESS_DENIED_TYPE` with "سيارة مميزة" message.
- Business impact: valid participants are blocked from rounds.

## 3) Reproduction Steps
1. Register participant as special car by mistake.
2. Reject, edit to free-show, then approve.
3. Scan badge in rounds scanner -> denied as special car.

## 4) Root Cause
- File: `verify_round.php`
- Logic issue: participant selection stops at the first matching record in `admin/data/data.json`.
- Data issue: same badge/wasel can have multiple records after correction flow; older non-free-show record may appear first.

## 5) Fix Scope
- In scope: participant resolution in `verify_round.php`.
- Out of scope: full data cleanup/migration of historical duplicate JSON records.
- Compatibility: keeps existing schema and response format unchanged.

## 6) Data Rules
- Candidate resolution priority:
  1) approved records first,
  2) free-show type preferred,
  3) latest timestamp (`approved_date`, then `registration_date`, then `created_at`).

## 7) Validation Plan
- Manual checks:
  - corrected participant can enter round,
  - special-car participant still denied,
  - non-approved participant still blocked.
- API checks:
  - no change in payload shape for success/error.

## 8) Deployment Plan
- Files to deploy:
  - `verify_round.php`
  - `deploy_rounds_notes_fix.py` (updated file list)
- No migration required.
- Rollback: redeploy previous `verify_round.php`.
