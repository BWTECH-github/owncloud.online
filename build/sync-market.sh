#!/usr/bin/env bash
#
# Die Market-App unter apps-external/market aus ihrem eigenen Repository
# übernehmen.
#
#   build/sync-market.sh            festgehaltenen Stand erneut übernehmen
#   build/sync-market.sh <ref>      neuen Stand übernehmen (Commit, Tag oder
#                                   Branch) und in build/market.ref festhalten
#   build/sync-market.sh --check    nur prüfen, ob der committete Stand von
#                                   apps-external/market dem festgehaltenen
#                                   Stand entspricht (Exit 1 bei Abweichung)
#
# MARKET_REPO=<URL oder Pfad> holt den Stand aus einer anderen Quelle, etwa
# einem lokalen Klon. Festgehalten wird trotzdem immer das Repository auf
# GitHub: gegen das prüft die CI, der Commit muss dort also ankommen. Für
# --check gilt MARKET_REPO nicht - die Prüfung läuft immer gegen GitHub.
#
# build/market.ref ist Eingabe eines CI-Wächters und wird deshalb nicht blind
# übernommen: repo= muss das Repository auf GitHub sein, commit= eine volle
# Commit-ID (40 Hex-Zeichen), ein <ref>-Argument ein schlichter Name ohne
# führendes '-'. Sonst ließe sich der Wächter mit einem Fork erfüllen, oder
# ein commit=--upload-pack=... würde von git fetch als Option ausgeführt.
# --check verlangt außerdem, dass der Commit von main oder einem Tag des
# Repositorys aus erreichbar ist, also gemergt oder freigegeben wurde.
#
# Warum es das gibt: Die Market-App lag zweimal vor, als eigenes Repository
# (BWTECH-github/market) und als Kopie hier, die ins Release-Paket geht. Die
# beiden sind auseinandergelaufen - jede Korrektur musste von Hand an beiden
# Stellen landen, die gebündelte Kopie trug noch "ownCloud" in der
# Beschreibung, und eine Oberflächenkorrektur des Repositorys fehlte hier im
# Quelltext. Maßgeblich ist jetzt allein das Repository. Diese Kopie entsteht
# daraus und wird nicht mehr direkt bearbeitet; die CI prüft das bei jedem
# Push (Job market-copy in .github/workflows/lint-and-codestyle.yml).
#
# Übernommen werden alle versionierten Dateien des Repositorys außer denen,
# die nur dort gebraucht werden: .github/ (dessen eigene CI), .gitignore und
# tests/ (landen ohnehin nicht im Release-Paket).

set -euo pipefail

cd "$(dirname "$0")/.."

ref_file=build/market.ref
target=apps-external/market
default_repo=https://github.com/BWTECH-github/market.git

gespeichert() {
  sed -n "s/^$1=//p" "$ref_file" 2>/dev/null | head -n1
}

pruefen=nein
ref=""
case "${1:-}" in
  --check) pruefen=ja ;;
  -h|--help) sed -n '2,/^$/p' "$0"; exit 0 ;;
  "") ;;
  *) ref="$1" ;;
esac

festhalten="$default_repo"
if [ "$pruefen" = ja ] && [ -n "${MARKET_REPO:-}" ]; then
  echo "MARKET_REPO gilt nicht für --check: geprüft wird immer gegen $default_repo." >&2
  exit 2
fi

if [ -n "$ref" ]; then
  # Commit, Tag oder Branch - nichts, was git als Option oder Refspec lesen
  # könnte.
  if ! [[ "$ref" =~ ^[A-Za-z0-9._][A-Za-z0-9._/-]*$ ]]; then
    echo "Ungültiger Stand '$ref': erlaubt sind Commit-ID, Tag oder Branch (A-Z a-z 0-9 . _ / -, nicht mit '-' am Anfang)." >&2
    exit 2
  fi
else
  gespeichertes_repo="$(gespeichert repo)"
  ref="$(gespeichert commit)"
  if [ -z "$gespeichertes_repo" ] || [ -z "$ref" ]; then
    echo "$ref_file fehlt oder ist unvollständig - ersten Stand mit '$0 <ref>' übernehmen." >&2
    exit 2
  fi
  if [ "$gespeichertes_repo" != "$default_repo" ]; then
    echo "$ref_file nennt repo=$gespeichertes_repo - erlaubt ist nur $default_repo. Nicht von Hand ändern, sondern '$0 <commit>' ausführen." >&2
    exit 2
  fi
  if ! [[ "$ref" =~ ^[0-9a-f]{40}$ ]]; then
    echo "$ref_file nennt commit=$ref - erwartet wird eine volle Commit-ID (40 Hex-Zeichen). Nicht von Hand ändern, sondern '$0 <commit>' ausführen." >&2
    exit 2
  fi
fi
if [ "$pruefen" = ja ]; then
  repo="$default_repo"
else
  repo="${MARKET_REPO:-$default_repo}"
fi

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

git init -q "$tmp/quelle"
# Einzelner Commit ohne Verlauf; GitHub liefert auch Commits aus, auf die kein
# Branch zeigt, solange sie erreichbar sind (ob gemergt, prüft --check unten).
# '--' vor den Positionsargumenten: git wertet Optionen sonst auch dahinter aus.
if ! git -C "$tmp/quelle" fetch -q --depth 1 -- "$repo" "$ref"; then
  echo "Stand '$ref' aus $repo nicht abrufbar." >&2
  exit 2
fi
commit="$(git -C "$tmp/quelle" rev-parse FETCH_HEAD)"

if [ "$pruefen" = ja ]; then
  # Abrufbar heißt nicht gemergt: GitHub liefert jeden Commit aus, der über
  # irgendeine Ref erreichbar ist, auch über refs/pull/<n>/head, das jeder
  # GitHub-Nutzer mit einem Pull Request aus seinem Fork anlegen kann. Zählen
  # soll nur, was auf main liegt oder mit einem Tag freigegeben wurde. Dafür
  # reicht der Verlauf ohne Dateien (--filter=tree:0, einige hundert KiB).
  # rev-list zählt nur auf, was von main und den Tags aus erreichbar ist, und
  # fasst den Commit selbst nicht an; merge-base oder cat-file würden ihn in
  # diesem Partial Clone erst nachladen, notfalls über die Pull-Request-Ref.
  git init -q "$tmp/herkunft"
  if ! git -C "$tmp/herkunft" fetch -q --no-tags --filter=tree:0 -- "$repo" \
      '+refs/heads/main:refs/remotes/kanonisch/main' '+refs/tags/*:refs/tags/*'; then
    echo "main und Tags aus $repo nicht abrufbar." >&2
    exit 2
  fi
  if ! git -C "$tmp/herkunft" rev-list refs/remotes/kanonisch/main --tags > "$tmp/gemergt.txt" ||
     ! grep -qxF "$commit" "$tmp/gemergt.txt"; then
    echo "$ref_file nennt commit=$commit - der liegt in $repo weder auf main noch unter einem Tag (etwa nur in einem Pull Request oder auf einem anderen Branch). Erst im Market-Repository mergen, dann '$0 <commit>' ausführen." >&2
    exit 2
  fi
fi

mkdir -p "$tmp/neu"
git -C "$tmp/quelle" archive "$commit" | tar -x -C "$tmp/neu"
rm -rf "$tmp/neu/.github" "$tmp/neu/.gitignore" "$tmp/neu/tests"

if [ "$pruefen" = ja ]; then
  # Verglichen wird der COMMITTETE Stand, nicht der Arbeitsbaum: node_modules
  # oder ein lokaler Bau unter apps-external/market sollen die Prüfung weder
  # stören noch von ihr gelöscht werden.
  mkdir -p "$tmp/ist"
  git archive HEAD "$target" | tar -x -C "$tmp/ist"
  if diff -r "$tmp/neu" "$tmp/ist/$target" > "$tmp/diff.txt"; then
    echo "$target entspricht $repo @ $commit."
    exit 0
  fi
  echo "::error::$target weicht vom festgehaltenen Stand ab ($repo @ $commit)."
  echo "Die Kopie wird nicht direkt bearbeitet: Änderung im Market-Repository"
  echo "committen, dann 'build/sync-market.sh <commit>' ausführen und beides"
  echo "hier committen."
  sed -n '1,60p' "$tmp/diff.txt"
  exit 1
fi

# node_modules eines lokalen Baus bleibt stehen, alles andere entspricht danach
# genau der Quelle.
rsync -a --delete --exclude /node_modules "$tmp/neu/" "$target/"

cat > "$ref_file" <<EOF
# Quelle der Market-App unter $target. Von build/sync-market.sh
# geschrieben - nicht von Hand ändern, sondern das Skript mit dem neuen Stand
# aufrufen.
repo=$festhalten
commit=$commit
EOF

echo "$target übernommen aus $repo @ $commit."
git status --short -- "$target" "$ref_file" | sed -n '1,40p'
