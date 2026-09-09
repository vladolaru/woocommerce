<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\PaymentGatewayFeature;
use Automattic\WooCommerce\Internal\Payments\CapabilityManifest;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\ProviderContract;
use Automattic\WooCommerce\Internal\Payments\ProviderOperationEffectApplier;
use Automattic\WooCommerce\Internal\Payments\ProviderOutcomeMetadataMapper;
use Automattic\WooCommerce\Internal\Payments\ProviderPostLifecycleEffectApplier;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceProfile;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabulary;
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
		delete_option( 'woocommerce_woocommerce_payments_affirm_settings' );
		delete_option( 'woocommerce_woocommerce_payments_klarna_settings' );
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
		$this->assertInstanceOf( ProviderPersistenceVocabulary::class, $profile );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $profile->get_gateway_id() );
	}

	/**
	 * @testdox Provider contracts keep persistence vocabulary separate from outcome mapping behavior.
	 */
	public function test_provider_contract_separates_persistence_vocabulary_from_outcome_mapping(): void {
		$return_type = ( new \ReflectionMethod( ProviderContract::class, 'get_persistence_profile' ) )->getReturnType();

		$this->assertSame( ProviderPersistenceVocabulary::class, (string) $return_type );
		$this->assertInstanceOf( ProviderOutcomeMetadataMapper::class, $this->sut );
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
		$call_sequence   = array();
		$gateway_adapter = $this->getMockBuilder( WooPaymentsProviderGatewayAdapter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'finalize_charge_idempotency_key' ) )
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
			->willReturnCallback(
				static function () use ( &$call_sequence, $outcome ): PaymentOutcome {
					$call_sequence[] = 'apply';
					return $outcome;
				}
			);
		$effect_applier->expects( $this->once() )
			->method( 'apply_payment_method_display_details' )
			->with( $order, array( 'status' => 'succeeded' ) )
			->willReturnCallback(
				static function () use ( &$call_sequence ): void {
					$call_sequence[] = 'display';
				}
			);
		$gateway_adapter->expects( $this->once() )
			->method( 'finalize_charge_idempotency_key' )
			->with( $order, $outcome )
			->willReturnCallback(
				function () use ( &$call_sequence ): void {
					$this->assertSame( array( 'apply', 'display' ), $call_sequence, 'Charge key finalization must follow payment-method display projection.' );
				}
			);

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
	 * @testdox Provider batches cold canonical and split gateway settings reads before construction.
	 */
	public function test_provider_batches_cold_canonical_and_split_gateway_settings_reads_before_construction(): void {
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'woocommerce_woocommerce_payments_klarna_settings' );
		delete_option( 'woocommerce_woocommerce_payments_affirm_settings' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_set( 'notoptions', array(), 'options' );

		$option_family_queries = array();
		$option_query_observer = static function ( string $query ) use ( &$option_family_queries ): string {
			if ( false !== strpos( $query, 'woocommerce_woocommerce_payments_' ) ) {
				$option_family_queries[] = $query;
			}

			return $query;
		};
		add_filter( 'query', $option_query_observer );
		try {
			$this->create_provider_with_capabilities( array() )->get_payment_gateways();
		} finally {
			remove_filter( 'query', $option_query_observer );
		}

		$this->assertCount( 1, $option_family_queries, 'Cold split gateway settings must be fetched in one batched option-family query.' );
		$this->assertStringContainsString( ' IN (', $option_family_queries[0], 'The option-family query must use a batched IN clause.' );
		$this->assertStringContainsString( "'woocommerce_woocommerce_payments_settings'", $option_family_queries[0], 'The canonical gateway setting must be primed.' );
		$this->assertStringContainsString( "'woocommerce_woocommerce_payments_klarna_settings'", $option_family_queries[0], 'The Klarna split setting must be primed.' );
		$this->assertStringContainsString( "'woocommerce_woocommerce_payments_affirm_settings'", $option_family_queries[0], 'The Affirm split setting must be primed.' );
	}

	/**
	 * @testdox Provider honors a stored split gateway enabled setting after cache priming.
	 */
	public function test_provider_honors_stored_split_gateway_enabled_setting_after_cache_priming(): void {
		update_option(
			'woocommerce_woocommerce_payments_klarna_settings',
			array(
				'enabled' => 'no',
			)
		);

		$gateway = $this->create_provider_with_capabilities( array() )->get_gateway_for_method( 'klarna' );

		$this->assertSame( 'no', $gateway->enabled, 'The stored Klarna setting must continue to control the derived gateway enabled state.' );
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
