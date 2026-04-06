=== DB Cookie Manager ===
Contributors: davidebertolinoit
Tags: cookie, gdpr, privacy, cookie banner, consent, cookie policy, garante privacy
Requires at least: 5.9
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gestione completa dei cookie per WordPress: scansione automatica, banner GDPR con blocco preventivo, generatore Cookie Policy e registro consensi.

== Description ==

DB Cookie Manager è un plugin WordPress completo per la gestione dei cookie, conforme al GDPR e alle linee guida del Garante Privacy italiano (giugno 2021).

= Funzionalità principali =

* **Scansione automatica** — Rileva i cookie del tuo sito da HTTP headers e script (Google Analytics, Facebook Pixel, Hotjar, YouTube e altri)
* **Banner GDPR** — Tre layout (barra, card, fullscreen) con bottoni Accetta/Rifiuta/Personalizza
* **Blocco preventivo** — Blocca gli script di analisi e marketing prima del consenso dell'utente
* **5 categorie** — Necessari, Prestazioni, Analitici, Marketing, Non classificati
* **Generatore Cookie Policy** — Testo completo in italiano pronto da copiare
* **Registro consensi** — Log anonimizzato con export CSV e pulizia automatica
* **Multilingua** — IT, EN, FR, DE, ES, PT con rilevamento automatico del browser
* **Accessibile** — ARIA, focus trap, navigazione da tastiera, prefers-reduced-motion
* **Tema chiaro e scuro** — Colori completamente personalizzabili

= Requisiti =

* WordPress 5.9 o superiore
* PHP 7.4 o superiore

== Installation ==

1. Scarica il file ZIP dalla pagina GitHub Releases
2. In WordPress vai in Plugin → Aggiungi nuovo → Carica plugin
3. Seleziona il file ZIP e clicca Installa ora
4. Attiva il plugin
5. Vai in Strumenti → Cookie Manager per configurare

== Frequently Asked Questions ==

= Il plugin rallenta il sito? =

No. La scansione è asincrona e avviene solo su richiesta. Il banner aggiunge un CSS (~4KB) e un JS (~8KB).

= Funziona con qualsiasi tema? =

Sì. Il plugin è standalone e non dipende da nessun tema specifico.

= Come blocca gli script? =

Cambia `type="text/javascript"` in `type="text/plain"` sugli script noti. Quando l'utente accetta, li riattiva senza ricaricare la pagina.

= È compatibile con WPML/Polylang? =

Il plugin ha un sistema multilingua integrato indipendente. Non richiede plugin di traduzione esterni.

== Changelog ==

= 2.0.1 =
* Fix layout banner "barra": disposizione orizzontale su desktop
* Cookie WordPress core iniettati automaticamente nei risultati scansione
* Rilevamento Google Fonts alternativo via stili registrati e theme_mod
* Fix salvataggio impostazioni: checkbox non più azzerati tra sezioni diverse
* Crediti "Powered by" opzionali nel banner
* Try-catch nell'init JS per debug errori

= 2.0.0 =
* Banner cookie frontend con 3 layout e tema chiaro/scuro
* Blocco preventivo script con attivazione dinamica
* Placeholder per iframe bloccati (YouTube, Vimeo, Google Maps)
* Registro consensi con export CSV e pulizia automatica
* Sistema multilingua integrato (6 lingue)
* Categoria "Prestazioni" per cookie CDN/caching
* Accessibilità WCAG: ARIA, focus trap, keyboard navigation

= 1.0.0 =
* Scansione asincrona AJAX
* Database 50+ cookie noti
* Classificazione automatica
* Generatore Cookie Policy
* Report con modifica manuale
