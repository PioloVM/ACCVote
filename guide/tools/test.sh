#!/usr/bin/env bash
# Guide smoke tests — run from the repository root.
set -u
BASE="http://localhost/voting-app3"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
[ -z "$PHP_BIN" ] && [ -x /c/xampp/php/php.exe ] && PHP_BIN=/c/xampp/php/php.exe
FAIL=0

ok()   { printf '  ok   %s\n' "$1"; }
bad()  { printf '  FAIL %s\n' "$1"; FAIL=1; }

echo "== local gate =="
REMOTE=$($PHP_BIN -r '$_SERVER["REMOTE_ADDR"]="8.8.8.8"; putenv("GUIDE_ALLOW_REMOTE=0"); require "guide/config.php"; echo guide_is_local() ? "true" : "false";' 2>/dev/null)
LOCAL=$($PHP_BIN -r '$_SERVER["REMOTE_ADDR"]="127.0.0.1"; require "guide/config.php"; echo guide_is_local() ? "true" : "false";' 2>/dev/null)
[ "$REMOTE" = "false" ] && ok "remote denied by default" || bad "remote gate returned '$REMOTE'"
[ "$LOCAL" = "true" ] && ok "loopback allowed" || bad "loopback gate returned '$LOCAL'"

echo "== shell =="
CODE=$(curl -s -o /tmp/guide-index.html -w "%{http_code}" "$BASE/guide/")
[ "$CODE" = "200" ] && ok "guide returns 200" || bad "guide returned $CODE"
for id in tour-topbar tour-rail stage app-frame marker-layer drawer palette welcome toast; do
  grep -q "id=\"$id\"" /tmp/guide-index.html && ok "shell has #$id" || bad "shell missing #$id"
done
grep -q "window.GUIDE_DATA" /tmp/guide-index.html && ok "shell injects GUIDE_DATA" || bad "shell missing GUIDE_DATA"

echo "== snippet API =="
SNIP=$(curl -s "$BASE/guide/api/snippet.php?file=login.php&anchor=password_verify&before=4&after=8")
echo "$SNIP" | python -c "import json,sys;d=json.load(sys.stdin);assert d['ok'] and 'password_verify' in d['code'] and d['start'] <= d['anchor_line'] <= d['end']" 2>/dev/null \
  && ok "valid anchor resolves with real code" || bad "valid anchor failed: $SNIP"
TRAV=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/guide/api/snippet.php?file=../.env&anchor=x")
[ "$TRAV" = "400" ] && ok "path traversal rejected" || bad "traversal returned $TRAV"
BIN=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/guide/api/snippet.php?file=assets/logo.png&anchor=x")
[ "$BIN" = "400" ] && ok "non-text extension rejected" || bad "binary returned $BIN"
MISS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/guide/api/snippet.php?file=login.php&anchor=__nope__")
[ "$MISS" = "404" ] && ok "missing anchor returns 404" || bad "missing anchor returned $MISS"
DENIED_ROOT=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/guide/api/snippet.php?file=guide/api/snippet.php&anchor=guide_require_local")
[ "$DENIED_ROOT" = "400" ] && ok "guide/api is not readable" || bad "guide/api returned $DENIED_ROOT"
DOTFILE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/guide/api/snippet.php?file=.env&anchor=x")
[ "$DOTFILE" = "400" ] && ok "dotfiles rejected" || bad "dotfile returned $DOTFILE"
ABS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/guide/api/snippet.php?file=C:/Windows/win.ini&anchor=x")
[ "$ABS" = "400" ] && ok "absolute path rejected" || bad "absolute path returned $ABS"
NOANCHOR=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/guide/api/snippet.php?file=login.php")
[ "$NOANCHOR" = "400" ] && ok "missing anchor parameter rejected" || bad "no anchor returned $NOANCHOR"
CLAMP=$(curl -s "$BASE/guide/api/snippet.php?file=login.php&anchor=password_verify&before=999&after=999")
echo "$CLAMP" | python -c "import json,sys;d=json.load(sys.stdin);assert d['ok'] and d['end']-d['start']<=400 and d['start']<=d['anchor_line']<=d['end']" 2>/dev/null \
  && ok "context window clamped" || bad "context clamp failed: $CLAMP"
head -c 524289 /dev/zero | tr '\0' 'a' > guide/tools/oversized.php
BIG=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/guide/api/snippet.php?file=guide/tools/oversized.php&anchor=aa")
rm -f guide/tools/oversized.php
[ "$BIG" = "413" ] && ok "oversized file rejected" || bad "oversized file returned $BIG"

echo "== highlighter =="
if node - <<'NODE'
const fs = require('fs');
const vm = require('vm');
const ctx = { window: {} };
vm.createContext(ctx);
vm.runInContext(fs.readFileSync('guide/assets/highlight.js', 'utf8'), ctx);
const h = ctx.window.GuideHighlight.highlight('<?php echo $name; // note', 'php');
const need = ['tok-key', 'tok-var', 'tok-com', '&lt;'];
if (need.some((token) => !h.includes(token))) process.exit(1);
if (h.includes('<php')) process.exit(1);
const r = ctx.window.GuideHighlight.renderCode('a\nb', 'php', 10);
if (!r.includes('class="ln">10</span>') || !r.includes('class="ln">11</span>')) process.exit(1);
NODE
then ok "highlighter escapes and tokenizes"; else bad "highlighter output wrong"; fi

echo "== demo session API =="
ROLES=$(curl -s "$BASE/guide/api/roles.php")
echo "$ROLES" | python -c "import json,sys;d=json.load(sys.stdin);assert d['student'] is False and d['admin'] is False" 2>/dev/null \
  && ok "roles probe answers 200 for anonymous visitors" || bad "roles probe failed: $ROLES"
GET_LOGIN=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/guide/api/demo-login.php")
[ "$GET_LOGIN" = "405" ] && ok "demo login requires POST" || bad "GET demo login returned $GET_LOGIN"
NOHEADER=$(curl -s -o /dev/null -w "%{http_code}" -X POST -d "role=student" "$BASE/guide/api/demo-login.php")
[ "$NOHEADER" = "400" ] && ok "demo login requires the guide header" || bad "headerless demo login returned $NOHEADER"
BADROLE=$(curl -s -o /dev/null -w "%{http_code}" -H "X-Guide-Demo: 1" -X POST -d "role=teacher" "$BASE/guide/api/demo-login.php")
[ "$BADROLE" = "400" ] && ok "unknown demo role rejected" || bad "bad role returned $BADROLE"
GET_LOGOUT=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/guide/api/logout.php")
[ "$GET_LOGOUT" = "405" ] && ok "logout requires POST" || bad "GET logout returned $GET_LOGOUT"

echo "== validator =="
VALID=$(curl -s "$BASE/guide/api/validate.php")
echo "$VALID" | python -c "import json,sys;d=json.load(sys.stdin);assert d['ok'] and not d['unresolved']" 2>/dev/null \
  && ok "all anchors resolve" || bad "validator failed: $VALID"

echo
if [ "$FAIL" = "0" ]; then echo "PASS"; else echo "FAIL"; exit 1; fi
