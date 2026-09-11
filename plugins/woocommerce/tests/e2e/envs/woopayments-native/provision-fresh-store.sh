#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
readonly PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd -P)"
readonly PNPM_BIN="${E2E_WOOPAYMENTS_FRESH_PNPM_BIN:-pnpm}"
readonly OPENSSL_BIN="${E2E_WOOPAYMENTS_FRESH_OPENSSL_BIN:-openssl}"
readonly STORE_DIR="${E2E_WOOPAYMENTS_FRESH_STORE_DIR:?E2E_WOOPAYMENTS_FRESH_STORE_DIR is required}"
readonly STORE_URL="${E2E_WOOPAYMENTS_FRESH_STORE_URL:?E2E_WOOPAYMENTS_FRESH_STORE_URL is required}"
readonly STORE_ID="${E2E_WOOPAYMENTS_FRESH_STORE_ID:?E2E_WOOPAYMENTS_FRESH_STORE_ID is required}"
readonly RUN_ID="${E2E_WOOPAYMENTS_FRESH_RUN_ID:?E2E_WOOPAYMENTS_FRESH_RUN_ID is required}"
readonly WPCOM_TRANSPORT_ADAPTER="${E2E_WOOPAYMENTS_FRESH_WPCOM_TRANSPORT_ADAPTER:?E2E_WOOPAYMENTS_FRESH_WPCOM_TRANSPORT_ADAPTER is required}"
readonly DATABASE_IDENTITY_OPTION='woocommerce_native_payments_fresh_provisioning_identity'

profile_dir=''
wp_env_config=''
receipt_path=''
run_nonce=''
database_nonce=''
transport_adapter=''
fresh_setup=''

require_create_command() {
	if [[ "$#" != '1' || "$1" != 'create' ]]; then
		echo 'Usage: provision-fresh-store.sh create' >&2
		exit 64
	fi
}

validate_identity() {
	if [[ ! "$STORE_URL" =~ ^https?://[^[:space:]]+$ ]]; then
		echo 'Fresh-store provisioner requires an HTTP(S) store URL.' >&2
		exit 64
	fi

	case "$STORE_URL" in
		*:8082* | *:8889*)
			echo 'Fresh-store provisioner refuses the shared WooPayments stores on :8082 and :8889.' >&2
			exit 64
			;;
	esac

	if [[ ! "$STORE_ID" =~ ^[a-z0-9][a-z0-9-]{0,63}$ || ! "$RUN_ID" =~ ^[a-z0-9][a-z0-9-]{0,63}$ ]]; then
		echo 'Fresh-store provisioner received an invalid store or run identity.' >&2
		exit 64
	fi

	if [[ ! -f "$WPCOM_TRANSPORT_ADAPTER" || -L "$WPCOM_TRANSPORT_ADAPTER" ]]; then
		echo 'Fresh-store provisioner requires one regular caller-supplied local WPCOM transport adapter.' >&2
		exit 64
	fi
	transport_adapter="$(cd "$(dirname "$WPCOM_TRANSPORT_ADAPTER")" && pwd -P)/$(basename "$WPCOM_TRANSPORT_ADAPTER")"
}

create_unused_profile() {
	local profile_parent
	local profile_basename

	profile_parent="$(cd "$(dirname "$STORE_DIR")" && pwd -P)"
	profile_basename="$(basename "$STORE_DIR")"
	if [[ "$profile_basename" == '.' || "$profile_basename" == '..' || -L "$STORE_DIR" || -e "$STORE_DIR" ]]; then
		echo 'Fresh-store provisioner is refusing reuse of an existing or unsafe profile.' >&2
		exit 64
	fi

	profile_dir="$profile_parent/$profile_basename"
	if ! mkdir "$profile_dir"; then
		echo 'Fresh-store provisioner is refusing reuse of an existing profile.' >&2
		exit 64
	fi

	wp_env_config="$profile_dir/.wp-env.json"
	receipt_path="$profile_dir/provisioning-receipt.json"
}

generate_nonce() {
	local nonce

	nonce="$("$OPENSSL_BIN" rand -hex 32)"
	if [[ ! "$nonce" =~ ^[a-f0-9]{64}$ ]]; then
		echo 'Fresh-store provisioner could not generate a valid random nonce.' >&2
		exit 1
	fi

	printf '%s\n' "$nonce"
}

write_profile_config() {
	jq -cn \
		--arg plugin_root "$PLUGIN_ROOT" \
		--arg store_url "$STORE_URL" \
		--arg transport_adapter "$transport_adapter" '
		{
			core: "https://wordpress.org/wordpress-latest.zip",
			phpVersion: "8.1",
			testsEnvironment: false,
			port: ($store_url | capture( ":(?<port>[0-9]+)$" ).port | tonumber),
			plugins: [ $plugin_root ],
			config: {
				WP_HOME: $store_url,
				WP_SITEURL: $store_url,
				E2E_WOOPAYMENTS_NATIVE: true,
				JETPACK_DEV_DEBUG: false,
				WP_DEBUG: false,
				WP_DEBUG_LOG: true,
				WP_DEBUG_DISPLAY: false,
				WP_MEMORY_LIMIT: "512M",
				WP_MAX_MEMORY_LIMIT: "512M",
				ALTERNATE_WP_CRON: false
			},
			mappings: {
				"wp-content/plugins/woocommerce": $plugin_root,
				"wp-content/plugins/e2e-test-bin": ($plugin_root + "/tests/e2e/bin"),
				"wp-content/mu-plugins/woopayments-native-runtime.php": ($plugin_root + "/tests/e2e/test-plugins/woopayments-native-runtime/woopayments-native-runtime.php"),
				"wp-content/mu-plugins/wpcom-local-store-transport.php": $transport_adapter
			}
		}' > "$wp_env_config"
}

extract_json_object() {
	local output="$1"
	local json

	json="$(printf '%s\n' "$output" | sed -n 's/^[^{]*\({.*}\)[^}]*$/\1/p' | tail -n 1)"
	if ! printf '%s\n' "$json" | jq -ce 'select(type == "object")'; then
		echo 'Fresh-store provisioner could not read the live database identity.' >&2
		return 1
	fi
}

seed_database_identity() {
	local identity
	local seed_code
	local live_identity

	identity="$(jq -cn --arg store_id "$STORE_ID" --arg run_id "$RUN_ID" --arg run_nonce "$run_nonce" --arg database_nonce "$database_nonce" '{ store_id: $store_id, run_id: $run_id, run_nonce: $run_nonce, database_nonce: $database_nonce }')"
	seed_code='$identity = json_decode( '\''"$identity"'\'', true, 512, JSON_THROW_ON_ERROR ); if ( false !== get_option( "woocommerce_native_payments_fresh_provisioning_identity", false ) ) { throw new RuntimeException( "Fresh-store database identity already exists." ); } if ( ! add_option( "woocommerce_native_payments_fresh_provisioning_identity", $identity, "", false ) ) { throw new RuntimeException( "Fresh-store database identity could not be stored." ); } $stored = get_option( "woocommerce_native_payments_fresh_provisioning_identity", false ); if ( $stored !== $identity ) { throw new RuntimeException( "Fresh-store database identity did not persist exactly." ); } echo wp_json_encode( $stored );'

	if ! live_identity="$("$PNPM_BIN" --dir "$PLUGIN_ROOT" exec wp-env --config "$wp_env_config" run cli wp --user=1 eval "$seed_code")"; then
		echo 'Fresh-store provisioner could not seed the live database identity.' >&2
		return 1
	fi

	extract_json_object "$live_identity" > /dev/null
	if ! jq -e --argjson expected "$identity" '$expected == .' <<< "$(extract_json_object "$live_identity")" > /dev/null; then
		echo 'Fresh-store provisioner seeded a database identity different from its receipt identity.' >&2
		return 1
	fi
}

setup_fresh_store() {
	local setup_input
	local setup_code
	local setup_output

	setup_input="$(jq -cn --arg run_id "$RUN_ID" '{ customer_login: ("fresh-native-" + $run_id + "-customer"), customer_email: ("fresh-native-" + $run_id + "-customer@example.test"), product_sku: ("fresh-native-" + $run_id + "-product") }')"
	setup_code='$setup = json_decode( '\''"$setup_input"'\'', true, 512, JSON_THROW_ON_ERROR ); \WC_Install::create_pages(); $product_id = wc_get_product_id_by_sku( $setup["product_sku"] ); if ( ! $product_id ) { $product = new WC_Product_Simple(); $product->set_name( "Fresh native onboarding product" ); $product->set_regular_price( "10.00" ); $product->set_sku( $setup["product_sku"] ); $product_id = $product->save(); } $customer = get_user_by( "login", $setup["customer_login"] ); if ( ! $customer ) { $customer_id = wp_insert_user( array( "user_login" => $setup["customer_login"], "user_email" => $setup["customer_email"], "user_pass" => "fresh-native-e2e-password", "role" => "customer" ) ); if ( is_wp_error( $customer_id ) ) { throw new RuntimeException( $customer_id->get_error_message() ); } } else { $customer_id = $customer->ID; } echo wp_json_encode( array( "pages_installed" => true, "product_id" => (int) $product_id, "customer_id" => (int) $customer_id ) );'

	if ! setup_output="$("$PNPM_BIN" --dir "$PLUGIN_ROOT" exec wp-env --config "$wp_env_config" run cli wp --user=1 eval "$setup_code")"; then
		echo 'Fresh-store provisioner could not create fresh-store prerequisites.' >&2
		return 1
	fi

	fresh_setup="$(extract_json_object "$setup_output")"
	if ! jq -e '.pages_installed == true and (.product_id | type == "number" and . > 0) and (.customer_id | type == "number" and . > 0)' <<< "$fresh_setup" > /dev/null; then
		echo 'Fresh-store provisioner could not verify fresh-store prerequisites.' >&2
		return 1
	fi
}

write_receipt() {
	jq -cn \
		--arg profile_path "$profile_dir" \
		--arg wp_env_config "$wp_env_config" \
		--arg store_url "$STORE_URL" \
		--arg store_id "$STORE_ID" \
		--arg run_id "$RUN_ID" \
		--arg run_nonce "$run_nonce" \
		--arg database_nonce "$database_nonce" \
		--arg transport_adapter "$transport_adapter" \
		--argjson fresh_setup "$fresh_setup" \
		'{ schema_version: 2, provisioner: "woocommerce-native-fresh-store", fresh: true, profile_path: $profile_path, wp_env_config: $wp_env_config, store_url: $store_url, store_id: $store_id, run_id: $run_id, run_nonce: $run_nonce, database_nonce: $database_nonce, transport_adapter: $transport_adapter, fresh_setup: $fresh_setup }' > "$receipt_path"
}

require_create_command "$@"
validate_identity
create_unused_profile
run_nonce="$(generate_nonce)"
database_nonce="$(generate_nonce)"
write_profile_config

"$PNPM_BIN" --dir "$PLUGIN_ROOT" exec wp-env --config "$wp_env_config" start >&2
seed_database_identity
setup_fresh_store
write_receipt

jq -cn \
	--arg profile_path "$profile_dir" \
	--arg wp_env_config "$wp_env_config" \
	--arg provisioning_receipt "$receipt_path" \
	--arg store_url "$STORE_URL" \
	--arg store_id "$STORE_ID" \
	--arg run_id "$RUN_ID" \
	--arg run_nonce "$run_nonce" \
	--arg database_nonce "$database_nonce" \
	--arg transport_adapter "$transport_adapter" \
	--argjson fresh_setup "$fresh_setup" \
	'{ profile_path: $profile_path, wp_env_config: $wp_env_config, provisioning_receipt: $provisioning_receipt, store_url: $store_url, store_id: $store_id, run_id: $run_id, run_nonce: $run_nonce, database_nonce: $database_nonce, transport_adapter: $transport_adapter, fresh_setup: $fresh_setup }'
