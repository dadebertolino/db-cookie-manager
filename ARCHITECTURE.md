# DB Cookie Manager — Architettura

Documento di reference delle scelte strategiche prese durante il rebuild 3.0.0. Pensato come compagno del [README.md](README.md): se il README spiega *cosa* fa il plugin, questo documento spiega *perché* è fatto così.

Utile per:
- l'autore stesso che rilegge il codice fra mesi
- eventuali contributor esterni che vogliono capire le decisioni prima di proporre modifiche
- come template architetturale per gli altri plugin DB

---

## 1. Architettura e compatibilità

### Rebuild non retrocompatibile

Riscrittura da zero invece di patch incrementali sulla 2.0.1.

**Motivazione**: poche installazioni esistenti (uso personale + qualche cliente). Il costo della migrazione è basso, il vantaggio di non avere compromessi sulle decisioni nuove (categorie, schema cookie, API) è alto.

**Costo accettato**: chi aggiorna deve svuotare option e tabelle. Documentato nel changelog.

### 5 categorie standard WP Consent API

`functional`, `preferences`, `statistics`, `statistics-anonymous`, `marketing`. Niente categorie custom italiane (tecnici/analitici/profilazione/...).

**Beneficio**: interoperabilità con tutti i plugin che rispettano lo standard, integrazione automatica con `wp_has_consent()`, riconoscibilità da parte del DB SEO Manager.

**Costo accettato**: traduzioni nei testi user-facing meno "naturali" all'orecchio italiano. Mitigato con `DBCM_Cookie_Database::get_category_label()` che fornisce label localizzate ("Tecnici (necessari)", "Marketing / Profilazione", ...) mantenendo gli identificatori interni standard.

### Schema cookie versionato

Il cookie `dbcm_consent` include `{v: 3, ts, type, ...categories}`. I cookie con schema diverso vengono ignorati in lettura.

**Beneficio**: future evoluzioni del plugin non rompono installazioni esistenti — il banner riapparirà automaticamente per chi aveva uno schema vecchio.

**Costo accettato**: ogni upgrade di schema costa una tornata di re-consent agli utenti. Da usare con parsimonia (`COOKIE_SCHEMA_VERSION` aggiornato solo per breaking change reali).

---

## 2. Privacy by design

### UA aggregato di default nel consent log

Tre modalità (`none`/`aggregate`/`full`), default `aggregate` (Chrome/Firefox/Safari/Edge/Opera/Mobile/IE/Bot/Altro). La 2.0.1 salvava lo UA completo.

**Motivazione**: l'art. 7 GDPR richiede prova del consenso, non identificazione del browser specifico. Salvare UA completo + IP hashato nello stesso record permetteva fingerprinting non necessario alla giustificazione legale.

### IP solo hashato, mai in chiaro

SHA256 + `wp_salt('auth')`. Irreversibile in pratica. Lo stesso IP produce lo stesso hash → permette correlazioni "stesso visitatore" senza rivelare l'IP originale.

### Trust dei proxy headers solo via filtro esplicito

`X-Forwarded-For`, `CF-Connecting-IP`, `X-Real-IP` ignorati di default. L'admin deve dichiarare esplicitamente di essere dietro proxy via:

```php
add_filter('dbcm_trust_proxy_headers', '__return_true');
```

**Motivazione**: sicurezza by default contro IP spoofing. Un attaccante può inviare qualsiasi `X-Forwarded-For`; fidarcisi cieca­mente vuol dire log inquinati.

### Default categorie disattivate

Tutti i toggle delle categorie opzionali (preferences/statistics/statistics-anonymous/marketing) hanno default `false`. La pagina admin Banner ha un alert GDPR esplicito ("Attivarli pre-selezionati equivale a un consenso non valido").

**Costo accettato**: l'admin deve scegliere consapevolmente di violare la conformità per attivarli. Compromesso volontario di UX a favore della compliance.

### Cookie unmatched → marketing safer-by-default

La categoria `sconosciuto` della 2.0.1 è stata eliminata. Cookie non identificati cadono ora su `marketing` con descrizione esplicita "Cookie non identificato — verificare manualmente".

**Motivazione**: coerente col blocker che già trattava sconosciuti come tracking. Default conservativo.

---

## 3. Blocker preventivo

### Cloudflare CDN sbloccato

`cdnjs.cloudflare.com`, `cdn.cloudflare.com`, `ajax.cloudflare.com` non sono più bloccati. Sono infrastruttura, non tracking. Solo `challenges.cloudflare.com` (Turnstile) resta in `marketing`. Il bot management (`__cf_bm`, `cf_clearance`) è ora `functional`.

**Motivazione**: la 2.0.1 rompeva siti che caricavano Font Awesome o jQuery da Cloudflare. Era un falso positivo: un CDN che serve un asset statico non traccia l'utente più di quanto faccia il server di origine.

### Plausible/Umami in `statistics-anonymous`

Cookieless by design, aggregati. Il SEO Manager riconosce questa categoria. Per il Garante possono essere assimilati ai tecnici se anonimizzati propriamente.

**Motivazione**: flessibilità lasciata all'admin. Se vuole considerarli tecnici (no consent richiesto), può farlo via filtro `dbcm_blocker_patterns` rimuovendo il pattern. Default conservativo: trattati come opzionali ma in una categoria separata.

### Pattern map estensibile via filtro

`dbcm_blocker_patterns` con validazione automatica. Tema/altri plugin aggiungono domini custom senza patchare il file principale.

```php
add_filter('dbcm_blocker_patterns', function($p) {
    $p[] = ['category' => 'marketing', 'type' => 'script', 'patterns' => ['my-pixel.example.com']];
    return $p;
});
```

### Doppio meccanismo preservato

`script_loader_tag` filter (priorità 100) + output buffering su `template_redirect` (priorità 1). Copre sia gli script enqueued sia inline/iframe hardcoded nei template.

**Motivazione**: il solo filtro `script_loader_tag` non vede gli script inline aggiunti via `wp_head`/`wp_footer` né gli iframe nel content del post. Servono entrambi.

### Click sul placeholder iframe → modal preferenze

In 2.0.1 emetteva un custom event `dbcm:requestConsent` non documentato. Ora chiama `window.DBCM.openPreferences()`.

**Motivazione**: coerente col pulsante "Riapri" del banner, UX uniforme. Evento custom undocumented = trappola per chi rilegge il codice.

---

## 4. Sicurezza admin

### Dispatcher centralizzato `admin-post.php`

Tutti i form puntano a una sola action `dbcm_save_settings`. Sicurezza a 4 livelli: capability + nonce + schema esplicito (`sanitize_section`) + sanitize per tipo.

**Mass-assignment protection**: un POST per "banner_appearance" non può sovrascrivere "log_settings" anche se manipolato. Ogni sezione dichiara esplicitamente le chiavi che accetta.

### Schema sanitize esplicito per chiave

Ogni campo dichiara il proprio tipo: `bool`/`int`/`color`/`select:val1|val2`/`page_id`/`ua_mode`/`lang_array`. Chiavi non in lista vengono ignorate silenziosamente.

**Clamp specifici per chiave**: `consent_duration` 1-730gg, `consent_log_retention` 0-3650gg. Validazione semantica oltre alla validazione di tipo.

### Nonce dedicati per AJAX critici

`dbcm_scanner_nonce` separato dal nonce delle settings. `dbcm_create_policy_page` separato dal salvataggio standard.

**Motivazione**: compromettere un nonce non dà accesso agli altri. Principle of least privilege esteso ai token CSRF.

---

## 5. UI e design system

### Design system condiviso `db-admin-ui.css`

Stesso file CSS usato dagli altri plugin DB. Variabili `--db-*`, componenti `db-ui-*`.

**Beneficio**: cambio design system in un punto solo aggiorna tutti i plugin. Coerenza visiva fra plugin diversi della stessa suite.

**Costo accettato**: dipendenza implicita fra i progetti. Se cambio una variabile devo verificare che gli altri plugin non si rompano.

### Color picker nativo `<input type="color">`

Niente WP color picker JS. Risparmio 30+KB di asset enqueued.

**Compromesso accettato**: meno funzionalità (no swatch presets) ma per 4 colori in una pagina rara non vale la pena. Browser support universale (IE non supportato).

### Due form indipendenti per Banner

Aspetto e Contenuto salvano in sezioni separate (`banner_appearance`, `banner_content`). L'admin può modificare i colori senza riconfermare le lingue.

**Costo accettato**: due bottoni "Salva" sulla stessa pagina possono confondere. Mitigato con label esplicite ("Salva aspetto", "Salva contenuto").

### Iframe sandboxed per anteprima Cookie Policy

`sandbox="allow-same-origin"` con `srcdoc` e CSS isolato.

**Beneficio**: l'anteprima non eredita stili dell'admin (font monospace, sfondi grigi). Mostra esattamente come apparirà nel sito pubblico.

### Stub puliti durante sviluppo

Le pagine non ancora implementate mostravano un placeholder `db-ui-empty` con messaggio "Le funzioni di backend sono già attive".

**Beneficio**: il plugin si poteva attivare e usare anche con admin parziale negli step intermedi del rebuild.

---

## 6. Robustezza

### Schema versionati con migrazione just-in-time

`dbcm_consent_log_schema` e `dbcm_scanner_schema` come option separate. `maybe_upgrade_schema()` chiamato in `admin_init`.

**Motivazione**: funziona anche su hosting dove `register_activation_hook` non scatta (mu-plugins, WP-CLI bulk install). Difensivo contro casi limite.

### Activation hook centralizzato nel bootstrap

`DBCM_Plugin::on_activation()` chiama `DBCM_Consent_Log::create_table()` + `DBCM_Scanner::create_table()` se le classi esistono. Niente più `register_activation_hook` dentro i moduli singoli.

**Beneficio**: niente race condition fra moduli, ordine deterministico.

### Compatibilità `Requests`/`WpOrg\Requests`

`extract_set_cookie_headers()` gestisce sia il vecchio `Requests_Utility_CaseInsensitiveDictionary` (WP < 6.2) sia il nuovo `WpOrg\Requests\Utility\CaseInsensitiveDictionary` (WP ≥ 6.2) con `class_exists` check. No `instanceof` su classi potenzialmente inesistenti.

### Scanner: continua su fallimento singolo URL

Un 404 o timeout su una pagina non blocca tutta la scansione. Il JS continua col `next()` anche se l'AJAX della singola URL fallisce.

**Motivazione**: cruciale per siti con pagine vecchie/rimosse. Una scansione che fallisce al primo errore è inutile su siti reali.

### Riattivazione script al boot del banner

Se esiste un cookie valido al `boot()`, gli script bloccati vengono riattivati subito.

**Bug 2.x risolto**: dopo un page reload gli script tracking restavano `text/plain` per sempre (il blocker li neutralizzava ma niente li riattivava finché l'utente non cliccava di nuovo sul banner).

---

## 7. API e estensibilità

### `window.DBCM` API pubblica documentata

6 metodi: `hasConsent`, `getConsent`, `setConsent`, `openPreferences`, `onConsent`, `categories`. Più evento DOM standard `dbcm:consent`.

**Beneficio**: gli sviluppatori che integrano col plugin hanno un contratto stabile.

**Costo accettato**: vincola le evoluzioni future a non rompere questi nomi.

### Hook `dbcm_consent_set` invece di AJAX duplicato

Il consent log si aggancia all'action emessa da `DBCM_Consent_API` quando l'utente cambia consenso. Niente più endpoint AJAX dedicato (`dbcm_log_consent` rimosso).

**Beneficio**: single-source-of-truth per l'evento "consent changed". Chi vuole loggare in modo custom (es. su servizio esterno) hooka la stessa action.

### Sezioni Cookie Policy modulari filtrabili

`dbcm_policy_section_header/definitions/cookies/external/...` come metodi separati con filtri individuali.

**Beneficio**: lo shortcode preferenze (e altri usi futuri) possono comporre solo le sezioni che servono. Modulo riutilizzabile.

### 18 hook/filtri PHP documentati nel README

Dichiarati esplicitamente nel README come API pubblica.

**Contratto di stabilità**: i nomi e le firme di questi hook non cambieranno senza un major version bump.

---

## 8. Geo-targeting

### Default permissivo se geolocation fallisce

Se nessuna delle 4 strategie (CF-IPCountry, CloudFront, filtro custom, Accept-Language) riesce, mostra il banner.

**Motivazione**: il rischio legale è asimmetrico. Mostrare il banner a un visitatore extra-UE non viola alcuna legge. Nasconderlo a un utente UE viola il GDPR. Default conservativo nella direzione della compliance.

### 31 codici paese: UE 27 + EEA + UK

Lista esplicita filtrabile via `dbcm_eu_country_codes`. UK incluso (GDPR-equivalent post-Brexit). Svizzera esclusa di default (può essere aggiunta via filtro).

### Strategia a 4 livelli per detection

1. Cloudflare `CF-IPCountry` (più affidabile)
2. CloudFront `CloudFront-Viewer-Country` (AWS)
3. Filtro `dbcm_visitor_country_code` (per MaxMind/GeoIP locali)
4. `Accept-Language` come fallback debole

**Approccio difensivo**: ognuno è opzionale, il successo del primo che funziona vince.

---

## 9. Workflow di sviluppo

### Step incrementali con punto di test naturale

7 step principali, di cui il 6 spezzato in 4 sotto-step (6a/b/c/d). Ogni step produceva uno ZIP installabile e testabile.

**Costo accettato**: alcune iterazioni hanno scritto codice in anticipo per gli step successivi (lo step 6c era in gran parte già fatto in 6a) — gestito con note di trasparenza nel changelog.

### Test funzionali a ogni step

PHP standalone con stub WP minimal, eseguiti dopo ogni cambio importante. Verifica sanitize, render, parsing. Totale ~447 test sull'intero rebuild.

**Compromesso**: niente PHPUnit "vero", ma i test catturano regressioni prima del commit. Mocking lightweight, focus su behavior verifiable.

### Lint a ogni step

PHP `php -l`, JS `node --check`, CSS conta graffe.

**Limite riconosciuto**: non sostituisce phpcs/eslint completi ma cattura il 90% degli errori di sintassi a costo zero.

### Decisioni esplicitamente documentate nel codice

Ogni cambio rispetto a 2.0.1 ha un commento `Cambio rispetto a 2.0.1: ...` che spiega il motivo.

**Beneficio**: rileggere il codice fra 2 anni si capisce subito perché è così.

**Costo accettato**: codice più verboso. Compromesso volontario a favore della maintainability.

---

## Pattern riutilizzabili per altri plugin DB

Le decisioni di questo plugin che si traducono bene su altri progetti della suite:

- **Schema versionato** per option e tabelle DB con `maybe_upgrade_schema()` just-in-time
- **Dispatcher `admin-post.php` centralizzato** con sanitize per sezione e mass-assignment protection
- **Design system condiviso** via `db-admin-ui.css` e variabili `--db-*`
- **Stub render puliti** durante sviluppo incrementale invece di pagine vuote o errori
- **Hook `*_set` invece di AJAX dedicati** per eventi cross-cutting (es. `dbseo_meta_updated`)
- **Default permissivo nelle decisioni asimmetriche** (es. mostrare banner se geolocation fallisce)
- **Test funzionali standalone PHP** con stub minimal — sufficiente per coverage 90% senza overhead PHPUnit
- **Documentare rispetto al passato** nei commenti di codice (`Cambio rispetto a X: ...`)

---

*Documento aggiornato all'ultima versione 3.0.0. Le decisioni qui consolidate sono state prese durante un rebuild guidato da diagnosi precompilata della 2.0.1, in 7 step incrementali fra ottobre 2025 e maggio 2026.*
