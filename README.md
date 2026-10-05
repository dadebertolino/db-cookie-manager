# DB Cookie Manager

> 🇮🇹 **Italiano** | 🇬🇧 [English](#-english)

---

## 🇮🇹 Italiano

**Plugin WordPress per la gestione GDPR-compliant dei cookie**, con banner multilingua, scanner automatico, registro consensi, generatore di Cookie Policy e integrazione nativa con la WP Consent API.

Sviluppato da **Davide Bertolino** per uso personale e professionale, rilasciato come open-source (GPL v2+).

> **Integrazione con DB SEO Manager 1.2.0+**: se entrambi i plugin sono installati, la dashboard "Stato GDPR" del SEO Manager riconosce il Cookie Manager (bonus +5 al punteggio), il registro privacy mostra automaticamente i tre trattamenti del Cookie Manager con il badge "Plugin esterno", e l'audit homepage arricchisce ogni host noto con i cookie rilevati dallo scanner. Nessuna configurazione richiesta: l'integrazione è automatica e bidirezionale.

---

### Caratteristiche principali

- **Banner cookie multilingua** (Italiano, English, Français, Deutsch, Español, Português) con auto-detect dalla lingua del browser
- **5 categorie standard WP Consent API**: `functional`, `preferences`, `statistics`, `statistics-anonymous`, `marketing`
- **Blocco preventivo** degli script di tracking (Google Analytics, Meta Pixel, GTM, TikTok, ecc.) e degli iframe (YouTube, Vimeo, Maps, Spotify, ecc.)
- **Scanner automatico** che visita le pagine principali, parsa i `Set-Cookie` e rileva firme di servizi terzi — 64 cookie noti pre-classificati
- **Cookie Policy generator** conforme alle Linee Guida del Garante 10 giugno 2021 (rif. art. 122 D.Lgs. 196/2003 + GDPR)
- **Registro consensi GDPR-friendly** con IP hashato (irreversibile) e user-agent aggregato — export CSV e JSON con filtri
- **API JavaScript pubblica** `window.DBCM` per integrazioni custom
- **Integrazione WP Consent API**: `wp_has_consent('statistics')` risponde correttamente in base al consenso del visitatore
- **Segnali browser opzionali**: rispetta Do Not Track (DNT) e Global Privacy Control (GPC)
- **Geo-targeting opzionale**: mostra il banner solo a visitatori UE/EEA/UK — ⚠️ incompatibile con la cache di pagina completa, salvo cache che varia per paese (vedi FAQ)
- **Google Consent Mode v2** *(opt-in)*: comunica il consenso ai tag Google con default negato nel `<head>` e update al consenso; mapping personalizzabile via `dbcm_gcm_mapping`
- **Localizzazione Google Fonts** *(opt-in)*: rimuove i riferimenti remoti a Google Fonts così l'IP dell'utente non viene trasmesso a Google
- **Cancellazione reattiva dei cookie**: rimuove dal browser i cookie delle categorie non concesse, rendendo effettiva la revoca del consenso
- **Placeholder click-to-load** accessibile per gli embed bloccati (consenso granulare per singolo contenuto)
- **Firme personalizzate**: aggiunta manuale di servizi/cookie con import/export JSON
- **Report differenziale scanner**: evidenzia i cookie nuovi o rimossi tra due scansioni
- **Shortcode `[dbcm_preferences]`** per il pulsante "Modifica preferenze"
- **Auto-aggiornamento da GitHub** via [DB GitHub Updater](https://github.com/dadebertolino/db-github-updater)
- **Design system condiviso** con gli altri plugin DB (`db-admin-ui.css`)
- **Disinstallazione pulita** via `uninstall.php`

---

### Requisiti

| Componente          | Versione minima                                                              |
| ------------------- | ---------------------------------------------------------------------------- |
| WordPress           | 6.0+                                                                         |
| PHP                 | 7.4+                                                                         |
| WP Consent API      | *(opzionale)* — per integrazione automatica con altri plugin compatibili     |

---

### Installazione

1. Scarica l'ultima release ZIP da [GitHub Releases](https://github.com/dadebertolino/db-cookie-manager/releases)
2. WordPress admin → **Plugin → Aggiungi nuovo → Carica plugin** → seleziona lo ZIP → **Installa ora** → **Attiva**
3. Vai a **Cookie Manager** nel menu sidebar
4. Configura in ordine: **Banner & aspetto** → **Scanner** → **Cookie Policy** → **Avanzate**

Gli aggiornamenti successivi arrivano automaticamente via GitHub Updater.

---

### Configurazione consigliata

**Per la maggior parte dei siti italiani:**

1. Banner: layout *"Riquadro flottante"*, posizione *"In basso a destra"*, tema *"Auto"*
2. Default categorie: **tutte disattivate** (richiesto dal GDPR)
3. Durata consenso: **180 giorni** (raccomandazione del Garante)
4. Scanner: lancia almeno una scansione, poi rivedi i cookie marketing non noti
5. Cookie Policy: crea la pagina automaticamente, poi compila `[NOME COMPLETO / RAGIONE SOCIALE]` e `[INDIRIZZO]`
6. Avanzate: DNT/GPC **off** per consensi espliciti, **on** per massima privacy by default

---

### API JavaScript

```js
// Verifica consenso
if (window.DBCM.hasConsent('statistics')) { loadGoogleAnalytics(); }

// Reagisci ai cambi
window.DBCM.onConsent(function(consent, type) {
    console.log('Type:', type);       // 'accept_all' | 'reject_all' | 'custom'
    console.log('Marketing:', consent.marketing);
});

// Apri il modal preferenze
window.DBCM.openPreferences();

// Imposta programmaticamente
window.DBCM.setConsent('marketing', true);

// Mappa completa (null se nessun consenso ancora dato)
var consent = window.DBCM.getConsent();

// Lista canonica delle 5 categorie
console.log(window.DBCM.categories);
```

Evento DOM alternativo:

```js
document.addEventListener('dbcm:consent', function(ev) {
    var consent = ev.detail.consent;
    var type    = ev.detail.type;
});

// Dalla 3.7.1: window.DBCM è pronto (utile se banner.js viene ritardato
// da un ottimizzatore e il tuo script gira prima).
document.addEventListener('dbcm:ready', function() {
    if (window.DBCM.hasConsent('marketing')) { /* ... */ }
});
```

---

### Hooks e filtri PHP

#### Banner

| Hook                          | Tipo    | Note                                                      |
| ----------------------------- | ------- | --------------------------------------------------------- |
| `dbcm_should_render_banner`   | filter  | Default `true` — sopprimi il banner su pagine specifiche  |
| `dbcm_banner_translations`    | filter  | Aggiungi/sovrascrivi traduzioni                           |
| `dbcm_visitor_country_code`   | filter  | Fornisci il codice paese ISO-3166 alpha-2                 |
| `dbcm_eu_country_codes`       | filter  | Personalizza la lista paesi UE/EEA per il geo-targeting   |
| `dbcm_banner_inline_css`      | filter  | _(3.8.0)_ CSS inline generato da colori, tema auto e CSS personalizzato |

#### Consent

| Hook                        | Tipo    | Note                                                         |
| --------------------------- | ------- | ------------------------------------------------------------ |
| `dbcm_consent_set`          | action  | Fired ad ogni cambio consenso — args: `$type, $consent`      |
| `dbcm_consent_propagated`   | action  | Fired dopo la propagazione a `wp_set_consent()`              |
| `dbcm_consent_type`         | filter  | Default `'optin'` — sovrascrivi il consent type WP API       |
| `dbcm_consent_rate_limit`   | filter  | Default `60` (dalla 3.8.0; prima `20`) — richieste di consenso per IP ogni 10 minuti (visitatori anonimi); `0` disattiva. L'IP segue `dbcm_trust_proxy_headers` |

#### Blocker

| Hook                              | Tipo   | Note                                         |
| --------------------------------- | ------ | -------------------------------------------- |
| `dbcm_blocker_patterns`           | filter | Aggiungi/rimuovi pattern di blocco           |
| `dbcm_blocker_placeholder_text`   | filter | Testo del placeholder iframe (multilingua)   |
| `dbcm_blocker_placeholder_btn_label` | filter | Label del pulsante del placeholder        |
| `dbcm_blocker_server_side_decision` | filter | _(3.8.0)_ Default `true` solo per utenti loggati. Per gli anonimi l'HTML è sempre bloccato e la riattivazione è lato client (cache-safe). Forzarlo a `true` per tutti **solo** su siti senza cache di pagina |

#### Scanner

| Hook                            | Tipo   | Note                                             |
| ------------------------------- | ------ | ------------------------------------------------ |
| `dbcm_known_cookies`            | filter | Aggiungi cookie custom al database statico       |
| `dbcm_scan_urls`                | filter | Personalizza le URL scansionate                  |
| `dbcm_scanner_html_detections`  | filter | Aggiungi firme HTML di servizi terzi             |

#### Cookie Policy

| Hook                      | Tipo   | Note                                                                                            |
| ------------------------- | ------ | ----------------------------------------------------------------------------------------------- |
| `dbcm_policy_sections`    | filter | Riordina/rimuovi sezioni                                                                        |
| `dbcm_policy_html`        | filter | Modifica l'HTML finale                                                                          |
| `dbcm_policy_section_*`   | filter | Modifica singole sezioni (`header`, `definitions`, `cookies`, `external`, `browser`, ecc.)     |

#### Consent Log

| Hook                      | Tipo   | Note                                                               |
| ------------------------- | ------ | ------------------------------------------------------------------ |
| `dbcm_trust_proxy_headers`| filter | Default `false`. Se `true` (sito dietro Cloudflare/reverse proxy fidato) l'IP del client è letto da `CF-Connecting-IP`, poi primo IP di `X-Forwarded-For`, poi `X-Real-IP` (validati), **prima** di `REMOTE_ADDR`. Vale per registro consensi e rate limit (3.8.0) |

---

### API server-side per altri plugin

#### `DBCM_Consent_API::has_consent($category)` _(dalla 3.0.0)_

Helper unificato per leggere il consenso. Strategia a 3 livelli: `wp_has_consent()` se WP Consent API installata, altrimenti cookie diretto, altrimenti `false`.

#### `DBCM_Scanner::get_cookies_by_provider_keyword($keyword, $limit = 50)` _(dalla 3.0.2)_

Ricerca i cookie scansionati il cui campo `provider` contiene la keyword (LIKE substring, case-insensitive). Usato da DB SEO Manager 1.2.0+.

```php
if (class_exists('DBCM_Scanner')) {
    $cookies = DBCM_Scanner::get_cookies_by_provider_keyword('Google Analytics');
    foreach ($cookies as $c) {
        echo $c->cookie_name . ' (' . $c->category . ')';
    }
}
```

---

### Esempi pratici

**Caricare Google Analytics solo dopo consenso `statistics`:**

```php
add_action('wp_footer', function() {
    if (function_exists('wp_has_consent') && wp_has_consent('statistics')) { ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=G-XXXXX"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', 'G-XXXXX');
        </script>
    <?php }
});
```

**Sopprimere il banner su una landing page:**

```php
add_filter('dbcm_should_render_banner', function($render) {
    if (is_page('landing-newsletter')) return false;
    return $render;
});
```

**Aggiungere un cookie custom allo scanner:**

```php
add_filter('dbcm_known_cookies', function($cookies) {
    $cookies['my_app_session'] = [
        'category'    => 'functional',
        'description' => 'Sessione applicativa interna.',
        'duration'    => 'Sessione',
        'provider'    => 'My App',
    ];
    return $cookies;
});
```

**Link "Modifica preferenze" nel footer:**

```php
echo do_shortcode('[dbcm_preferences label="Cookie" style="link"]');
```

---

### FAQ

**Il plugin è davvero conforme al GDPR?**
Fornisce gli strumenti tecnici per la conformità. La conformità completa dipende dalla configurazione e dalle scelte editoriali. Una revisione da DPO o avvocato resta necessaria per rassicurazioni legali.

**Posso usarlo con altri consent manager?**
No — solo uno alla volta. Disattiva l'altro prima.

**Funziona in multisite?**
Sì. Ogni sito ha le proprie option e il proprio log. La disinstallazione è multisite-aware.

**Funziona con la cache di pagina (WP Rocket, LiteSpeed Cache, Cloudflare APO)?**
Sì, dalla 3.8.0 l'HTML servito ai visitatori anonimi è identico per tutti: script e iframe traccianti sono sempre neutralizzati e `banner.js` li riattiva nel browser se il cookie di consenso lo consente; anche la decisione di mostrare il banner è presa lato client. Unica eccezione: il **geo-targeting**, deciso sul server dal paese del visitatore — con una cache di pagina completa la prima richiesta decide per tutti. Attivalo solo se la cache varia per paese (es. Cloudflare con `CF-IPCountry` nella chiave di cache) o se il sito non usa cache.

**Il blocco preventivo rompe il mio sito?**
Solo se il tema dipende esattamente da uno script di tracking bloccato (raro). Se hai problemi, disabilita "Blocco preventivo" nella pagina Scanner.

**Come gestisce IPv6?**
Hashing SHA256 dell'IP completo (v4 o v6) + salt site-specifico. Irreversibile in pratica.

**Posso esportare il log per richieste GDPR?**
Sì — **Registro consensi → Scarica CSV** o **Scarica JSON**, con filtri per tipo e data.

---

### Integrazione ecosistema DB privacy

Quando uno o più plugin dell'ecosistema DB privacy (DB Privacy Hub, DB SEO Manager, DB Form Builder) sono installati, DB Cookie Manager li sfrutta automaticamente — senza configurazione. Ogni integrazione è opzionale: senza Hub il plugin funziona in modo identico.

#### Consent gate per gli script di terzi
DB Cookie Manager **è** il gate del consenso dell'ecosistema: è il consent manager della WP Consent API (`wp_get_consent_type` → `optin`), propaga le scelte con `wp_set_consent()` lato server e — dalla 3.8.0 — anche lato client (`window.wp_set_consent`, che emette `wp_listen_for_consent_change` per gli script degli altri plugin senza ricaricare la pagina). Gli script e gli iframe riconosciuti sono neutralizzati nell'HTML e riattivati nel browser solo per le categorie concesse. Il Meta Pixel nativo (opt-in) è gated by-design sulla categoria Marketing.

#### Dichiarazione trattamenti al registro privacy unificato
Quando **DB Privacy Hub 1.0.0+** è installato, DB Cookie Manager dichiara fino a 4 trattamenti nel pannello "Privacy → Registro trattamenti" (filter `dbph_processing_register`; per compatibilità anche il legacy `dbseo_processing_register` del SEO Manager 1.2.x).

| ID | Quando appare |
|---|---|
| `dbcm_consent_collection` | sempre (raccolta del consenso tramite banner) |
| `dbcm_consent_log` | registro consensi attivo (default) |
| `dbcm_cookie_scanner` | sempre (metadati dei cookie del sito, nessun dato degli utenti) |
| `dbcm_meta_pixel` | Meta Pixel nativo attivo con Pixel ID valido |

Inoltre:
- **Registro consensi** (`dbph_consents_register`, Hub 1.3.0+): i consensi cookie compaiono in "Privacy → Registro consensi" con data/ora locale del sito, filtri per data e limite righe rispettati (fino a 50.000 per l'export).
- **Destinatari** (`dbph_policy_destinatari`, 3.8.0): con Meta Pixel attivo, *Meta Platforms Ireland Ltd (Meta Pixel)* è aggiunto ai destinatari della Privacy Policy generata dall'Hub (contitolare per la raccolta via pixel, autonomo titolare per i trattamenti successivi; SCC + DPF per i trasferimenti verso Meta Platforms Inc., USA).
- **Servizi dichiarati** (`dbcm_declared_services_register`): gli embed e i tracker gated rilevati dal blocker sono disponibili all'Hub.
- **Sezioni Cookie Policy** (`DBCM_Policy_Generator::get_sections()`): l'Hub importa le sezioni nella Privacy Policy; dalla 3.8.0 i titoli non hanno numerazione fissa, così si annidano senza doppie numerazioni.
- **Versione della Privacy Policy**: ogni consenso registra l'ID dello snapshot in vigore (`DBPH_Policy_Archive::get_current_version_id()`, 0 se Hub assente).

#### DSAR routing via Privacy Hub
Non supportato, per scelta: il registro consensi conserva solo l'**hash salato dell'IP** (mai l'IP in chiaro, nessuna email, nessun account). Una richiesta di accesso o cancellazione (artt. 15/17) identificata da un'email non può essere ricondotta a nessuna riga, quindi il plugin non registra exporter/eraser presso l'Hub né presso gli strumenti privacy di WordPress.

#### Marker `DBCM_DSAR_AVAILABLE`
**Non definito.** Il blocco "Privacy capabilities" in testa a `db-cookie-manager.php` documenta la scelta (DSAR-aware: NO, Hub-aware: YES): la Privacy Policy dell'Hub non menziona una procedura DSAR semplificata per i dati del Cookie Manager.

---

### Privacy

Il plugin **non comunica con server esterni**. Niente telemetria, niente phone-home. L'unica connessione esterna è GitHub Updater per gli aggiornamenti del plugin.

Cookie scritti dal plugin:

| Cookie         | Categoria   | Durata                   | Scopo                          |
| -------------- | ----------- | ------------------------ | ------------------------------ |
| `dbcm_consent` | functional  | 365 giorni (configurabile) | Memorizza la scelta dell'utente |

---

### Changelog

#### 3.8.1 — Correzioni emerse dalla nuova suite E2E _(2026)_

Release di sole correzioni. Nessuna migrazione, nessun cambio nelle API pubbliche, nessuna nuova richiesta di consenso ai visitatori.

**Banner:**
- Lo schema del cookie `dbcm_consent` è confrontato come numero: `wp_localize_script` lo passa come stringa (`"3"`), quindi un cookie scritto con `v:3` numerico veniva scartato. I cookie esistenti restano validi.

**Registro consensi:**
- I link "Scarica CSV/JSON" puntavano alla pagina inesistente `dbcm-consent-log`; con nonce scaduto l'export finiva su una pagina di errore generica. Ora puntano a `dbcm-log` e un nonce non valido mostra un messaggio chiaro.
- "Consensi totali" mostra l'intero registro, non il conteggio filtrato; un filtro con tipo sconosciuto non trova righe (prima mostrava i "personalizzati"); descrizione dell'envelope JSON allineata alle chiavi reali.

**Scanner:**
- Una sola richiesta HTTP per URL invece di due identiche: scansione in circa metà del tempo.
- La durata di `dbcm_consent` nei risultati segue l'impostazione "Durata del consenso" (prima fissa a 365 giorni).
- Cambio categoria ed eliminazione di un cookie inesistente restituiscono un errore (prima "successo"); testi di avanzamento ed errori traducibili; al termine compare l'avviso "Scansione completata.".

**Admin:**
- Eliminare una firma o un servizio dichiarato inesistente mostra un errore specifico (prima "eliminata" o "nome obbligatorio").
- La pagina Servizi dichiarati senza permessi mostra l'errore standard invece di una pagina vuota.
- La lingua predefinita del banner è sempre una delle lingue attive.
- Id del nonce non più duplicato nella pagina Banner; descrizioni delle card Firme e Servizi dichiarati nella Dashboard; rimossi i testi di sviluppo "step 7".

#### 3.8.0 — Blocco cache-safe, IP dietro proxy, WP Consent API lato client e allineamento con l'Hub _(2026)_

Release di correzioni da audit. Il punto critico: con una cache di pagina completa il blocco preventivo poteva servire **tracker attivi a visitatori che non avevano dato il consenso** (Art. 6.1.a, Art. 122 Codice Privacy). Nessuna migrazione di schema, nessun breaking change nelle API pubbliche.

**Blocco preventivo indipendente dalla cache (critico):**
- Il blocker decideva lato server leggendo il cookie del visitatore: la pagina generata per chi aveva accettato finiva in cache e veniva servita a tutti con script e iframe attivi. Ora per i visitatori **anonimi** ogni script/iframe riconosciuto è **sempre** neutralizzato e `banner.js` lo riattiva nel browser (boot/commit) solo se il cookie di quel browser concede la categoria. Decisione server-side mantenuta solo per gli utenti loggati (pagine non cachate); nuovo filtro `dbcm_blocker_server_side_decision`.
- Riattivazione client-side resa fedele: gli iframe ripristinati recuperano gli attributi originali (`title`, `allow`, `class`, ...; esclusi `src`/`srcdoc`/`on*`), gli script esterni mantengono l'ordine di esecuzione (`async=false` se non dichiarato) e un `type` non classico (es. `module`) è conservato in `data-dbcm-type`.
- `autoOpen` del banner non dipende più dal cookie del visitatore (lo verifica `banner.js`): HTML identico per tutti. Il **geo-targeting** resta server-side ed è ora dichiarato in admin e README **incompatibile con la cache di pagina completa** salvo cache che varia per paese.
- Hydrate WP Consent API: `wp_set_consent()` solo per le categorie il cui cookie `wp_consent_*` è diverso dal valore atteso (prima 5 `Set-Cookie` a ogni pagina vista); `$_COOKIE` allineato per l'effetto nella stessa richiesta.

**Consenso e WP Consent API:**
- `has_consent()`: se il cookie `dbcm_consent` manca, è malformato o ha una versione del consenso superata, restituisce `false` **prima** di consultare `wp_has_consent()` — i cookie `wp_consent_*` stantii non tengono più vivo un consenso pre-bump (Art. 4.11, 7). `functional` resta sempre concessa.
- `banner.js` chiama `window.wp_set_consent()` per ogni categoria (`allow`/`deny`) se la WP Consent API è presente: gli altri plugin ricevono `wp_listen_for_consent_change` senza ricaricare la pagina.
- DNT/GPC: rimosso il doppio pulsante "Riapri preferenze" (lo disegna già `commit()` → `close()`); errori di rete della sincronizzazione ora segnalati con `console.warn`.

**Registro consensi (Art. 7.1) e rate limit:**
- `dbcm_trust_proxy_headers` non aveva effetto (gli header venivano letti solo con `REMOTE_ADDR` vuoto). Ora, se `true`, l'IP è letto **prima** da `CF-Connecting-IP`, poi primo IP di `X-Forwarded-For`, poi `X-Real-IP` (validati con `FILTER_VALIDATE_IP`); la stessa funzione alimenta il rate limit, che prima usava sempre l'IP del proxy (tutti i visitatori nello stesso contatore → 429 → consensi persi).
- Rate limit anonimi portato da 20 a **60 richieste / 10 minuti** per IP (NAT scolastici e aziendali), sempre filtrabile con `dbcm_consent_rate_limit`.
- Retention del registro vincolata a **≥ durata del cookie di consenso** (0 = illimitata resta ammesso): un valore inferiore viene alzato al salvataggio con avviso — la prova del consenso deve sopravvivere al consenso stesso.
- Cron `dbcm_daily_cleanup` ora garantito su `init` (prima solo all'attivazione: mancava dopo aggiornamenti via file, attivazioni di rete, reset di WP-Cron → retention Art. 5.1.e non applicata).
- Multisite: schema del registro verificato anche su `init` (confronto di option, nessuna query se aggiornato) e tabelle/cron creati su `wp_initialize_site` per i nuovi siti con attivazione di rete.
- Date: `consent_date` (TIMESTAMP, UTC interno, restituito nel fuso della sessione MySQL) era confrontata con stringhe PHP (`gmdate`) → scarti di ore a seconda dell'hosting. Confronti ora via epoch (`FROM_UNIXTIME`/`UNIX_TIMESTAMP`) nella pulizia e nei filtri data (giorni interpretati nel fuso di WordPress); output in ora locale WP in admin, export e Registro consensi dell'Hub. Nessuna migrazione dello schema (scelta conservativa su dati di accountability).
- Export CSV protetto da CSV/formula injection (celle che iniziano con `=`, `+`, `-`, `@`, tab, CR prefissate da `'`); export JSON con campo `timezone`.
- Registro consensi dell'Hub: rispettato `$args['limit']` passato dall'Hub (prima era letto solo `_internal_limit` → sempre 1000 righe), clamp 1–50.000; le chiavi meta (`v`, `cv`) non compaiono più tra le "categorie accettate".

**Integrazione DB Privacy Hub:**
- Nuovo destinatario via `dbph_policy_destinatari` quando il Meta Pixel è attivo: *Meta Platforms Ireland Ltd (Meta Pixel)*, contitolare/autonomo titolare, Irlanda con possibili trasferimenti verso Meta Platforms Inc. (USA) su SCC + DPF (Art. 13.1.e-f, 26, 44 ss.).
- Blocco "Privacy capabilities" nel file principale (DSAR-aware: NO — solo hash dell'IP, non collegabile a un'email; Hub-aware: YES). `DBCM_DSAR_AVAILABLE` volutamente non definita. Nuova sezione README "Integrazione ecosistema DB privacy".
- Cookie Policy: titoli di sezione senza numerazione fissa (si annidano nella Privacy Policy dell'Hub e non saltano più un numero se "Servizi esterni" è assente), durata del cookie `dbcm_consent` letta da `consent_duration` (prima fissa a 365), data del footer = ultima scansione (fallback oggi), email del titolare non più doppiamente escapata.

**Aspetto del banner:**
- Colori (`banner_color_*`), tema **auto** (`prefers-color-scheme: dark`) e **CSS personalizzato** erano salvati ma mai applicati: ora emessi con `wp_add_inline_style()` sulle variabili `--dbcm-*` (filtro `dbcm_banner_inline_css`). Sfondo/testo personalizzati valgono per il tema chiaro e per "auto" in modalità chiara.
- Credit "Powered by DB Cookie Manager" ora effettivamente mostrato quando attivo; default portato a **disattivo** per non comparire all'improvviso sui siti che non l'hanno mai scelto.

**Pulizie:**
- Rimosso dal blocker il pattern di test `sa.davidebertolino.it`.
- `uninstall.php` cancella solo il transient dell'updater di questo plugin (`dbgu_` + md5 del basename) invece di tutti i `dbgu_*` (azzerava la cache aggiornamenti degli altri plugin DB). Nuova opzione **"Conserva i dati alla disinstallazione"** (Avanzate, default off): se attiva, registro consensi, cookie scansionati e impostazioni sopravvivono alla disinstallazione (come `dbph_preserve_data_on_uninstall` del Privacy Hub).
- Test: +8 unit (172 totali).

#### 3.7.1 — Consensi registrati anche con cache di pagina _(2026)_

- **Fix registro consensi con cache di pagina**: con WP Rocket, LiteSpeed Cache e simili il nonce stampato nell'HTML scadeva dopo 12–24h e da quel momento ogni scelta dei visitatori veniva rifiutata in silenzio dall'endpoint `dbcm_set_consent` — il banner funzionava, ma il consenso non finiva nel registro (Art. 7.1) né nella WP Consent API. Per i visitatori anonimi il nonce (identico per tutti) è sostituito da controllo di origine (`Origin`/`Referer` dello stesso sito) e rate limit per IP (hash salato, filtro `dbcm_consent_rate_limit`); per gli utenti loggati resta il nonce. `banner.js` ora segnala in console una sincronizzazione fallita invece di ignorarla.
- **Meta Pixel con JS ritardato**: se un ottimizzatore ritarda `banner.js` fino alla prima interazione, il gate non trovava `window.DBCM` a `DOMContentLoaded` e il pixel non partiva per chi aveva già acconsentito. Nuovo evento `dbcm:ready`, emesso quando l'API pubblica è disponibile: il gate ripete lì il check.
- Test: +7 unit (164 totali).

#### 3.7.0 — Modulo Meta Pixel nativo _(2026)_

Il plugin che possiede il consenso possiede anche il pixel: nuovo modulo opt-in (*Avanzate → Meta Pixel*) che emette lo snippet Meta come gate JS, quindi il caricamento di `fbevents.js` è vincolato **per costruzione** alla categoria Marketing (Art. 6.1.a) — nessuna race tra CMP e tag di terzi.

- **Fire senza reload**: check iniziale su `window.DBCM.hasConsent('marketing')` (version-aware: un consenso con versione obsoleta non concede) + listener su `dbcm:consent`. Alla revoca invia `fbq('consent','revoke')` a runtime (Art. 7.3); la rimozione di `_fbp` è coperta dalla cancellazione reattiva esistente.
- **Anti auto-blocco**: il gate contiene le stringhe che il blocker matcha sul contenuto inline (`connect.facebook.net`, `fbevents.js`) — nuovo skip esplicito `data-dbcm-own` nel blocker, con test di contratto: il gate passa, un Meta Pixel di terzi (es. Meta for WooCommerce) resta bloccato.
- **Trasparenza automatica**: quando attivo, lo slug `meta-pixel` è registrato nei servizi dichiarati (idratato dalla firma: fonte unica di verità) e il trattamento in contitolarità con Meta (Art. 26, DPF) è dichiarato a DB Privacy Hub via `dbph_processing_register`. Alla firma `meta-pixel` si aggiunge il cookie `_fbc` (click ID).
- **Handoff CAPI** (opt-in): PageView inviato con `eventID` condiviso esposto in `window.DBCM_META_LAST_EVENT_ID`, per la deduplicazione con la futura Conversions API server-side (DB Meta Events).
- Pixel ID validato server-side (solo 15–16 cifre, ri-sanitizzato anche in lettura). Test: +24 unit (modulo 20, contratto blocker 4), validati con mutation testing.

#### 3.6.1 — Firma Facebook per contenuti incorporati _(2026)_

Chiude un gap di blocco rilevato tramite la nuova pagina Servizi dichiarati: gli embed Facebook via iframe (`facebook.com/plugins/…` — post, video, like/share button) non erano coperti da alcuna firma né dai pattern del blocker, quindi **caricavano prima del consenso** (era gestito solo il Meta Pixel via `connect.facebook.net`). Nuova firma `facebook` con cookie tipici (`fr`, `datr`, `sb`) e informativa Meta: in cascata sistemano blocco preventivo dell'iframe, placeholder click-to-load, registro dei servizi dichiarati, pick-list e rilevamento scanner. Il caricamento via SDK JS resta attribuito a `meta-pixel` (nessuna doppia attribuzione). Test: +3 unit di regressione (132 totali).

#### 3.6.0 — Registro dei servizi dichiarati _(2026)_

Risolve un problema strutturale emerso in audit su caso reale: la Cookie Policy generata elencava solo i cookie tecnici, perché lo scanner acquisisce le pagine via self-request e l'HTML che analizza è già passato dal blocco — gli embed gated (YouTube, Maps, ...) sono già placeholder e le firme non matchano più. Più il blocco funziona, più la policy è incompleta: non giustifica il consenso richiesto dal banner (incoerenza documentale, Art. 5(1)(a)).

- **Registrazione automatica al momento del blocco**: quando il blocker matcha e riscrive un iframe/script, risolve la src in una firma (`DBCM_Signatures::identify_url()`, nuovo) e registra `{slug, last_seen}`. Evidenza empirica dell'uso reale — niente sovra-dichiarazione dei 42 servizi bundled su siti che non li usano. La self-request dello scanner passa dal blocco, quindi **lanciare una scansione popola il registro**: circolarità risolta senza alcun bypass (un bypass sarebbe spoofabile = elusione del consenso). Scritture throttled: una per servizio al giorno.
- **Staleness**: le voci auto entrano in policy solo se viste negli ultimi 30 giorni (filtro `dbcm_declared_services_ttl`) — embed rimosso, voce che decade da sola. I dettagli (fornitore, cookie tipici, durate, informativa) sono idratati dal DB firme alla generazione: fonte unica di verità.
- **Nuova sezione in policy** "Contenuti incorporati e servizi attivi previo consenso", per categoria, con dicitura "bloccati per impostazione predefinita" e rinvio esplicito alle informative delle terze parti (ammesso dalle Linee Guida Garante 10/06/2021 per cookie non sotto il controllo del titolare).
- **Dichiarazione manuale** (*Cookie Manager → Servizi dichiarati*): pick-list dalle firme note (dati precompilati) o form libero per i casi residuali; le voci manuali non scadono e, a parità di servizio, prevalgono su quelle automatiche.
- **Validazione di coerenza banner ↔ policy**: categorie richieste nel banner senza cookie rilevati né servizi dichiarati generano un avviso in dashboard — copre sia il servizio non documentato sia la richiesta di consenso ingiustificata.
- **Integrazione DB Privacy Hub**: registro esposto via filtro `dbcm_declared_services_register` (consumo da DBPH ≥ 1.4.0, fallback trasparente se assente).
- Test: +22 unit (registro 17, `identify_url` 3, policy 2+1), tutti validati con mutation testing.

#### 3.5.1 — Split di class-admin.php _(2026)_

Release di manutenzione, zero cambi di comportamento. Il monolite `class-admin.php` (2.871 righe) è stato spezzato in un guscio comune (menu, dispatcher di salvataggio, render helper, flash notices — 745 righe) più una classe per pagina: Dashboard, Banner, Scanner, Firme, Cookie Policy, Registro consensi, Avanzate (`inc/class-admin-page-*.php`, ognuna ≤ 510 righe). Refactor meccanico validato con la suite completa prima/dopo (107 unit + 16 integration invariati) e smoke di riflessione sulle classi estratte. Pattern PHPCS dei falsi positivi documentati estesi ai nuovi file (stesso codice, stessa verifica manuale).

#### 3.5.0 — Versioning del consenso _(2026)_

Il consenso GDPR è **specifico e informato rispetto ai trattamenti presentati al momento della scelta** (Art. 4(11) + 6(1)(a)): se dopo la raccolta del consenso aggiungi un nuovo tracker o cambi in modo significativo i trattamenti, i consensi già salvati (validi fino a 365 giorni) non coprono le novità. Da questa versione ogni consenso è legato a una **versione della configurazione dei trattamenti**.

- **Contatore manuale** (default: 1) con bottone in admin (*Banner → Contenuto → Versione del consenso*) "Richiedi nuovo consenso a tutti gli utenti". La valutazione di "mutamento significativo delle condizioni del trattamento" (Linee guida Garante 10/6/2021 §5) è un giudizio che spetta al titolare: nessun re-prompt automatico, per evitare l'assuefazione al consenso (consent fatigue) che le stesse linee guida indicano come criticità.
- **Mismatch = assenza di consenso (rigoroso)**: dopo un incremento, i cookie `dbcm_consent` con versione precedente vengono trattati come nessuna scelta — lato client (banner ri-mostrato) **e** lato server (`has_consent()` nega; all'hydrate viene propagato un deny esplicito alla WP Consent API, i cui cookie sopravvivrebbero altrimenti al bump). Nessuna pre-selezione delle vecchie scelte nel nuovo banner.
- **Valore probatorio (Art. 7(1))**: il consent log registra la versione in una nuova colonna `consent_version` (schema 3, migrazione additiva non distruttiva), valorizzata lato server dal setting — non falsificabile dal client. Esposta nella tabella admin (colonna *Ver.*), negli export CSV/JSON e nell'integrazione Privacy Hub. Complementare a `policy_version`: quella è la versione del *documento* informativa, questa della *configurazione* dei trattamenti.
- **Retrocompatibilità**: i cookie pre-3.5.0 (senza campo `cv`) valgono come versione 1 — l'aggiornamento del plugin da solo **non** ri-presenta il banner a nessuno; solo l'incremento esplicito dell'admin lo fa.
- Rimossa l'opzione `reconsent_on_change` (mai collegata a un comportamento reale): il versioning la sostituisce con un meccanismo effettivo.
- Test: +12 unit (validazione `cv` in `read_cookie`/`has_consent`/`hydrate`, clamp del setting) e +3 integration (schema 3, valore loggato), tutti validati con mutation testing.

#### 3.4.3 — Segnali di consenso Microsoft (UET + Clarity) _(2026)_

Due nuovi segnali di consenso, entrambi **opt-in** e disattivati di default, sul modello di Google Consent Mode v2. Microsoft richiede un segnale di consenso esplicito per i visitatori da SEE, Regno Unito e Svizzera (per Clarity dal 31/10/2025).

**Microsoft UET Consent Mode:**
- Nuova opzione (Avanzate → *Segnali di consenso Microsoft*) per i tag UET (Microsoft Advertising / Bing Ads).
- Inietta nel `<head>`, prima del tag UET, `window.uetq.push('consent','default',{ad_storage:'denied'})` — privacy by default, GDPR Art. 25.
- Al consenso (categoria Marketing), `banner.js` invia l'update `granted`; alla revoca torna `denied` (Art. 7(3): revocare è facile quanto consentire). Mapping personalizzabile via filtro **`dbcm_uet_mapping`**.

**Microsoft Clarity ConsentV2:**
- Segnale `consentv2` con `ad_Storage` e `analytics_Storage` negati di default; l'update segue le categorie Marketing e Statistiche.
- Scelta conservativa: la categoria *Statistiche anonime* **non** è mappata — le registrazioni di sessione di Clarity non sono assimilabili a statistica anonima. Estendibile via filtro **`dbcm_clarity_mapping`**.
- Alla revoca Clarity riceve il denied, elimina i propri cookie e prosegue in modalità senza consenso — coerente con la cancellazione reattiva del plugin.

**Ambito dichiarato (scope non-TCF):**
- DBCM **non implementa** il framework IAB TCF v2.2: servire annunci personalizzati in SEE/UK con AdSense/Ad Manager/AdMob richiede una CMP certificata da Google e registrata IAB, con ricertificazione annuale — insostenibile per un plugin indipendente. Chi usa AdSense può affiancare la CMP di Google; DBCM copre tutti gli altri scenari di consenso (analytics, marketing, embed, segnali Google e Microsoft).

#### 3.4.2 — Link alle informative dei fornitori _(2026)_
- Nella tabella cookie della Cookie Policy, il nome del fornitore ora linka la sua informativa privacy quando il fornitore è noto al database firme (trasparenza GDPR Art. 13(1)(e)-(f): informazioni sui destinatari dei dati; completa la colonna "Trasferimento" per le garanzie del Capo V).
- Il matching allinea le due nomenclature interne ("Google Ireland Ltd." nelle firme, "Google Analytics" nello scanner-da-header) con regole conservative: nessun link viene inventato per fornitori ignoti o self-hosted.
- Le firme personalizzate supportano il nuovo campo **URL informativa privacy** (form admin, import/export JSON); gli URL non `http(s)` vengono scartati in salvataggio.

#### 3.4.1 — Aggiornamento Cookie policy  _(2026)_
- Nuova colonna "Trasferimento" che indica i cookie con trasferimento dati extra-UE (USA), con nota sulle garanzie del Capo V GDPR (Clausole Contrattuali Standard / Data Privacy Framework). Rilevamento automatico dei provider USA noti.

#### 3.4.0 — Consent Mode v2, Google Fonts locali e report scanner _(2026)_

Tre nuove funzionalità orientate alla conformità, tutte **opt-in** e disattivate di default.

**Google Consent Mode v2:**
- Nuova opzione (Avanzate → *Google Consent Mode v2*) che comunica lo stato del consenso ai tag Google (GA4, Google Ads).
- Inietta nel `<head>`, il prima possibile, il comando `gtag('consent','default',…)` con **tutti i segnali negati** (`analytics_storage`, `ad_storage`, `ad_user_data`, `ad_personalization`) — privacy by default, GDPR Art. 25.
- Al consenso, `banner.js` invia `gtag('consent','update',…)` con i soli segnali concessi. Mapping categoria→segnale personalizzabile via filtro **`dbcm_gcm_mapping`**.
- Consigliata la disattivazione se il Consent Mode è già gestito via Google Tag Manager, per evitare doppie inizializzazioni.

**Localizzazione Google Fonts:**
- Nuova opzione (Avanzate → *Google Fonts*) che rimuove dall'HTML i riferimenti remoti a `fonts.googleapis.com` / `fonts.gstatic.com` (stylesheet, preconnect, dns-prefetch e `@import` correlati).
- Il browser non contatta più i server Google al caricamento della pagina: l'indirizzo IP dell'utente non viene trasmesso (la trasmissione avverrebbe prima di ogni consenso). Rilevante dopo la sentenza del Tribunale di Monaco (gennaio 2022).
- Il sito ripiega sui font di sistema del fallback CSS. L'opzione **rimuove** i font, non fa self-hosting (download + riscrittura locale).

**Report differenziale scanner:**
- La pagina Scanner mostra ora una card *"Modifiche dall'ultima scansione"* con i cookie **nuovi** e **rimossi** rispetto alla scansione precedente.
- Utile per l'accountability (GDPR Art. 5.2): evidenzia se un aggiornamento del sito ha introdotto tracker inattesi. Un cookie che cambia categoria è segnalato come rimosso dalla vecchia e aggiunto alla nuova.
- Snapshot leggero salvato in una singola option prima del `TRUNCATE` — nessuna nuova tabella.

**Correzioni:**
- Lo scanner allinea la classificazione al database firme condiviso: i cookie tecnici noti (es. `store_notice*` di WooCommerce) non vengono più erroneamente classificati come *marketing*. Il fallback consulta `DBCM_Signatures` prima di ripiegare su *marketing*.

#### 3.3.0 — Segnali di consenso avanzati _(2026)_

- **Cancellazione reattiva dei cookie**: alla revoca del consenso (o al load senza consenso), i cookie delle categorie non concesse vengono rimossi dal browser. Rende la revoca effettiva e non solo formale (GDPR Art. 7.3, 17, 5.1.e). Supporta i wildcard (es. `_ga_*`).
- **UI aggiunta manuale firme**: nuova pagina *Firme personalizzate* per aggiungere servizi e cookie non coperti dal database interno, con pattern di blocco (substring/regex), pulizia reattiva e **import/export JSON**. Ogni scrittura è sanificata e le regex malformate degradano automaticamente a substring.
- **Placeholder click-to-load**: gli embed bloccati (YouTube, Maps, ecc.) mostrano un placeholder accessibile (`role="region"`, navigabile da tastiera) con un pulsante *"Carica …"* che attiva il singolo contenuto senza scrivere un consenso persistente per l'intera categoria — consenso granulare e puntuale.

#### 3.3.1 — Fix _(2026)_
- Correzione classificazione `store_notice*` (poi consolidata in 3.4.0).

#### 3.2.0 — Linking versione Privacy Policy + filter Hub consensi _(2026)_

Allineamento con il **Privacy Hub 1.3.0** che introduce il Registro consensi unificato.

**Linking automatico alla versione Privacy Policy:**
- Ogni riga in `wp_dbcm_consent_log` ora include una colonna `policy_version BIGINT` che memorizza l'ID dello snapshot Privacy Policy in vigore al momento del consenso (letto via `DBPH_Policy_Archive::get_current_version_id()`).
- Permette di dimostrare in audit "l'utente ha accettato leggendo *questa* versione del documento" (audit trail completo per art. 7.1 GDPR).
- Senza il Privacy Hub: `policy_version=0`, comportamento identico alla 3.1.0 (nessuna regressione).

**Filter `dbph_consents_register`:**
- Cookie Manager dichiara la propria fonte di consensi al Privacy Hub via questo filter pubblico. La pagina `Privacy → Registro consensi` dell'Hub include automaticamente i consensi cookie nella vista unificata.
- Callback fornite: `count`, `query`, `export`. L'Hub gestisce filtri (data range, identificativo, fonte) e produzione CSV.

**Schema DB:**
- Migrazione `wp_dbcm_consent_log` schema 1 → 2: aggiunta colonna `policy_version` + indice. dbDelta è additivo, dati esistenti preservati. Le righe pre-3.2.0 hanno `policy_version=0` (visibili nel registro Hub con label "—").

**Compatibilità retroattiva:**
- Nessun breaking change. Il plugin funziona identicamente in standalone, con SEO Manager, con Privacy Hub 1.0-1.2.x.
- Privacy Hub 1.3.0+ è raccomandato (non richiesto) per visualizzare il Registro consensi unificato.

#### 3.1.0 — Integrazione DB Privacy Hub _(2026)_
- Nuovo metodo pubblico **`DBCM_Policy_Generator::get_sections()`** — espone le sezioni della Cookie Policy come array associativo per il riuso da parte del DB Privacy Hub (Privacy Policy unificata)
- Sezione "Titolare del trattamento" della Cookie Policy ora **legge automaticamente i dati salvati nel DB Privacy Hub** (`dbph_titolare_*`): se il Privacy Hub è installato e configurato, niente più placeholder da sostituire a mano
- `DBCM_Privacy_Declarations` ora si aggancia al filter unificato **`dbph_processing_register`** del Privacy Hub, mantenendo backward-compat con `dbseo_processing_register` (SEO Manager 1.2.x)
- ⚠️ Il filter `dbcm_policy_sections` ora riceve un array **associativo** (chiavi: `header`, `cookies_used`, ecc.) invece che indicizzato — uso interno, breaking solo per filtri custom

#### 3.0.2 — API pubblica scanner e README bilingue _(2026)_
- Nuovo metodo pubblico `DBCM_Scanner::get_cookies_by_provider_keyword($keyword, $limit = 50)`
- Modulo `DBCM_Privacy_Declarations`: integrazione automatica con DB SEO Manager 1.2.0+
- Sanitizzazione difensiva: keyword min 2 caratteri, hard cap 200 righe
- README bilingue IT/EN con tabelle allineate

#### 3.0.1 — Integrazione registro privacy SEO Manager _(2026)_
- Modulo `DBCM_Privacy_Declarations`: dichiara i tre trattamenti al registro privacy DB SEO Manager 1.2.0+

#### 3.0.0 — Rebuild completo _(2026)_ ⚠️ Breaking change
Riscrittura da zero, **non retrocompatibile** con 2.x. Svuotare le option `dbcm_*` e ricreare le tabelle.

- Integrazione WP Consent API nativa — 5 categorie standard (`functional`, `preferences`, `statistics`, `statistics-anonymous`, `marketing`)
- Blocker preventivo riallineato: Cloudflare CDN sbloccato, aggiunti TikTok/Pinterest/Bing UET/MS Clarity/Mixpanel/Heap/Amplitude/FullStory/GTM; embed estesi (Instagram, X, Spotify, SoundCloud); placeholder iframe traducibile
- Consent log GDPR-friendly: UA aggregato, IP solo hashato, export JSON oltre a CSV, schema versionato con migrazione just-in-time
- Scanner: schema versionato, HTML detection allineata al blocker, parsing `Set-Cookie` compatibile WP < 6.2 e ≥ 6.2, injection automatica `dbcm_consent` nelle policy
- Cookie database: 64 cookie noti, cookie unmatched → `marketing` safer-by-default, `guess_provider` esteso a 26 prefix
- Policy generator: sezioni modulari filtrabili, riferimento Linee Guida Garante 2021, sezione "Servizi senza cookie"
- Admin UI completa: dashboard + 5 sottopagine, dispatcher centralizzato, `db-admin-ui.css`
- GitHub Updater integrato, `uninstall.php` con cleanup completo, shortcode `[dbcm_preferences]`
- DNT/GPC runtime opt-out automatico, geo-targeting opzionale UE/EEA/UK

#### 2.0.1 _(2026)_
- Fix layout banner "barra": disposizione orizzontale su desktop (testo a sinistra, bottoni a destra)
- Cookie WordPress core (`wordpress_sec_*`, `wordpress_logged_in_*`, `wp-settings-*`, `wordpress_test_cookie`) iniettati automaticamente nei risultati della scansione
- Rilevamento Google Fonts alternativo via stili registrati WordPress e `theme_mod` (fallback per hosting con loopback bloccato)
- Fix salvataggio impostazioni: i checkbox non vengono più azzerati salvando da un'altra sezione
- Crediti "Powered by DB Cookie Manager" opzionali nel banner
- Try-catch nell'init JS per debug errori

#### 2.0.0 _(2026)_
- Banner cookie frontend con 3 layout e tema chiaro/scuro
- Blocco preventivo script con attivazione dinamica
- Placeholder per iframe bloccati
- Registro consensi con export CSV e pulizia automatica
- Sistema multilingua integrato (6 lingue)
- Categoria "Prestazioni" per cookie CDN/caching
- Pagina impostazioni completa con anteprima colori live
- Accessibilità WCAG: ARIA, focus trap, keyboard navigation, reduced motion

#### 1.0.0 _(2026)_
- Scansione asincrona AJAX
- Database 50+ cookie noti con classificazione automatica
- Generatore Cookie Policy
- Report con modifica manuale

---

### Licenza

GPL v2 o successiva — vedi [LICENSE](LICENSE).

### Crediti

Sviluppato da [Davide Bertolino](https://davidebertolino.it). Design system `db-admin-ui.css` condiviso con la suite plugin DB. Riconoscimenti a [WP Consent API](https://wordpress.org/plugins/wp-consent-api/) per lo standard di interoperabilità.

---
---

## 🇬🇧 English

**WordPress plugin for GDPR-compliant cookie management**, with a multilingual banner, automatic scanner, consent log, Cookie Policy generator, and native WP Consent API integration.

Developed by **Davide Bertolino** for personal and professional use, released as open-source (GPL v2+).

> **Integration with DB SEO Manager 1.2.0+**: when both plugins are installed, the SEO Manager's "GDPR Status" dashboard recognises Cookie Manager (bonus +5 to score), the privacy register automatically shows the three Cookie Manager treatments with an "External Plugin" badge, and the homepage audit enriches each known host with cookies actually detected by the scanner. No configuration required: the integration is automatic and bidirectional.

---

### Main Features

- **Multilingual cookie banner** (Italian, English, French, German, Spanish, Portuguese) with auto-detect from browser language
- **5 standard WP Consent API categories**: `functional`, `preferences`, `statistics`, `statistics-anonymous`, `marketing`
- **Preventive blocking** of tracking scripts (Google Analytics, Meta Pixel, GTM, TikTok, etc.) and iframes (YouTube, Vimeo, Maps, Spotify, etc.)
- **Automatic scanner** that visits main pages, parses `Set-Cookie` headers and detects third-party service signatures — 64 pre-classified known cookies
- **Cookie Policy generator** compliant with the Italian DPA Guidelines of 10 June 2021 (ref. art. 122 D.Lgs. 196/2003 + GDPR)
- **GDPR-friendly consent log** with hashed (irreversible) IP and aggregated user-agent — CSV and JSON export with filters
- **Public JavaScript API** `window.DBCM` for custom integrations
- **WP Consent API integration**: `wp_has_consent('statistics')` responds correctly based on visitor consent
- **Optional browser signals**: respects Do Not Track (DNT) and Global Privacy Control (GPC)
- **Optional geo-targeting**: shows banner only to EU/EEA/UK visitors — ⚠️ not compatible with full-page caching unless the cache varies by country
- **Google Consent Mode v2** *(opt-in)*: signals consent to Google tags with a denied default in `<head>` and update on consent; mapping customisable via `dbcm_gcm_mapping`
- **Google Fonts localisation** *(opt-in)*: strips remote Google Fonts references so the user's IP is not sent to Google
- **Reactive cookie cleanup**: removes cookies of non-granted categories from the browser, making consent withdrawal effective
- **Accessible click-to-load placeholder** for blocked embeds (granular per-embed consent)
- **Custom signatures**: manually add services/cookies with JSON import/export
- **Scanner differential report**: highlights cookies added or removed between two scans
- **Shortcode `[dbcm_preferences]`** for a "Manage preferences" button anywhere
- **Auto-update from GitHub** via [DB GitHub Updater](https://github.com/dadebertolino/db-github-updater)
- **Shared design system** with other DB plugins (`db-admin-ui.css`)
- **Clean uninstall** via `uninstall.php`

---

### Requirements

| Component       | Minimum version                                                               |
| --------------- | ----------------------------------------------------------------------------- |
| WordPress       | 6.0+                                                                          |
| PHP             | 7.4+                                                                          |
| WP Consent API  | *(optional)* — for automatic integration with other compatible plugins        |

---

### Installation

1. Download the latest ZIP from [GitHub Releases](https://github.com/dadebertolino/db-cookie-manager/releases)
2. WordPress admin → **Plugins → Add new → Upload plugin** → select ZIP → **Install now** → **Activate**
3. Go to **Cookie Manager** in the sidebar menu
4. Configure in order: **Banner & appearance** → **Scanner** → **Cookie Policy** → **Advanced**

Subsequent updates arrive automatically via GitHub Updater.

---

### Recommended Configuration

**For most websites:**

1. Banner: layout *"Floating box"*, position *"Bottom right"*, theme *"Auto"*
2. Default categories: **all disabled** (required by GDPR)
3. Consent duration: **180 days** (DPA recommendation)
4. Scanner: run at least one scan, then manually review unrecognised marketing cookies
5. Cookie Policy: auto-create the page, then fill in `[FULL NAME / COMPANY NAME]` and `[ADDRESS]`
6. Advanced: DNT/GPC **off** for explicit consent collection, **on** for maximum privacy by default

---

### JavaScript API

```js
// Check consent
if (window.DBCM.hasConsent('statistics')) { loadGoogleAnalytics(); }

// React to changes
window.DBCM.onConsent(function(consent, type) {
    console.log('Type:', type);       // 'accept_all' | 'reject_all' | 'custom'
    console.log('Marketing:', consent.marketing);
});

// Open preferences modal
window.DBCM.openPreferences();

// Set programmatically
window.DBCM.setConsent('marketing', true);

// Full map (null if no consent given yet)
var consent = window.DBCM.getConsent();

// Canonical list of 5 categories
console.log(window.DBCM.categories);
```

Alternative DOM event:

```js
document.addEventListener('dbcm:consent', function(ev) {
    var consent = ev.detail.consent;
    var type    = ev.detail.type;
});

// Since 3.7.1: window.DBCM is ready (useful when an optimizer delays
// banner.js and your script runs first).
document.addEventListener('dbcm:ready', function() {
    if (window.DBCM.hasConsent('marketing')) { /* ... */ }
});
```

---

### PHP Hooks & Filters

#### Banner

| Hook                          | Type    | Notes                                                     |
| ----------------------------- | ------- | --------------------------------------------------------- |
| `dbcm_should_render_banner`   | filter  | Default `true` — suppress banner on specific pages        |
| `dbcm_banner_translations`    | filter  | Add/override translations                                 |
| `dbcm_visitor_country_code`   | filter  | Supply ISO-3166 alpha-2 country code from MaxMind/GeoIP   |
| `dbcm_eu_country_codes`       | filter  | Customise EU/EEA country list for geo-targeting           |

#### Consent

| Hook                        | Type    | Notes                                                           |
| --------------------------- | ------- | --------------------------------------------------------------- |
| `dbcm_consent_set`          | action  | Fired on every consent change — args: `$type, $consent`         |
| `dbcm_consent_propagated`   | action  | Fired after propagation to `wp_set_consent()`                   |
| `dbcm_consent_type`         | filter  | Default `'optin'` — override WP API consent type                |
| `dbcm_consent_rate_limit`   | filter  | Default `60` (since 3.8.0; was `20`) — consent requests per IP every 10 minutes (anonymous visitors); `0` disables. IP follows `dbcm_trust_proxy_headers` |

#### Blocker

| Hook                                  | Type   | Notes                                      |
| ------------------------------------- | ------ | ------------------------------------------ |
| `dbcm_blocker_patterns`               | filter | Add/remove blocking patterns               |
| `dbcm_blocker_placeholder_text`       | filter | Iframe placeholder text (multilingual)     |
| `dbcm_blocker_placeholder_btn_label`  | filter | Placeholder button label                   |
| `dbcm_blocker_server_side_decision`   | filter | _(3.8.0)_ Default `true` for logged-in users only; anonymous HTML is always blocked and re-enabled client-side (cache-safe) |

#### Scanner

| Hook                            | Type   | Notes                                         |
| ------------------------------- | ------ | --------------------------------------------- |
| `dbcm_known_cookies`            | filter | Add custom cookies to the static database     |
| `dbcm_scan_urls`                | filter | Customise scanned URLs                        |
| `dbcm_scanner_html_detections`  | filter | Add HTML signatures of third-party services   |

#### Cookie Policy

| Hook                      | Type   | Notes                                                                                               |
| ------------------------- | ------ | --------------------------------------------------------------------------------------------------- |
| `dbcm_policy_sections`    | filter | Reorder/remove sections                                                                             |
| `dbcm_policy_html`        | filter | Modify final HTML                                                                                   |
| `dbcm_policy_section_*`   | filter | Modify individual sections (`header`, `definitions`, `cookies`, `external`, `browser`, etc.)        |

#### Consent Log

| Hook                       | Type   | Notes                                                                  |
| -------------------------- | ------ | ---------------------------------------------------------------------- |
| `dbcm_trust_proxy_headers` | filter | Default `false`. When `true`, the client IP is read from `CF-Connecting-IP`, then first `X-Forwarded-For` IP, then `X-Real-IP` (validated) **before** `REMOTE_ADDR`; used by the consent log and the rate limit (3.8.0) |

---

### Server-side API for Other Plugins

#### `DBCM_Consent_API::has_consent($category)` _(since 3.0.0)_

Unified helper for reading consent. 3-level strategy: `wp_has_consent()` if WP Consent API is installed, otherwise direct cookie, otherwise `false`.

#### `DBCM_Scanner::get_cookies_by_provider_keyword($keyword, $limit = 50)` _(since 3.0.2)_

Searches scanned cookies whose `provider` field contains the keyword (LIKE substring, case-insensitive). Used by DB SEO Manager 1.2.0+.

```php
if (class_exists('DBCM_Scanner')) {
    $cookies = DBCM_Scanner::get_cookies_by_provider_keyword('Google Analytics');
    foreach ($cookies as $c) {
        echo $c->cookie_name . ' (' . $c->category . ')';
    }
}
```

---

### Practical Examples

**Load Google Analytics only after `statistics` consent:**

```php
add_action('wp_footer', function() {
    if (function_exists('wp_has_consent') && wp_has_consent('statistics')) { ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=G-XXXXX"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', 'G-XXXXX');
        </script>
    <?php }
});
```

**Suppress banner on a landing page:**

```php
add_filter('dbcm_should_render_banner', function($render) {
    if (is_page('landing-newsletter')) return false;
    return $render;
});
```

**Add a custom cookie to the scanner:**

```php
add_filter('dbcm_known_cookies', function($cookies) {
    $cookies['my_app_session'] = [
        'category'    => 'functional',
        'description' => 'Internal application session.',
        'duration'    => 'Session',
        'provider'    => 'My App',
    ];
    return $cookies;
});
```

**"Manage preferences" link in footer:**

```php
echo do_shortcode('[dbcm_preferences label="Cookie" style="link"]');
```

---

### FAQ

**Is the plugin truly GDPR-compliant?**
It provides the technical tools for compliance. Full compliance depends on your configuration and editorial choices. A review by a DPO or lawyer remains necessary for legal assurance.

**Can I use it alongside another consent manager?**
No — only one at a time. Deactivate the other first.

**Does it work in multisite?**
Yes. Each site has its own options and its own log. Uninstallation is multisite-aware.

**Does preventive blocking break my site?**
Only if a theme depends on exactly one of the blocked tracking scripts (rare). If you encounter issues, disable "Preventive blocking" on the Scanner page.

**How does it handle IPv6?**
SHA256 hashing of the full IP (v4 or v6) + site-specific salt. Irreversible in practice.

**Can I export the log for GDPR requests?**
Yes — **Consent log → Download CSV** or **Download JSON**, with filters by type and date.

---

### Privacy

The plugin **does not communicate with external servers**. No telemetry, no phone-home. The only external connection is GitHub Updater checking for plugin updates.

Cookies written by the plugin:

| Cookie         | Category   | Duration                    | Purpose                        |
| -------------- | ---------- | --------------------------- | ------------------------------ |
| `dbcm_consent` | functional | 365 days (configurable)     | Stores the visitor's choice    |

---

### Changelog

#### 3.8.1 — Fixes found by the new E2E suite _(2026)_

- **Banner**: cookie schema compared numerically (`wp_localize_script` passes `"3"` as a string); existing cookies stay valid, no re-consent.
- **Consent log**: CSV/JSON export links pointed to the non-existent `dbcm-consent-log` page; expired nonce now shows a clear error; "Total consents" counts the whole log; unknown type filter returns no rows; JSON envelope description fixed.
- **Scanner**: one HTTP request per URL instead of two; `dbcm_consent` duration follows the configured consent duration; override/delete of missing cookies return an error; translatable progress/error strings; "Scan completed." notice.
- **Admin**: specific errors when deleting missing signatures/declared services; Declared services page dies with the standard permission error; default banner language forced among active languages; unique nonce id on the Banner page; dashboard card descriptions; stale "step 7" texts removed.

#### 3.8.0 — Cache-safe blocking, proxy-aware IP, client-side WP Consent API, Hub alignment _(2026)_

- **Critical — cache-safe blocking**: anonymous visitors now always receive neutralized scripts/iframes; `banner.js` re-enables them client-side from the visitor's own cookie. Previously a page cached for a consenting visitor served live trackers to everyone. Banner `autoOpen` no longer reads the cookie server-side; geo-targeting is documented as incompatible with full-page caching. Faithful client-side restore (iframe attributes, script order, `type="module"`).
- **Consent**: `has_consent()` returns `false` when the `dbcm_consent` cookie is missing/stale before trusting `wp_consent_*`; `banner.js` calls `window.wp_set_consent()`; hydrate only rewrites changed `wp_consent_*` cookies; duplicate DNT/GPC reopen button removed; network errors logged.
- **Consent log**: `dbcm_trust_proxy_headers` now actually works (trusted headers first) and drives the rate limit too; default rate limit 60/10 min; retention forced ≥ consent duration; daily cron ensured on `init`; multisite schema/cron for new sites; epoch-based date handling with WP-local output (no schema migration); CSV injection protection; Hub `limit` honoured (up to 50,000).
- **Privacy Hub**: Meta Platforms Ireland declared via `dbph_policy_destinatari` when Meta Pixel is active; "Privacy capabilities" header (DSAR-aware NO, Hub-aware YES); unnumbered policy section titles, real cookie duration, last-scan footer date, single-escaped email.
- **Appearance**: colours, auto theme and custom CSS finally applied (`dbcm_banner_inline_css`); credits shown when enabled (default now off).
- **Cleanup**: test pattern `sa.davidebertolino.it` removed; uninstall deletes only this plugin's updater transient; new "Keep data on uninstall" option.

#### 3.7.1 — Consents recorded even with page caching _(2026)_

- **Consent log fix with page caching**: with WP Rocket, LiteSpeed Cache and similar, the nonce printed in the HTML expired after 12–24h and from then on every visitor choice was silently rejected by the `dbcm_set_consent` endpoint — the banner worked, but the consent never reached the log (Art. 7.1) nor the WP Consent API. For anonymous visitors the nonce (identical for everyone) is replaced by an origin check (same-site `Origin`/`Referer`) and a per-IP rate limit (salted hash, `dbcm_consent_rate_limit` filter); logged-in users keep the nonce. `banner.js` now reports a failed sync in the console instead of swallowing it.
- **Meta Pixel with delayed JS**: when an optimizer delays `banner.js` until first interaction, the gate could not find `window.DBCM` at `DOMContentLoaded` and the pixel never fired for visitors who had already consented. New `dbcm:ready` event, fired when the public API is available: the gate re-checks there.
- Tests: +7 unit (164 total).

#### 3.7.0 — Native Meta Pixel module _(2026)_

The plugin that owns the consent also owns the pixel: new opt-in module (*Advanced → Meta Pixel*) that emits the Meta snippet as a JS gate, so loading `fbevents.js` is bound to the Marketing category **by construction** (Art. 6.1.a) — no race between CMP and third-party tags.

- **Fires without reload**: initial check on `window.DBCM.hasConsent('marketing')` (version-aware: consent with an outdated version does not grant) + `dbcm:consent` listener. On withdrawal it sends `fbq('consent','revoke')` at runtime (Art. 7.3); `_fbp` removal is covered by the existing reactive cleanup.
- **Self-blocking avoided**: the gate contains the strings the blocker matches on inline content (`connect.facebook.net`, `fbevents.js`) — new explicit `data-dbcm-own` skip in the blocker, with contract tests: the gate passes, a third-party Meta Pixel (e.g. Meta for WooCommerce) stays blocked.
- **Automatic transparency**: when active, the `meta-pixel` slug is registered in the declared services (hydrated from the signature: single source of truth) and the joint-controllership treatment with Meta (Art. 26, DPF) is declared to DB Privacy Hub via `dbph_processing_register`. The `_fbc` cookie (click ID) is added to the `meta-pixel` signature.
- **CAPI handoff** (opt-in): PageView sent with a shared `eventID` exposed in `window.DBCM_META_LAST_EVENT_ID`, for deduplication with the upcoming server-side Conversions API (DB Meta Events).
- Pixel ID validated server-side (15–16 digits only, re-sanitised on read too). Tests: +24 units (module 20, blocker contract 4), validated via mutation testing.

#### 3.6.1 — Facebook signature for embedded content _(2026)_

Closes a blocking gap surfaced through the new Declared Services page: Facebook iframe embeds (`facebook.com/plugins/…` — posts, videos, like/share buttons) were covered by no signature and no blocker pattern, so they **loaded before consent** (only the Meta Pixel via `connect.facebook.net` was handled). New `facebook` signature with typical cookies (`fr`, `datr`, `sb`) and the Meta policy link: it cascades into preventive iframe blocking, the click-to-load placeholder, the declared services registry, the pick-list and scanner detection. SDK-based loading remains attributed to `meta-pixel` (no double attribution). Tests: +3 regression units (132 total).

#### 3.6.0 — Declared services registry _(2026)_

Fixes a structural issue found in a real-world audit: the generated Cookie Policy only listed technical cookies, because the scanner fetches pages via self-request and the HTML it analyses has already gone through the blocker — gated embeds (YouTube, Maps, ...) are already placeholders and signatures no longer match. The better the blocking works, the more incomplete the policy: it fails to justify the consent the banner requests (documentation inconsistency, Art. 5(1)(a)).

- **Automatic registration at block time**: when the blocker matches and rewrites an iframe/script, it resolves the src to a signature (new `DBCM_Signatures::identify_url()`) and records `{slug, last_seen}`. Empirical evidence of actual usage — no over-declaration of the 42 bundled services on sites that don't use them. The scanner's self-request goes through the blocker, so **running a scan populates the registry**: circularity solved with no blocker bypass (a bypass would be spoofable = consent circumvention). Writes throttled to one per service per day.
- **Staleness**: auto entries appear in the policy only if seen within the last 30 days (`dbcm_declared_services_ttl` filter) — embed removed, entry decays on its own. Details (provider, typical cookies, durations, policy link) are hydrated from the signatures DB at generation time: single source of truth.
- **New policy section** "Embedded content and services active subject to consent", per category, with the "blocked by default" wording and an explicit referral to third-party policies (allowed by the Italian DPA Guidelines of 10 June 2021 for cookies outside the controller's direct control).
- **Manual declaration** (*Cookie Manager → Declared services*): pick-list from known signatures (pre-filled data) or free form for residual cases; manual entries never expire and take precedence over automatic ones for the same service.
- **Banner ↔ policy coherence validation**: categories requested in the banner with no detected cookies and no declared services trigger a dashboard warning — covering both the undocumented service and the unjustified consent request.
- **DB Privacy Hub integration**: registry exposed via the `dbcm_declared_services_register` filter (consumed by DBPH ≥ 1.4.0, transparent fallback if absent).
- Tests: +22 unit (registry 17, `identify_url` 3, policy 2+1), all validated with mutation testing.

#### 3.5.1 — class-admin.php split _(2026)_

Maintenance release, zero behavior changes. The `class-admin.php` monolith (2,871 lines) has been split into a shared core (menu, save dispatcher, render helpers, flash notices — 745 lines) plus one class per page: Dashboard, Banner, Scanner, Signatures, Cookie Policy, Consent Log, Advanced (`inc/class-admin-page-*.php`, each ≤ 510 lines). Mechanical refactor validated with the full suite before/after (107 unit + 16 integration unchanged) and a reflection smoke test on the extracted classes. Documented PHPCS false-positive patterns extended to the new files (same code, same manual verification).

#### 3.5.0 — Consent versioning _(2026)_

GDPR consent is **specific and informed with respect to the processing presented at the moment of choice** (Art. 4(11) + 6(1)(a)): if you add a new tracker or significantly change the processing after consent was collected, the stored consents (valid up to 365 days) do not cover the new processing. From this version, every consent is tied to a **version of the processing configuration**.

- **Manual counter** (default: 1) with an admin button (*Banner → Content → Consent version*) "Request new consent from all users". Assessing whether "the conditions of the processing changed significantly" (Italian DPA Guidelines 10 Jun 2021 §5) is a judgment for the data controller: no automatic re-prompt, avoiding the consent fatigue that the same guidelines flag as an issue.
- **Mismatch = no consent (strict)**: after a bump, `dbcm_consent` cookies with a previous version are treated as no choice — client-side (banner shown again) **and** server-side (`has_consent()` denies; on hydrate an explicit deny is propagated to the WP Consent API, whose own cookies would otherwise survive the bump). No pre-selection of old choices in the new banner.
- **Evidentiary value (Art. 7(1))**: the consent log records the version in a new `consent_version` column (schema 3, additive non-destructive migration), populated server-side from the setting — not forgeable by the client. Shown in the admin table (*Ver.* column), in the CSV/JSON exports and in the Privacy Hub integration. Complementary to `policy_version`: that one tracks the *policy document* version, this one the *processing configuration* version.
- **Backward compatibility**: pre-3.5.0 cookies (without the `cv` field) count as version 1 — updating the plugin alone does **not** re-show the banner to anyone; only an explicit admin bump does.
- Removed the `reconsent_on_change` option (never wired to any real behavior): versioning replaces it with an effective mechanism.
- Tests: +12 unit (`cv` validation in `read_cookie`/`has_consent`/`hydrate`, setting clamp) and +3 integration (schema 3, logged value), all validated with mutation testing.

#### 3.4.3 — Microsoft consent signals (UET + Clarity) _(2026)_

Two new consent signals, both **opt-in** and disabled by default, mirroring Google Consent Mode v2. Microsoft enforces explicit consent signals for visitors from the EEA, UK and Switzerland (for Clarity since 31 Oct 2025).

**Microsoft UET Consent Mode:**
- New option (Advanced → *Microsoft consent signals*) for UET tags (Microsoft Advertising / Bing Ads).
- Injects `window.uetq.push('consent','default',{ad_storage:'denied'})` in `<head>` before the UET tag — privacy by default, GDPR Art. 25.
- On consent (Marketing category), `banner.js` sends the `granted` update; on withdrawal it returns to `denied` (Art. 7(3): withdrawing must be as easy as consenting). Mapping customisable via the **`dbcm_uet_mapping`** filter.

**Microsoft Clarity ConsentV2:**
- `consentv2` signal with `ad_Storage` and `analytics_Storage` denied by default; the update follows the Marketing and Statistics categories.
- Conservative choice: the *Anonymous statistics* category is **not** mapped — Clarity session recordings are not anonymous statistics. Extendable via the **`dbcm_clarity_mapping`** filter.
- On withdrawal Clarity receives the denied signal, deletes its own cookies and continues in no-consent mode — consistent with the plugin's reactive cleanup.

**Declared scope (non-TCF):**
- DBCM does **not** implement the IAB TCF v2.2 framework: serving personalised ads in the EEA/UK with AdSense/Ad Manager/AdMob requires a Google-certified, IAB-registered CMP with yearly re-certification — unsustainable for an independent plugin. AdSense users can run Google's own CMP alongside DBCM; DBCM covers every other consent scenario (analytics, marketing, embeds, Google and Microsoft signals).

#### 3.4.2 — Provider privacy policy links _(2026)_
- In the Cookie Policy table, the provider name now links to its privacy policy whenever the provider is known to the signatures database (GDPR Art. 13(1)(e)-(f) transparency: information about data recipients; complements the "Transfer" column for Chapter V safeguards).
- The lookup aligns the two internal naming schemes ("Google Ireland Ltd." in signatures, "Google Analytics" in the header scanner) with conservative rules: no link is ever invented for unknown or self-hosted providers.
- Custom signatures support the new **Privacy policy URL** field (admin form, JSON import/export); non-`http(s)` URLs are discarded on save.

#### 3.4.1 — Cookie policy update _(2026)_
- New "Transfer" column flagging cookies with extra-EU (USA) data transfers, with a note on GDPR Chapter V safeguards (Standard Contractual Clauses / Data Privacy Framework). Known US providers are detected automatically.

#### 3.4.0 — Consent Mode v2, local Google Fonts and scanner report _(2026)_

Three new compliance-oriented features, all **opt-in** and disabled by default.

**Google Consent Mode v2:**
- New option (Advanced → *Google Consent Mode v2*) that signals consent state to Google tags (GA4, Google Ads).
- Injects `gtag('consent','default',…)` as early as possible in `<head>` with **all signals denied** (`analytics_storage`, `ad_storage`, `ad_user_data`, `ad_personalization`) — privacy by default, GDPR Art. 25.
- On consent, `banner.js` sends `gtag('consent','update',…)` with the granted signals only. Category→signal mapping customisable via the **`dbcm_gcm_mapping`** filter.
- Recommended to leave off if Consent Mode is already handled through Google Tag Manager.

**Google Fonts localisation:**
- New option (Advanced → *Google Fonts*) that strips remote references to `fonts.googleapis.com` / `fonts.gstatic.com` from the HTML (stylesheets, preconnect, dns-prefetch and related `@import`).
- The browser no longer contacts Google's servers on page load, so the user's IP is not transmitted. Relevant after the Munich court ruling (January 2022).
- The site falls back to system fonts. This option **removes** the fonts; it does not self-host them.

**Scanner differential report:**
- The Scanner page now shows a *"Changes since last scan"* card listing **added** and **removed** cookies versus the previous scan.
- Useful for accountability (GDPR Art. 5.2): flags unexpected trackers introduced by a site update. A cookie that changes category is reported as removed from the old and added to the new.

**Fixes:**
- The scanner aligns classification with the shared signatures database: known technical cookies (e.g. WooCommerce `store_notice*`) are no longer mis-classified as *marketing*.

#### 3.3.0 — Advanced consent signals _(2026)_
- **Reactive cookie cleanup**: on consent withdrawal (or on load without consent), cookies of non-granted categories are removed from the browser, making withdrawal effective (GDPR Art. 7.3, 17, 5.1.e). Wildcard support (e.g. `_ga_*`).
- **Manual signature UI**: new *Custom signatures* page to add services and cookies not covered by the built-in database, with block patterns (substring/regex), reactive cleanup and **JSON import/export**.
- **Click-to-load placeholder**: blocked embeds (YouTube, Maps, …) show an accessible placeholder (`role="region"`, keyboard-navigable) with a *"Load …"* button that activates a single embed without writing category-wide consent.

#### 3.0.2 — Public scanner API and bilingual README _(2026)_
- New public method `DBCM_Scanner::get_cookies_by_provider_keyword($keyword, $limit = 50)`
- `DBCM_Privacy_Declarations` module: automatic integration with DB SEO Manager 1.2.0+
- Defensive sanitisation: keyword min 2 chars, hard cap 200 rows
- Bilingual IT/EN README with aligned tables

#### 3.0.1 — SEO Manager privacy register integration _(2026)_
- `DBCM_Privacy_Declarations` module: automatically declares the three Cookie Manager treatments to the DB SEO Manager 1.2.0+ privacy register

#### 3.0.0 — Full rebuild _(2026)_ ⚠️ Breaking change
Ground-up rewrite, **not backward-compatible** with 2.x. Flush `dbcm_*` options and recreate tables.

- Native WP Consent API integration — 5 standard categories (`functional`, `preferences`, `statistics`, `statistics-anonymous`, `marketing`)
- Realigned preventive blocker: Cloudflare CDN unblocked; added TikTok, Pinterest, Bing UET, MS Clarity, Mixpanel, Heap, Amplitude, FullStory, GTM; extended embeds (Instagram, X, Spotify, SoundCloud); translatable iframe placeholder
- GDPR-friendly consent log: aggregated UA, IP hashed only, JSON export alongside CSV, versioned schema with just-in-time migration
- Scanner: versioned schema, HTML detection aligned to blocker, `Set-Cookie` parsing compatible with WP < 6.2 and ≥ 6.2, automatic `dbcm_consent` injection in policies
- Cookie database: 64 known cookies, unmatched cookies → `marketing` safer-by-default, `guess_provider` extended to 26 prefixes
- Policy generator: filterable modular sections, reference to 2021 Italian DPA Guidelines, "Cookie-free services" section
- Full admin UI: dashboard + 5 sub-pages, centralised dispatcher, `db-admin-ui.css`
- GitHub Updater integrated, `uninstall.php` with complete cleanup, `[dbcm_preferences]` shortcode
- DNT/GPC runtime automatic opt-out, optional EU/EEA/UK geo-targeting

#### 2.0.1 _(2026)_
- Fix "bar" banner layout: horizontal arrangement on desktop (text left, buttons right)
- WordPress core cookies (`wordpress_sec_*`, `wordpress_logged_in_*`, `wp-settings-*`, `wordpress_test_cookie`) automatically injected into scan results
- Alternative Google Fonts detection via registered WordPress styles and `theme_mod` (fallback for hosts with blocked loopback)
- Fix settings save: checkboxes no longer reset when saving from a different section
- Optional "Powered by DB Cookie Manager" credit in the banner
- Try-catch in JS init for error debugging

#### 2.0.0 _(2026)_
- Frontend cookie banner with 3 layouts and light/dark theme
- Preventive script blocking with dynamic re-activation
- Placeholder for blocked iframes
- Consent log with CSV export and automatic cleanup
- Built-in multilingual system (6 languages)
- "Performance" category for CDN/caching cookies
- Full settings page with live colour preview
- WCAG accessibility: ARIA, focus trap, keyboard navigation, reduced motion

#### 1.0.0 _(2026)_
- Asynchronous AJAX scanning
- 50+ known cookies database with automatic classification
- Cookie Policy generator
- Report with manual editing

---

### License

GPL v2 or later — see [LICENSE](LICENSE).

### Credits

Developed by [Davide Bertolino](https://davidebertolino.it). `db-admin-ui.css` design system shared across the DB plugin suite. Credits to [WP Consent API](https://wordpress.org/plugins/wp-consent-api/) for the interoperability standard.