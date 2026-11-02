# Voting App3 — Bug/Feature Batch (2026-10-29)

## 1. Election management: Target School Year behavior  ✅ DONE (2026-10-29)
- [x] Investigated: wizard had a SY dropdown (default = upcoming); server honored explicit target_school_year_id override; "Next School Year Election" = archive-all button (unrelated to the target field)
- [x] Answer delivered to user (see final report)
- [x] Made automatic: server ignores client target_school_year_id (create = upcoming year, edit keeps stored year, duplicate retargets to upcoming); wizard shows read-only badge
- [x] E2E: 14/14 checks pass (spoofed SY ignored, dup detector blocks create/activate/schedule, legit reactivation works)

## 2. Combined ballot header bug ✅ DONE
- [x] Root cause: "closest top" metric flipped the label when the next section's top peeked into view
- [x] Fix: scrollspy — last block whose top ≤ header line (dashboard.php ~line 801)
- [x] Browser-verified: top→SSG, DSG peeking@650px→still SSG (old bug zone), DSG past header→DSG, back to top→SSG

## 3. Duplicate detector bypass via activate ✅ DONE
- [x] assertNotDuplicateElection() now also runs on lightweight-patch activation (status → scheduled/ongoing) and draft schedule-save
- [x] E2E: duplicate copy can't be scheduled or started; legit archived→ongoing reactivation still allowed (14/14 checks)

## 4. Dark mode: Faculty read-only textbox ✅ DONE
- [x] Inline #eef2f7 → var(--surface-2)/var(--muted) (admin/dashboard.php:2299)
- [x] Browser-verified in dark mode: bg resolves to dark surface, text light gray

## 5. Admin settings layout ✅ DONE
- [x] Year Levels card margin-bottom:1.5rem (matches siblings)
- [x] Browser-verified: all four settings cards computed margin 24px

## 6. Student live results: SSG/DSG panel switcher ✅ DONE
- [x] resultsTabControllerHtml()/switchResultsTab() mirror the Candidates tabs (window.__resultsTab survives the 15s auto-refresh)
- [x] Hidden results now state why: "never shown" vs "only after the election ends" (no more silent disappearance)
- [x] Browser-verified: tab switch SSG↔DSG works, hidden-notice shows, zero console errors

## 7. Landing page hero ✅ DONE
- [x] "Official Ballot · A.Y. …" eyebrow removed (landingpage.php:504)
- [x] Browser-verified: 0 .eyebrow in hero, no "Official Ballot" text on page
