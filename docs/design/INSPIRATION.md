# voting-app3 — Design System & Reference Pack

**Project:** voting-app3 (School Elections)  
**System:** Navy + lime election UI — dark hero, glass nav, pill controls, light & dark dashboard themes  
**Status:** Documents the site **as built**. This is not a proposed restyle.  
**Companion board:** [`inspiration.html`](inspiration.html) · **Screens:** [`screens/`](screens/)

---

## 1. The direction in one line

> Dark navy and one lime signal for action, gold for seals — election night, not enterprise software.

voting-app3 is a **dark-navy + lime election interface**. Concretely, the system is:

- a radial-gradient navy hero (`#16345c → #0b1f3a → #081428`) with a **blurred glass sticky nav** (`rgba(11,31,58,.82)` + `blur(14px)`)
- **lime `#84cc16`** as the single action color: buttons, active nav, progress fills, tally bars
- **gold `#d4af37`** for eyebrows, seals, stamps and the ballot-stub header
- a **perforated ballot stub** rotated 2.5° with a `0 30px 60px` shadow
- a scrolling ticker strip, pill CTAs/chips/badges (999px), rounded cards with soft shadows
- two matched dashboard themes: light `#f8fafc` and dark `#0a1120`
- emoji used as **functional glyphs** (🗳 🎓 ✅) in buttons, tabs and banners

| Surface | Job |
|---|---|
| Landing | Say what's open right now and get a student to the Vote button |
| Login / Register | One glass card, ID + password, nothing to distract |
| Admin dashboard | Run the election: KPIs, turnout, tables, logs |
| Student portal | One continuous ballot, one decision per block |
| Public results | Live tallies that read at a glance |

---

## 2. Reference shelf

> **Correction note.** An earlier version of this shelf listed Swiss print, GOV.UK, USWDS, Pentagram, paper stocks and registrar documents. Those were carried over from **voting-app2's** document pack — they describe a flat, no-shadow, no-gradient print system that voting-app3 deliberately is not. They have been replaced with references that match what the code actually renders. Each entry is tagged **visual match** (it looks like this site) or **clarity only** (we borrow wording rules, not looks).

### Visual match

- **Material Design 3** — https://m3.material.io/  
  Pill buttons, tonal chips, rounded cards, fully-rounded badges — the shape language the site runs: 999px hero CTAs, nav items, filter chips and status pills.

- **Carbon Design System** — https://carbondesignsystem.com/  
  IBM's system for data-heavy admin tools: Plex type, dense tables, dark themes. Closest match to the admin registry, results and logs panels.

- **shadcn/ui patterns** — https://ui.shadcn.com/  
  Dashboard cards, filter pills, badges and toggles in matched light/dark themes — the same structure as `assets/dashboard-app.css` and its two token sets.

- **Sora · Inter · IBM Plex Mono** — https://fonts.google.com/  
  The three faces the site actually loads.

### Clarity only — wording, not visuals

- **AIGA — Design for Democracy** — https://www.aiga.org/design-for-democracy  
  Ballot instructions and plain language. Explicitly *not* a visual reference: AIGA's system is flat print; this site is dark, rounded and glowing.

- **Center for Civic Design — Field Guides** — https://civicdesign.org/field-guides/  
  Candidate-list hierarchy and first-time-voter instructions — how a ballot section reads, not how it looks.

---

## 3. Palette

All values read from `landingpage.php`, `assets/style.css` and `assets/dashboard-app.css`.

| Token | Hex | Where it lives |
|---|---|---|
| Navy-950 | `#081428` | Hero base, darkest surface (never pure black) |
| Navy-900 | `#0B1F3A` | Hero mid, glass nav base, dark headers |
| Auth navy | `#0B2545` | Login / register surface |
| Lime | `#84CC16` | The action color |
| Lime dark | `#65A30D` | Hover / gradient end for progress fills |
| Gold | `#D4AF37` | Eyebrows, seals, stamps, ballot-stub header |
| Paper | `#F7F5EF` | Light band background on the landing |
| Sheet | `#FFFFFF` | Cards, light theme |
| Ink | `#0F172A` | Light-theme text |
| Muted | `#5C6B81` | Secondary copy on cream |
| Border (light) | `#E2E8F0` | Card and input borders, light theme |
| Dark bg | `#0A1120` | Dark theme page background |
| Dark card | `#111A2C` | Dark theme card |
| Dark secondary | `#8B98AD` | Dark theme secondary text only |
| Border (dark) | `#232F45` | Dark theme borders |
| Status | `#16A34A` / `#D97706` / `#DC2626` | success / warning / danger (dark: `#4ADE80` / `#FBBF24` / `#F87171`) |

### Readability rules (these are the ones that bite)

| Combination | Ratio | Rule |
|---|---|---|
| Lime `#84CC16` on cream / white | **1.8:1** | Never use lime as text on a light surface. Use `#4D7C0F` (4.9:1), or keep lime on navy (8.4:1). |
| `#8B98AD` on cream | **2.7:1** | Dark-theme token only. On light surfaces use `#5D6C82` (~4.9:1). |
| `#5C6B81` on cream | 5.0:1 | OK for secondary copy. |
| `#8B98AD` on `#111A2C` | 5.9:1 | OK in dark mode. |

The companion board follows these rules and audits at **0 contrast failures**.

---

## 4. Type system

```css
--display: 'Sora', system-ui, sans-serif;
--body:    'Inter', system-ui, -apple-system, sans-serif;
--mono:    'IBM Plex Mono', ui-monospace, monospace;
```

| Role | Face | Treatment |
|---|---|---|
| Page headline | Sora | 800 weight, tight leading, `-0.02em` tracking |
| Section heading | Sora | 800 weight, 2.1–2.8rem |
| Body | Inter | 400–600, 0.9–1rem, 1.6 line-height |
| Button | Inter | 600–700, 0.85–0.95rem |
| Eyebrow / label | IBM Plex Mono | uppercase, 0.1em tracking, 0.62–0.72rem |
| IDs, dates, counts | IBM Plex Mono | tabular numerals where available |
| Status badge | IBM Plex Mono | uppercase, pill-shaped, 0.68rem |

**No more than three families.** Sora carries the proclamation voice; Inter carries the form; Mono carries the record.

---

## 5. Component anatomy

### Landing — "the hero board"

- Sticky glass nav: `rgba(11,31,58,.82)` + `blur(14px)`, lime pill "Log In".
- Gold-bordered eyebrow pill (`● OFFICIAL BALLOT — A.Y. 2026–2027`).
- Headline "Your Voice / Your Choice" — line 2 in lime, the "o" redrawn as a ballot bubble.
- Lime-tinted live voting banner with the deadline in gold.
- Lime pill CTA with a pulse-glow ring.
- Ballot-stub specimen: perforated edge (radial-gradient dots), dashed rule, gold seal, rotated 2.5°, `0 30px 60px` shadow, gold stamp rotated -4°.
- Ticker strip with masked edges: elections live, students registered, ballots cast.
- Election cards with a 4px lime top border and a hover lift; "How it works" arrow badges; candidate roster with gold top bar and lime avatar ring; closing CTA over a giant gold ✓ watermark.

**Template lesson:** the hero answers *what is open, how long do I have, and where do I tap* in one screen.

### Login / Register — "the glass card"

- One translucent card over a **blurred campus photo** — not a split panel.
- White inputs with a lime focus ring; card trimmed in gold.
- Rounded (8–12px) Log In button — note: auth uses rounded buttons, the *landing* uses 999px pills.
- School seal, "Welcome Back", "← Back to Home".

**Template lesson:** authentication is one object on screen. Nothing competes with the two fields.

### Admin — "the control room"

- 240px dark gradient sidebar (`#1e293b → #0f172a`) with a lime pill active state plus an inset 3px lime bar.
- KPI cards with pastel icon tiles; turnout donut; lime progress bars; department rows.
- Election cards with status-colored top borders; status pills (`ONGOING` and friends).
- Light/dark theme switch in the sidebar footer.

**Template lesson:** admin density is fine if every row has a label, a status, and one obvious next action.

### Student — "the ballot folder"

- Same sidebar pattern with pill active state.
- One continuous scroll ballot — every position section lives in the DOM.
- Sticky progress strip with a gradient fill (`#84cc16 → #65a30d`).
- Max-select enforcement: at `winner_count` picks, remaining options disable.
- Submit opens a modal with a quiet check mark — no confetti, no receipt of picks.
- Vote All chains elections via `dashboard.php?page=vote&combined=1`.

**Template lesson:** a student should never have to guess whether an election is open, whether they voted, or what happens next.

### Public results — "the tally sheet"

- One card per election, gold-bordered header, `ONGOING` pill, school-year chip.
- Position-by-position rows: rank, avatar, name, party, votes, percentage.
- Lime share bar for the leader; gold ring + `LEADING` badge on first place.
- `LIVE · UPDATED JUST NOW` pill; mono tallies.

**Template lesson:** results should be scannable in three seconds and printable in one page.

---

## 6. Copy/paste component recipes

### Tokens

```css
:root{
  /* brand + landing */
  --navy-950:#081428;
  --navy-900:#0b1f3a;
  --navy-auth:#0b2545;
  --lime:#84cc16;
  --lime-dark:#65a30d;
  --lime-ink:#4d7c0f;   /* lime as TEXT on light surfaces — 4.9:1 */
  --gold:#d4af37;
  --gold-ink:#8a651e;   /* gold as TEXT on light surfaces */
  --paper:#f7f5ef;
  --ink:#0f172a;
  --muted:#5c6b81;
  --faint:#5d6c82;      /* secondary text on light — replaces dark-only #8b98ad */

  /* dashboard themes */
  --bg:#f8fafc; --card:#ffffff; --border:#e2e8f0; --text:#0f172a;
  /* [data-theme="dark"]: --bg:#0a1120; --card:#111a2c; --border:#232f45;
     --text:#e7ecf5; --text-secondary:#8b98ad; */

  --font-display:'Sora', system-ui, sans-serif;
  --font-body:'Inter', system-ui, sans-serif;
  --font-mono:'IBM Plex Mono', ui-monospace, monospace;
}
```

### Page frame

```css
body{
  background:var(--paper);
  color:var(--ink);
  font-family:var(--font-body);
}
.hairline{height:1px;background:var(--border);border:0;}
```

### Eyebrow

```css
.eyebrow{
  font:500 0.63rem/1.4 var(--font-mono);
  letter-spacing:0.14em;text-transform:uppercase;
  color:var(--lime-ink);            /* on navy use var(--lime) — 8.4:1 */
  border-left:3px solid var(--gold);
  padding-left:0.6rem;
}
```

### Primary button (999px pill, landing)

```css
.btn{
  display:inline-flex;align-items:center;justify-content:center;
  min-height:48px;padding:0 2rem;
  border-radius:999px;
  font:700 0.95rem/1 var(--font-body);
  text-decoration:none;cursor:pointer;
}
.btn-primary{background:var(--lime);color:#052e16;}          /* 7.9:1 */
.btn-primary:hover{background:var(--lime-dark);color:#fff;}
.btn-ghost{background:transparent;border:1px solid rgba(255,255,255,.7);color:#fff;}
```

### Status pill

```css
.pill{
  display:inline-flex;align-items:center;gap:0.4rem;
  padding:0.32rem 0.75rem;border-radius:999px;
  font:600 0.58rem/1.2 var(--font-mono);
  letter-spacing:0.06em;text-transform:uppercase;
}
.pill.ongoing{background:rgba(34,197,94,.12);color:#15803d;}
.pill.closed{background:rgba(239,68,68,.1);color:#b91c1c;}
.pill .dot{width:6px;height:6px;border-radius:50%;background:currentColor;}
```

### Ballot stub card

```css
.ballot-stub{
  position:relative;background:#fff;border-radius:14px;
  box-shadow:0 30px 60px rgba(2,10,26,.45);padding:1.6rem;
  transform:rotate(2.5deg);
}
.ballot-stub .bs-head{
  display:flex;justify-content:space-between;align-items:center;
  border-bottom:2px dashed var(--paper-dim);padding-bottom:.85rem;
  margin-bottom:1rem;
}
.ballot-stub .seal{
  width:40px;height:40px;border-radius:50%;border:2px solid var(--gold);
  display:grid;place-items:center;font:800 1rem var(--font-display);
  color:var(--gold);
}
/* perforation: circles punched along the left edge */
.ballot-stub::before{
  content:'';position:absolute;left:-6px;top:12px;bottom:12px;width:12px;
  background:radial-gradient(circle, var(--navy-950) 5px, transparent 5.5px);
}
```

### Election card (admin)

```css
.election-card{
  position:relative;background:var(--card);border-radius:16px;
  border:1px solid var(--border);border-top:4px solid var(--border);
  padding:1.6rem 1.75rem;
  box-shadow:0 3px 12px rgba(15,23,42,.05);
  transition:transform .2s, box-shadow .2s;
}
.election-card:hover{transform:translateY(-4px);box-shadow:0 16px 32px rgba(15,23,42,.1);}
.election-card.status-ongoing{border-top-color:#22c55e;}
.election-card.status-paused{border-top-color:#f59e0b;}
.election-card.status-scheduled{border-top-color:#6366f1;}
.election-card.status-closed{border-top-color:#ef4444;}
```

---

## 7. Panel script (5 minutes)

1. **Landing** — "Navy hero, lime button, gold eyebrow. The ballot stub is a live diagram of the ballot, the ticker is real turnout, and the Vote button pulses because it's the only thing on this page a student should press."
2. **Login / Register** — "One glass card over the campus photo. Two fields, one button, lime focus ring. Nothing competes with the form."
3. **Admin dashboard** — "KPI counts, not vanity metrics. Turnout donut, department bars, status pills, and a light/dark switch — the same tokens both ways."
4. **Student portal** — "One continuous ballot with a sticky progress strip. At the max picks, the rest disable themselves. No confetti — just a check mark."
5. **Public results** — "One card per election, position by position, with a lime share bar and a gold ring on the leader."
6. **Close** — "Tokens and components live in `assets/dashboard-app.css`, `assets/style.css` and `landingpage.php`. The reference board is `docs/design/inspiration.html`; live captures are in `docs/design/screens/`."

**If asked "why lime and navy?"**  
Lime is the one action color — every screen has exactly one thing lime means *press this / this is selected*. Navy is the institutional surface: hero, headers, sidebar, footer. Gold is ceremony only — seals, eyebrows, stamps, the stub header. Because lime is reserved, it stays loud; if gold or a second accent started competing for actions, it would stop working.

---

## 8. File map

| File | Purpose |
|---|---|
| `landingpage.php` | Hero board, glass nav, ticker, election cards, roster (inline styles) |
| `login.php` | Student glass-card sign-in |
| `student/register.php` | Student self-registration |
| `assets/style.css` | Auth surfaces: navy/gold tokens, cards, inputs |
| `assets/dashboard-app.css` | Shared dashboard: two theme token sets, pills, cards, charts, ballot |
| `admin/dashboard.php` | Control room |
| `dashboard.php` | Student ballot folder (continuous + combined voting) |
| `results.php` | Public tally sheet |
| `404.php` | Self-contained error page |
| `docs/design/inspiration.html` | Visual reference board |
| `docs/design/screens/` | Live captures of every public, admin and student surface |

---

*Prepared for the voting-app3 design review. Navy for the institution, lime for the one action, gold for the seal — and every claim in this document checked against the code or the captures.*
