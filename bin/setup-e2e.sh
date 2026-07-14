#!/usr/bin/env bash
#
# Prepara l'ambiente wp-env per gli E2E. Fallisce (set -e) sui passi essenziali.
#
set -euo pipefail

run() { npx wp-env run cli wp "$@"; }

echo "→ Permalink pretty (necessari per /shop/ e i prodotti)"
run rewrite structure '/%postname%/' --hard
run rewrite flush --hard

echo "→ DBCM attivo"
run plugin activate db-cookie-manager || true

echo "→ WooCommerce: installa se assente, poi attiva"
# Non ci affidiamo al download automatico di wp-env: lo installiamo qui in modo
# deterministico. Se è già presente, --activate non fa danni.
if ! run plugin is-installed woocommerce 2>/dev/null; then
	echo "  WooCommerce non installato: lo scarico da wordpress.org"
	run plugin install woocommerce --activate
else
	run plugin activate woocommerce || true
fi

# Verifica che ora sia attivo, altrimenti fermati con un messaggio chiaro.
if ! run plugin is-active woocommerce 2>/dev/null; then
	echo "::error::WooCommerce non attivo dopo l'installazione. Setup fallito." >&2
	run plugin list --status=active
	exit 1
fi
echo "  WooCommerce attivo."

echo "→ Impostazioni base WooCommerce"
run option update woocommerce_default_country 'IT:TO'
run option update woocommerce_currency 'EUR'
run wc --user=admin tool run install_pages || run wc tool run install_pages --user=admin || true

echo "→ Prodotto di test acquistabile (SKU dbcm-test-prod)"
EXISTING="$(run wc product list --user=admin --sku=dbcm-test-prod --field=id 2>/dev/null || true)"
if [ -z "${EXISTING}" ]; then
	run wc product create --user=admin \
		--name='Prodotto Test DBCM' \
		--slug='prodotto-test-dbcm' \
		--type=simple \
		--regular_price='9.99' \
		--sku='dbcm-test-prod' \
		--manage_stock=false \
		--status=publish
fi

PID="$(run wc product list --user=admin --sku=dbcm-test-prod --field=id 2>/dev/null || true)"
if [ -z "${PID}" ]; then
	echo "::error::Prodotto di test non creato. Setup fallito." >&2
	exit 1
fi
echo "  Prodotto ID: ${PID}"

echo "→ Flush rewrite finale + endpoint fixture"
run rewrite flush --hard
run eval 'delete_option("dbcm_e2e_rewrite_flushed"); do_action("init");' || true
run rewrite flush --hard

echo "Setup E2E completato."
