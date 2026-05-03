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
- **Geo-targeting opzionale**: mostra il banner solo a visitatori UE/EEA/UK
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

#### Consent

| Hook                        | Tipo    | Note                                                         |
| --------------------------- | ------- | ------------------------------------------------------------ |
| `dbcm_consent_set`          | action  | Fired ad ogni cambio consenso — args: `$type, $consent`      |
| `dbcm_consent_propagated`   | action  | Fired dopo la propagazione a `wp_set_consent()`              |
| `dbcm_consent_type`         | filter  | Default `'optin'` — sovrascrivi il consent type WP API       |

#### Blocker

| Hook                              | Tipo   | Note                                         |
| --------------------------------- | ------ | -------------------------------------------- |
| `dbcm_blocker_patterns`           | filter | Aggiungi/rimuovi pattern di blocco           |
| `dbcm_blocker_placeholder_text`   | filter | Testo del placeholder iframe (multilingua)   |
| `dbcm_blocker_placeholder_btn_label` | filter | Label del pulsante del placeholder        |

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
| `dbcm_trust_proxy_headers`| filter | Default `false` — fidati di `X-Forwarded-For` e simili per l'IP   |

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

**Il blocco preventivo rompe il mio sito?**
Solo se il tema dipende esattamente da uno script di tracking bloccato (raro). Se hai problemi, disabilita "Blocco preventivo" nella pagina Scanner.

**Come gestisce IPv6?**
Hashing SHA256 dell'IP completo (v4 o v6) + salt site-specifico. Irreversibile in pratica.

**Posso esportare il log per richieste GDPR?**
Sì — **Registro consensi → Scarica CSV** o **Scarica JSON**, con filtri per tipo e data.

---

### Privacy

Il plugin **non comunica con server esterni**. Niente telemetria, niente phone-home. L'unica connessione esterna è GitHub Updater per gli aggiornamenti del plugin.

Cookie scritti dal plugin:

| Cookie         | Categoria   | Durata                   | Scopo                          |
| -------------- | ----------- | ------------------------ | ------------------------------ |
| `dbcm_consent` | functional  | 365 giorni (configurabile) | Memorizza la scelta dell'utente |

---

### Changelog

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
- **Optional geo-targeting**: shows banner only to EU/EEA/UK visitors
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

#### Blocker

| Hook                                  | Type   | Notes                                      |
| ------------------------------------- | ------ | ------------------------------------------ |
| `dbcm_blocker_patterns`               | filter | Add/remove blocking patterns               |
| `dbcm_blocker_placeholder_text`       | filter | Iframe placeholder text (multilingual)     |
| `dbcm_blocker_placeholder_btn_label`  | filter | Placeholder button label                   |

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
| `dbcm_trust_proxy_headers` | filter | Default `false` — trust `X-Forwarded-For` and similar headers for IP   |

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
