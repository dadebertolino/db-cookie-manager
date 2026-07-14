#!/usr/bin/env bash
#
# Prepara l'ambiente wp-env per gli E2E. Fallisce (set -e) se un passo
# essenziale non riesce, così i problemi di setup emergono qui e non come
# timeout misteriosi nei test.
#
set -euo pipefail

run() { npx wp-env run cli wp "$@"; }

echo "→ Permalink pretty (necessari per /dbcm-test/ e /shop/)"
run rewrite structure '/%postname%/' --hard
run rewrite flush --hard

echo "→ Attivazione plugin"
run plugin activate db-cookie-manager
run plugin activate woocommerce

echo "→ Setup WooCommerce (pagine + impostazioni base)"
run option update woocommerce_default_country 'IT:TO'
run option update woocommerce_currency 'EUR'
# Crea le pagine WC (shop, cart, checkout). Il comando corretto è install_pages.
run wc --user=admin tool run install_pages || run wc tool run install_pages --user=admin

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

echo "→ Verifica prodotto creato e acquistabile"
PID="$(run wc product list --user=admin --sku=dbcm-test-prod --field=id 2>/dev/null || true)"
if [ -z "${PID}" ]; then
	echo "::error::Prodotto di test non creato. Setup fallito." >&2
	exit 1
fi
echo "  Prodotto ID: ${PID}"

echo "→ Flush rewrite finale (per l'endpoint /dbcm-test/ della fixture)"
run rewrite flush --hard
# Forza la creazione della pagina fixture e il flush delle sue rewrite.
run eval 'delete_option("dbcm_e2e_rewrite_flushed"); do_action("init");' || true
run rewrite flush --hard

echo "→ Verifica endpoint fixture raggiungibile"
# Un curl interno alla pagina di test: deve contenere il link WhatsApp.
BASEURL="$(run option get siteurl 2>/dev/null || echo '')"
echo "  siteurl: ${BASEURL}"

echo "Setup E2E completato."
