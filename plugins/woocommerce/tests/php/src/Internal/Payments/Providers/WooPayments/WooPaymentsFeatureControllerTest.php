<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\FeaturePluginCompatibility;
use Automattic\WooCommerce\Internal\Features\FeaturesController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPayments feature definition and its protected untick.
 */
class WooPaymentsFeatureControllerTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$_GET = array();
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
		parent::tearDown();
	}

	/**
	 * @testdox The WooPayments feature is an ordinary Features page entry, off by default, with its own option and no compatibility checks.
	 */
	public function test_definition_is_an_ordinary_feature_off_by_default(): void {
		$features_controller = $this->createMock( FeaturesController::class );
		$features_controller
			->expects( $this->once() )
			->method( 'add_feature_definition' )
			->with(
				'woopayments',
				'WooPayments',
				$this->callback(
					function ( array $definition ): bool {
						return 'woocommerce_feature_woopayments_enabled' === $definition['option_key']
							&& 'Use WooPayments built into WooCommerce. A store without the WooPayments extension sets it up from Payments settings; a store running the extension is offered a one-click switch.' === $definition['description']
							&& false === $definition['enabled_by_default']
							&& false === $definition['disable_ui']
							&& false === $definition['is_experimental']
							&& true === $definition['skip_compatibility_checks']
							&& FeaturePluginCompatibility::COMPATIBLE === $definition['default_plugin_compatibility']
							&& 'radio' === $definition['setting']['type']
							&& is_callable( $definition['setting']['disabled'] )
							&& is_callable( $definition['setting']['desc'] );
					}
				)
			);

		$this->create_sut( WooPaymentsRuntimeArbiter::OWNER_NONE )->add_feature_definition( $features_controller );
	}

	/**
	 * @testdox The Features page refuses turning WooPayments off only while the built-in WooPayments takes payments ($_dataName).
	 * @dataProvider provide_protection_states
	 *
	 * @param string $owner       Runtime owner.
	 * @param string $stored_tier Stored setup tier.
	 * @param bool   $refused     Whether the off choice is refused.
	 */
	public function test_off_choice_is_refused_only_while_the_builtin_takes_payments( string $owner, string $stored_tier, bool $refused ): void {
		// A store that ran the extension, so the message names the one-step way back.
		update_option( 'woocommerce_woocommerce_payments_version', '11.1.0' );
		update_option( WooPaymentsSetupTier::OPTION_NAME, $stored_tier );
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
		$setting = $this->create_sut( $owner )->get_feature_setting();

		$disabled    = ( $setting['disabled'] )();
		$description = ( $setting['desc'] )();

		if ( ! $refused ) {
			$this->assertSame( array(), $disabled );
			$this->assertStringStartsWith( 'Use WooPayments built into WooCommerce.', $description );
			return;
		}
		$this->assertSame( array( 'no' ), $disabled );
		$this->assertStringStartsWith( 'WooPayments is taking payments on this store. Turning this off stops checkout. To go back to the WooPayments extension, activate it from Plugins. <a href="', $description );
		$this->assertStringContainsString( 'woopayments=0', $description );
		$this->assertStringContainsString( '_feature_nonce=', $description );
		$this->assertStringContainsString( 'section=features', $description );
	}

	/**
	 * On a store that never ran the extension, its activation is refused while the built-in WooPayments owns payments, so the
	 * message names both steps (N-501); a store that ran it reactivates it in one step (N-482).
	 *
	 * @testdox The protected message names the way to the WooPayments extension that works on this store ($_dataName).
	 * @testWith ["a store that never ran the extension", false, "To use the WooPayments extension instead, disable WooPayments here first, then activate the extension from Plugins."]
	 *           ["a store that ran the extension", true, "To go back to the WooPayments extension, activate it from Plugins."]
	 *
	 * @param string $label         Case label.
	 * @param bool   $extension_ran Whether the extension's version option is stored.
	 * @param string $sentence      Expected third sentence.
	 */
	public function test_protected_message_names_the_way_to_the_extension( string $label, bool $extension_ran, string $sentence ): void {
		unset( $label );
		if ( $extension_ran ) {
			update_option( 'woocommerce_woocommerce_payments_version', '11.1.0' );
		} else {
			delete_option( 'woocommerce_woocommerce_payments_version' );
		}

		$this->assertSame( 'WooPayments is taking payments on this store. Turning this off stops checkout. ' . $sentence, $this->create_sut( WooPaymentsRuntimeArbiter::OWNER_BUILTIN )->get_disable_protected_message() );
	}

	/**
	 * Owner and stored tier combinations, and whether the off choice is refused.
	 *
	 * @return array<string,array{0:string,1:string,2:bool}>
	 */
	public function provide_protection_states(): array {
		return array(
			'built-in owner, connected'         => array( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, WooPaymentsSetupTier::CONNECTED, true ),
			'built-in owner, active'            => array( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, WooPaymentsSetupTier::ACTIVE, true ),
			'built-in owner, available'         => array( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, WooPaymentsSetupTier::AVAILABLE, false ),
			'extension owner, connected stored' => array( WooPaymentsRuntimeArbiter::OWNER_EXTENSION, WooPaymentsSetupTier::CONNECTED, false ),
			'no owner, connected stored'        => array( WooPaymentsRuntimeArbiter::OWNER_NONE, WooPaymentsSetupTier::CONNECTED, false ),
		);
	}

	/**
	 * @testdox The disable link turns the WooPayments feature off through the core Features handler.
	 */
	public function test_disable_link_turns_the_feature_off(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$url = $this->create_sut( WooPaymentsRuntimeArbiter::OWNER_BUILTIN )->get_disable_anyway_url();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$_GET = $query;
		// The handler redirects to the page without its query arguments; the test only needs the write.
		add_filter( 'wp_redirect', '__return_false' );

		wc_get_container()->get( FeaturesController::class )->change_feature_enable_from_query_params();

		$this->assertSame( 'no', get_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION ) );
	}

	/**
	 * A Features tab opened before WooPayments took payments still posts "Disable"; the save keeps the stored value only
	 * while the disable choice is protected.
	 *
	 * @testdox A Features page save that turns WooPayments off is refused only while the built-in WooPayments takes payments ($_dataName).
	 * @dataProvider provide_features_page_saves
	 *
	 * @param string $owner       Runtime owner.
	 * @param string $stored_tier Stored setup tier.
	 * @param string $expected    The feature option after the save.
	 */
	public function test_features_page_save_refuses_the_off_choice_only_while_protected( string $owner, string $stored_tier, string $expected ): void {
		$sut = $this->create_protectable_sut( $owner, $stored_tier );

		\WC_Admin_Settings::save_fields( array( $sut->get_feature_setting() ), array( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION => 'no' ) );

		$this->assertSame( $expected, get_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION ) );
	}

	/**
	 * Owner and stored tier combinations for a save that posts "Disable", and the stored value after it.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function provide_features_page_saves(): array {
		return array(
			'built-in owner, connected (stale tab)' => array( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, WooPaymentsSetupTier::CONNECTED, 'yes' ),
			'built-in owner, available'             => array( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, WooPaymentsSetupTier::AVAILABLE, 'no' ),
			'extension owner, connected stored'     => array( WooPaymentsRuntimeArbiter::OWNER_EXTENSION, WooPaymentsSetupTier::CONNECTED, 'no' ),
		);
	}

	/**
	 * @testdox The disable link still turns WooPayments off while the Features page save refuses it.
	 */
	public function test_disable_link_turns_the_feature_off_while_protected(): void {
		$sut = $this->create_protectable_sut( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, WooPaymentsSetupTier::CONNECTED );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		parse_str( (string) wp_parse_url( $sut->get_disable_anyway_url(), PHP_URL_QUERY ), $query );
		$_GET = $query;
		add_filter( 'wp_redirect', '__return_false' );

		wc_get_container()->get( FeaturesController::class )->change_feature_enable_from_query_params();

		$this->assertSame( 'no', get_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION ) );
	}

	/**
	 * Create the controller for a store with the WooPayments feature on, and register its hooks as the Features page does.
	 *
	 * The controller WooCommerce built for this test run is unhooked first, so only this one decides.
	 *
	 * @param string $owner       Runtime owner.
	 * @param string $stored_tier Stored setup tier.
	 * @return WooPaymentsFeatureController
	 */
	private function create_protectable_sut( string $owner, string $stored_tier ): WooPaymentsFeatureController {
		include_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( WooPaymentsSetupTier::OPTION_NAME, $stored_tier );
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
		remove_all_filters( 'woocommerce_admin_settings_sanitize_option_' . WooPaymentsFeatureController::FEATURE_ENABLE_OPTION );
		$sut = $this->create_sut( $owner );
		$sut->add_feature_definition( $this->createMock( FeaturesController::class ) );

		return $sut;
	}

	/**
	 * Create the controller with a runtime owner.
	 *
	 * @param string $owner Runtime owner.
	 * @return WooPaymentsFeatureController
	 */
	private function create_sut( string $owner ): WooPaymentsFeatureController {
		$arbiter = $this->getMockBuilder( WooPaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_runtime_owner' ) )
			->getMock();
		$arbiter->method( 'get_runtime_owner' )->willReturn( $owner );
		$sut = new WooPaymentsFeatureController();
		$sut->init( $arbiter, wc_get_container()->get( WooPaymentsSetupTier::class ) );

		return $sut;
	}
}
