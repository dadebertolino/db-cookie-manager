# Test & CI — DB Cookie Manager

Piramide dei test su GitHub Actions (`.github/workflows/ci.yml`).

## I quattro job

| Job | Cosa verifica | Quando | Durata |
|-----|---------------|--------|--------|
| **lint** | `php -l` su tutti i file + PHPCS (standard WordPress) | ogni push/PR | ~30 s |
| **unit** | Logica pura di `DBCM_Signatures` (PHPUnit), matrice PHP 7.4–8.3 | ogni push/PR | ~1 min |
| **e2e** | Scenari §9 nel browser (wp-env + Playwright) | dopo lint+unit | ~5-8 min |
| **build** | ZIP di release compatibile con `DB_GitHub_Updater` | solo su tag `v*` | ~30 s |

Il job **e2e** dipende da lint+unit: se la sintassi è rotta non si avvia Docker
inutilmente. Il job **build** gira solo sui tag e allega lo ZIP alla release.

## Perché questa struttura

I test §9 (carrello WooCommerce, assenza di richieste terze parti, placeholder
da tastiera) richiedono WordPress + browser reali: solo l'E2E può verificarli.
Ma gran parte della logica (merge firme, classificazione cookie, degradazione
regex) è PHP puro: gli **unit** la coprono in un minuto, senza Docker. Così
l'E2E resta focalizzato su ciò che solo lui può testare e non si rompe per
errori che i job leggeri avrebbero già intercettato.

## Fixture E2E builtin

Nessuna dipendenza da siti esterni. Il mu-plugin `tests/fixtures/dbcm-e2e-fixture.php`
(montato solo in wp-env) crea una pagina `/dbcm-test/` con embed YouTube, iframe
Maps e link WhatsApp, e inietta uno snippet GA4 **fittizio** (measurement ID
finto, nessun dato reale). È lo scenario deterministico su cui girano le
asserzioni. Non fa parte del pacchetto distribuito.

## Test "da implementare" (TDD)

`tests/e2e/pending-features.spec.js` contiene test marcati `test.skip` per
feature non ancora sviluppate (placeholder click-to-load §3/§9.4, navigazione
da tastiera §9.8, cancellazione reattiva). Sono la specifica scritta come test
eseguibile: quando si implementa la feature, si rimuove lo `.skip`
corrispondente. La CI resta verde e il debito è tracciato invece di essere un
test rosso ignorato.

Coperti invece adesso: blocco GA4 e assenza richieste terze parti (§9.1),
neutralizzazione script, link WhatsApp libero (§9.3), carrello WooCommerce che
sopravvive al rifiuto (§9.1, §9.2).

Non coperti in CI per natura: localizzazione Google Fonts (§9.5, arriverà con
la feature) e checkout PayPal reale (§9.6, richiede sandbox con credenziali).

## Eseguire in locale

```bash
# Prerequisiti: Docker attivo, Node 20, PHP 8.x, Composer.

# --- lint + unit ---
composer install
composer run lint          # php -l ricorsivo
composer run phpcs         # standard WordPress
composer run test:unit     # PHPUnit

# --- e2e ---
npm ci
npx playwright install --with-deps chromium
npm run env:start          # avvia wp-env (Docker)
npm run env:setup          # WooCommerce + pagine fixture
npm run test:e2e
npm run env:stop
```

## Release

Taggare un commit con `vX.Y.Z` fa girare l'intera pipeline e, se verde,
costruisce `dist/db-cookie-manager.zip` e lo allega alla GitHub Release. Lo ZIP
contiene il plugin dentro la cartella `db-cookie-manager/`, come richiesto
dall'updater per l'aggiornamento in-place.
