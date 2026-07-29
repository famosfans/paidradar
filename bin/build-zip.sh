#!/usr/bin/env bash
# Baut das WordPress.org-Einreichungs-ZIP aus dem Repo.
#
# Warum ein Script: das erste ZIP wurde handgebaut und trug eine fremde Plugin-URI
# nach draussen, weil niemand den fertigen Payload noch einmal gegengelesen hat.
# Hier werden Ausschluesse aus .distignore gelesen und der Payload danach geprueft.
#
# Nutzung:  bin/build-zip.sh [ziel-verzeichnis]
#           Default-Ziel: ../paidradar-dist
set -euo pipefail

SLUG="paidradar"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="${1:-$(dirname "$REPO")/${SLUG}-dist}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

# .distignore -> rsync-Filter (Kommentare und Leerzeilen raus)
FILTER="$STAGE/filter.txt"
grep -vE '^\s*(#|$)' "$REPO/.distignore" > "$FILTER"

mkdir -p "$STAGE/$SLUG"
rsync -a --exclude-from="$FILTER" "$REPO"/ "$STAGE/$SLUG"/

# --- Pre-Flight auf dem fertigen Payload, nicht auf dem Repo ---
fail=0

# 1) Tote oder fremde Domains im Payload. famosmedia.com ist nicht registriert,
#    paidradar.com gehoert einem Dritten. Beides darf nicht ausgeliefert werden.
if grep -rn --exclude-dir=.git -E 'https?://(paidradar\.com|famosmedia\.com)' "$STAGE/$SLUG" 2>/dev/null; then
  echo "FEHLER: fremde/tote Domain im Payload (siehe oben)." >&2
  fail=1
fi

# 2) Stable tag in readme.txt muss der Version im Plugin-Header entsprechen.
hdr_ver="$(grep -m1 -E '^\s*\*\s*Version:' "$STAGE/$SLUG/$SLUG.php" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"
rdm_ver="$(grep -m1 -E '^Stable tag:' "$STAGE/$SLUG/readme.txt" | sed -E 's/^Stable tag:[[:space:]]*//' | tr -d '[:space:]')"
if [[ "$hdr_ver" != "$rdm_ver" ]]; then
  echo "FEHLER: Version-Mismatch — Header '$hdr_ver' vs. readme Stable tag '$rdm_ver'." >&2
  fail=1
fi

# 3) PHP-Syntax, falls php verfuegbar ist (auf dem Mac Mini meist nicht).
if command -v php >/dev/null 2>&1; then
  while IFS= read -r -d '' f; do php -l "$f" >/dev/null || fail=1; done \
    < <(find "$STAGE/$SLUG" -name '*.php' -print0)
else
  echo "HINWEIS: kein php im PATH — Syntaxpruefung uebersprungen." >&2
fi

[[ $fail -eq 0 ]] || { echo "Build abgebrochen." >&2; exit 1; }

mkdir -p "$DEST"
rm -rf "${DEST:?}/$SLUG" "${DEST:?}/$SLUG.zip"
cp -R "$STAGE/$SLUG" "$DEST/$SLUG"
( cd "$STAGE" && zip -qr "$DEST/$SLUG.zip" "$SLUG" -x '*.DS_Store' )

nfiles="$(find "$DEST/$SLUG" -type f | wc -l | tr -d ' ')"
echo "OK: $DEST/$SLUG.zip"
echo "    $nfiles Files, $(stat -f%z "$DEST/$SLUG.zip" 2>/dev/null || stat -c%s "$DEST/$SLUG.zip") Bytes, Version $hdr_ver"
