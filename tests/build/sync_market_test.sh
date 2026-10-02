#!/usr/bin/env bash
#
# Regressionstest fuer build/sync-market.sh: build/market.ref und das
# <ref>-Argument duerfen den CI-Waechter (--check) weder mit einem Fork noch
# mit einem nie gemergten Stand erfuellen und ueber git fetch keine Optionen
# einschleusen.
#
#   bash tests/build/sync_market_test.sh
#
# Laeuft ohne Netz: GIT_ALLOW_PROTOCOL=file laesst nur Abrufe aus lokalen
# Pfaden zu, und das Market-Repository auf GitHub wird per insteadOf auf ein
# lokales "kanonisches" Repository umgelenkt. Jeder Angriff muss mit exit 2 UND
# der Meldung der Pruefung enden - ein gescheiterter Abruf zaehlt nicht als
# abgewiesen. Aufgebaut werden ein Mini-Kern-Repository (build/ und
# apps-external/market), das kanonische Repository (main, ein Tag ausserhalb
# von main) und ein Fork mit veraenderter Market-App, dessen Stand wie bei
# einem Pull Request unter refs/pull/1/head im kanonischen Repository liegt.
# SYNC_MARKET_SCRIPT=<pfad> prueft ein anderes Skript (Gegenprobe).

set -uo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
script="${SYNC_MARKET_SCRIPT:-$root/build/sync-market.sh}"
canon=https://github.com/BWTECH-github/market.git

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

# Eigene Git-Konfiguration, gilt auch fuer das gepruefte Skript.
export GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL="$tmp/gitconfig" GIT_ALLOW_PROTOCOL=file
git config --global user.name test
git config --global user.email test@example.com
git config --global commit.gpgsign false
git config --global tag.gpgsign false
git config --global init.defaultBranch main
git config --global url."$tmp/canon".insteadOf "$canon"

# Kanonisches Repository: main mit zwei Staenden, dazu ein Tag auf einem
# Release-Branch, der danach geloescht wurde. Alle drei haben denselben Inhalt.
mkdir -p "$tmp/canon/appinfo"
printf '<?php\n// market\n' > "$tmp/canon/appinfo/app.php"
(cd "$tmp/canon" && git init -q && git add -A && git commit -qm "market 1" &&
  git checkout -qb release && git commit -q --allow-empty -m "release 1.0" &&
  git tag -a -m 1.0 v1.0 && git checkout -q main && git branch -qD release &&
  git commit -q --allow-empty -m "market 2" &&
  git config uploadpack.allowFilter true)
main_sha="$(git -C "$tmp/canon" rev-parse HEAD~1)" # auf main, nicht die Spitze
tag_sha="$(git -C "$tmp/canon" rev-parse 'v1.0^{commit}')"

# Fork mit eingeschleuster Aenderung; der Pull Request legt ihn im kanonischen
# Repository unter refs/pull/1/head ab, gemergt wird er nie.
git clone -q "$tmp/canon" "$tmp/fork"
printf '<?php\n// injected\n' > "$tmp/fork/appinfo/app.php"
(cd "$tmp/fork" && git commit -qam fork && git push -q origin HEAD:refs/pull/1/head)
fork_sha="$(git -C "$tmp/fork" rev-parse HEAD)"

# Selbstkontrolle des Aufbaus: https gesperrt, GitHub-URL lokal umgelenkt.
if git ls-remote https://github.com/evil/market.git >/dev/null 2>&1 ||
   [ "$(git ls-remote "$canon" refs/pull/1/head | cut -f1)" != "$fork_sha" ]; then
  echo "FEHLER Aufbau: Netz nicht gesperrt oder $canon nicht auf $tmp/canon umgelenkt"
  exit 1
fi

# Kern: einmal mit der Kopie des Forks, einmal mit der Kopie von main.
mkdir -p "$tmp/core/build" "$tmp/core/apps-external/market/appinfo"
cp "$script" "$tmp/core/build/sync-market.sh"
cp "$tmp/fork/appinfo/app.php" "$tmp/core/apps-external/market/appinfo/app.php"
(cd "$tmp/core" && git init -q && git add -A && git commit -qm "Kopie wie Fork")
basis_fork="$(git -C "$tmp/core" rev-parse HEAD)"
cp "$tmp/canon/appinfo/app.php" "$tmp/core/apps-external/market/appinfo/app.php"
(cd "$tmp/core" && git commit -qam "Kopie wie main")
basis_main="$(git -C "$tmp/core" rev-parse HEAD)"
inject="--upload-pack=touch $tmp/pwned; git-upload-pack"

fehler=0
basis="$basis_fork"
fall() { # <name> <erwarteter exit> <erwartete Meldung> <repo=> <commit=> <befehl...>
  local name=$1 want=$2 msg=$3 repo=$4 commit=$5
  shift 5
  (cd "$tmp/core" && git reset -q --hard "$basis" && git clean -qfdx)
  rm -f "$tmp/pwned"
  printf '# test\nrepo=%s\ncommit=%s\n' "$repo" "$commit" > "$tmp/core/build/market.ref"
  (cd "$tmp/core" && git add -A && git commit -qm ref)
  local out rc
  out="$(cd "$tmp/core" && "$@" 2>&1)"
  rc=$?
  local probleme=""
  [ "$rc" = "$want" ] || probleme="$probleme exit=$rc statt $want;"
  if [ -n "$msg" ] && ! grep -qF -e "$msg" <<<"$out"; then
    probleme="$probleme Meldung '$msg' fehlt;"
  fi
  [ -e "$tmp/pwned" ] && probleme="$probleme eingeschleuster Befehl ausgefuehrt;"
  if [ "$want" != 0 ] && [ -n "$(git -C "$tmp/core" status --porcelain)" ]; then
    probleme="$probleme Arbeitsbaum veraendert;"
  fi
  if [ -z "$probleme" ]; then
    echo "ok     $name"
  else
    echo "FEHLER $name:$probleme"
    echo "$out" | sed -n '1,3s/^/         /p'
    fehler=1
  fi
}

sm=(bash build/sync-market.sh)
nur_github="erlaubt ist nur $canon"
volle_id="erwartet wird eine volle Commit-ID"

echo "-- Angriffe (Kopie = Fork)"
fall "Fork als repo=, --check"                 2 "$nur_github" "$tmp/fork" "$fork_sha" "${sm[@]}" --check
fall "fremde URL als repo=, --check"           2 "$nur_github" https://github.com/evil/market.git "$fork_sha" "${sm[@]}" --check
fall "Fork als repo=, erneut uebernehmen"      2 "$nur_github" "$tmp/fork" "$fork_sha" "${sm[@]}"
fall "MARKET_REPO bei --check"                 2 "MARKET_REPO gilt nicht" "$canon" "$fork_sha" env MARKET_REPO="$tmp/fork" "${sm[@]}" --check
fall "commit=--upload-pack mit Fork, --check"  2 "$nur_github" "$tmp/fork" "$inject" "${sm[@]}" --check
fall "commit=--upload-pack, uebernehmen"       2 "$volle_id" "$canon" "$inject" env MARKET_REPO="$tmp/fork" "${sm[@]}"
fall "<ref>=--upload-pack mit MARKET_REPO"     2 "erlaubt sind Commit-ID, Tag oder Branch" "$canon" "$fork_sha" env MARKET_REPO="$tmp/fork" "${sm[@]}" "$inject"
fall "nie gemergter Stand (refs/pull/1/head)"  2 "weder auf main noch unter einem Tag" "$canon" "$fork_sha" "${sm[@]}" --check
basis="$basis_main"
fall "gekuerzte Commit-ID, --check"            2 "$volle_id" "$canon" "${main_sha:0:12}" "${sm[@]}" --check

echo "-- Grundfunktion"
fall "Stand auf main, --check"                 0 "entspricht $canon @ $main_sha" "$canon" "$main_sha" "${sm[@]}" --check
fall "Tag ausserhalb von main, --check"        0 "entspricht $canon @ $tag_sha" "$canon" "$tag_sha" "${sm[@]}" --check
basis="$basis_fork"
fall "Kopie weicht von main ab, --check"       1 "weicht vom festgehaltenen Stand ab" "$canon" "$main_sha" "${sm[@]}" --check
# Erlaubt bleibt: einen Stand aus einer anderen Quelle uebernehmen.
fall "MARKET_REPO beim Uebernehmen"            0 "" "$canon" "$fork_sha" env MARKET_REPO="$tmp/fork" "${sm[@]}" "$fork_sha"
if ! grep -qx "repo=$canon" "$tmp/core/build/market.ref" || ! grep -qx "commit=$fork_sha" "$tmp/core/build/market.ref"; then
  echo "FEHLER build/market.ref haelt nach dem Uebernehmen nicht $canon @ $fork_sha fest"
  fehler=1
fi

exit $fehler
