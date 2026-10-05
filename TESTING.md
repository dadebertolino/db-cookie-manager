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
(montato solo in wp-env, non fa parte del pacchetto distribuito) fornisce:

| Risorsa | A cosa serve |
|---------|--------------|
| `/?dbcm_e2e=1` | Pagina **grezza** con embed YouTube, iframe Maps, link WhatsApp, GA4 **fittizio** e Google Fonts. Niente `wp_head`/`wp_footer`: `banner.js` riceve una config minima (banner chiuso). Isola la logica client: blocco, riattivazione, click-to-load, cancellazione reattiva. |
| `/?dbcm_e2e=wp` | Stesso contenuto, ma passa da `wp_head()`/`wp_footer()`: config reale del banner, CSS inline, snippet GCM/UET/Clarity, gate Meta Pixel, GA4 accodato con `wp_enqueue_script` (copre `script_loader_tag`), shortcode `[dbcm_preferences id="fixture-prefs"]`. |
| `POST /?rest_route=/dbcm-e2e/v1/reset` | Riporta il plugin allo stato **baseline**: impostazioni di default + `localize_google_fonts`, firma custom `_mypix` (marketing, cancellazione reattiva), registro consensi e scanner vuoti, servizi dichiarati e rate limit azzerati. Accetta `settings`, `signatures`, `rate_limit`, `seed_log`. |

Lo stato baseline si imposta **solo** con il reset (da `bin/setup-e2e.sh` e dai
`beforeEach` degli spec, tramite `resetState()` in `tests/e2e/helpers.js`): le
opzioni salvate dai test admin non vengono più sovrascritte a ogni richiesta.
Il rate limit dell'endpoint di consenso è disattivato in baseline, perché tutti
i test arrivano dallo stesso IP; chi lo testa passa `rate_limit`.

## Progetti Playwright

- **setup** (`tests/e2e/auth.setup.js`): reset baseline + login admin
  (`admin`/`password` di wp-env, sovrascrivibili con `WP_ADMIN_USER` /
  `WP_ADMIN_PASS`). Salva la sessione in `tests/e2e/.auth/admin.json`
  (ignorata da git).
- **chromium**: tutti gli spec tranne `mobile`, dopo `setup`. Gli spec admin
  usano `test.use( { storageState: ADMIN_STATE } )`.
- **mobile**: solo `mobile.spec.js`, su un telefono Android emulato (Pixel 7,
  motore Chromium, touch): banner, modal, placeholder e pulsante 🍪 dentro lo
  schermo, bersagli al tocco, nessuno scroll orizzontale, verticale e
  orizzontale.

`tests/e2e/infra.spec.js` verifica l'infrastruttura stessa (reset, pagina `wp`,
sessione admin): se fallisce, i risultati degli altri spec non sono attendibili.

## Cosa copre la suite E2E

| Area | Spec |
|------|------|
| Blocco preventivo, Google Fonts, placeholder accessibili, cancellazione reattiva (§3, §4, §9.1, §9.3, §9.4, §9.8) | `blocking` |
| Banner reale: scelte, cookie, sync, riapertura, lingue | `banner` |
| Riattivazione di script ed embed al commit e al caricamento, revoca | `reactivation` |
| HTML identico per gli anonimi con o senza consenso (cache di pagina) | `cache-safe` |
| Endpoint `dbcm_set_consent`: origine, rate limit, nonce, payload | `consent-ajax` |
| Registro consensi dal banner all'admin, export | `consent-log` |
| GPC, DNT, geo-targeting | `browser-signals` |
| Google Consent Mode v2, Microsoft UET e Clarity | `consent-modes` |
| Consent Mode base e avanzato con GTM, evento `dbcm_consent_update`, revoca dal footer | `consent-mode-advanced` |
| Meta Pixel nativo | `meta-pixel` |
| Versione del consenso | `consent-version` |
| Admin: salvataggi, clamp, coerenze, sicurezza del dispatcher | `admin-settings` |
| Admin: filtri e paginazione del registro | `admin-log` |
| Admin: firme personalizzate, import/export, blocco sul sito | `admin-signatures` |
| Admin: servizi dichiarati e Cookie Policy | `admin-declared-policy` |
| Admin: scanner | `admin-scanner` |
| Integrazione con il plugin WP Consent API (attivato solo qui) | `consent-api` |
| Accessibilità: axe-core WCAG 2.1 AA, tastiera, focus del modal | `a11y` |
| Telefono: layout, tocco, bersagli, scroll orizzontale | `mobile` |
| Carrello WooCommerce che sopravvive al rifiuto (§9.1, §9.2) | `woocommerce` |

Lo scanner in wp-env non raggiunge il sito dal server (`localhost:8888` non è
visibile dal container): la scansione si completa con i soli cookie WordPress e
del plugin, sufficienti per il flusso dell'interfaccia. Il rilevamento dei cookie
reali è coperto dagli integration test.

Non coperti in CI per natura: checkout PayPal reale (§9.6, richiede sandbox con
credenziali), disinstallazione (in wp-env il plugin è montato dalla working copy).

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
