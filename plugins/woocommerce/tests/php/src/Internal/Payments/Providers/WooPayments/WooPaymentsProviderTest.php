<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\PaymentGatewayFeature;
use Automattic\WooCommerce\Internal\Payments\CapabilityManifest;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\ProviderOperationEffectApplier;
use Automattic\WooCommerce\Internal\Payments\ProviderPostLifecycleEffectApplier;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceProfile;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectPlan;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProviderGatewayAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsProvider class.
 */
class WooPaymentsProviderTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsProvider
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = wc_get_container()->get( WooPaymentsProvider::class );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( '_wcpay_feature_amazon_pay' );
		remove_all_filters( 'wcpay_upe_available_payment_methods' );

		parent::tearDown();
	}

	/**
	 * @testdox Provider identity preserves the WooPayments gateway ID.
	 */
	public function test_provider_identity_preserves_woopayments_gateway_id(): void {
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $this->sut->get_id() );
		$this->assertInstanceOf( CapabilityManifest::class, $this->sut->get_capability_manifest() );
	}

	/**
	 * @testdox Provider identity exposes the WooPayments persistence profile.
	 */
	public function test_provider_identity_exposes_woopayments_persistence_profile(): void {
		$profile = $this->sut->get_persistence_profile();

		$this->assertInstanceOf( ProviderPersistenceProfile::class, $profile );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $profile->get_gateway_id() );
	}

	/**
	 * @testdox A3 provider publishes money-moving operations once native processing exists.
	 */
	public function test_provider_publishes_money_moving_operations_for_native_processing(): void {
		foreach (
			array(
				'get_payment_gateways',
				'charge',
				'capture',
				'cancel',
				'refund',
			) as $method
		) {
			$this->assertTrue( method_exists( $this->sut, $method ), "{$method} must be exposed through ProviderContract for A3." );
		}
	}

	/**
	 * @testdox Provider exposes and delegates the optional pre- and post-lifecycle effect ports.
	 */
	public function test_provider_delegates_woopayments_operation_effects(): void {
		$order           = wc_create_order();
		$context         = PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_effects' );
		$effect_plan     = WooPaymentsOrderEffectPlan::for_payment_intent( array( 'status' => 'succeeded' ), false );
		$outcome         = ( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_effects' ) )->with_effect_plan( $effect_plan );
		$gateway_adapter = $this->getMockBuilder( WooPaymentsProviderGatewayAdapter::class )
			->disableOriginalConstructor()
			->getMock();
		$api_client      = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->getMock();
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->getMock();
		$effect_applier  = $this->getMockBuilder( WooPaymentsOrderEffectApplier::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'apply', 'apply_payment_method_display_details' ) )
			->getMock();
		$effect_applier->expects( $this->once() )
			->method( 'apply' )
			->with( $context, $outcome, $effect_plan )
			->willReturn( $outcome );
		$effect_applier->expects( $this->once() )
			->method( 'apply_payment_method_display_details' )
			->with( $order, array( 'status' => 'succeeded' ) );

		$provider = new WooPaymentsProvider();
		$provider->init( $gateway_adapter, $api_client, $account_service, null, $effect_applier );

		$this->assertInstanceOf( ProviderOperationEffectApplier::class, $provider );
		$this->assertInstanceOf( ProviderPostLifecycleEffectApplier::class, $provider );
		$this->assertSame( $outcome, $provider->apply_operation_effects( $context, $outcome, 'charge' ) );
		$provider->apply_post_lifecycle_effects( $context, $outcome, 'charge' );
	}

	/**
	 * @testdox Provider should publish gateway identity independently of transient capability state.
	 */
	public function test_provider_publishes_native_gateway_instances_for_active_payment_method_definitions(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'saved_cards' => 'yes',
			)
		);

		$provider = $this->create_provider_with_capabilities(
			array(
				'card_payments'       => 'active',
				'link_payments'       => 'active',
				'klarna_payments'     => 'active',
				'sepa_debit_payments' => 'active',
				'affirm_payments'     => 'unrequested',
			)
		);
		$gateways = $provider->get_payment_gateways();

		$gateway_ids = array_map(
			static fn( NativeWooPaymentsGateway $gateway ): string => $gateway->id,
			$gateways
		);
		$this->assertContains( OrderPaymentStore::GATEWAY_ID, $gateway_ids );
		$this->assertContains( OrderPaymentStore::GATEWAY_ID . '_klarna', $gateway_ids );
		$this->assertContains( OrderPaymentStore::GATEWAY_ID . '_sepa_debit', $gateway_ids );
		$this->assertContains( OrderPaymentStore::GATEWAY_ID . '_affirm', $gateway_ids );
		$this->assertContains( OrderPaymentStore::GATEWAY_ID . '_apple_pay', $gateway_ids );
		$this->assertContains( OrderPaymentStore::GATEWAY_ID . '_google_pay', $gateway_ids );
		$this->assertNotContains( OrderPaymentStore::GATEWAY_ID . '_link', $gateway_ids );

		$klarna_gateway = $provider->get_gateway_for_method( 'klarna' );
		$link_gateway   = $provider->get_gateway_for_method( 'link' );

		$this->assertInstanceOf( NativeWooPaymentsGateway::class, $klarna_gateway );
		$this->assertInstanceOf( NativeWooPaymentsGateway::class, $link_gateway );
		$this->assertInstanceOf( NativeWooPaymentsGateway::class, $provider->get_gateway_for_method( 'affirm' ) );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $provider->get_gateway_for_method( 'card' )->id );
		$this->assertSame( 'Klarna', $klarna_gateway->get_title() );
		$this->assertSame( 'WooPayments (Klarna)', $klarna_gateway->method_title );
		$this->assertFalse( $klarna_gateway->supports( PaymentGatewayFeature::TOKENIZATION ) );
		$this->assertTrue( $link_gateway->supports( PaymentGatewayFeature::TOKENIZATION ) );
		$this->assertSame( $gateways, $provider->get_payment_gateways(), 'Provider should cache split gateway instances for the request.' );
	}

	/**
	 * @testdox Classic checkout gateway publication honors the filtered availability catalog once.
	 */
	public function test_provider_filters_classic_checkout_gateway_publication_once(): void {
		$filter_calls = 0;
		add_filter(
			'wcpay_upe_available_payment_methods',
			static function ( array $payment_method_ids ) use ( &$filter_calls ): array {
				++$filter_calls;

				return array_values( array_diff( $payment_method_ids, array( 'bancontact' ) ) );
			}
		);
		$provider = $this->create_provider_with_capabilities( array( 'bancontact_payments' => 'active' ) );

		$gateway_ids = array_map(
			static fn( NativeWooPaymentsGateway $gateway ): string => $gateway->id,
			$provider->get_payment_gateways()
		);
		$provider->get_payment_gateways();

		$this->assertNotContains( OrderPaymentStore::GATEWAY_ID . '_bancontact', $gateway_ids, 'A filtered method should not be published to classic checkout.' );
		$this->assertSame( 1, $filter_calls, 'The request-scoped gateway map should compute availability only once.' );
	}

	/**
	 * @testdox Provider should preserve gateway identity independently of transient account capability state.
	 */
	public function test_provider_builds_gateways_for_inactive_payment_method_definitions(): void {
		$provider = $this->create_provider_with_capabilities(
			array(
				'card_payments'   => 'restricted',
				'affirm_payments' => 'unrequested',
			)
		);

		$this->assertInstanceOf( NativeWooPaymentsGateway::class, $provider->get_gateway_for_method( 'card' ) );
		$this->assertInstanceOf( NativeWooPaymentsGateway::class, $provider->get_gateway_for_method( 'affirm' ) );
		$this->assertContains(
			OrderPaymentStore::GATEWAY_ID . '_affirm',
			array_map(
				static fn( NativeWooPaymentsGateway $gateway ): string => $gateway->id,
				$provider->get_payment_gateways()
			)
		);
	}

	/**
	 * @testdox Amazon Pay identity is published only when its shared feature prerequisites are enabled.
	 */
	public function test_provider_applies_amazon_pay_feature_policy_when_building_gateways(): void {
		update_option( '_wcpay_feature_amazon_pay', '1' );
		$confirmation_tokens_disabled = $this->create_provider_with_capabilities(
			array( 'amazon_pay_payments' => 'active' ),
			array( 'ece_confirmation_tokens_disabled' => true )
		);
		$this->assertNull( $confirmation_tokens_disabled->get_gateway_for_method( 'amazon_pay' ) );

		update_option( '_wcpay_feature_amazon_pay', '0' );
		$feature_disabled = $this->create_provider_with_capabilities(
			array( 'amazon_pay_payments' => 'active' ),
			array( 'ece_confirmation_tokens_disabled' => false )
		);
		$this->assertNull( $feature_disabled->get_gateway_for_method( 'amazon_pay' ) );

		update_option( '_wcpay_feature_amazon_pay', '1' );
		$enabled = $this->create_provider_with_capabilities(
			array( 'amazon_pay_payments' => 'active' ),
			array( 'ece_confirmation_tokens_disabled' => false )
		);
		$this->assertInstanceOf( NativeWooPaymentsGateway::class, $enabled->get_gateway_for_method( 'amazon_pay' ) );
	}

	/**
	 * Create a WooPayments provider with account capability fixture data.
	 *
	 * @param array<string,string> $capabilities Account capability status map.
	 * @param array<string,mixed>  $account_data Account data overrides.
	 * @return WooPaymentsProvider
	 */
	private function create_provider_with_capabilities( array $capabilities, array $account_data = array() ): WooPaymentsProvider {
		$gateway_adapter = $this->getMockBuilder( WooPaymentsProviderGatewayAdapter::class )
			->disableOriginalConstructor()
			->getMock();
		$api_client      = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->getMock();
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$account_service
			->method( 'get_cached_account_data' )
			->willReturn(
				array_merge( array( 'capabilities' => $capabilities ), $account_data )
			);

		$provider = new WooPaymentsProvider();
		$provider->init( $gateway_adapter, $api_client, $account_service );

		return $provider;
	}

	/**
	 * @testdox Provider capabilities should expose the WooPayments native processing surface.
	 */
	public function test_provider_capabilities_expose_native_processing_surface(): void {
		$manifest = $this->sut->get_capability_manifest();

		foreach (
			array(
				CapabilityManifest::CAPABILITY_CARDS,
				CapabilityManifest::CAPABILITY_SAVED_TOKENS,
				CapabilityManifest::CAPABILITY_MANDATES,
				CapabilityManifest::CAPABILITY_ASYNC_REDIRECT,
				CapabilityManifest::CAPABILITY_REFUNDS,
				CapabilityManifest::CAPABILITY_PARTIAL_REFUNDS,
				CapabilityManifest::CAPABILITY_MANUAL_CAPTURE,
				CapabilityManifest::CAPABILITY_EXPRESS_CHECKOUT,
				CapabilityManifest::CAPABILITY_HOSTED_SESSION,
				CapabilityManifest::CAPABILITY_SUBSCRIPTIONS,
				CapabilityManifest::CAPABILITY_IN_PERSON,
			) as $capability
		) {
			$this->assertTrue( $manifest->supports( $capability ), "{$capability} should be declared for WooPayments native processing." );
		}
	}

	/**
	 * @testdox Provider availability requires native transport and account readiness.
	 *
	 * @dataProvider provider_native_readiness
	 *
	 * @param bool $transport_available Whether native transport is available.
	 * @param bool $account_ready       Whether the account can process payments.
	 * @param bool $expected            Expected readiness.
	 */
	public function test_can_process_payments_requires_native_transport_and_account_readiness( bool $transport_available, bool $account_ready, bool $expected ): void {
		$gateway_adapter = $this->getMockBuilder( WooPaymentsProviderGatewayAdapter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available' ) )
			->getMock();
		$gateway_adapter
			->expects( $this->never() )
			->method( 'is_available' );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available' ) )
			->getMock();
		$api_client
			->expects( $this->once() )
			->method( 'is_available' )
			->willReturn( $transport_available );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();

		if ( $transport_available ) {
			$account_service
				->expects( $this->once() )
				->method( 'can_process_payments' )
				->willReturn( $account_ready );
		} else {
			$account_service
				->expects( $this->never() )
				->method( 'can_process_payments' );
		}

		$provider = new WooPaymentsProvider();
		$provider->init( $gateway_adapter, $api_client, $account_service );

		$this->assertSame( $expected, $provider->can_process_payments() );
	}

	/**
	 * @testdox Provider onboarding availability requires native transport, not account readiness.
	 *
	 * @dataProvider boolean_provider
	 *
	 * @param bool $transport_available Whether native transport is available.
	 */
	public function test_can_manage_onboarding_requires_native_transport_only( bool $transport_available ): void {
		$gateway_adapter = $this->getMockBuilder( WooPaymentsProviderGatewayAdapter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available' ) )
			->getMock();
		$gateway_adapter
			->expects( $this->never() )
			->method( 'is_available' );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available' ) )
			->getMock();
		$api_client
			->expects( $this->once() )
			->method( 'is_available' )
			->willReturn( $transport_available );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$account_service
			->expects( $this->never() )
			->method( 'can_process_payments' );

		$provider = new WooPaymentsProvider();
		$provider->init( $gateway_adapter, $api_client, $account_service );

		$this->assertSame( $transport_available, $provider->can_manage_onboarding() );
	}

	/**
	 * Data provider for native provider readiness.
	 *
	 * @return array<string,array{bool,bool,bool}>
	 */
	public function provider_native_readiness(): array {
		return array(
			'transport and account ready' => array( true, true, true ),
			'transport unavailable'       => array( false, true, false ),
			'account unavailable'         => array( true, false, false ),
		);
	}

	/**
	 * Data provider for boolean inputs.
	 *
	 * @return array<string,array{bool}>
	 */
	public function boolean_provider(): array {
		return array(
			'true'  => array( true ),
			'false' => array( false ),
		);
	}

	/**
	 * @testdox Provider should receive the gateway adapter through dependency injection.
	 */
	public function test_provider_gateway_adapter_access_is_injected(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local plugin source for provider-boundary regression coverage.
		$source = (string) file_get_contents( WC()->plugin_path() . '/src/Internal/Payments/Providers/WooPayments/WooPaymentsProvider.php' );

		$this->assertDoesNotMatchRegularExpression(
			'/wc_get_container\(\)\s*->get\(\s*WooPaymentsProviderGatewayAdapter::class\s*\)/',
			$source,
			'WooPaymentsProvider should receive the gateway adapter through init injection.'
		);
		$this->assertStringNotContainsString(
			'get_gateway_adapter()->is_available()',
			$source,
			'WooPaymentsProvider readiness should use native transport and account readiness, not legacy gateway adapter availability.'
		);
	}
}
