<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\DependencyManagement\RuntimeContainer;
use Automattic\WooCommerce\Internal\Features\FeaturesController;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProviderInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyAdminNoteController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyBootstrap;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController as Feature;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RateService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use WC_Unit_Test_Case;

/** Tests the shared inbox note across feature changes and payments ownership changes. */
class MultiCurrencyNoteLifecycleTest extends WC_Unit_Test_Case {

	private const NAME          = MultiCurrencyAdminNoteController::NOTE_NAME;
	private const CORE_LINK     = 'admin.php?page=wc-settings&tab=wcpay_multi_currency';
	private const PLUGIN_LINK   = 'admin.php?page=wc-admin&path=/payments/multi-currency-setup';
	private const UNRELATED     = 'wc-test-unrelated';
	private const OPTION_CHANGE = 'update_option_' . Feature::FEATURE_ENABLE_OPTION;

	/** @var int ID of the unrelated note that every transition must preserve. */
	private int $unrelated_id;

	/** @var int Site with separate WooCommerce tables for the network activation test. */
	private static int $second_site;

	/** Install the second site's tables outside the per-test transaction. */
	public static function wpSetUpBeforeClass(): void {
		if ( is_multisite() ) {
			self::$second_site = self::factory()->blog->create();
			switch_to_blog( self::$second_site );
			try {
				\WC_Install::create_tables();
			} finally {
				restore_current_blog();
			}
		}
	}

	/** Remove the network fixture and its tables. */
	public static function wpTearDownAfterClass(): void {
		if ( is_multisite() ) {
			wpmu_delete_blog( self::$second_site, true );
		}
	}

	/** Start with Multi-Currency enabled independently of built-in payments. */
	public function set_up(): void {
		parent::set_up();
		set_current_screen( 'dashboard' );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		delete_option( Feature::LAST_PAYMENTS_OWNER_OPTION );
		update_option( Feature::FEATURE_ENABLE_OPTION, 'yes' );
		$this->unrelated_id = $this->save_note( self::UNRELATED, admin_url() );
	}

	/** Check the unrelated note and its action after every lifecycle. */
	protected function assertPostConditions(): void {
		parent::assertPostConditions();
		$this->assertSame( $this->unrelated_id, $this->assert_one_note( self::UNRELATED, admin_url(), 'present' )->get_id() );
	}

	/**
	 * @testdox An eligible visit respects the starting note, disabling clears it and re-enabling creates a visible note: $state.
	 * @testWith ["absent"]
	 *           ["present"]
	 *           ["dismissed"]
	 *           ["actioned"]
	 *
	 * @param string $state Starting note state.
	 */
	public function test_feature_cycle( string $state ): void {
		$existing = $this->starting_note( $state );
		$this->register_request();
		do_action( 'admin_init' );
		$note = $this->assert_one_note( self::NAME, admin_url( self::CORE_LINK ), $state );
		if ( 'absent' === $state ) {
			$note->delete();
		} else {
			$this->assertSame( $existing, $note->get_id() );
		}

		$writes = array();
		$record = static function ( $query ) use ( &$writes ) {
			if ( preg_match( '/^\s*(?:INSERT|UPDATE|REPLACE|DELETE)\b.*wc_admin_note/is', $query ) ) {
				$writes[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $record );
		$this->toggle_feature( false );
		remove_filter( 'query', $record );
		if ( 'absent' === $state ) {
			$this->assertSame( array(), $writes );
		}
		$this->assert_note_absent( $existing );
		do_action( 'admin_init' );
		$this->assert_note_absent( $existing );

		$this->toggle_feature( true );
		$this->register_request();
		do_action( 'admin_init' );
		$fresh = $this->assert_one_note( self::NAME, admin_url( self::CORE_LINK ), 'present' );
		$this->assertNotSame( $existing, $fresh->get_id() );
	}

	/**
	 * @testdox The extension takes over from $state and keeps its own dismissed replacement.
	 * @testWith ["absent"]
	 *           ["present"]
	 *           ["dismissed"]
	 *           ["actioned"]
	 *
	 * @param string $state Starting note state.
	 */
	public function test_extension_takes_over( string $state ): void {
		$existing = $this->starting_note( $state );
		$this->register_request();
		$this->activate_extension();
		$controller = $this->register_request();
		$this->assertFalse( has_action( 'admin_init', array( $controller, 'handle_admin_init' ) ) );
		$this->assert_note_absent( $existing );

		$replacement = $this->save_note( self::NAME, self::PLUGIN_LINK, 'dismissed' );
		$this->assertNotSame( $existing, $replacement );
		$this->register_request();
		$this->toggle_feature( false );
		$this->assertSame( $replacement, $this->assert_one_note( self::NAME, self::PLUGIN_LINK, 'dismissed' )->get_id() );
	}

	/**
	 * @testdox Only outgoing copies are removed when the extension's Multi-Currency module is off.
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $known Whether a previous payments owner was recorded.
	 */
	public function test_mixed_notes_on_first_observation( bool $known ): void {
		if ( $known ) {
			update_option( Feature::LAST_PAYMENTS_OWNER_OPTION, Feature::LAST_PAYMENTS_OWNER_BUILTIN );
		}
		$core_ids = array( $this->starting_note( 'present' ), $this->save_note( self::NAME, 'https://old.example/previous/wp-admin/' . self::CORE_LINK, 'actioned' ) );
		$plugin   = $this->save_note( self::NAME, self::PLUGIN_LINK, 'dismissed' );
		$this->activate_extension();
		update_option( Feature::WOOPAYMENTS_MULTI_CURRENCY_FLAG_OPTION, '0' );
		$this->register_request();
		foreach ( $core_ids as $id ) {
			$this->assertSame( 0, $this->count_stored_actions( $id ) );
		}
		$this->assertSame( $plugin, $this->assert_one_note( self::NAME, self::PLUGIN_LINK, 'dismissed' )->get_id() );

		// The disabled extension module did not register its deactivation callback.
		update_option( 'active_plugins', array() );
		$this->register_request();
		$this->toggle_feature( true );
		$this->register_request();
		do_action( 'admin_init' );
		$this->toggle_feature( false );
		$this->assertSame( $plugin, $this->assert_one_note( self::NAME, self::PLUGIN_LINK, 'dismissed' )->get_id() );
	}

	/**
	 * @testdox The extension can claim an unrecognized stored marker and remove the outgoing note.
	 * At b7dca8d7fbd, a stored array or integer caused a TypeError in claim_handover().
	 *
	 * @testWith [["unknown"]]
	 *           [42]
	 *           ["BUILTIN"]
	 *
	 * @param array|int|string $marker Stored option value.
	 */
	public function test_takeover_with_unknown_marker( $marker ): void {
		update_option( Feature::LAST_PAYMENTS_OWNER_OPTION, $marker );
		$this->assertSame( $marker, get_option( Feature::LAST_PAYMENTS_OWNER_OPTION ) );
		$id = $this->starting_note( 'dismissed' );
		$this->activate_extension();
		$this->register_request();

		$this->assertSame( 'extension', get_option( Feature::LAST_PAYMENTS_OWNER_OPTION ) );
		$this->assert_note_absent( $id );
	}

	/**
	 * @testdox Subsequent extension-owned requests and disabled sites do not read or write inbox notes.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $extension_active Whether the extension owns payments.
	 */
	public function test_dormant_requests( bool $extension_active ): void {
		$this->toggle_feature( false );
		if ( $extension_active ) {
			$this->activate_extension();
			$this->register_request();
		}
		$queries = array();
		$record  = static function ( $query ) use ( &$queries ) {
			if ( false !== strpos( $query, 'wc_admin_note' ) ) {
				$queries[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $record );
		$this->register_request();
		do_action( 'admin_init' );
		remove_filter( 'query', $record );
		$this->assertSame( array(), $queries );
		$this->assertFalse( has_action( self::OPTION_CHANGE ) );
	}

	/**
	 * @testdox Disabling from a non-admin request removes all built-in copies even after the provider disconnects.
	 */
	public function test_disable_without_connected_provider(): void {
		$first  = $this->starting_note( 'present' );
		$second = $this->starting_note( 'dismissed' );
		$this->register_request( false, 'cli' );
		$this->toggle_feature( false );
		$this->assert_note_absent( $first );
		$this->assertSame( 0, $this->count_stored_actions( $second ) );
	}

	/**
	 * @testdox Disabling before wp_loaded keeps the note and its action until the deferred callback removes both.
	 */
	public function test_deferred_feature_off_removes_note(): void {
		$id = $this->starting_note( 'present' );
		remove_all_actions( 'wp_loaded' );
		unset( $GLOBALS['wp_actions']['wp_loaded'] );
		$this->register_request();
		$this->toggle_feature( false );

		$this->assertSame( $id, $this->assert_one_note( self::NAME, admin_url( self::CORE_LINK ), 'present' )->get_id() );
		do_action( 'wp_loaded' );
		$this->assert_note_absent( $id );
	}

	/**
	 * @testdox Two first requests share one deletion even when both observed the outgoing ID.
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $known Whether the marker needs updating or inserting.
	 */
	public function test_interleaved_takeovers( bool $known ): void {
		if ( $known ) {
			update_option( Feature::LAST_PAYMENTS_OWNER_OPTION, Feature::LAST_PAYMENTS_OWNER_BUILTIN );
		}
		$id      = $this->starting_note( 'dismissed' );
		$deleted = array();
		add_action(
			'woocommerce_note_deleted',
			static function ( $deleted_id ) use ( &$deleted ) {
				$deleted[] = $deleted_id;
			}
		);
		$this->activate_extension();
		$interleave = function ( $note ) use ( $id, &$interleave ) {
			if ( $id === $note->get_id() ) {
				remove_action( 'woocommerce_note_loaded', $interleave );
				$this->register_request();
				$this->save_note( self::NAME, self::PLUGIN_LINK, 'dismissed' );
			}
		};
		add_action( 'woocommerce_note_loaded', $interleave );
		$this->register_request();

		$this->assertSame( array( $id ), $deleted );
		$this->assertSame( 0, $this->count_stored_actions( $id ) );
		$this->assert_one_note( self::NAME, self::PLUGIN_LINK, 'dismissed' );
	}

	/**
	 * @testdox An early takeover waits for WC Admin to register its data store and finishes before admin_init.
	 */
	public function test_takeover_before_the_notes_data_store_loads(): void {
		$id = $this->starting_note( 'present' );
		$this->activate_extension();
		remove_all_actions( 'wp_loaded' );
		unset( $GLOBALS['wp_actions']['wp_loaded'] );
		$register_stores = array( \Automattic\WooCommerce\Admin\API\Init::class, 'add_data_stores' );
		remove_filter( 'woocommerce_data_stores', $register_stores );
		try {
			$this->register_request();
		} finally {
			add_filter( 'woocommerce_data_stores', $register_stores );
		}

		do_action( 'wp_loaded' );
		$this->assert_note_absent( $id );
		$this->assertSame( 'extension', get_option( Feature::LAST_PAYMENTS_OWNER_OPTION ) );
	}

	/**
	 * @testdox A site with no inbox still records the extension owner and hands its setup back on deactivation.
	 */
	public function test_owner_change_without_a_notes_store(): void {
		$this->activate_extension();
		$register_stores = array( \Automattic\WooCommerce\Admin\API\Init::class, 'add_data_stores' );
		remove_filter( 'woocommerce_data_stores', $register_stores );
		try {
			$this->register_request();
		} finally {
			add_filter( 'woocommerce_data_stores', $register_stores );
		}
		$this->assertSame( 'extension', get_option( Feature::LAST_PAYMENTS_OWNER_OPTION ) );
		update_option( 'active_plugins', array() );
		$this->register_request();
		$this->assertSame( 'no', get_option( Feature::FEATURE_ENABLE_OPTION ) );
	}

	/**
	 * @testdox A network activation removes each site's outgoing note on its own first request.
	 * @group multisite
	 */
	public function test_network_activation(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}
		$first = $this->starting_note( 'present' );
		switch_to_blog( self::$second_site );
		try {
			$second_id = $this->starting_note( 'actioned' );
		} finally {
			restore_current_blog();
		}

		update_site_option( 'active_sitewide_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE => 1 ) );
		remove_all_actions( 'wp_loaded' );
		unset( $GLOBALS['wp_actions']['wp_loaded'] );
		$this->register_request();
		switch_to_blog( self::$second_site );
		try {
			do_action( 'wp_loaded' );
			$this->assertSame( self::$second_site, get_current_blog_id() );
			$this->assertSame( $second_id, $this->assert_one_note( self::NAME, admin_url( self::CORE_LINK ), 'actioned' )->get_id() );
			$this->register_request();
			$this->assert_note_absent( $second_id );
		} finally {
			restore_current_blog();
		}
		$this->assert_note_absent( $first );
	}

	/** Toggle the feature through the Features page's write path.
	 *
	 * @param bool $enabled New setting.
	 */
	private function toggle_feature( bool $enabled ): void {
		wc_get_container()->get( FeaturesController::class )->change_feature_enable( Feature::FEATURE_ID, $enabled );
	}

	/** Make the site active-plugin list name the extension. */
	private function activate_extension(): void {
		update_option( 'active_plugins', array_merge( get_option( 'active_plugins', array() ), array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) ) );
	}

	/**
	 * Run a fresh bootstrap with the real owner arbiters.
	 *
	 * @param bool   $connected Whether rates are available.
	 * @param string $request   Request type.
	 */
	private function register_request( bool $connected = true, string $request = 'admin' ): MultiCurrencyAdminNoteController {
		remove_all_actions( 'admin_init' );
		remove_all_actions( self::OPTION_CHANGE );
		remove_action( FeaturesController::FEATURE_ENABLED_CHANGED_ACTION, array( Feature::class, 'mirror_extension_flag' ) );
		$provider = $this->createMock( CurrencyRateProviderInterface::class );
		$provider->method( 'is_connected' )->willReturn( $connected );
		$container = new RuntimeContainer( array( RateService::class => RateServices::with( $provider ) ) );
		( new MultiCurrencyBootstrap( static fn(): array => array() ) )->register( $container, $request );
		return $container->get( MultiCurrencyAdminNoteController::class );
	}

	/** Create the outgoing note when the fixture calls for one.
	 *
	 * @param string $state Starting state.
	 */
	private function starting_note( string $state ): int {
		return 'absent' === $state ? 0 : $this->save_note( self::NAME, admin_url( self::CORE_LINK ), $state );
	}

	/** Persist a note and its setup button.
	 *
	 * @param string $name  Note name.
	 * @param string $url   Button destination.
	 * @param string $state Starting state.
	 */
	private function save_note( string $name, string $url, string $state = 'present' ): int {
		$note = new Note();
		$note->set_name( $name );
		$note->set_is_deleted( 'dismissed' === $state );
		$note->set_status( 'actioned' === $state ? 'actioned' : 'unactioned' );
		$note->add_action( $name, 'Set up now', $url, 'unactioned', true );
		return $note->save();
	}

	/** Read the single stored note and check its action and status.
	 *
	 * @param string $name  Note name.
	 * @param string $url   Expected button destination.
	 * @param string $state Expected note state.
	 */
	private function assert_one_note( string $name, string $url, string $state ): Note {
		$ids = Notes::load_data_store()->get_notes_with_name( $name );
		$this->assertCount( 1, $ids, $name );
		$note = new Note( $ids[0] );
		$this->assertSame( 'dismissed' === $state, $note->get_is_deleted() );
		$this->assertSame( 'actioned' === $state ? 'actioned' : 'unactioned', $note->get_status() );
		$this->assertCount( 1, $note->get_actions() );
		$this->assertSame( $url, $note->get_action( $name )->query );
		return $note;
	}

	/** Assert that neither the note nor its stored actions remain.
	 *
	 * @param int $id Outgoing note ID, zero for an absent note.
	 */
	private function assert_note_absent( int $id ): void {
		$this->assertSame( array(), Notes::load_data_store()->get_notes_with_name( self::NAME ) );
		$this->assertSame( 0, $this->count_stored_actions( $id ) );
	}

	/** Count action rows independently of the parent note.
	 *
	 * @param int $id Note ID.
	 */
	private function count_stored_actions( int $id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_admin_note_actions WHERE note_id = %d", $id ) );
	}
}
