# Notes Type Mapping Spec

This spec defines the unified display language and mapping between UI and database.

## Goal
Unify labels across scanner pages and logs in Arabic:
- تحذير
- حرمان
- منع

## Source of Truth
- Storage remains backward compatible with current schema:
  - `notes.note_type` in: `warning`, `blocker`, `info` (legacy)
  - `notes.priority` in: `low`, `medium`, `high`

## UI -> DB Mapping
- UI: `تحذير` -> `note_type=warning`, `priority` stays selected
- UI: `حرمان` -> `note_type=warning`, `priority=high`
- UI: `منع` -> `note_type=blocker`, `priority>=medium`

## DB -> UI Mapping
- `note_type=blocker` -> display `منع 🛑`
- `note_type=warning` + `priority=high` -> display `حرمان ⛔`
- `note_type=warning` + `priority in (low, medium)` -> display `تحذير ⚠️`
- anything else -> display `ملاحظة`

## Pages Covered
- `admin/notes_scanner.php`
- `admin/rounds_scanner.php`
- `admin/view_notes.php`
- `get_notes.php`

## Regression Checklist
- New warning appears as تحذير
- New deprivation appears as حرمان
- New blocker appears as منع
- Old records still render correctly
- Notes delete action removes from the correct source table
