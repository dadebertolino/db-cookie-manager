<?php
/**
 * DB Cookie Manager — Uninstall
 *
 * Eseguito SOLO quando l'utente disinstalla il plugin dal pannello admin di
 * WordPress (non quando lo disattiva). Cancella tutti i dati persistenti:
 *
 *  - Tabelle DB:
 *      {prefix}dbcm_cookies          (cookie scoperti dallo scanner)
 *      {prefix}dbcm_consent_log      (registro consensi con IP hashato)
 *
 *  - wp_options:
 *      tutte le righe con option_name LIKE 'dbcm_%'
 *
 *  - Transients:
 *      tutti i transient con prefix _transient_dbcm_ e _transient_timeout_dbcm_
 *      (incluso il transient del GitHub Updater dbgu_<md5(basename)>)
 *
 *  - Eventi cron:
 *      dbcm_cleanup_consent_log  (cron 2.x retrocompatibile)
 *      dbcm_daily_cleanup        (cron 3.x)
 *
 *  - User meta (precauzione futura):
 *      tutte le meta_key che iniziano con dbcm_
 *
 * Su multisite la pulizia viene eseguita per ogni sito della rete.
 *
 * @package DBCM
 */

// Sicurezza: questo file deve essere eseguito SOLO da WordPress in fase di
// uninstall (mai accessibile direttamente).
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pulisce tutti i dati di un singolo sito.
 *
 * Questa funzione viene chiamata una volta in single-site, oppure una volta
 * per ogni sub-site nel caso di una rete multisite.
 *
 * @return void
 */
function dbcm_uninstall_cleanup_site() {
	global $wpdb;

	/* ---------------------------------------------------------------------
	 * 1. Drop tabelle del plugin
	 * ------------------------------------------------------------------ */
	$tables = array(
		$wpdb->prefix . 'dbcm_cookies',
		$wpdb->prefix . 'dbcm_consent_log',
	);
	foreach ( $tables as $table ) {
		// $table è costruito da prefix + costante: nessun input utente.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}

	/* ---------------------------------------------------------------------
	 * 2. Cancella TUTTE le option che iniziano con dbcm_
	 *
	 * Usiamo una singola DELETE LIKE per evitare di dover mantenere una
	 * lista hardcoded di option keys. Se una versione futura aggiunge nuove
	 * option, vengono cancellate automaticamente purché rispettino il prefix.
	 * ------------------------------------------------------------------ */
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'dbcm_' ) . '%'
		)
	);

	/* ---------------------------------------------------------------------
	 * 3. Cancella i transient del plugin (incluso GitHub Updater)
	 *
	 * I transient sono in wp_options con prefix _transient_<name> e
	 * _transient_timeout_<name>. Ne cancelliamo due famiglie:
	 *  - dbcm_*  : transient del plugin
	 *  - dbgu_*  : transient del GitHub Updater (chiave: 'dbgu_' . md5(basename))
	 *
	 * Su site_options (multisite) usiamo lo stesso pattern con sitemeta.
	 * ------------------------------------------------------------------ */
	$transient_patterns = array( 'dbcm_', 'dbgu_' );
	foreach ( $transient_patterns as $prefix ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_' . $wpdb->esc_like( $prefix ) . '%',
				'_transient_timeout_' . $wpdb->esc_like( $prefix ) . '%'
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * 4. Cancella user meta (precauzione futura)
	 *
	 * Oggi il plugin non scrive user meta, ma se in futuro lo facesse
	 * (es. per memorizzare il consenso degli utenti loggati) questa
	 * cancellazione lo coprirebbe automaticamente.
	 * ------------------------------------------------------------------ */
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( 'dbcm_' ) . '%'
		)
	);

	/* ---------------------------------------------------------------------
	 * 5. Rimuovi gli eventi cron
	 *
	 * Sia il nome usato da 2.x (dbcm_cleanup_consent_log) sia il nuovo
	 * 3.x (dbcm_daily_cleanup) per coprire upgrade da versioni precedenti.
	 * ------------------------------------------------------------------ */
	$cron_events = array(
		'dbcm_cleanup_consent_log',
		'dbcm_daily_cleanup',
	);
	foreach ( $cron_events as $event ) {
		$timestamp = wp_next_scheduled( $event );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, $event );
			$timestamp = wp_next_scheduled( $event );
		}
		// Pulisce eventuali residui (occorrenze multiple).
		wp_clear_scheduled_hook( $event );
	}
}

/* =============================================================================
 * Esecuzione: single-site vs multisite
 * ========================================================================== */

if ( is_multisite() ) {
	// Multisite: applica la pulizia a ogni sito della rete.
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0, // tutti.
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		dbcm_uninstall_cleanup_site();
		restore_current_blog();
	}

	// Network options eventuali (oggi non usate, ma copertura preventiva).
	global $wpdb;
	if ( ! empty( $wpdb->sitemeta ) ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( 'dbcm_' ) . '%'
			)
		);
	}
} else {
	// Single site.
	dbcm_uninstall_cleanup_site();
}
