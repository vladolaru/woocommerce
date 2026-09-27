<?php
/**
 * Plugin Name: WooPayments transition seed
 * Description: WP-CLI seed/reset for the T.4 Batch T transition rows. Installs a pinned WooPayments zip and a donor-borrowed Jetpack/account connection read from a git-ignored `connection.local.json` beside this file (export command in `data/t4-transition-seed.md` 4d). Never commit that file.
 *
 * @package woopayments-transition-seed
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/** Registers `wp woopayments-e2e-transition {reset,seed,seed_finish}`. */
final class WooPayments_Transition_Seed_CLI {

	/**
	 * Pinned WooPayments release zips, checked by SHA-256 before install.
	 *
	 * @var array<string,array{url:string,sha256:string}>
	 */
	private const PINS = array(
		'10.4.0' => array(
			'url'    => 'https://github.com/Automattic/woocommerce-payments/releases/download/10.4.0/woocommerce-payments.zip',
			'sha256' => '976453a6608249930dfdf5aa52aa8dedc4021e33349b015737aec34867fb5db1',
		),
		'10.5.0' => array(
			'url'    => 'https://github.com/Automattic/woocommerce-payments/releases/download/10.5.0/woocommerce-payments.zip',
			'sha256' => '2653ea0572d36b10f5fde16ed34a840db07e0e682b62035235008f7234725442',
		),
		'11.1.0' => array(
			'url'    => 'https://github.com/Automattic/woocommerce-payments/releases/download/11.1.0/woocommerce-payments.zip',
			'sha256' => 'd655a77f24f638a3a57edbfd192c6d9e1196970b23320d289a48fc443ca7ffb1',
		),
	);

	/** Registers the command. */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'woopayments-e2e-transition', self::class );
		}
	}

	/**
	 * Resets the store to a bare, plugin-inactive baseline. A full DB reset
	 * replaces undoing options one by one: the cutover deletes plugin options
	 * and deactivates the plugin, so only a reset reliably undoes a profile.
	 */
	public function reset(): void {
		WP_CLI::runcommand( 'db reset --yes' );
		WP_CLI::runcommand( 'core install --url=' . escapeshellarg( home_url() ) . ' --title="WooPayments transition seed" --admin_user=admin --admin_password=password --admin_email=admin@example.com --skip-email' );
		// "master" is the Basic-Auth plugin's wp-env-derived slug (the GitHub
		// archive URL's basename), needed for the shared restApi fixture.
		WP_CLI::runcommand( 'plugin activate woocommerce woopayments-transition-seed master woocommerce-e2e-test-helper' );
		// Pretty permalinks are required for the shared restApi fixture's
		// /wp-json/ paths; a plain-permalink install serves ?rest_route= only.
		file_put_contents( ABSPATH . 'wp-cli.yml', "apache_modules:\n  - mod_rewrite\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		WP_CLI::runcommand( 'rewrite structure "/%postname%/" --hard' );
		WP_CLI::success( 'Transition store reset.' );
	}

	/**
	 * Seeds one transition profile.
	 *
	 * ## OPTIONS
	 *
	 * <profile>
	 * : One of cutover, historical-tokens, historical-money-records.
	 *
	 * [--version=<version>]
	 * : Pinned WooPayments version to install. Default 10.5.0.
	 *
	 * [--pending-migrator]
	 * : Schedule a pending wcpay_migrate_subscription_retry action (cutover only).
	 *
	 * [--ctp=<on|off>]
	 * : Card-testing protection eligibility (historical-money-records only).
	 *
	 * @param array<int,string>    $args Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function seed( array $args, array $assoc_args ): void {
		$profile = $args[0] ?? '';
		if ( ! in_array( $profile, array( 'cutover', 'historical-tokens', 'historical-money-records' ), true ) ) {
			WP_CLI::error( 'profile must be one of: cutover, historical-tokens, historical-money-records.' );
		}
		$version = $assoc_args['version'] ?? '10.5.0';
		if ( ! isset( self::PINS[ $version ] ) ) {
			WP_CLI::error( 'version must be one of: ' . implode( ', ', array_keys( self::PINS ) ) . '.' );
		}
		$installed = $this->ensure_plugin_version( $version );
		WP_CLI::runcommand( 'plugin activate woocommerce-payments woocommerce-payments-dev-tools' );
		if ( $installed !== $version ) {
			WP_CLI::error( "Installed WooPayments version {$installed} does not match the requested {$version}." );
		}
		WP_CLI::runcommand( 'wc tool run install_pages --user=1' );
		$this->seed_customer_and_options();
		if ( 'historical-money-records' !== $profile ) {
			$this->ensure_classic_checkout_page();
		}
		$donor = $this->read_donor_payload();
		update_option( 'wcpaydev_local_wpcom_jetpack_connection', '1' );
		update_option( 'wcpaydev_local_wpcom_base_url', (string) $donor['local_wpcom_base_url'] );
		update_option( 'wcpaydev_dev_mode', '1' );
		update_option( 'wcpaydev_redirect', '1' );
		update_option( 'wcpaydev_redirect_to', (string) $donor['redirect_to'] );
		if ( ! class_exists( 'Jetpack_Options' ) ) {
			WP_CLI::error( 'Jetpack_Options is unavailable; cannot inject the donor connection.' );
		}
		Jetpack_Options::update_option( 'id', (int) $donor['blog_id'] );
		Jetpack_Options::update_option( 'master_user', 1 );
		Jetpack_Options::update_option( 'blog_token', (string) $donor['blog_token'] );
		Jetpack_Options::update_option( 'user_tokens', array( 1 => (string) $donor['user_token'] ) );
		WP_CLI::runcommand( 'wcpay_dev refresh_account_data' );
		// The native gateway reads this key directly (no WC_Settings_API
		// field-default fallback), so every profile sets it or the gateway
		// is unavailable at checkout post-cutover.
		$settings = array(
			'enabled'                        => 'yes',
			'saved_cards'                    => 'yes',
			'test_mode'                      => 'yes',
			'upe_enabled_payment_method_ids' => array( 'card' ),
		);
		// Every profile seeds `active` as a harness convenience, not because a
		// real store has it: a real plugin-era store reaches `available`
		// through the `wc_update_11205_repair_native_payments_state` update and
		// `active` when the cutover finalizes. While the plugin is active,
		// `active` reads as `available`, so ownership before cutover still
		// tracks the plugin.
		$native_state = 'active';
		if ( 'cutover' === $profile ) {
			$settings['upe_enabled_payment_method_ids'] = array( 'card', 'future_lpm' );
		}
		update_option( 'woocommerce_woocommerce_payments_settings', $settings );
		$account = $donor['account_data'];
		if ( ! is_array( $account ) || empty( $account ) || ( $account['account_id'] ?? null ) !== $donor['account_id'] || false !== ( $account['is_live'] ?? null ) ) {
			WP_CLI::error( 'The donor account payload is missing or is not the expected non-live account.' );
		}
		if ( 'historical-money-records' === $profile && isset( $assoc_args['ctp'] ) ) {
			$account['card_testing_protection_eligible'] = 'on' === $assoc_args['ctp'];
			'on' === $assoc_args['ctp']
				? update_option( 'wcpaydev_force_card_testing_protection_on', '1' )
				: delete_option( 'wcpaydev_force_card_testing_protection_on' );
		}
		// The plugin was only just activated by a subprocess above, so this
		// long-lived process never loaded its classes; the rest runs in a
		// fresh subprocess that boots with the plugin already active.
		update_option( '_woopayments_transition_seed_account', $account, false );
		$finish = 'woopayments-e2e-transition seed_finish --native-state=' . escapeshellarg( (string) $native_state );
		if ( 'cutover' === $profile && ! empty( $assoc_args['pending-migrator'] ) ) {
			$finish .= ' --pending-migrator';
		}
		WP_CLI::runcommand( $finish );
	}

	/**
	 * Finishes seeding in a fresh process where WooPayments is active:
	 * onboarding test mode, the account cache, native state, the optional
	 * pending migrator action, and the final identity line.
	 *
	 * ## OPTIONS
	 *
	 * [--native-state=<active|available>]
	 * : Native payments state to write before reading identity back.
	 *
	 * [--pending-migrator]
	 * : Schedule a pending wcpay_migrate_subscription_retry action.
	 *
	 * @param array<int,string>    $args Positional arguments (unused).
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function seed_finish( array $args, array $assoc_args ): void {
		$native_state = '' !== $assoc_args['native-state'] ? $assoc_args['native-state'] : null;
		$account      = get_option( '_woopayments_transition_seed_account', null );
		delete_option( '_woopayments_transition_seed_account' );
		if ( ! is_array( $account ) || ! class_exists( 'WC_Payments_Onboarding_Service' ) || ! class_exists( 'WC_Payments' ) ) {
			WP_CLI::error( 'WooPayments onboarding/database-cache classes are unavailable.' );
		}
		WC_Payments_Onboarding_Service::set_test_mode( true );
		WC_Payments::get_database_cache()->add( WCPay\Database_Cache::ACCOUNT_KEY, $account );
		if ( null !== $native_state ) {
			$state_service = wc_get_container()->get( Automattic\WooCommerce\Internal\Payments\NativePaymentsState::class );
			$target        = 'active' === $native_state
				? Automattic\WooCommerce\Internal\Payments\NativePaymentsState::ACTIVE
				: Automattic\WooCommerce\Internal\Payments\NativePaymentsState::AVAILABLE;
			if ( ! $state_service->write_state( $target ) ) {
				WP_CLI::error( "Native payments {$native_state} state could not be seeded." );
			}
		}
		$migrator_action_id = null;
		if ( ! empty( $assoc_args['pending-migrator'] ) ) {
			$migrator_action_id = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wcpay_migrate_subscription_retry' );
			if ( ! is_numeric( $migrator_action_id ) || (int) $migrator_action_id <= 0 ) {
				WP_CLI::error( 'Unable to schedule the pending WooPayments migrator action.' );
			}
			$migrator_action_id = (int) $migrator_action_id;
		}
		$identity                       = $this->read_identity();
		$identity['native_state']       = $native_state;
		$identity['migrator_action_id'] = $migrator_action_id;
		WP_CLI::line( wp_json_encode( $identity ) );
	}

	/** Reads the installed WooPayments version directly, without a WP-CLI subprocess. */
	private function installed_plugin_version(): string {
		$file = WP_PLUGIN_DIR . '/woocommerce-payments/woocommerce-payments.php';
		return file_exists( $file ) ? get_plugin_data( $file, false, false )['Version'] : '';
	}

	/**
	 * Installs the pinned WooPayments zip when it is not already the active version.
	 * Runs no SHA-256 check when the installed version already matches: nothing is
	 * downloaded or overwritten in that case, so there is nothing to verify.
	 *
	 * @param string $version Pinned version key from self::PINS.
	 * @return string The installed version after this call.
	 */
	private function ensure_plugin_version( string $version ): string {
		$current = $this->installed_plugin_version();
		if ( $current === $version ) {
			return $current;
		}
		$pin      = self::PINS[ $version ];
		$zip_path = get_temp_dir() . 'woopayments-transition-seed-' . $version . '.zip';
		if ( ! file_exists( $zip_path ) || hash_file( 'sha256', $zip_path ) !== $pin['sha256'] ) {
			$response = wp_remote_get( $pin['url'], array( 'timeout' => 120 ) );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				WP_CLI::error( "Unable to download the pinned WooPayments {$version} zip." );
			}
			file_put_contents( $zip_path, wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( hash_file( 'sha256', $zip_path ) !== $pin['sha256'] ) {
			WP_CLI::error( "The downloaded WooPayments {$version} zip does not match its pinned SHA-256." );
		}
		WP_CLI::runcommand( 'plugin install ' . escapeshellarg( $zip_path ) . ' --force' );
		return $this->installed_plugin_version();
	}

	/** Seeds the shared customer, billing/shipping meta, and store options (S4, S6). */
	private function seed_customer_and_options(): void {
		$user    = get_user_by( 'login', 'customer' );
		$user_id = $user ? $user->ID : 0;
		if ( ! $user_id ) {
			$inserted = wp_insert_user(
				array(
					'user_login' => 'customer',
					'user_pass'  => 'password',
					'user_email' => 'customer@woocommercecoree2etestsuite.com',
					'first_name' => 'Jane',
					'last_name'  => 'Smith',
					'role'       => 'customer',
				)
			);
			if ( is_wp_error( $inserted ) ) {
				WP_CLI::error( $inserted->get_error_message() );
				return;
			}
			$user_id = $inserted;
		}
		$meta = array(
			'billing_first_name'  => 'Maggie',
			'billing_last_name'   => 'Simpson',
			'billing_address_1'   => '123 Evergreen Terrace',
			'billing_city'        => 'Springfield',
			'billing_country'     => 'US',
			'billing_state'       => 'OR',
			'billing_postcode'    => '97403',
			'billing_phone'       => '555 555-5555',
			'billing_email'       => 'customer@woocommercecoree2etestsuite.com',
			'shipping_first_name' => 'Maggie',
			'shipping_last_name'  => 'Simpson',
			'shipping_address_1'  => '123 Evergreen Terrace',
			'shipping_city'       => 'Springfield',
			'shipping_country'    => 'US',
			'shipping_state'      => 'OR',
			'shipping_postcode'   => '97403',
		);
		foreach ( $meta as $key => $value ) {
			update_user_meta( $user_id, $key, $value );
		}
		update_option( 'woocommerce_coming_soon', 'no' );
		update_option( 'woocommerce_currency', 'USD' );
	}

	/** Creates the classic-checkout shortcode page (S5), when the profile owns it. */
	private function ensure_classic_checkout_page(): void {
		if ( get_page_by_path( 'classic-checkout' ) ) {
			return;
		}
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Classic checkout',
				'post_name'    => 'classic-checkout',
				'post_content' => '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->',
			),
			true
		);
		if ( is_wp_error( $page_id ) ) {
			WP_CLI::error( $page_id->get_error_message() );
		}
	}

	/**
	 * Reads and validates the git-ignored donor payload beside this file.
	 *
	 * @return array<string,mixed>
	 */
	private function read_donor_payload(): array {
		$path = __DIR__ . '/connection.local.json';
		if ( ! file_exists( $path ) ) {
			WP_CLI::error( 'connection.local.json is missing. Export the donor payload first (data/t4-transition-seed.md 4d).' );
		}
		$donor = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local, git-ignored file, not a remote fetch.
		if ( ! is_array( $donor ) || empty( $donor['blog_id'] ) || empty( $donor['blog_token'] ) || empty( $donor['user_token'] ) || empty( $donor['account_id'] ) || ! is_array( $donor['account_data'] ?? null ) || empty( $donor['local_wpcom_base_url'] ) || empty( $donor['redirect_to'] ) ) {
			WP_CLI::error( 'connection.local.json is invalid or incomplete.' );
		}
		return $donor;
	}

	/**
	 * Reads back the identity facts the checkpoint and each spec assert.
	 *
	 * @return array<string,mixed>
	 */
	private function read_identity(): array {
		$user_tokens = class_exists( 'Jetpack_Options' ) ? Jetpack_Options::get_option( 'user_tokens' ) : array();
		$account     = class_exists( 'WC_Payments' ) && method_exists( 'WC_Payments', 'get_account_service' )
			? WC_Payments::get_account_service()->get_cached_account_data()
			: array();
		$account     = is_array( $account ) ? $account : array();
		return array(
			'plugin_version'     => $this->installed_plugin_version(),
			'plugin_active'      => is_plugin_active( 'woocommerce-payments/woocommerce-payments.php' ),
			'wpcom_blog_id'      => class_exists( 'Jetpack_Options' ) ? (int) Jetpack_Options::get_option( 'id' ) : 0,
			'blog_token_present' => class_exists( 'Jetpack_Options' ) && '' !== (string) Jetpack_Options::get_option( 'blog_token' ),
			'user_token_present' => ! empty( array_filter( (array) $user_tokens, 'is_string' ) ),
			'account_id'         => (string) ( $account['account_id'] ?? '' ),
			'is_live'            => ! empty( $account['is_live'] ),
		);
	}
}

add_action( 'plugins_loaded', array( 'WooPayments_Transition_Seed_CLI', 'register' ) );
