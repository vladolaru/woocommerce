<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Notes;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Admin\WCAdminHelper;
use Automattic\WooCommerce\Internal\Admin\Notes\WooCommercePayments;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
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
	 * @testdox Does not add the note while the feature is on and no runtime owns payments.
	 *
	 * Catches the note reading the runtime owner instead of the feature setting: here the extension has run before and
	 * is inactive, so nothing owns payments, yet the setting is on and the built-in WooPayments is offered.
	 */
	public function test_skips_note_while_feature_is_on_and_no_runtime_owns_payments(): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( WooPaymentsRuntimeArbiter::EXTENSION_VERSION_OPTION, '11.3.0' );
		$arbiter = wc_get_container()->get( WooPaymentsRuntimeArbiter::class );
		$arbiter->invalidate();

		try {
			$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_NONE, $arbiter->get_runtime_owner() );

			WooCommercePayments::possibly_add_note();

			$this->assertFalse( Notes::get_note_by_name( WooCommercePayments::NOTE_NAME ) );
		} finally {
			delete_option( WooPaymentsRuntimeArbiter::EXTENSION_VERSION_OPTION );
			$arbiter->invalidate();
		}
	}

	/**
	 * @testdox The note's install action and its scheduled add are hooked when WooCommerce loads.
	 *
	 * Catches the note's provider dropped from the admin load, which would leave its Get started link and the scheduled
	 * add with nothing to run them.
	 */
	public function test_note_hooks_are_registered_at_load(): void {
		$this->assertTrue( $this->has_note_callback( 'init', 'install_on_action' ) );
		$this->assertTrue( $this->has_note_callback( 'wc-admin-woocommerce-payments_add_note', 'add_note' ) );
	}

	/**
	 * @testdox Sends the note's install action to the Payments settings page, with no install, while the WooPayments feature is on.
	 */
	public function test_sends_install_action_to_payments_settings_while_feature_is_on(): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );

		$this->run_install_action();

		$this->assertSame( array( admin_url( 'admin.php?page=wc-settings&tab=checkout&from=WCADMIN_PAYMENT_TASK' ) ), $this->redirects );
		$this->assertCount( 0, $this->http_requests );
	}

	/**
	 * @testdox Does nothing on the note's install action for a user who cannot manage WooCommerce while the feature is on.
	 */
	public function test_skips_install_action_for_user_without_capability_while_feature_is_on(): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );

		$this->run_install_action( 'editor' );

		$this->assertSame( array(), $this->redirects );
		$this->assertCount( 0, $this->http_requests );
	}

	/**
	 * @testdox Keeps trunk's behaviour while the WooPayments feature is off: a failed install lookup still redirects to onboarding.
	 */
	public function test_keeps_trunk_onboarding_redirect_after_failed_install_while_feature_is_off(): void {
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
	 * Tell whether a note provider's method is hooked to an action.
	 *
	 * @param string $hook_name Action name.
	 * @param string $method    Method of the note provider.
	 * @return bool
	 */
	private function has_note_callback( string $hook_name, string $method ): bool {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook_name ] ) ) {
			return false;
		}
		foreach ( $wp_filter[ $hook_name ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && $function[0] instanceof WooCommercePayments && $method === $function[1] ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Visit a stored note's action as a user of the given role.
	 *
	 * @param string $role User role.
	 */
	private function run_install_action( string $role = 'administrator' ): void {
		$user_id        = $this->factory->user->create( array( 'role' => $role ) );
		$is_super_admin = is_multisite() && 'administrator' === $role;
		if ( $is_super_admin ) {
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
			if ( $is_super_admin ) {
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
