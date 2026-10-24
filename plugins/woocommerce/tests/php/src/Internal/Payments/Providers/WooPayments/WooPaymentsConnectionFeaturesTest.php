<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Config as JetpackConfig;
use Automattic\Jetpack\Connection\Manager as JetpackConnectionManager;
use Automattic\Jetpack\Connection\Plugin_Storage;
use Automattic\Jetpack\Identity_Crisis;
use Automattic\Jetpack\IdentityCrisis\UI as IdentityCrisisUI;
use Automattic\Jetpack\Status\Cache as StatusCache;
use Automattic\Jetpack\Sync\Data_Settings;
use Automattic\Jetpack\Sync\Default_Filter_Settings;
use Automattic\Jetpack\Sync\JSON_Deflate_Array_Codec;
use Automattic\Jetpack\Sync\Actions as Sync_Actions;
use Automattic\Jetpack\Sync\Modules;
use Automattic\Jetpack\Sync\Settings as Sync_Settings;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsHttpClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsConnectionFeatures;
use WC_Unit_Test_Case;

/**
 * Tests for the Jetpack features WooCommerce turns on for the store's WordPress.com connection once WooPayments is set up.
 */
class WooPaymentsConnectionFeaturesTest extends WC_Unit_Test_Case {

	/**
	 * Where WordPress.com says the store lives, in the tests that put the store in Safe Mode.
	 */
	private const WPCOM_URL = 'https://production-store.example';

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsConnectionFeatures
	 */
	private $sut;

	/**
	 * The request URI before the test, which one test changes.
	 *
	 * @var string|null
	 */
	private ?string $request_uri = null;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved to restore it.
		$this->reset_jetpack_state();
		// The test site's local domain would put Jetpack in offline mode, where Safe Mode never applies.
		add_filter( 'jetpack_offline_mode', '__return_false' );
		$this->sut = new WooPaymentsConnectionFeatures();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		try {
			// Static caches in the Jetpack connection package, which no base class resets.
			$this->reset_jetpack_state();
			if ( null === $this->request_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $this->request_uri;
			}
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Turns on Jetpack Sync with the WooPayments extension's minimal settings and nothing more.
	 *
	 * Expected: client 11.1.0 woocommerce-payments.php:114-133 merges the package's required settings
	 * (Data_Settings::MUST_SYNC_DATA_SETTINGS) with four modules and five options.
	 */
	public function test_turns_on_sync_with_the_extension_minimal_settings(): void {
		$must     = Data_Settings::MUST_SYNC_DATA_SETTINGS;
		$defaults = new Default_Filter_Settings();

		$this->sut->register();

		$this->assertEqualsCanonicalizing(
			array_unique(
				array_merge(
					$must['jetpack_sync_modules'],
					array(
						'Automattic\\Jetpack\\Sync\\Modules\\Full_Sync_Immediately',
						'Automattic\\Jetpack\\Sync\\Modules\\Options',
						'Automattic\\Jetpack\\Sync\\Modules\\Posts',
						'Automattic\\Jetpack\\Sync\\Modules\\Meta',
					)
				)
			),
			array_unique( apply_filters( 'jetpack_sync_modules', Modules::DEFAULT_SYNC_MODULES ) ),
			'Sync runs the required modules and the four the extension adds.'
		);
		$this->assertEqualsCanonicalizing(
			array_unique( array_merge( $must['jetpack_sync_options_whitelist'], array( 'active_plugins', 'blogdescription', 'blogname', 'timezone_string', 'gmt_offset' ) ) ),
			array_unique( apply_filters( 'jetpack_sync_options_whitelist', $defaults->get_default_settings( 'jetpack_sync_options_whitelist' ) ) ),
			'Sync sends the required options and the five the extension adds.'
		);
		$this->assertEqualsCanonicalizing(
			array_keys( $must['jetpack_sync_callable_whitelist'] ),
			array_keys( apply_filters( 'jetpack_sync_callable_whitelist', $defaults->get_default_settings( 'jetpack_sync_callable_whitelist' ) ) ),
			'Sync sends only the required callables.'
		);
		$this->assertEqualsCanonicalizing(
			$must['jetpack_sync_constants_whitelist'],
			apply_filters( 'jetpack_sync_constants_whitelist', $defaults->get_default_settings( 'jetpack_sync_constants_whitelist' ) ),
			'Sync sends only the required constants.'
		);
	}

	/**
	 * @testdox Registers WooCommerce as the Safe Mode consumer: slug, priority, WooCommerce logo and admin page, under a subdirectory install too.
	 */
	public function test_registers_woocommerce_as_the_safe_mode_consumer(): void {
		// WordPress installed in a subdirectory: the connection matches the admin page against the full request path.
		add_filter( 'admin_url', static fn( string $url ): string => str_replace( '/wp-admin/', '/shop/wp-admin/', $url ) );

		$this->sut->register();

		$consumers = apply_filters( 'jetpack_idc_consumers', array() );

		$this->assertCount( 1, $consumers, 'One Safe Mode consumer is registered.' );
		$consumer = $consumers[0];
		$this->assertSame( 'woocommerce', $consumer['slug'] );
		$this->assertSame( 5, $consumer['priority'] );
		$this->assertSame( plugins_url( 'assets/images/woo-logo.svg', WC_PLUGIN_FILE ), $consumer['logo'] );
		$this->assertSame( '/shop/wp-admin/admin.php?page=wc-admin', $consumer['admin_page'] );
		$this->assertIsCallable( $consumer['customContent'], 'The banner copy is built when the banner renders, after translations load.' );
		$this->assertSame( 'WooCommerce Safe Mode', call_user_func( $consumer['customContent'] )['adminBarSafeModeLabel'] );
	}

	/**
	 * @testdox No Safe Mode banner text names a payment provider, with or without the mismatched URLs: $_dataName.
	 *
	 * @testWith ["store not in Safe Mode", false]
	 *           ["store in Safe Mode", true]
	 *
	 * @param string $state        The case name.
	 * @param bool   $in_safe_mode Whether the store is in Safe Mode, which adds the URL-dependent texts.
	 */
	public function test_safe_mode_texts_name_no_payment_provider( string $state, bool $in_safe_mode ): void {
		unset( $state );
		if ( $in_safe_mode ) {
			$this->skip_on_multisite();
			$this->connect_store();
			$this->store_safe_mode_error();
		}

		$content = $this->sut->get_safe_mode_content();

		$this->assertSame( $in_safe_mode, isset( $content['migrateCardBodyText'] ), 'The URL-dependent texts are present only in Safe Mode.' );
		// The two link targets point at the existing Safe Mode document, which lives under the WooPayments docs.
		unset( $content['supportURL'], $content['dynamicSiteUrlSupportLink'] );
		foreach ( $content as $key => $text ) {
			$this->assertDoesNotMatchRegularExpression( '/woopayments|wcpay|woocommerce-payments|stripe/i', $text, "The $key text must not name a payment provider." );
		}
	}

	/**
	 * @testdox A platform answer that reports a mismatched URL puts the store in Safe Mode with the WooCommerce banner.
	 *
	 * The answer's `idc_detected` shape is the platform's: the Jetpack auth base controller merges it into the response
	 * (wpcom wp-content/rest-api-plugins/endpoints/jetpack-auth.php:113-150) with the keys built in
	 * wp-content/mu-plugins/jetpack/sync/class.jetpack-sync-idc-cleanup.php:284-290.
	 */
	public function test_platform_reported_url_mismatch_enters_safe_mode_with_the_woocommerce_banner(): void {
		$this->skip_on_multisite();
		$this->connect_store();
		$this->register_and_run_plugins_loaded();
		$sent_url = '';
		$answer   = static function ( $preempt, array $args, string $url ) use ( &$sent_url ) {
			unset( $preempt, $args );
			$sent_url = $url;

			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode(
					array(
						'id'           => 'acct_test',
						'idc_detected' => array(
							'error_code'      => 'jetpack_url_mismatch',
							'request_siteurl' => site_url(),
							'request_home'    => home_url(),
							'wpcom_siteurl'   => self::WPCOM_URL,
							'wpcom_home'      => self::WPCOM_URL,
						),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $answer, 10, 3 );
		$http_client = new class() extends WooPaymentsHttpClient {
			/**
			 * Treat the store as connected; signing reads the blog token itself.
			 *
			 * @return bool
			 */
			public function is_connected(): bool {
				return true;
			}
		};

		$http_client->request( 'GET', '/sites/123/wcpay/accounts' );

		parse_str( (string) wp_parse_url( $sent_url, PHP_URL_QUERY ), $sent_args );
		$this->assertSame( '1', $sent_args['idc'] ?? null, 'The request asks the platform to check for a copied site.' );
		$this->assertArrayHasKey( 'home', $sent_args, 'The request tells the platform the URL it comes from.' );
		$this->assertTrue( Identity_Crisis::has_identity_crisis(), 'The store is in Safe Mode.' );
		$_SERVER['REQUEST_URI'] = wp_parse_url( admin_url( 'admin.php' ), PHP_URL_PATH ) . '?page=wc-admin&path=/payments/overview';
		$banner                 = IdentityCrisisUI::get_consumer_data();
		$this->assertSame( 'woocommerce', $banner['slug'] ?? null, 'The Safe Mode banner is WooCommerce\'s.' );
		$this->assertSame( 'WooCommerce Safe Mode', $banner['customContent']['adminBarSafeModeLabel'] ?? null );
	}

	/**
	 * @testdox WordPress's plugins_loaded step starts Safe Mode and Sync, and Sync's first send already carries the Safe Mode arguments.
	 *
	 * Without them a copy of the store would sync its URLs to WordPress.com unchecked. The sent item has the sender's shape,
	 * one queue item id mapped to its codec-encoded action (jetpack-sync class-sender.php:517-567), because the connection
	 * refuses to sign an empty body. The answer has the shape of WordPress.com's Sync endpoint
	 * (wp-content/rest-api-plugins/endpoints/jetpack-sync-actions.php:118-122, `processed_items`); only the request URL is read.
	 */
	public function test_plugins_loaded_starts_safe_mode_before_the_first_sync_send(): void {
		$this->skip_on_multisite();
		$this->connect_store();

		$this->register_and_run_plugins_loaded();

		$this->assertSame( 1, did_action( 'jetpack_feature_identity_crisis_enabled' ), 'Safe Mode started.' );
		$this->assertSame( 1, did_action( 'jetpack_feature_sync_enabled' ), 'Sync started.' );
		$sync_urls = array();
		$answer    = static function ( $preempt, array $args, string $url ) use ( &$sync_urls ) {
			unset( $preempt, $args );
			if ( false !== strpos( $url, '/jetpack-sync-actions' ) || false !== strpos( $url, 'xmlrpc.php' ) ) {
				$sync_urls[] = $url;
			}

			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'processed_items' => array() ) ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $answer, 10, 3 );
		$codec = new JSON_Deflate_Array_Codec();
		$items = array( 'jpsq_sync-1791946252.000000-000001-1' => $codec->encode( array( 'updated_option', array( 'blogname', 'Old name', 'New name' ), 0, microtime( true ), false ) ) );

		Sync_Actions::send_data( $items, $codec->name(), microtime( true ), 'sync', 0.0, 0.0 );

		$this->assertCount( 1, $sync_urls, 'Sync sent its actions to WordPress.com once.' );
		parse_str( (string) wp_parse_url( $sync_urls[0], PHP_URL_QUERY ), $sent_args );
		$this->assertSame( '1', $sent_args['sync'] ?? null, 'The request is a Sync send.' );
		$this->assertSame( '1', $sent_args['idc'] ?? null, 'Sync\'s first send asks the platform to check for a copied site.' );
		$this->assertArrayHasKey( 'home', $sent_args, 'Sync\'s first send tells the platform the URL it comes from.' );
	}

	/**
	 * @testdox Jetpack Sync reads as off, and stores no setting, while no plugin asked jetpack-config for it.
	 *
	 * WooCommerce bundles Sync on every store but turns it on only once WooPayments is set up. Sync stores its default
	 * setting the first time it is read (jetpack-sync class-settings.php:150-157), which would write an autoloaded option
	 * on stores where nothing runs Sync.
	 */
	public function test_sync_reads_off_and_stores_nothing_while_no_plugin_turned_it_on(): void {
		global $wpdb;
		delete_option( 'jetpack_sync_settings_disable' );

		$this->assertFalse( Sync_Settings::is_sync_enabled() );
		$this->assertNull(
			$wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'jetpack_sync_settings_disable' ) ),
			'No Sync setting is stored.'
		);
	}

	/**
	 * @testdox Once a plugin asked jetpack-config for Jetpack Sync, its stored setting decides (stored: $stored).
	 *
	 * @testWith [null, true]
	 *           ["0", true]
	 *           ["1", false]
	 *
	 * @param string|null $stored   The stored `jetpack_sync_settings_disable` value, or null when none is stored.
	 * @param bool        $expected Whether Sync reads as enabled.
	 */
	public function test_sync_keeps_its_setting_once_a_plugin_turned_it_on( ?string $stored, bool $expected ): void {
		delete_option( 'jetpack_sync_settings_disable' );
		// The Jetpack plugin's own call (no settings, so Sync's defaults).
		( new JetpackConfig() )->ensure( 'sync' );
		if ( null !== $stored ) {
			update_option( 'jetpack_sync_settings_disable', $stored );
		}

		$this->assertSame( $expected, Sync_Settings::is_sync_enabled() );
	}

	/**
	 * @testdox While no plugin turned Jetpack Sync on, the connection reports the active connected plugins to WordPress.com itself.
	 *
	 * The connection skips this report when it believes Sync carries it (connection class-plugin-storage.php:213-227,
	 * 233-256). The answer is synthetic transport-only input in WP_Http::request()'s array shape; only the URL is read.
	 */
	public function test_connection_reports_active_plugins_itself_while_no_plugin_turned_sync_on(): void {
		$this->connect_store();
		$sent_urls = array();
		$answer    = static function ( $preempt, array $args, string $url ) use ( &$sent_urls ) {
			unset( $preempt, $args );
			$sent_urls[] = $url;

			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => '{}',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $answer, 10, 3 );

		Plugin_Storage::update_active_plugins_option();

		$reports = array_filter( $sent_urls, static fn( string $url ): bool => false !== strpos( $url, '/sites/123/jetpack-active-connected-plugins' ) );
		$this->assertCount( 1, $reports, 'The connection posts its active connected plugins to WordPress.com.' );
	}

	/**
	 * Jetpack handles Safe Mode only on single sites unless a site opts in (Identity_Crisis::should_handle_idc(),
	 * class-identity-crisis.php:521-543), as with the WooPayments extension.
	 */
	private function skip_on_multisite(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Jetpack does not handle Safe Mode on multisite by default.' );
		}
	}

	/**
	 * Register the SUT and run the plugins_loaded callback it leaves, as WordPress does after WooCommerce loads.
	 */
	private function register_and_run_plugins_loaded(): void {
		global $wp_filter;
		$before = $wp_filter['plugins_loaded']->callbacks[2] ?? array();

		$this->sut->register();

		$added = array_diff_key( $wp_filter['plugins_loaded']->callbacks[2] ?? array(), $before );
		$this->assertCount( 1, $added, 'register() leaves one plugins_loaded callback, jetpack-config\'s.' );
		foreach ( $added as $callback ) {
			call_user_func( $callback['function'] );
		}
	}

	/**
	 * Store a connection: the blog ID and blog token Jetpack's Tokens class reads.
	 */
	private function connect_store(): void {
		\Jetpack_Options::update_option( 'id', 123 );
		\Jetpack_Options::update_option( 'blog_token', 'tokenkey.blogsecret' );
		\Jetpack_Options::update_option( 'time_diff', 0 );
		( new JetpackConnectionManager() )->reset_connection_status();
	}

	/**
	 * Store the Safe Mode error the connection writes from a platform answer (class-identity-crisis.php:315-336).
	 */
	private function store_safe_mode_error(): void {
		\Jetpack_Options::update_option(
			'sync_error_idc',
			Identity_Crisis::get_sync_error_idc_option(
				array(
					'error_code'    => 'jetpack_url_mismatch',
					'wpcom_siteurl' => self::WPCOM_URL,
					'wpcom_home'    => self::WPCOM_URL,
				)
			)
		);
	}

	/**
	 * Reset the connection package's static state.
	 */
	private function reset_jetpack_state(): void {
		foreach ( array(
			array( Identity_Crisis::class, 'instance' ),
			array( IdentityCrisisUI::class, 'consumers' ),
		) as list( $class_name, $property_name ) ) {
			$property = new \ReflectionProperty( $class_name, $property_name );
			$property->setAccessible( true );
			$property->setValue( null, null );
		}
		Identity_Crisis::$is_safe_mode_confirmed = null;
		\Jetpack_Options::delete_option( array( 'id', 'blog_token', 'time_diff', 'sync_error_idc' ) );
		delete_transient( 'jetpack_idc_local' );
		( new JetpackConnectionManager() )->reset_connection_status();
		StatusCache::clear();
		// The merged Sync settings are static, and the package adds its required settings once per request.
		( new Data_Settings() )->empty_data_settings_and_hooks();
		unset( $GLOBALS['wp_actions']['jetpack_sync_add_required_data_settings'] );
		// jetpack-config starts each feature once per request (class-config.php:207-226).
		unset( $GLOBALS['wp_actions']['jetpack_feature_sync_enabled'], $GLOBALS['wp_actions']['jetpack_feature_identity_crisis_enabled'] );
	}
}
