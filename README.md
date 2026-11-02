# School Year Overhaul — Plan & Spec

Phase 1 (schema) is done — see `migration_schoolyear.sql` /
`migration_schoolyear.mysql.sql`. This doc is the spec for Phases 2–4 so
each one can be built without re-deciding the rules.

## Decisions locked in

- **"More than 4 years" = blocked at the 5th school year onward.** A
  student who started in 2022-2023 is normal through 2025-2026 (year 4)
  and blocked starting 2026-2027 (year 5) unless `account_status` flags
  them as still legitimately enrolled.
- **`account_status` is admin-set**, not auto-derived. Values:
  `normal`, `repeating`, `undergrad_repeating`, `suspended_repeating`,
  `dropped`, `graduated`. Default `normal`.
- **Faculty** is a UI relabel of the existing `departments` table/concept
  — no table rename (too much blast radius across 6+ files for a label
  change). Every place that currently says "Department" in admin/student
  UI text becomes "Faculty"; the `department`/`department_code` columns
  and `departments` table keep their existing names in code.
- **Major and Section are removed** — registration form, admin Students
  form, CSV import template/columns, Settings panel's Majors section all
  go away in Phase 2/3.

## computeYearLevel() — the core function (Phase 3)

Add to `includes/functions.php`:

```php
/**
 * $studentStartYear: the start_year of the student's school_year_id row
 * $activeStartYear:  the start_year of the currently-active school year
 * Returns: ['index' => int, 'label' => ?string, 'blocked' => bool]
 *   index 1-4  -> normal, label = year_levels row at that position
 *   index >= 5 -> blocked unless account_status overrides it
 */
function computeYearLevel(int $studentStartYear, int $activeStartYear, array $yearLevelNames): array
{
    $index = ($activeStartYear - $studentStartYear) + 1;
    if ($index < 1) $index = 1; // shouldn't happen, guards bad data
    $label = $yearLevelNames[$index - 1] ?? null; // year_levels ordered 1st..4th by sort_order
    return ['index' => $index, 'label' => $label, 'blocked' => $index >= 5];
}
```

`$yearLevelNames` = `year_levels` table ordered by `sort_order`, e.g.
`['1st Year', '2nd Year', '3rd Year', '4th Year']`. This keeps the
existing `year_levels` admin settings table as the label source, so
`election_positions.year_restriction` matching doesn't change at all —
only where the label comes from changes.

## Login gate (Phase 3, in login.php)

On successful password check, before issuing the session:

```
year = computeYearLevel(student.school_year.start_year, active.start_year, ...)

if year.blocked:
    if account_status in ('repeating', 'undergrad_repeating', 'suspended_repeating'):
        -> allow login, but set a session flag requiring the
           "Update Your Account" screen before anything else
    elif account_status in ('dropped', 'graduated'):
        -> reject login: "This account is no longer active."
    else: # normal, but past 4 years and nobody flagged them
        -> reject login: "Your account needs review — please contact
           the election administrator." (this is the safety net for a
           student who should have been marked repeating/undergrad but
           wasn't yet)
```

## "Next School Year Election" admin action (Phase 3, admin/api)

New endpoint action, single transaction:

1. `UPDATE elections SET status = 'archived' WHERE status != 'archived'`
2. Insert or activate the new `school_years` row; clear `is_active` on
   the old one.
3. For every student with `registration_status != 'unregistered'`:
   recompute `year_level` via `computeYearLevel()`.
   - index 1-4: write the new label, leave `account_status` alone.
   - index >= 5 and `account_status = 'normal'`: auto-set
     `account_status = 'graduated'` **only if** this is their first time
     crossing the threshold this way (i.e. don't silently graduate
     someone an admin already marked `repeating`) — graduated students
     keep their last real `year_level` label rather than getting a
     nonsensical "5th Year".
   - index >= 5 and already flagged repeating/undergrad/suspended: leave
     as-is (admin will resolve via the per-student "update account" flow
     next login, or manually).
4. Log one activity_logs line summarizing the counts (archived N
   elections, advanced to school year X, graduated N, flagged N).

This intentionally does **not** touch the elections' underlying rows
(candidates/votes stay intact) — archiving is the same soft-hide that
already exists for a single election today, just applied in bulk.

## Reports (Phase 4)

New Results → Reports view:
- Two report types: **SSG** (one document, school-wide winners) and
  **DSG** (one document per rollover, sectioned by Faculty, each
  Faculty's winners listed under its own heading).
- "Winners only" = the candidate(s) with the highest vote count per
  position, up to `winner_count` seats — ties get flagged rather than
  silently picking one.
- Print-preview = a full-page HTML view styled for print (`@media
  print`), same data source as the export.
- Export = XLSX (via the project's `xlsx` skill) and DOCX (via the
  `docx` skill) — both generated from the same winners dataset so the
  three views never drift out of sync.

## Suggested build order for Phases 2–4

1. **Phase 2 — Admin Settings**: School Years CRUD (add/set active/
   reorder), remove Majors section, relabel Department → Faculty in
   Settings UI only.
2. **Phase 3 — Registration + login + Next School Year action**: the
   business logic above, touching `register.php`, `login.php`,
   `includes/functions.php`, and a new admin API action.
3. **Phase 4 — Admin Students panel + CSV**: remove major/section
   fields, add School Year picker (replaces manual Year Level dropdown),
   relabel Department → Faculty across admin + student-facing UI.
4. **Phase 5 — Reports**: the export/print system described above.

Say the word for any of these and I'll build it out fully, file by file.