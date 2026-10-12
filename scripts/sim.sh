#!/bin/bash
# WPicker end-to-end simulation against two Local (localwp.com) sites.
#
#   server site: plugin + child theme installed, CLI already paired with it
#   local site : its child-theme folder is the CLI working directory
#
# Usage: scripts/sim.sh            (or: make sim)
# Override paths with SIM_SERVER_THEME / SIM_LOCAL_THEME / WPICKER_BIN.
#
# check_gap marks a behaviour that is not implemented yet: it prints KNOWN and
# never fails the run, and turns into PASS by itself once the gap is closed.

set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
W="${WPICKER_BIN:-$ROOT/bin/wpicker}"
SRV="${SIM_SERVER_THEME:-$HOME/Local Sites/wpicker/app/public/wp-content/themes/wpicker-child}"
LOC="${SIM_LOCAL_THEME:-$HOME/Local Sites/wpicker-local/app/public/wp-content/themes/wpicker-child}"

pass=0; fail=0; known=0
ok()    { echo "  PASS   $1"; pass=$((pass + 1)); }
bad()   { echo "  FAIL   $1"; fail=$((fail + 1)); }
gap()   { echo "  KNOWN  $1"; known=$((known + 1)); }
check() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }
# Gap check: passes silently once the gap is fixed, reports KNOWN until then.
check_gap() { if eval "$2"; then ok "$1 (gap fixed)"; else gap "$1"; fi; }
sha()   { shasum -a 256 "$1" 2>/dev/null | cut -d' ' -f1; }

[ -x "$W" ] || { echo "CLI not built: $W (run: make build-cli)"; exit 2; }
[ -d "$SRV" ] || { echo "Server child theme not found: $SRV"; exit 2; }
"$W" whoami >/dev/null 2>&1 || { echo "CLI not paired (run: wpicker login)"; exit 2; }

mkdir -p "$LOC" && cd "$LOC" || exit 2
TAG="sim$$"
D="$TAG-d" # scratch sub-directory, so real theme folders are never touched

cleanup() {
	rm -f "$SRV/$D/$TAG.php" "$SRV/$TAG.bin" "$SRV/$TAG-srv.bin" \
		"$LOC/$D/$TAG.php" "$LOC/$D/$TAG-bad.php" "$LOC/$TAG.bin" "$LOC/$TAG-srv.bin"
	rmdir "$SRV/$D" "$LOC/$D" 2>/dev/null
	rm -f "$SRV/$TAG-a.php" "$LOC/$TAG-a.php"
	cp "$BAK/style.css" "$BAK/functions.php" "$BAK/index.php" "$SRV/"
	rm -rf "$BAK"
	"$W" theme pull --force >/dev/null 2>&1
}
# Backup lives outside the theme so pull/push never see it.
BAK="$(mktemp -d)"
cp "$SRV/style.css" "$SRV/functions.php" "$SRV/index.php" "$BAK/"
trap cleanup EXIT

echo "== $("$W" whoami 2>/dev/null | head -1)"

echo "1. pull"
check "pull succeeds" '"$W" theme pull >/dev/null 2>&1'
check "local matches server" '[ "$(sha style.css)" = "$(sha "$SRV/style.css")" ]'
check "no-op push reports no changes" '"$W" theme push 2>&1 | grep -q "No local changes"'

echo "2. push edit + new file"
echo "/* $TAG */" >> style.css
mkdir -p "$D" && printf "<?php\nfunction %s_hello() { return 'hi'; }\n" "$TAG" > "$D/$TAG.php"
OUT="$("$W" theme push 2>&1)"; RC=$?
if echo "$OUT" | grep -q "for review"; then
	echo "  The site is in push mode \"Review first\": pushes wait for approval in WP-Admin."
	echo "  This simulation needs \"Direct\" (WP-Admin → WPicker → Settings)."
	exit 2
fi
check "push succeeds" '[ "$RC" -eq 0 ]'
check "edit applied on server" 'grep -q "$TAG" "$SRV/style.css"'
check "new file created on server" '[ -f "$SRV/$D/$TAG.php" ]'

echo "3. lint gate"
printf "<?php\nfunction broken( {\n" > "$D/$TAG-bad.php"
check "syntax error rejected" '! "$W" theme push >/dev/null 2>&1'
check "bad file not written on server" '[ ! -f "$SRV/$D/$TAG-bad.php" ]'
check "last-error.json has file + line" 'grep -q "\"line\": 2" .wpicker/last-error.json && grep -q "wpicker_lint_syntax" .wpicker/last-error.json'
rm -f "$D/$TAG-bad.php"

echo "4. drift"
echo "/* local $TAG */" >> functions.php
echo "/* server $TAG */" >> "$SRV/functions.php"
check "drifted push refused" '"$W" theme push 2>&1 | grep -q "Drift detected"'
check "server copy untouched" '! grep -q "local $TAG" "$SRV/functions.php"'

echo "5. rollback"
ID="$("$W" --json history 2>/dev/null | grep -o '"id": *"[^"]*"' | head -1 | cut -d'"' -f4)"
check "history returns a manifest id" '[ -n "$ID" ]'
check "rollback succeeds" '"$W" rollback "$ID" >/dev/null 2>&1'
check "rollback restored pre-push style.css" '! grep -q "$TAG" "$SRV/style.css"'
check "rollback removed server-side drift" '! grep -q "server $TAG" "$SRV/functions.php"'
check "rollback removed the file added after the snapshot" '[ ! -f "$SRV/$D/$TAG.php" ]'

echo "6. deletions"
"$W" theme pull --force >/dev/null 2>&1 # discard the drifted local edit from step 4
check "pull removes a file deleted on the server" '[ ! -f "$D/$TAG.php" ]'
mkdir -p "$D" && echo "<?php // $TAG" > "$D/$TAG.php"
"$W" theme push >/dev/null 2>&1
rm -f "$D/$TAG.php"
check "push after a local delete succeeds" '"$W" theme push >/dev/null 2>&1'
check "local delete propagated to server" '[ ! -f "$SRV/$D/$TAG.php" ]'
check "emptied directory removed on server" '[ ! -d "$SRV/$D" ]'
mkdir -p "$D" && echo "<?php // $TAG" > "$D/$TAG.php"
"$W" theme push >/dev/null 2>&1
echo "// edited on server" >> "$SRV/$D/$TAG.php"
rm -f "$D/$TAG.php"
check "delete refused when the server copy changed" '"$W" theme push 2>&1 | grep -q "Drift detected"'
check "server edit survived the refused delete" '[ -f "$SRV/$D/$TAG.php" ]'
rm -f "$SRV/$D/$TAG.php"; rmdir "$SRV/$D" 2>/dev/null
"$W" theme pull >/dev/null 2>&1

echo "7. binary files"
head -c 2048 /dev/urandom > "$TAG.bin"
"$W" theme push >/dev/null 2>&1
check "binary file survives push" '[ "$(sha "$TAG.bin")" = "$(sha "$SRV/$TAG.bin")" ]'
head -c 2048 /dev/urandom > "$SRV/$TAG-srv.bin"
"$W" theme pull >/dev/null 2>&1
check "binary file survives pull" '[ "$(sha "$TAG-srv.bin")" = "$(sha "$SRV/$TAG-srv.bin")" ]'
check "no-op push after binary round-trip" '"$W" theme push 2>&1 | grep -q "No local changes"'
rm -f "$TAG.bin" "$TAG-srv.bin"
"$W" theme push >/dev/null 2>&1

echo "8. runtime fatal (passes lint, would kill the site)"
SITE="$("$W" whoami 2>/dev/null | sed -n 's/^Site: *//p')"
echo "${TAG}_undefined_function();" >> functions.php
check "fatal push rejected" '! "$W" theme push >/dev/null 2>&1'
check "error is wpicker_health_failed, rolled back" 'grep -q "wpicker_health_failed" .wpicker/last-error.json && grep -q "\"rolled_back\": true" .wpicker/last-error.json'
check "error names the file and line" 'grep -q "\"file\": \"functions.php\"" .wpicker/last-error.json && grep -Eq "\"line\": [1-9]" .wpicker/last-error.json'
check "server files restored" '! grep -q "${TAG}_undefined_function" "$SRV/functions.php"'
check "site answers 200" '[ "$(curl -s -o /dev/null -m 20 -w "%{http_code}" "$SITE/")" = 200 ]'
check "CLI still has access" '"$W" history >/dev/null 2>&1'
sed -i '' "/${TAG}_undefined_function/d" functions.php
check "fixed file pushes cleanly" '"$W" theme push 2>&1 | grep -q "No local changes"'

echo "9. selective pull (git-style)"
echo "/* site $TAG */" >> "$SRV/style.css"
echo "<?php // $TAG" > "$SRV/$TAG-a.php"
echo "// local $TAG" >> index.php
echo "// site $TAG" >> "$SRV/index.php"
LIST="$("$W" theme pull --dry-run 2>&1)"
check "list shows added, modified and conflicting files" 'echo "$LIST" | grep -q "A  $TAG-a.php" && echo "$LIST" | grep -q "M  style.css" && echo "$LIST" | grep -q "C  index.php"'
check "dry-run applies nothing" '[ ! -f "$TAG-a.php" ] && ! grep -q "site $TAG" style.css'
"$W" theme pull "$TAG-a.php" >/dev/null 2>&1
check "pulling one path applies only that file" '[ -f "$TAG-a.php" ] && ! grep -q "site $TAG" style.css'
check "status still lists the unapplied change" '"$W" theme status | grep -q "M  style.css"'
"$W" theme pull --all >/dev/null 2>&1
check "pull --all applies the remaining safe change" 'grep -q "site $TAG" style.css'
check "pull --all merges the file changed on both sides, keeping both versions" 'grep -q "^// local $TAG" index.php && grep -q "^// site $TAG" index.php'
check "the overlapping lines are marked as a conflict" 'grep -q "^<<<<<<< yours" index.php && grep -q "^>>>>>>> site" index.php'
check "the pre-merge local copy is kept" 'grep -q "local $TAG" .wpicker/merge-backup/index.php && ! grep -q "site $TAG" .wpicker/merge-backup/index.php'
check "push refused while conflict markers remain" '"$W" theme push 2>&1 | grep -q "Unresolved merge"'
check "site untouched by the refused push" '! grep -q "local $TAG" "$SRV/index.php"'
# Resolve the way a person would: keep both lines, drop the markers.
sed -i '' -e '/^<<<<<<< /d' -e '/^=======$/d' -e '/^>>>>>>> /d' index.php
check "resolved file pushes" '"$W" theme push >/dev/null 2>&1'
check "site has both changes" 'grep -q "local $TAG" "$SRV/index.php" && grep -q "site $TAG" "$SRV/index.php"'

echo "10. choosing a side instead of merging"
echo "// local2 $TAG" >> index.php
echo "// site2 $TAG" >> "$SRV/index.php"
"$W" theme pull --theirs index.php >/dev/null 2>&1
check "--theirs takes the site version" 'grep -q "site2 $TAG" index.php && ! grep -q "local2 $TAG" index.php'
echo "// local3 $TAG" >> index.php
echo "// site3 $TAG" >> "$SRV/index.php"
"$W" theme pull --keep-local index.php >/dev/null 2>&1
check "--keep-local then push makes the site take the local version" '"$W" theme push >/dev/null 2>&1 && grep -q "local3 $TAG" "$SRV/index.php" && ! grep -q "site3 $TAG" "$SRV/index.php"'

echo "11. two people, different lines of the same file"
printf '<?php\n// one %s\n// two\n// three\n// four\n// five\n' "$TAG" > "$TAG-a.php"
"$W" theme push >/dev/null 2>&1
sed -i '' "s|^// one $TAG\$|// one $TAG (edited locally)|" "$TAG-a.php"
sed -i '' 's|^// five$|// five (edited on the site)|' "$SRV/$TAG-a.php"
check "push refused: the site has moved on" '"$W" theme push 2>&1 | grep -q "Drift detected"'
OUT="$("$W" theme pull --all 2>&1)"
check "pull merges cleanly" 'echo "$OUT" | grep -q "merged cleanly"'
check "merged file has both edits and no markers" 'grep -q "edited locally" "$TAG-a.php" && grep -q "edited on the site" "$TAG-a.php" && ! grep -q "^<<<<<<<" "$TAG-a.php"'
check "merged file pushes" '"$W" theme push >/dev/null 2>&1'
check "site has both edits" 'grep -q "edited locally" "$SRV/$TAG-a.php" && grep -q "edited on the site" "$SRV/$TAG-a.php"'
check "everything in sync afterwards" '"$W" theme status | grep -q "In sync"'

echo
echo "pass=$pass fail=$fail known-gaps=$known"
[ "$fail" -eq 0 ]
