<?php

namespace Automattic\WooCommerce\Tests\Admin\API;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Admin\Features\PaymentGatewaySuggestions\DefaultPaymentGateways;
use Automattic\WooCommerce\Admin\Features\PaymentGatewaySuggestions\EvaluateSuggestion;
use Automattic\WooCommerce\Admin\Features\PaymentGatewaySuggestions\Init;
use Automattic\WooCommerce\Admin\Marketing\MarketingCampaign;
use Automattic\WooCommerce\Admin\Marketing\MarketingCampaignType;
use Automattic\WooCommerce\Admin\Marketing\MarketingChannelInterface;
use Automattic\WooCommerce\Admin\Marketing\MarketingChannels as MarketingChannelsService;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;

/**
 * PaymentGatewaySuggestionsTest API controller test.
 *
 * @class PaymentGatewaySuggestionsTest.
 */
class PaymentGatewaySuggestionsTest extends WC_REST_Unit_Test_Case {
	/**
	 * Endpoint.
	 *
	 * @var string
	 */
	const ENDPOINT = '/wc-admin/payment-gateway-suggestions';

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		// Register an administrator user and log in.
		$this->user = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $this->user );
	}

	/**
	 * Test it clears cache when the base country gets updated.
	 *
	 * @return void
	 */
	public function test_it_clears_cache_when_the_base_country_gets_updated() {
		// Clear any existing cache first.
		Init::delete_specs_transient();

		$existing_base_country = wc_get_base_location();
		// update the base country to the U.S for testing purposes.
		update_option( 'woocommerce_default_country', 'US:CA' );

		$response_mock_ref = function ( $preempt, $parsed_args, $url ) {
			if ( str_contains( $url, 'https://woocommerce.com/wp-json/wccom/payment-gateway-suggestions/2.0/suggestions.json' ) ) {
				return array(
					'success' => true,
					'body'    => wp_json_encode(
						array(
							array(
								'id' => wc_get_base_location()['country'],
							),
						)
					),
				);
			}

			return $preempt;
		};

		// Make a new request -- this should populate the cache with the base country.
		add_filter( 'pre_http_request', $response_mock_ref, 10, 3 );
		$request  = new WP_REST_Request( 'GET', self::ENDPOINT );
		$response = rest_get_server()->dispatch( $request )->get_data();

		// Confirm the current data returns id = US.
		$this->assertEquals( 'US', $response[0]->id );

		// Remove filter just in case and a new request still returns the cached data.
		remove_filter( 'pre_http_request', $response_mock_ref );
		$response = rest_get_server()->dispatch( $request )->get_data();
		$this->assertEquals( 'US', $response[0]->id );

		add_filter( 'pre_http_request', $response_mock_ref, 10, 3 );

		// Update the base country to CA.
		update_option( 'woocommerce_default_country', 'CA:ON' );

		// Make a new request -- this should populate the cache with the updated country.
		$response = rest_get_server()->dispatch( $request )->get_data();
		$this->assertEquals( 'CA', $response[0]->id );

		// Clean up.
		remove_filter( 'pre_http_request', $response_mock_ref );

		// restore the base country.
		update_option( 'woocommerce_default_country', $existing_base_country['country'] . ':' . $existing_base_country['state'] );
	}

	/**
	 * @testdox Should not advertise WooPayments suggestions as WooPayments plugin installs.
	 */
	public function test_woopayments_suggestions_do_not_include_plugin_install_metadata() {
		$woopayments_suggestions = array_filter(
			DefaultPaymentGateways::get_all(),
			function ( $suggestion ) {
				return isset( $suggestion['id'] ) && str_starts_with( $suggestion['id'], 'woocommerce_payments' );
			}
		);

		$this->assertNotEmpty( $woopayments_suggestions );

		foreach ( $woopayments_suggestions as $suggestion ) {
			$this->assertNotContains( 'woocommerce-payments', $suggestion['plugins'] ?? array() );
		}
	}

	/**
	 * @testdox Should preserve WooPayments plugin install metadata for merged feature development.
	 */
	public function test_woopayments_suggestions_include_plugin_install_metadata_for_merged_feature_development() {
		Constants::set_constant( 'WC_ALLOW_MERGED_FEATURE_PLUGINS', true );

		try {
			$woopayments_suggestions = array_filter(
				DefaultPaymentGateways::get_all(),
				function ( $suggestion ) {
					return isset( $suggestion['id'] ) && str_starts_with( $suggestion['id'], 'woocommerce_payments' );
				}
			);

			$this->assertNotEmpty( $woopayments_suggestions );
			foreach ( $woopayments_suggestions as $suggestion ) {
				$this->assertContains( 'woocommerce-payments', $suggestion['plugins'] ?? array() );
			}
		} finally {
			Constants::clear_single_constant( 'WC_ALLOW_MERGED_FEATURE_PLUGINS' );
		}
	}

	/**
	 * @testdox Should use native enablement instead of WooPayments plugin activation in native builds.
	 */
	public function test_woopayments_activation_rules_use_the_native_enablement_option() {
		$active_rule = DefaultPaymentGateways::get_rules_for_wcpay_activated( true );

		$this->assertSame( 'option', $active_rule->type );
		$this->assertSame( 'woocommerce_native_payments_enabled', $active_rule->option_name );
		$this->assertSame( '=', $active_rule->operation );
		$this->assertSame( 'yes', $active_rule->value );
		$this->assertSame( 'no', $active_rule->default );

		$inactive_rule = DefaultPaymentGateways::get_rules_for_wcpay_activated( false );
		$this->assertSame( 'not', $inactive_rule->type );
		$this->assertEquals( $active_rule, $inactive_rule->operand[0] );
	}

	/**
	 * @testdox Should retain WooPayments plugin activation rules for merged feature development.
	 */
	public function test_woopayments_activation_rules_use_the_plugin_in_merged_feature_development() {
		Constants::set_constant( 'WC_ALLOW_MERGED_FEATURE_PLUGINS', true );

		try {
			$active_rule = DefaultPaymentGateways::get_rules_for_wcpay_activated( true );

			$this->assertSame( 'plugins_activated', $active_rule->type );
			$this->assertSame( array( 'woocommerce-payments' ), $active_rule->plugins );
		} finally {
			Constants::clear_single_constant( 'WC_ALLOW_MERGED_FEATURE_PLUGINS' );
		}
	}

	/**
	 * @testdox Should strip legacy WooPayments plugin install metadata from remote suggestions.
	 */
	public function test_remote_woopayments_suggestions_do_not_include_plugin_install_metadata() {
		Init::delete_specs_transient();
		EvaluateSuggestion::reset_memo();

		$response_mock_ref = function ( $preempt, $parsed_args, $url ) {
			if ( str_contains( $url, 'https://woocommerce.com/wp-json/wccom/payment-gateway-suggestions/2.0/suggestions.json' ) ) {
				return array(
					'success' => true,
					'body'    => wp_json_encode(
						array(
							array(
								'id'         => 'woocommerce_payments:without-in-person-payments',
								'title'      => 'WooPayments',
								'plugins'    => array( 'woocommerce-payments' ),
								'is_visible' => true,
							),
							array(
								'id'         => 'stripe',
								'title'      => 'Stripe',
								'plugins'    => array( 'woocommerce-gateway-stripe' ),
								'is_visible' => true,
							),
						)
					),
				);
			}

			return $preempt;
		};

		add_filter( 'pre_http_request', $response_mock_ref, 10, 3 );

		$request     = new WP_REST_Request( 'GET', self::ENDPOINT );
		$suggestions = rest_get_server()->dispatch( $request )->get_data();

		remove_filter( 'pre_http_request', $response_mock_ref );
		Init::delete_specs_transient();
		EvaluateSuggestion::reset_memo();

		$woopayments_suggestions = array_filter(
			$suggestions,
			function ( $suggestion ) {
				return isset( $suggestion->id ) && str_starts_with( $suggestion->id, 'woocommerce_payments' );
			}
		);
		$stripe_suggestions      = array_filter(
			$suggestions,
			function ( $suggestion ) {
				return isset( $suggestion->id ) && 'stripe' === $suggestion->id;
			}
		);

		$this->assertNotEmpty( $woopayments_suggestions );
		foreach ( $woopayments_suggestions as $suggestion ) {
			$this->assertFalse( property_exists( $suggestion, 'plugins' ) );
		}

		$this->assertNotEmpty( $stripe_suggestions );
		$stripe_suggestion = reset( $stripe_suggestions );
		$this->assertContains( 'woocommerce-gateway-stripe', $stripe_suggestion->plugins );
	}

	/**
	 * @testdox Should retain remote WooPayments plugin install metadata for merged feature development.
	 */
	public function test_remote_woopayments_suggestions_include_plugin_install_metadata_for_merged_feature_development() {
		Init::delete_specs_transient();
		EvaluateSuggestion::reset_memo();
		Constants::set_constant( 'WC_ALLOW_MERGED_FEATURE_PLUGINS', true );

		$response_mock_ref = function ( $preempt, $parsed_args, $url ) {
			if ( str_contains( $url, 'https://woocommerce.com/wp-json/wccom/payment-gateway-suggestions/2.0/suggestions.json' ) ) {
				return array(
					'success' => true,
					'body'    => wp_json_encode(
						array(
							array(
								'id'         => 'woocommerce_payments:without-in-person-payments',
								'title'      => 'WooPayments',
								'plugins'    => array( 'woocommerce-payments' ),
								'is_visible' => true,
							),
						)
					),
				);
			}

			return $preempt;
		};

		add_filter( 'pre_http_request', $response_mock_ref, 10, 3 );

		try {
			$request     = new WP_REST_Request( 'GET', self::ENDPOINT );
			$suggestions = rest_get_server()->dispatch( $request )->get_data();
			$this->assertCount( 1, $suggestions );
			$this->assertTrue( property_exists( $suggestions[0], 'plugins' ) );
			$this->assertSame( array( 'woocommerce-payments' ), $suggestions[0]->plugins );
		} finally {
			remove_filter( 'pre_http_request', $response_mock_ref, 10 );
			Init::delete_specs_transient();
			EvaluateSuggestion::reset_memo();
			Constants::clear_single_constant( 'WC_ALLOW_MERGED_FEATURE_PLUGINS' );
		}
	}
}
