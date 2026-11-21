<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Notes;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Admin\WCAdminHelper;
use Automattic\WooCommerce\Internal\Admin\Notes\WooCommercePayments;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPayments inbox note.
 */
class WooCommercePaymentsTest extends WC_Unit_Test_Case {

	/**
	 * Redirects attempted by the action.
	 *
	 * @var string[]
	 */
	private $redirects = array();

	/**
	 * Put the store within the note's country and age limits.
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( WCAdminHelper::WC_ADMIN_TIMESTAMP_OPTION, time() - 2 * WEEK_IN_SECONDS );
		update_option( 'woocommerce_show_marketplace_suggestions', 'yes' );
		Notes::delete_notes_with_name( WooCommercePayments::NOTE_NAME );
	}

	/**
	 * Clear the install action's request and listeners.
	 */
	public function tearDown(): void {
		try {
			unset( $_GET['page'], $_GET['action'], $_GET['_wpnonce'] );
			remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );
			wp_set_current_user( 0 );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Adds the marketing note with a Get started action while the WooPayments feature is off.
	 */
	public function test_adds_note_while_feature_is_off(): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );

		WooCommercePayments::possibly_add_note();

		$note = Notes::get_note_by_name( WooCommercePayments::NOTE_NAME );
		$this->assertInstanceOf( Note::class, $note );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_MARKETING, $note->get_type() );
		$this->assertNotNull( $note->get_action( 'get-started' ) );
	}

	/**
	 * @testdox Does not add the note while the WooPayments feature is on.
	 */
	public function test_skips_note_while_feature_is_on(): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );

		WooCommercePayments::possibly_add_note();

		$this->assertFalse( Notes::get_note_by_name( WooCommercePayments::NOTE_NAME ) );
	}

	/**
	 * @testdox Does nothing on the note's install action while the WooPayments feature is on.
	 */
	public function test_skips_install_action_while_feature_is_on(): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );

		$this->run_install_action();

		$this->assertSame( array(), $this->redirects );
		$this->assertCount( 0, $this->http_requests );
	}

	/**
	 * @testdox Starts the install on the note's action while the WooPayments feature is off.
	 */
	public function test_starts_install_while_feature_is_off(): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! is_wp_error( validate_plugin( WooCommercePayments::PLUGIN_FILE ) ) ) {
			$this->markTestSkipped( 'This test requires the WooPayments extension to be absent.' );
		}

		// Stop at the plugin directory lookup, before WordPress retries failed HTTP requests.
		$lookups = array();
		$lookup  = static function ( $result, $action, $args ) use ( &$lookups ) {
			if ( 'plugin_information' === $action && WooCommercePayments::PLUGIN_SLUG === $args->slug ) {
				$lookups[] = $args->slug;
				return new \WP_Error( 'test_plugin_unavailable' );
			}
			return $result;
		};
		add_filter( 'plugins_api', $lookup, 10, 3 );
		try {
			$this->run_install_action();
		} finally {
			remove_filter( 'plugins_api', $lookup );
		}

		$this->assertSame( array( WooCommercePayments::PLUGIN_SLUG ), $lookups );
		$this->assertCount( 1, $this->redirects );
		$this->assertStringContainsString( 'wcpay-connect=1', $this->redirects[0] );
	}

	/**
	 * Visit a stored note's action as an administrator.
	 */
	private function run_install_action(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}
		wp_set_current_user( $user_id );
		WooCommercePayments::get_note()->save();
		$_GET['page']     = 'wc-admin';
		$_GET['action']   = 'setup-woocommerce-payments';
		$_GET['_wpnonce'] = wp_create_nonce( 'setup-woocommerce-payments' );

		$this->http_requests  = array();
		$this->http_responder = static function () {
			return new \WP_Error( 'test_http_blocked' );
		};
		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );

		$sut = ( new \ReflectionClass( WooCommercePayments::class ) )->newInstanceWithoutConstructor();
		try {
			$sut->install_on_action();
		} catch ( \WPAjaxDieContinueException $e ) {
			// The redirect filter stopped the handler before exit.
			return;
		} finally {
			if ( is_multisite() ) {
				revoke_super_admin( $user_id );
			}
		}
	}

	/**
	 * Record the redirect and stop before exit.
	 *
	 * @param string $url Redirect destination.
	 * @throws \WPAjaxDieContinueException To return control to the test.
	 */
	public function capture_redirect( string $url ): void {
		$this->redirects[] = $url;
		throw new \WPAjaxDieContinueException();
	}
}
