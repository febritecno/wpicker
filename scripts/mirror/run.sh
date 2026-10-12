#!/bin/bash
# Site-to-site mirror test: back up the SOURCE site, restore it onto the
# TARGET site through WP-Admin's REST endpoints (upload + restore), then
# prove the target is a faithful copy:
#
#   - database: every row identical once each site's own address and path
#     are normalised (scripts/mirror/dump.php — independent of the plugin's
#     rewriter), no serialized value left unreadable
#   - nothing in the target's database still points at the source
#   - files: plugins / themes / uploads identical, byte for byte
#   - the target answers, with the source's title, and its pages do not
#     link back to the source
#
# Usage: scripts/mirror/run.sh        (defaults: the two Local sites)
#        MIRROR_METHOD=cli scripts/mirror/run.sh
#
# MIRROR_METHOD=admin (default) restores inside the target's WordPress — the
# plugin's PHP code. MIRROR_METHOD=cli restores from outside with
# `wpicker backup restore` — the CLI's Go code. Both must give the same result.
# The CLI must be paired with the SOURCE site. The TARGET is overwritten.

set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
W="${WPICKER_BIN:-$ROOT/bin/wpicker}"
LOCAL_APP="$HOME/Library/Application Support/Local"
PHP_BIN="${MIRROR_PHP:-$LOCAL_APP/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php}"

SRC_URL="${MIRROR_SRC_URL:-http://wpicker.local}"
SRC_DIR="${MIRROR_SRC_DIR:-$HOME/Local Sites/wpicker/app/public}"
SRC_INI="${MIRROR_SRC_INI:-$LOCAL_APP/run/zBqR06TtO/conf/php/php.ini}"
DST_URL="${MIRROR_DST_URL:-http://wpicker-local.local}"
DST_DIR="${MIRROR_DST_DIR:-$HOME/Local Sites/wpicker-local/app/public}"
DST_INI="${MIRROR_DST_INI:-$LOCAL_APP/run/YJrDSmmBP/conf/php/php.ini}"
DST_USER="${MIRROR_DST_USER:-root}"
DST_PASS="${MIRROR_DST_PASS:-root}"

METHOD="${MIRROR_METHOD:-admin}"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
fail=0
ok()  { echo "  PASS  $1"; }
bad() { echo "  FAIL  $1"; fail=$((fail + 1)); }
check() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }

wp_src() { "$PHP_BIN" -c "$SRC_INI" -d memory_limit=1G "$(command -v wp)" --path="$SRC_DIR" "$@" 2>/dev/null; }
wp_dst() { "$PHP_BIN" -c "$DST_INI" -d memory_limit=1G "$(command -v wp)" --path="$DST_DIR" "$@" 2>/dev/null; }
src_host="${SRC_URL#*://}"

echo "== $SRC_URL  ->  $DST_URL   (method: $METHOD)"

echo "1. back up the source"
"$W" whoami 2>/dev/null | grep -q "$SRC_URL" || { echo "CLI is not paired with $SRC_URL"; exit 2; }
ID="$("$W" --json backup create --note "mirror test" | grep -o '"id": *"[^"]*"' | head -1 | cut -d'"' -f4)"
check "backup created" '[ -n "$ID" ]'
"$W" backup download "$ID" --out "$TMP/site.tar" >/dev/null 2>&1
SIZE="$(wc -c < "$TMP/site.tar" | tr -d ' ')"
check "archive downloaded ($SIZE bytes)" '[ "$SIZE" -gt 1024 ]'
WPK_DUMP_OUT="$TMP/src.json" wp_src eval-file "$ROOT/scripts/mirror/dump.php" | sed 's/^/     source: /'

if [ "$METHOD" = cli ]; then

echo "2-3. restore on the target with the CLI"
OUT="$("$W" backup restore --file "$TMP/site.tar" --path "$DST_DIR" --yes 2>&1)"
check "CLI restore finished" 'echo "$OUT" | grep -q "Restored into"'
echo "$OUT" | grep -E "unpacked|Switched off|Error" | sed 's/^/   /'

else

echo "2. upload to the target (WP-Admin session)"
JAR="$TMP/cookies"
curl -s -c "$JAR" -b "wordpress_test_cookie=WP%20Cookie%20check" -o /dev/null \
	--data-urlencode "log=$DST_USER" --data-urlencode "pwd=$DST_PASS" -d "wp-submit=Log+In&testcookie=1" "$DST_URL/wp-login.php"
NONCE="$(curl -s -b "$JAR" "$DST_URL/wp-admin/admin.php?page=wpicker-backups" | grep -o 'WPickerAdmin = {[^}]*}' | grep -o '"nonce":"[a-f0-9]*"' | cut -d'"' -f4)"
check "signed in to the target's WP-Admin" '[ -n "$NONCE" ]'
API="$DST_URL/wp-json/wpicker/v1"
admin() { curl -s -b "$JAR" -H "X-WP-Nonce: $NONCE" "$@"; }
field() { python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('data',{}).get('$1','') if d.get('ok') else 'ERROR: '+str(d.get('error',d.get('message',d))))"; }

UP="$(admin -H 'Content-Type: application/json' -d "{\"name\":\"mirror.tar\",\"size\":$SIZE}" "$API/backup/upload" | field id)"
split -b 2097152 "$TMP/site.tar" "$TMP/chunk."
offset=0
for piece in "$TMP"/chunk.*; do
	got="$(admin -H 'Content-Type: application/octet-stream' --data-binary @"$piece" "$API/backup/$UP/chunk?offset=$offset" | field received)"
	offset="$got"
done
check "all chunks received" '[ "$offset" = "$SIZE" ]'
check "upload accepted as a backup" '[ "$(admin -X POST "$API/backup/$UP/finish" | field id)" = "$UP" ]'

echo "3. restore on the target (exact copy, with safety backup)"
START="$(admin -H 'Content-Type: application/json' -d "{\"backup_id\":\"$UP\",\"safety\":true,\"exact\":true}" "$API/restore")"
TOKEN="$(echo "$START" | field token)"
check "restore started" '[ ${#TOKEN} -ge 32 ]'
phase=""
for _ in $(seq 1 300); do
	STEP="$(curl -s -X POST -H "X-WPicker-Restore-Token: $TOKEN" "$API/restore/step")"
	phase="$(echo "$STEP" | field phase)"
	case "$phase" in done|failed|ERROR*) break ;; esac
done
check "restore finished (phase: $phase$(echo "$STEP" | field error))" '[ "$phase" = done ]'
echo "$STEP" | python3 -c "import sys,json; d=json.load(sys.stdin)['data']; print('     %d statements, %d rows rewritten, %d files written, %d removed, %d skipped' % (d['statements'], d['rows_rewritten'], d['files_done'], d['files_removed'], d['files_skipped']))"

fi

echo "4. database"
WPK_DUMP_OUT="$TMP/dst.json" wp_dst eval-file "$ROOT/scripts/mirror/dump.php" | sed 's/^/     target: /'
python3 "$ROOT/scripts/mirror/compare.py" "$TMP/src.json" "$TMP/dst.json" | sed 's/^/     /'
check "target database is an exact copy" '[ "${PIPESTATUS[0]}" -eq 0 ]'
# Raw scan, independent of the normalised dump: any value on the target that
# still holds the source's address as a URL.
# Addresses that merely start with the source's name (other people's sites)
# are expected to remain and do not count.
LEFT="$(wp_dst eval '
global $wpdb; $n = 0; $host = "'"$src_host"'";
foreach ( $wpdb->get_col( "SHOW TABLES LIKE \"" . $wpdb->prefix . "%\"" ) as $t ) {
	foreach ( $wpdb->get_results( "SELECT * FROM `$t`", ARRAY_A ) as $row ) {
		if ( "options" === substr( $t, strlen( $wpdb->prefix ) ) && preg_match( "/^(_transient|_site_transient|wpicker_)/", $row["option_name"] ) ) { continue; }
		foreach ( $row as $c => $v ) {
			if ( null !== $v && preg_match( "#(//|\\\\/\\\\/|%2F%2F|=[\"\x27])(www\\.)?" . preg_quote( $host, "#" ) . "(?![A-Za-z0-9_-]|\\.[A-Za-z0-9])#i", $v ) ) { $n++; echo "     left: $t.$c = " . substr( $v, 0, 110 ) . "\n"; }
		}
	}
}
echo "COUNT=$n\n";')"
echo "$LEFT" | grep -v '^COUNT=' | head -8
check "no source address left in the target database" 'echo "$LEFT" | grep -q "^COUNT=0$"'

echo "5. files"
tree() {
	# hash, modification time, path — a faithful copy keeps all three
	(cd "$1/wp-content" && find plugins mu-plugins themes uploads -type f \
		-not -path 'uploads/wpicker-vault/*' -not -path 'plugins/wpicker/*' -not -path '*/.wpicker/*' -not -name '.cursorrules' -not -name '.DS_Store' \
		2>/dev/null | LC_ALL=C sort | while IFS= read -r f; do printf '%s  %s  %s\n' "$(shasum -a 256 "$f" | cut -d' ' -f1)" "$(stat -f %m "$f" 2>/dev/null || stat -c %Y "$f")" "$f"; done)
}
tree "$SRC_DIR" > "$TMP/src.files"
tree "$DST_DIR" > "$TMP/dst.files"
diff "$TMP/src.files" "$TMP/dst.files" | head -6 | sed 's/^/     /'
check "plugins / themes / uploads identical, contents and timestamps ($(wc -l < "$TMP/src.files" | tr -d ' ') files)" 'diff -q "$TMP/src.files" "$TMP/dst.files" >/dev/null'

echo "6. rendered pages"
# What a visitor gets must be the same document, apart from the host name
# (and per-session nonces). Email addresses are not rewritten by design, so
# only the host inside URLs is normalised.
dst_host="${DST_URL#*://}"
pages_differ=0
for route in / "/?s=a" /wp-json/wp/v2/pages /wp-json/wp/v2/posts; do
	curl -s "$SRC_URL$route" | sed -e "s#//${src_host//./\\.}#//HOST#g" -e "s#\\\\/\\\\/${src_host//./\\.}#\\\\/\\\\/HOST#g" -e 's/nonce":"[a-f0-9]*/nonce":"X/g' > "$TMP/a.out"
	curl -s "$DST_URL$route" | sed -e "s#//${dst_host//./\\.}#//HOST#g" -e "s#\\\\/\\\\/${dst_host//./\\.}#\\\\/\\\\/HOST#g" -e 's/nonce":"[a-f0-9]*/nonce":"X/g' > "$TMP/b.out"
	if ! diff -q "$TMP/a.out" "$TMP/b.out" >/dev/null; then
		pages_differ=$((pages_differ + 1))
		echo "     $route differs:"; diff "$TMP/a.out" "$TMP/b.out" | grep '^[<>]' | cut -c1-180 | head -4 | sed 's/^/       /'
	fi
done
check "pages render identically on both sites" '[ "$pages_differ" -eq 0 ]'

echo "7. the target site itself"
check "front page answers 200" '[ "$(curl -s -o "$TMP/home.html" -w "%{http_code}" "$DST_URL/")" = 200 ]'
# -F: a dot in the host name must not match any character (wpicker.local vs wpicker-local).
grep -F "//$src_host" "$TMP/home.html" | cut -c1-160 | head -3 | sed 's/^/     found: /'
check "front page does not link to the source" '! grep -qF "//$src_host" "$TMP/home.html"'
SRC_NAME="$(curl -s "$SRC_URL/wp-json/" | python3 -c "import sys,json; print(json.load(sys.stdin)['name'])")"
DST_NAME="$(curl -s "$DST_URL/wp-json/" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['name']); print(d['url'], file=sys.stderr)" 2>"$TMP/dst.url")"
check "site title matches the source ($DST_NAME)" '[ -n "$SRC_NAME" ] && [ "$SRC_NAME" = "$DST_NAME" ]'
check "site reports its own address ($(cat "$TMP/dst.url"))" '[ "$(cat "$TMP/dst.url")" = "$DST_URL" ]'
check "can sign in with the source's account" '[ "$(curl -s -o /dev/null -w "%{http_code}" -b "wordpress_test_cookie=WP%20Cookie%20check" --data-urlencode "log=$DST_USER" --data-urlencode "pwd=$DST_PASS" -d "wp-submit=Log+In&testcookie=1" "$DST_URL/wp-login.php")" = 302 ]'

"$W" backup delete "$ID" >/dev/null 2>&1
echo
echo "failures: $fail"
[ "$fail" -eq 0 ]
