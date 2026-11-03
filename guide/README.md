# Civic Code — guided tour of voting-app3

A local, same-origin companion site that frames the real voting application and
explains it element by element. It is built for thesis defense: every annotation
shows what the element does, the actual source, the file and resolved line
numbers, how it connects to the rest of the system, and a line you can say out
loud.

**The tour never edits the application.** It reads source files live and frames
the running app. It does not create, update, or delete database rows.

## Open it

```
http://localhost/voting-app3/guide/
```

The guide is local-only by default. Requests from `127.0.0.1`, `::1`, and
`::ffff:127.0.0.1` are allowed; anything else receives a 403. Remote access
requires the Apache process to see `GUIDE_ALLOW_REMOTE=1`, and that is only
appropriate on a trusted machine.

## Demo accounts (local only)

| Role | Username / student ID | Password |
| --- | --- | --- |
| Student | `987654321` | `password123` |
| Admin | `admin` | `admin123` |

These are existing local demo credentials. Do not deploy this guide publicly:
the Demo access menu logs into the real app.

## Ways to use it

- **Walk the chapters.** Pick a chapter in the rail, then use Prev/Next. Each
  step loads the right app screen and opens the matching drawer.
- **Guided tour.** Start it from the welcome card (or `window.Guide.startTour()`)
  to walk every step in order. The counter shows `n of N` across all chapters.
  It resumes from `localStorage`, and `#chapter=<id>&step=<annotation>` is a
  deep link. "Following" keeps the chapter in sync with whatever you click in
  the frame; turn it off to stay put.
- **Click anything.** Every marker and every annotated element in the frame
  opens the drawer: behavior, under the hood, live code with resolved line
  numbers, tables, endpoints, connections, and a defense answer.
- **Search.** `Ctrl/Cmd+K` opens the palette; it searches annotations, chapters,
  tables, endpoints, and glossary terms. Arrow keys move, Enter opens, Esc closes.
- **System reference.** Architecture, flows, database, API, security, defense
  kit, quality, and glossary tabs. The defense kit has the 60-second pitch, a
  click-by-click demo script, and 20 panel questions with source links.
- **Print the defense kit.** The Print button in the system view expands every
  answer and prints the kit with a date header.
- **Offline fallback.** If the framed page cannot load, the guide shows a
  recorded screen and a list of annotations for that route.

## What is inside

```
guide/
  index.php            shell: top bar, chapter rail, framed app, inspector
  config.php           access gate, data loaders, JSON helper
  assets/
    tour.css           offline design system, responsive layout, print rules
    tour.js            iframe engine, markers, drawer, tour, search, fallback
    highlight.js       dependency-free source highlighter
  data/
    annotations.php    groups, chapters, steps, and element annotations
    system.php         architecture, database, API, security, flows, defense kit
  api/
    snippet.php        resolves file + anchor to real code
    validate.php       dev-only anchor audit
    demo-login.php     POST-only demo session bridge (no DB writes)
    roles.php          current demo session probe
    logout.php         POST-only session clear
  screens/             recorded fallback screens (captured from this app)
  tools/
    cdp.mjs            dependency-free headless Chrome driver
    qa.mjs             end-to-end browser QA (desktop, mobile, print)
    capture.mjs        regenerates guide/screens/*.webp
    test.sh            gate, snippet API, highlighter, validator smoke tests
```

## Source is read live

Code references never store line numbers. Each reference is a file plus a stable
anchor string, such as `password_verify` in `login.php`. The server finds the
anchor in the current file, resolves the surrounding lines, and returns the
snippet. If an anchor disappears, the validator reports it instead of showing
the wrong code.

### Add an annotation

1. Add a step to a chapter in `guide/data/annotations.php`.
2. Give the annotation a `selector` that exists on its `match.path`.
3. Add `code` entries as `{ file, anchor, before, after, label }`; anchor on a
   function name, a distinctive line, or a comment — never on a line number.
4. Run the validator (below) and then the QA suite.

## Verify

```bash
bash guide/tools/test.sh                 # gate, API, highlighter, anchors
node guide/tools/qa.mjs                  # full browser pass, writes guide/tools/out/
node guide/tools/capture.mjs             # refresh recorded screens
```

Expected: lint clean, `unresolved: []`, QA `PASS`, no console errors, and
readable desktop (`out/desktop-1440.png`), mobile (`out/mobile-390.png`), and
print (`out/print-defense.png`) captures.

## Troubleshooting

- **403 from the guide** — the request did not come from loopback. Open
  `http://localhost/...`, not a LAN IP.
- **The frame says it cannot load** — confirm Apache and MySQL are running in
  XAMPP and that `http://localhost/voting-app3/public/landingpage.php` works alone.
- **Blank admin charts** — Chart.js is loaded from a CDN by the app itself. The
  guide shows a banner and offers the recorded screen; the rest of the panel works.
- **A snippet says the anchor was not found** — the app changed that line. Update
  the anchor in `guide/data/annotations.php` or `system.php`, then re-run the
  validator.
- **Demo login does nothing** — the bridge requires `POST` plus the
  `X-GUIDE-DEMO: 1` header; the UI already sends both.

## Safety

- Only local requests by default.
- Snippet API: whitelisted extensions, realpath containment, size and line caps,
  no directory listing, no `.git` / env files / uploads.
- Source is inserted with `textContent`, never as HTML.
- No third-party runtime dependencies and no CDN in the guide UI.
- No application file and no database row is modified.
- Do not deploy this guide publicly: the demo bridge signs into the real app.
