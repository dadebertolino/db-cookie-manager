<?php
/**
 * DBCM_Declared_Services — Registro dei servizi dichiarati (3.6.0).
 *
 * PROBLEMA STRUTTURALE che questa classe risolve: lo scanner acquisisce le
 * pagine via self-request HTTP, quindi l'HTML che analizza è GIÀ passato dal
 * blocker — gli embed gated (YouTube, Maps, ...) sono già stati riscritti in
 * placeholder e le scan_signature non matchano più. Più il blocco funziona,
 * più la Cookie Policy generata è incompleta: elenca solo i cookie tecnici
 * e non giustifica l'esistenza del banner (incoerenza documentale rilevata
 * in audit su caso reale).
 *
 * SOLUZIONE: un registro di servizi "attivi previo consenso", con due fonti:
 *
 *  1. AUTO — registrazione al momento del blocco: quando il buffer del
 *     blocker matcha e riscrive un iframe/script, ha in mano la src; qui la
 *     risolviamo in una firma (DBCM_Signatures::identify_url) e registriamo
 *     { slug, last_seen }. Evidenza EMPIRICA dell'uso reale: niente
 *     sovra-dichiarazione dei 42 servizi bundled su siti che non li usano
 *     (Art. 5(1)(a): la policy deve riflettere i trattamenti effettivi).
 *     Bonus: la self-request dello scanner passa dal blocker, quindi
 *     lanciare una scansione popola il registro come effetto collaterale —
 *     la circolarità è risolta senza alcun bypass del blocco (un bypass
 *     sarebbe spoofabile = elusione del consenso).
 *
 *  2. MANUAL — voci aggiunte dall'admin per i casi residuali non coperti
 *     dalle firme (UI dedicata). Non scadono mai.
 *
 * STALENESS: le voci auto entrano in policy solo se viste negli ultimi N
 * giorni (default 30, filtro 'dbcm_declared_services_ttl'): embed rimosso
 * dal sito → la voce decade da sola alla rigenerazione.
 *
 * FONTE DI VERITÀ: per le voci auto si salva SOLO { slug, last_seen }; tutti
 * i dettagli (fornitore, cookie tipici, durate, informativa, categoria)
 * vengono idratati dal DB firme al momento della generazione — un
 * aggiornamento delle firme aggiorna la policy senza migrazioni.
 *
 * INTEGRAZIONE DB PRIVACY HUB: il registro è esposto via filtro
 * 'dbcm_declared_services_register' (consumato da DBPH >= 1.4.0 per la
 * sezione "Contenuti incorporati" della Privacy Policy — fonte unica).
 *
 * @package DBCM
 * @since 3.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBCM_Declared_Services' ) ) {

	class DBCM_Declared_Services {

		/**
		 * Option del registro.
		 * Struttura:
		 * array(
		 *   'auto'   => array( slug => array( 'last_seen' => 'Y-m-d' ) ),
		 *   'manual' => array( id => array( service, provider, category,
		 *                                   policy_url, cookies_text, duration_text ) ),
		 * )
		 */
		const OPTION = 'dbcm_declared_services';

		/**
		 * TTL di default (giorni) per le voci auto.
		 */
		const DEFAULT_TTL_DAYS = 30;

		/**
		 * Cache per-request degli slug già registrati in questa richiesta:
		 * il buffer può matchare lo stesso servizio molte volte per pagina
		 * (es. 10 video YouTube) — una sola risoluzione/scrittura basta.
		 *
		 * @var array<string,bool>
		 */
		private static $seen_this_request = array();

		/* =====================================================================
		 * REGISTRAZIONE AUTO (chiamata dal blocker al momento del match)
		 * ================================================================== */

		/**
		 * Registra il servizio corrispondente a un URL bloccato/gated.
		 *
		 * Chiamata dal blocker DOPO il match del pattern e PRIMA del check di
		 * consenso: il servizio è "in uso sul sito" a prescindere dallo stato
		 * del consenso del singolo visitatore.
		 *
		 * Throttling a due livelli per non martellare la option a ogni
		 * pageview: cache per-request + al massimo una scrittura per
		 * servizio al giorno (last_seen a granularità giornaliera).
		 *
		 * @param string $url Src dello script/iframe matchato.
		 * @return void
		 */
		public static function record_from_url( $url ) {
			$url = (string) $url;
			if ( '' === $url ) {
				return;
			}

			$sig = DBCM_Signatures::identify_url( $url );
			if ( null === $sig || empty( $sig['requires_consent'] ) ) {
				// URL non riconducibile a una firma (pattern del blocker non
				// mappato) o servizio esente da consenso: niente da
				// dichiarare qui. I casi non coperti si aggiungono a mano.
				return;
			}

			$slug = $sig['slug'];
			if ( isset( self::$seen_this_request[ $slug ] ) ) {
				return;
			}
			self::$seen_this_request[ $slug ] = true;

			$today = gmdate( 'Y-m-d' );
			$data  = self::read();
			if ( isset( $data['auto'][ $slug ]['last_seen'] ) && $data['auto'][ $slug ]['last_seen'] === $today ) {
				return; // Già registrato oggi: nessuna scrittura.
			}

			$data['auto'][ $slug ] = array( 'last_seen' => $today );
			update_option( self::OPTION, $data, false );
		}

		/**
		 * Reset della cache per-request (per i test).
		 *
		 * @return void
		 */
		public static function reset_request_cache() {
			self::$seen_this_request = array();
		}

		/* =====================================================================
		 * LETTURA / VOCI
		 * ================================================================== */

		/**
		 * Legge la option normalizzando la struttura.
		 *
		 * @return array{auto:array,manual:array}
		 */
		private static function read() {
			$data = get_option( self::OPTION, array() );
			if ( ! is_array( $data ) ) {
				$data = array();
			}
			return array(
				'auto'   => isset( $data['auto'] ) && is_array( $data['auto'] ) ? $data['auto'] : array(),
				'manual' => isset( $data['manual'] ) && is_array( $data['manual'] ) ? $data['manual'] : array(),
			);
		}

		/**
		 * TTL effettivo in giorni per le voci auto.
		 *
		 * @return int
		 */
		public static function ttl_days() {
			/**
			 * Filtra la finestra di validità (giorni) delle voci auto del
			 * registro servizi dichiarati.
			 *
			 * @since 3.6.0
			 * @param int $days Default 30.
			 */
			return max( 1, (int) apply_filters( 'dbcm_declared_services_ttl', self::DEFAULT_TTL_DAYS ) );
		}

		/**
		 * Voci AUTO valide (dentro il TTL), idratate dal DB firme.
		 *
		 * Slug la cui firma non esiste più (es. firma custom eliminata)
		 * vengono ignorati: decadono naturalmente.
		 *
		 * @return array<int,array> Voci normalizzate (vedi normalize_entry).
		 */
		public static function auto_entries() {
			$data   = self::read();
			$cutoff = gmdate( 'Y-m-d', time() - self::ttl_days() * DAY_IN_SECONDS );
			$out    = array();

			foreach ( $data['auto'] as $slug => $meta ) {
				$last_seen = isset( $meta['last_seen'] ) ? (string) $meta['last_seen'] : '';
				if ( '' === $last_seen || $last_seen < $cutoff ) {
					continue; // Fuori finestra: l'embed non risulta più in uso.
				}
				$all = DBCM_Signatures::all();
				if ( ! isset( $all[ $slug ] ) ) {
					continue;
				}
				$sig = $all[ $slug ];
				if ( empty( $sig['requires_consent'] ) ) {
					continue;
				}

				$cookies  = array();
				$duration = array();
				foreach ( ( isset( $sig['cookies'] ) ? (array) $sig['cookies'] : array() ) as $cookie ) {
					if ( ! empty( $cookie['name'] ) ) {
						$cookies[] = $cookie['name'];
					}
					if ( ! empty( $cookie['duration'] ) ) {
						$duration[] = $cookie['duration'];
					}
				}

				$out[] = self::normalize_entry(
					array(
						'id'            => 'auto:' . $slug,
						'origin'        => 'auto',
						'slug'          => $slug,
						'service'       => isset( $sig['service'] ) ? $sig['service'] : $slug,
						'provider'      => isset( $sig['provider'] ) ? $sig['provider'] : '',
						'category'      => isset( $sig['category'] ) ? $sig['category'] : 'marketing',
						'policy_url'    => isset( $sig['privacy_url'] ) ? $sig['privacy_url'] : '',
						'cookies_text'  => implode( ', ', array_unique( $cookies ) ),
						'duration_text' => implode( ' / ', array_unique( $duration ) ),
						'last_seen'     => $last_seen,
					)
				);
			}
			return $out;
		}

		/**
		 * Voci MANUALI normalizzate.
		 *
		 * @return array<int,array>
		 */
		public static function manual_entries() {
			$data = self::read();
			$out  = array();
			foreach ( $data['manual'] as $id => $entry ) {
				$entry['id']     = (string) $id;
				$entry['origin'] = 'manual';
				$out[]           = self::normalize_entry( $entry );
			}
			return $out;
		}

		/**
		 * Normalizza una voce alle chiavi standard.
		 *
		 * @param array $entry
		 * @return array
		 */
		private static function normalize_entry( $entry ) {
			$category = isset( $entry['category'] ) ? (string) $entry['category'] : 'marketing';
			if ( ! DBCM_Settings::is_valid_category( $category ) || 'functional' === $category ) {
				// Le voci dichiarate rappresentano servizi PREVIO CONSENSO:
				// 'functional' non ha senso qui. Fallback conservativo.
				$category = 'marketing';
			}
			return array(
				'id'            => isset( $entry['id'] ) ? (string) $entry['id'] : '',
				'origin'        => ( isset( $entry['origin'] ) && 'manual' === $entry['origin'] ) ? 'manual' : 'auto',
				'slug'          => isset( $entry['slug'] ) ? (string) $entry['slug'] : '',
				'service'       => isset( $entry['service'] ) ? (string) $entry['service'] : '',
				'provider'      => isset( $entry['provider'] ) ? (string) $entry['provider'] : '',
				'category'      => $category,
				'policy_url'    => isset( $entry['policy_url'] ) ? (string) $entry['policy_url'] : '',
				'cookies_text'  => isset( $entry['cookies_text'] ) ? (string) $entry['cookies_text'] : '',
				'duration_text' => isset( $entry['duration_text'] ) ? (string) $entry['duration_text'] : '',
				'last_seen'     => isset( $entry['last_seen'] ) ? (string) $entry['last_seen'] : '',
			);
		}

		/**
		 * Registro completo per la generazione della policy, raggruppato per
		 * categoria. Auto (dentro TTL) + manuali; deduplicato per servizio
		 * (una voce manuale con lo stesso slug/nome di una auto vince,
		 * perché l'admin può aver rifinito i dati).
		 *
		 * @return array<string,array<int,array>> categoria => voci.
		 */
		public static function grouped_for_policy() {
			$entries = array();
			$seen    = array();

			foreach ( self::manual_entries() as $entry ) {
				$key          = strtolower( '' !== $entry['slug'] ? $entry['slug'] : $entry['service'] );
				$seen[ $key ] = true;
				$entries[]    = $entry;
			}
			foreach ( self::auto_entries() as $entry ) {
				$key = strtolower( $entry['slug'] );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$entries[] = $entry;
			}

			$grouped = array();
			foreach ( $entries as $entry ) {
				$grouped[ $entry['category'] ][] = $entry;
			}
			ksort( $grouped );

			/**
			 * Espone il registro dei servizi dichiarati.
			 *
			 * Consumato da DB Privacy Hub (>= 1.4.0) per la sezione
			 * "Contenuti incorporati da piattaforme terze" della Privacy
			 * Policy: fonte unica di verità, niente doppia manutenzione.
			 *
			 * @since 3.6.0
			 * @param array $grouped categoria => voci normalizzate.
			 */
			return apply_filters( 'dbcm_declared_services_register', $grouped );
		}

		/* =====================================================================
		 * VOCI MANUALI — CRUD (usato dalla pagina admin)
		 * ================================================================== */

		/**
		 * Salva una voce manuale. Ritorna l'id o WP_Error-like false.
		 *
		 * @param array $raw Campi grezzi dal form.
		 * @return string|false Id della voce, o false se dati insufficienti.
		 */
		public static function save_manual( $raw ) {
			$raw     = is_array( $raw ) ? $raw : array();
			$service = isset( $raw['service'] ) ? sanitize_text_field( $raw['service'] ) : '';

			// Modalità pick-list: se arriva uno slug di firma nota, i dati
			// mancanti vengono precompilati dalla firma (un click, zero
			// digitazione). I campi compilati a mano hanno la precedenza.
			$slug = isset( $raw['slug'] ) ? sanitize_key( $raw['slug'] ) : '';
			$sig  = null;
			if ( '' !== $slug ) {
				$all = DBCM_Signatures::all();
				$sig = isset( $all[ $slug ] ) ? $all[ $slug ] : null;
			}
			if ( '' === $service && $sig ) {
				$service = $sig['service'];
			}
			if ( '' === $service ) {
				return false; // Il nome del servizio è l'unico campo davvero obbligatorio.
			}

			$cookies_text  = '';
			$duration_text = '';
			if ( $sig ) {
				$names = array();
				$durs  = array();
				foreach ( ( isset( $sig['cookies'] ) ? (array) $sig['cookies'] : array() ) as $cookie ) {
					if ( ! empty( $cookie['name'] ) ) {
						$names[] = $cookie['name'];
					}
					if ( ! empty( $cookie['duration'] ) ) {
						$durs[] = $cookie['duration'];
					}
				}
				$cookies_text  = implode( ', ', array_unique( $names ) );
				$duration_text = implode( ' / ', array_unique( $durs ) );
			}

			$entry = array(
				'slug'          => $slug,
				'service'       => $service,
				'provider'      => isset( $raw['provider'] ) && '' !== $raw['provider']
					? sanitize_text_field( $raw['provider'] )
					: ( $sig && isset( $sig['provider'] ) ? $sig['provider'] : '' ),
				'category'      => isset( $raw['category'] ) ? sanitize_key( $raw['category'] ) : '',
				'policy_url'    => isset( $raw['policy_url'] ) && '' !== $raw['policy_url']
					? esc_url_raw( $raw['policy_url'] )
					: ( $sig && isset( $sig['privacy_url'] ) ? $sig['privacy_url'] : '' ),
				'cookies_text'  => isset( $raw['cookies_text'] ) && '' !== $raw['cookies_text']
					? sanitize_text_field( $raw['cookies_text'] )
					: $cookies_text,
				'duration_text' => isset( $raw['duration_text'] ) && '' !== $raw['duration_text']
					? sanitize_text_field( $raw['duration_text'] )
					: $duration_text,
			);
			if ( '' === $entry['category'] && $sig && isset( $sig['category'] ) ) {
				$entry['category'] = $sig['category'];
			}

			$data = self::read();
			$id   = 'm' . substr( md5( strtolower( $service ) . '|' . $slug ), 0, 10 );

			$data['manual'][ $id ] = $entry;
			update_option( self::OPTION, $data, false );
			return $id;
		}

		/**
		 * Elimina una voce manuale.
		 *
		 * @param string $id
		 * @return bool True se esisteva.
		 */
		public static function delete_manual( $id ) {
			$id   = (string) $id;
			$data = self::read();
			if ( ! isset( $data['manual'][ $id ] ) ) {
				return false;
			}
			unset( $data['manual'][ $id ] );
			update_option( self::OPTION, $data, false );
			return true;
		}

		/* =====================================================================
		 * VALIDAZIONE DI COERENZA banner ↔ policy
		 * ================================================================== */

		/**
		 * Categorie opzionali richieste nel banner ma prive sia di cookie
		 * rilevati sia di servizi dichiarati.
		 *
		 * Doppia criticità coperta: (a) servizio attivo non documentato in
		 * policy (il caso audit), (b) categoria vuota nel banner = richiesta
		 * di consenso ingiustificata, anch'essa censurabile.
		 *
		 * @return array<int,string> Slug delle categorie scoperte.
		 */
		public static function coherence_warnings() {
			$declared = self::grouped_for_policy();

			$detected = array();
			if ( class_exists( 'DBCM_Scanner' ) && method_exists( 'DBCM_Scanner', 'count_by_category' ) ) {
				$detected = (array) DBCM_Scanner::count_by_category();
			}

			$uncovered = array();
			foreach ( DBCM_Settings::categories_optional() as $category ) {
				$has_detected = ! empty( $detected[ $category ] );
				$has_declared = ! empty( $declared[ $category ] );
				if ( ! $has_detected && ! $has_declared ) {
					$uncovered[] = $category;
				}
			}
			return $uncovered;
		}
	}
}
