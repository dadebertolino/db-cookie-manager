<?php
/**
 * DBCM_Shortcode — Shortcode [dbcm_preferences].
 *
 * Permette di inserire un pulsante "Modifica preferenze cookie" in
 * qualsiasi pagina/post/widget WP. Click apre il modal preferenze del
 * banner via window.DBCM.openPreferences() (API esposta dal banner.js).
 *
 * Attributi:
 *   label = testo del pulsante (default: "Modifica preferenze cookie")
 *   class = classi CSS extra
 *   style = "button" (default) | "link" — solo styling
 *   id    = ID HTML custom
 *
 * Esempi:
 *   [dbcm_preferences]
 *   [dbcm_preferences label="Cookie" style="link"]
 *   [dbcm_preferences class="my-custom-btn" id="footer-cookie-btn"]
 *
 * Inserito anche nel footer automaticamente solo se l'admin ha attivato
 * `show_reopen_btn` (gestito dal banner.js, non da qui — questo shortcode
 * è solo per il posizionamento manuale).
 *
 * @package DBCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBCM_Shortcode' ) ) {

	class DBCM_Shortcode {

		const TAG = 'dbcm_preferences';

		public static function init() {
			add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
		}

		/**
		 * Render dello shortcode.
		 *
		 * @param array  $atts
		 * @param string $content
		 * @return string
		 */
		public static function render( $atts = array(), $content = '' ) {
			$atts = shortcode_atts(
				array(
					'label' => __( 'Modifica preferenze cookie', 'db-cookie-manager' ),
					'class' => '',
					'style' => 'button',
					'id'    => '',
				),
				$atts,
				self::TAG
			);

			// Sanitizzazione di tutto l'input utente.
			$label = sanitize_text_field( $atts['label'] );
			$class = sanitize_html_class( $atts['class'] ); // sanitize_html_class accetta solo singola classe
			// Per più classi facciamo manualmente: split su spazi + sanitize ognuna.
			$classes = array();
			if ( ! empty( $atts['class'] ) ) {
				foreach ( preg_split( '/\s+/', (string) $atts['class'] ) as $c ) {
					$c = sanitize_html_class( $c );
					if ( '' !== $c ) {
						$classes[] = $c;
					}
				}
			}
			$id = sanitize_html_class( $atts['id'] );

			// Style = "link" → render come <a>; default "button" → <button>.
			$is_link = ( 'link' === $atts['style'] );

			// Classe base.
			$base_class = $is_link ? 'dbcm-prefs-link' : 'dbcm-prefs-btn';
			$classes    = array_merge( array( $base_class ), $classes );

			// onclick inline che apre il modal preferenze. È più semplice
			// di un addEventListener separato — funziona anche se gli script
			// del banner non sono ancora pronti (window.DBCM è disponibile
			// dal momento in cui banner.js parte, prima del DOMContentLoaded
			// per il footer).
			$onclick = "if(window.DBCM&&window.DBCM.openPreferences){window.DBCM.openPreferences();}return false;";

			$attrs = sprintf(
				'class="%s"%s onclick="%s"',
				esc_attr( implode( ' ', $classes ) ),
				$id ? ' id="' . esc_attr( $id ) . '"' : '',
				esc_attr( $onclick )
			);

			if ( $is_link ) {
				return sprintf(
					'<a href="#" %s>%s</a>',
					$attrs,
					esc_html( $label )
				);
			}

			return sprintf(
				'<button type="button" %s>%s</button>',
				$attrs,
				esc_html( $label )
			);
		}
	}
}
