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
# GitHub: gegen das prüft die CI, der Commit muss dort also ankommen.
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

if [ -n "$ref" ]; then
  repo="${MARKET_REPO:-$default_repo}"
  festhalten="$default_repo"
else
  festhalten="$(gespeichert repo)"
  repo="${MARKET_REPO:-$festhalten}"
  ref="$(gespeichert commit)"
  if [ -z "$festhalten" ] || [ -z "$ref" ]; then
    echo "$ref_file fehlt oder ist unvollständig - ersten Stand mit '$0 <ref>' übernehmen." >&2
    exit 2
  fi
fi

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

git init -q "$tmp/quelle"
# Einzelner Commit ohne Verlauf; GitHub liefert auch Commits aus, auf die kein
# Branch zeigt, solange sie erreichbar sind.
if ! git -C "$tmp/quelle" fetch -q --depth 1 "$repo" "$ref"; then
  echo "Stand '$ref' aus $repo nicht abrufbar." >&2
  exit 2
fi
commit="$(git -C "$tmp/quelle" rev-parse FETCH_HEAD)"

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
    echo "$target entspricht BWTECH-github/market @ $commit."
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
