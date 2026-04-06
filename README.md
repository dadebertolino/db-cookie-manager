# DB Cookie Manager

[![WordPress](https://img.shields.io/badge/WordPress-5.9%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL%20v2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-2.0.0-orange.svg)](https://github.com/davidebertolinoit/db-cookie-manager/releases)

Gestione completa dei cookie per WordPress: scansione automatica, banner GDPR con blocco preventivo, classificazione intelligente, generatore Cookie Policy e registro consensi.

Conforme alle linee guida del Garante Privacy italiano (giugno 2021) e al Regolamento (UE) 2016/679 (GDPR).

## Funzionalità

### 🔍 Scansione automatica
- Scansione asincrona delle pagine del sito (una alla volta, nessun timeout)
- Rilevamento cookie da HTTP headers e script nel codice HTML
- Database di 50+ cookie noti con classificazione automatica
- Riconoscimento di Google Analytics, Facebook Pixel, Hotjar, YouTube, HubSpot, LinkedIn, TikTok e altri

### 🍪 Banner cookie GDPR
- Tre layout: barra, card floating, fullscreen
- Tre bottoni: Accetta tutto, Solo necessari, Personalizza
- Pannello dettagli con toggle per categoria (Necessari, Prestazioni, Analitici, Marketing)
- Lista cookie per categoria con nome, fornitore e durata
- Tema chiaro e scuro con colori personalizzabili
- Icona riapertura preferenze (🍪) sempre visibile
- Multilingua integrato (IT, EN, FR, DE, ES, PT) con rilevamento automatico della lingua del browser

### 🛡️ Blocco preventivo script
- Blocca script di analisi e marketing prima del consenso
- Due meccanismi: filtro `script_loader_tag` + output buffering
- Attivazione dinamica senza ricaricare la pagina
- Placeholder per iframe bloccati (YouTube, Vimeo, Google Maps) con bottone "Accetta e carica"
- Pattern noti preconfigurati, estensibile

### 📋 Generatore Cookie Policy
- Testo completo in italiano conforme al GDPR
- Tabelle cookie per categoria con nome, fornitore, finalità e durata
- Sezione Google Fonts e servizi esterni
- Bottone "Copia HTML" per incollare nella pagina
- Riferimenti normativi aggiornati

### 📊 Registro consensi
- Log anonimizzato di ogni consenso (hash SHA-256 dell'IP)
- Filtri per tipo e periodo, paginazione
- Statistiche: accetta tutto, solo necessari, personalizzato
- Export CSV per Excel
- Pulizia automatica configurabile (WP Cron)

### ♿ Accessibilità
- Attributi ARIA (`role="dialog"`, `aria-modal`, `aria-label`)
- Focus trap nel pannello dettagli
- Navigazione completa da tastiera (Tab, Escape)
- Focus visibile su tutti gli elementi interattivi
- Supporto `prefers-reduced-motion`

## Requisiti

- WordPress 5.9+
- PHP 7.4+

## Installazione

### Da GitHub (consigliato)
1. Scarica l'ultima release da [Releases](https://github.com/dadebertolino/db-cookie-manager/releases)
2. In WordPress vai in **Plugin → Aggiungi nuovo → Carica plugin**
3. Seleziona il file ZIP e clicca **Installa ora**
4. Attiva il plugin

### Manuale via FTP
1. Scarica e decomprimi il file ZIP
2. Carica la cartella `db-cookie-manager` in `wp-content/plugins/`
3. Attiva il plugin dal pannello **Plugin**

## Configurazione

Dopo l'attivazione, vai in **Strumenti → Cookie Manager**:

1. **Scansione** — Clicca "Avvia scansione" per rilevare i cookie del tuo sito
2. **Risultati** — Verifica e riclassifica i cookie trovati
3. **Impostazioni** — Configura aspetto, comportamento, testi e lingue del banner
4. **Genera Policy** — Copia il testo della Cookie Policy nella pagina dedicata
5. **Registro** — Monitora i consensi raccolti

## Struttura del progetto

```
db-cookie-manager/
├── db-cookie-manager.php          # File principale del plugin
├── assets/
│   ├── css/
│   │   └── banner.css             # Stili del banner frontend
│   └── js/
│       └── banner.js              # Logica frontend (consent, toggle, blocker)
├── inc/
│   ├── class-admin.php            # Pagina admin (tab, form, risultati)
│   ├── class-banner.php           # Rendering e asset del banner
│   ├── class-blocker.php          # Blocco preventivo script e iframe
│   ├── class-consent-log.php      # Registro consensi con export CSV
│   ├── class-cookie-database.php  # Database cookie noti (50+)
│   ├── class-policy-generator.php # Generatore testo Cookie Policy
│   ├── class-scanner.php          # Scanner asincrono AJAX
│   └── class-settings.php         # Impostazioni con multilingua
├── README.md
├── readme.txt                     # Formato WordPress.org
└── LICENSE
```

## Categorie cookie

| Categoria | Descrizione | Consenso |
|-----------|------------|----------|
| **Necessari** | Cookie essenziali (sessione, CSRF, consenso) | Non richiesto |
| **Prestazioni** | CDN, caching, load balancing (Cloudflare) | Richiesto |
| **Analitici** | Misurazione traffico (Google Analytics, Hotjar) | Richiesto |
| **Marketing** | Pubblicità e tracciamento (Facebook, YouTube) | Richiesto |

## Hook e filtri

Il plugin dispone di un evento JavaScript per integrazioni personalizzate:

```javascript
document.addEventListener('dbcm:consent', function(e) {
    console.log(e.detail.type);    // 'all', 'necessary', 'custom'
    console.log(e.detail.consent); // { necessary: true, performance: true, analytics: false, marketing: false }
});
```

## FAQ

**Il plugin rallenta il sito?**
No. La scansione è asincrona e avviene solo su richiesta. Il banner aggiunge un file CSS (~4KB) e un file JS (~8KB), entrambi caricati dal tuo server.

**Funziona con i page builder?**
Sì. Il plugin usa `wp_footer` e `wp_enqueue_scripts`, compatibili con qualsiasi tema e page builder.

**Come gestisce il blocco di Google Tag Manager?**
Il plugin intercetta lo script di GTM e lo blocca cambiando `type="text/javascript"` in `type="text/plain"`. Quando l'utente dà il consenso, lo script viene riattivato senza ricaricare la pagina.

**È compatibile con WPML/Polylang?**
Il plugin ha un sistema multilingua integrato indipendente. Non richiede WPML o Polylang.

**Dove vengono salvati i dati?**
Due tabelle nel database WordPress: `wp_dbcm_cookies` (risultati scansione) e `wp_dbcm_consent_log` (registro consensi). Le impostazioni sono in `wp_options`.

## Changelog

### 2.0.0
- Banner cookie frontend con 3 layout e tema chiaro/scuro
- Blocco preventivo script con attivazione dinamica
- Placeholder per iframe bloccati
- Registro consensi con export CSV e pulizia automatica
- Sistema multilingua integrato (6 lingue)
- Categoria "Prestazioni" per cookie CDN/caching
- Pagina impostazioni completa con anteprima colori live
- Accessibilità WCAG: ARIA, focus trap, keyboard navigation, reduced motion

### 1.0.0
- Scansione asincrona AJAX
- Database 50+ cookie noti
- Classificazione automatica
- Generatore Cookie Policy
- Report con modifica manuale

## Licenza

Questo plugin è rilasciato sotto licenza [GPL v2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

## Autore

**Davide "The Prof." Bertolino**
- Sito: [davidebertolino.it](https://www.davidebertolino.it)
- GitHub: [davidebertolinoit](https://github.com/davidebertolinoit)
