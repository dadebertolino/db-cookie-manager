#!/usr/bin/env bash
#
# Prepara l'ambiente wp-env per gli E2E:
#  - permalink "pretty" (necessari per gli URL /dbcm-test/ e /shop/)
#  - installazione base di WooCommerce (pagine, nessuna guida onboarding)
#  - un prodotto acquistabile per il test del carrello
#  - attivazione esplicita dei plugin
#
# wp-cli gira nel container 'cli' di wp-env. Tutti i comandi passano da
# `wp-env run cli wp ...`.
#
set -euo pipefail

run() { npx wp-env run cli wp "$@"; }

echo "→ Permalink pretty"
run rewrite structure '/%postname%/' --hard
run rewrite flush --hard

echo "→ Attivazione plugin"
run plugin activate db-cookie-manager || true
run plugin activate woocommerce || true

echo "→ Setup WooCommerce (pagine + impostazioni base)"
# Crea le pagine WC (shop, cart, checkout, my-account) se assenti.
run wc --user=admin tool run install_pages || true
# Valuta store: paese, valuta, nessun onboarding.
run option update woocommerce_default_country 'IT:TO'
run option update woocommerce_currency 'EUR'
run option update woocommerce_onboarding_profile '{"completed":true}' --format=json || true

echo "→ Prodotto di test acquistabile"
# Se non esiste già un prodotto con SKU dbcm-test-prod, crealo.
if ! run wc product list --user=admin --sku=dbcm-test-prod --field=id 2>/dev/null | grep -q .; then
	run wc product create --user=admin \
		--name='Prodotto Test DBCM' \
		--type=simple \
		--regular_price='9.99' \
		--sku='dbcm-test-prod' \
		--manage_stock=false \
		--status=publish
fi

echo "→ Assicura la pagina fixture 'dbcm-test'"
# La crea il mu-plugin su init; forziamo un hit per triggerarlo.
run eval 'do_action("init");' || true

echo "Setup E2E completato."
