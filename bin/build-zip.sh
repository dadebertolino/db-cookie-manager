#!/usr/bin/env bash
#
# Costruisce lo ZIP di release di DB Cookie Manager.
#
# Il DB_GitHub_Updater installa il primo asset .zip della release estraendolo
# in wp-content/plugins/<slug>/. Perché l'aggiornamento vada a buon fine lo ZIP
# DEVE contenere i file dentro una cartella di primo livello 'db-cookie-manager/'.
#
# Esclude tutto ciò che è di sviluppo. Usa solo tar/zip: nessuna dipendenza
# da rsync, quindi gira sia in CI sia in locale.
#
set -euo pipefail

SLUG="db-cookie-manager"
DIST="dist"
STAGE="${DIST}/${SLUG}"

rm -rf "${DIST}"
mkdir -p "${STAGE}"

tar \
	--exclude='./.git' \
	--exclude='./.github' \
	--exclude='./dist' \
	--exclude='./node_modules' \
	--exclude='./vendor' \
	--exclude='./tests' \
	--exclude='./bin' \
	--exclude='./.wp-env.json' \
	--exclude='./package.json' \
	--exclude='./package-lock.json' \
	--exclude='./playwright.config.*' \
	--exclude='./composer.json' \
	--exclude='./composer.lock' \
	--exclude='./phpunit.xml.dist' \
	--exclude='./phpcs.xml.dist' \
	--exclude='./.gitignore' \
	--exclude='./.gitattributes' \
	--exclude='./TESTING.md' \
	--exclude='*.dist' \
	--exclude='./playwright-report' \
	--exclude='./test-results' \
	-cf - . | ( cd "${STAGE}" && tar -xf - )

if [[ ! -f "${STAGE}/${SLUG}.php" ]]; then
	echo "ERRORE: ${SLUG}.php non trovato nello stage. Build interrotta." >&2
	exit 1
fi

( cd "${DIST}" && zip -r -q "${SLUG}.zip" "${SLUG}" )

echo "ZIP creato: ${DIST}/${SLUG}.zip"
unzip -l "${DIST}/${SLUG}.zip" | head -30
