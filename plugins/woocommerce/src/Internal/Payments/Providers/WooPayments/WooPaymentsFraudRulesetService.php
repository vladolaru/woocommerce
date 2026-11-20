<?php
/**
 * WooPaymentsFraudRulesetService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\DatabaseCache;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilderFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StoreCurrencyLifecycleService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use WP_Error;

/**
 * Keeps the store's fraud protection ruleset in step with the platform.
 *
 * It reads the ruleset from a one-day transient, fetches it from the platform when the transient is missing, and creates
 * the Basic ruleset there when the platform has none, once per request. It builds the Standard and High rulesets,
 * validates submitted ones, saves the requested protection level, and updates the advanced ruleset's country check when the
 * selling locations change. Client 11.1.0 keeps the rulesets in `includes/fraud-prevention/class-fraud-risk-tools.php`
 * and the reads, the fetch and the selling-locations update on the gateway (`includes/class-wc-payment-gateway-wcpay.php:3674-3853`).
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsFraudRulesetService {

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * WooPayments API client.
	 *
	 * @var Client
	 */
	private Client $api_client;

	/**
	 * WooPayments logger.
	 *
	 * @var WooPaymentsLogger
	 */
	private WooPaymentsLogger $logger;

	/**
	 * Whether this request already ran fetch_or_create_fraud_ruleset().
	 *
	 * @var bool
	 */
	private bool $fraud_protection_settings_refreshed = false;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @param Client                    $api_client      WooPayments API client.
	 * @param WooPaymentsLogger         $logger          WooPayments logger.
	 */
	final public function init( WooPaymentsAccountService $account_service, Client $api_client, WooPaymentsLogger $logger ): void {
		$this->account_service = $account_service;
		$this->api_client      = $api_client;
		$this->logger          = $logger;
	}

	/**
	 * Save the protection level and ruleset a settings request asks for, as
	 * client 11.1.0 does on every settings save (`includes/admin/class-wc-rest-payments-settings-controller.php:1257-1306`);
	 * the platform keeps an unchanged ruleset as it is. An advanced level sent with the "error" sentinel saves nothing.
	 * A request that asks for none refreshes a Standard or High ruleset instead.
	 *
	 * The platform save comes first, so a rejected ruleset leaves the stored level, the cached ruleset and the account's cached AVS flag as they were.
	 * A ruleset with a purchase price or item count limit that would apply to every order, or to none, is refused before
	 * anything is sent or stored, unless it is the stored ruleset: a save that leaves the fraud rules as they are still goes through.
	 *
	 * @param array<string,mixed> $params Settings request parameters.
	 * @return WP_Error|null The refusal of a ruleset limit, or null.
	 * @throws ApiException When the platform rejects the ruleset.
	 */
	public function save_fraud_settings( array $params ): ?WP_Error {
		$fraud_settings = $this->get_requested_fraud_settings( $params );
		if ( null === $fraud_settings ) {
			$this->refresh_built_ruleset();
			return null;
		}

		$limits_error = self::get_limits_error( $fraud_settings['ruleset_config'] );
		if ( null !== $limits_error && ! $this->is_stored_fraud_ruleset( $fraud_settings['ruleset_config'] ) ) {
			return new WP_Error(
				'rest_invalid_param',
				sprintf(
					/* translators: %s: settings field key. */
					__( 'Invalid parameter(s): %s', 'woocommerce' ),
					'advanced_fraud_protection_settings'
				),
				array(
					'status'  => 400,
					'params'  => array( 'advanced_fraud_protection_settings' => $limits_error ),
					'details' => array(
						'advanced_fraud_protection_settings' => array(
							'code'    => 'rest_invalid_pattern',
							'message' => $limits_error,
							'data'    => null,
						),
					),
				)
			);
		}

		$this->api_client->save_fraud_ruleset( $fraud_settings['ruleset_config'] );
		set_transient( 'wcpay_fraud_protection_settings', $fraud_settings['ruleset_config'], DAY_IN_SECONDS );
		update_option( 'current_protection_level', $fraud_settings['protection_level'] );
		$this->sync_cached_fraud_mitigation_settings_after_fraud_save( $fraud_settings );

		return null;
	}

	/**
	 * Re-send the Standard or High ruleset when a rebuild from the selling countries, the currency threshold and the review
	 * setting differs from the cached one, or none is cached. It reads the stored level and the cache directly, so it makes
	 * no platform read and leaves the level alone. A platform failure is logged: the save it follows was not about fraud.
	 *
	 * @return void
	 */
	private function refresh_built_ruleset(): void {
		$level = get_option( 'current_protection_level', 'basic' );
		if ( ! in_array( $level, array( 'standard', 'high' ), true ) || ! $this->account_service->has_account() ) {
			return;
		}

		$ruleset = 'standard' === $level ? $this->get_standard_fraud_ruleset() : $this->get_high_fraud_ruleset();
		$cached  = get_transient( 'wcpay_fraud_protection_settings' );
		if ( is_array( $cached ) && self::normalize_fraud_ruleset( $cached ) === self::normalize_fraud_ruleset( $ruleset ) ) {
			return;
		}

		try {
			$this->api_client->save_fraud_ruleset( $ruleset );
			set_transient( 'wcpay_fraud_protection_settings', $ruleset, DAY_IN_SECONDS );
		} catch ( ApiException $e ) {
			$this->logger->log_always(
				'WooPayments fraud ruleset refresh after a settings save failed.',
				'warning',
				array(
					'error_code'  => WooPaymentsLogger::get_loggable_error_code( $e->get_error_code() ),
					'http_status' => $e->get_http_code(),
				)
			);
		}
	}

	/**
	 * Get the message for the first purchase price or item count limit in a ruleset that the platform would apply to every
	 * order, or to none: a value that is not a number, a limit below 1 as the platform reads it (`intval`, so 0.5 is 0, and an
	 * inclusive bound counts as whole units), or a minimum above the maximum in one rule, currency and `or` list. Every rule is
	 * read, since the platform runs these checks under any rule key.
	 *
	 * @param array<int|string,mixed> $ruleset Fraud ruleset.
	 * @return string|null
	 */
	private static function get_limits_error( array $ruleset ): ?string {
		foreach ( $ruleset as $rule ) {
			if ( ! is_array( $rule ) || ! is_array( $rule['check'] ?? null ) ) {
				continue;
			}

			$limits = array();
			foreach ( self::get_single_fraud_checks( $rule['check'] ) as list( $check, $is_disjunction ) ) {
				$key        = $check['key'] ?? null;
				$operator   = $check['operator'] ?? null;
				$is_minimum = in_array( $operator, array( 'less_than', 'less_or_equal' ), true );
				if ( ! in_array( $key, array( 'order_total', 'item_count' ), true ) || ( ! $is_minimum && ! in_array( $operator, array( 'greater_than', 'greater_or_equal' ), true ) ) ) {
					continue;
				}

				// The platform applies intval() to a number, and to a string's part before "|". A float is cast as it is: its
				// string form rounds 0.9999999999999999 up to 1 and caps a float above PHP_INT_MAX, which intval() wraps.
				$value  = $check['value'] ?? null;
				$parts  = is_string( $value ) ? explode( '|', $value, 2 ) : array( $value );
				$amount = $parts[0];
				if ( ! is_numeric( $amount ) || ( is_float( $amount ) && ! ( abs( $amount ) < 9.2e18 ) ) ) {
					return self::get_limit_message( $key, $is_minimum ? 'minimum' : 'maximum' );
				}

				// Amounts and counts are whole numbers, so "<= 5" is "< 6" and ">= 5" is "> 4".
				$amount = (int) $amount + ( 'less_or_equal' === $operator ? 1 : ( 'greater_or_equal' === $operator ? -1 : 0 ) );
				if ( $amount <= 0 ) {
					return self::get_limit_message( $key, $is_minimum ? 'minimum' : 'maximum' );
				}

				if ( $is_disjunction ) {
					// An item count has no currency; the platform compares a price without one in the order's own units.
					$currency = 'item_count' === $key ? '' : strtolower( (string) ( $parts[1] ?? '' ) );
					$limits[ $key ][ $is_minimum ? 'minimum' : 'maximum' ][] = array( $amount, $currency );
				}
			}

			foreach ( $limits as $key => $bounds ) {
				foreach ( $bounds['minimum'] ?? array() as list( $minimum, $minimum_currency ) ) {
					foreach ( $bounds['maximum'] ?? array() as list( $maximum, $maximum_currency ) ) {
						if ( ( $minimum_currency === $maximum_currency || '' === $minimum_currency || '' === $maximum_currency ) && $minimum > $maximum ) {
							return self::get_limit_message( $key, 'inverted' );
						}
					}
				}
			}
		}

		return null;
	}

	/**
	 * Whether a requested ruleset is the stored one, read as the settings page reads it, so a limit the extension let in does
	 * not block saves that leave the fraud rules unchanged. Compared strictly, after the two changes a JSON round trip
	 * through the page can make: key order, and a whole-number float read back as an integer.
	 *
	 * @param array<int|string,mixed> $ruleset Requested ruleset.
	 * @return bool
	 */
	private function is_stored_fraud_ruleset( array $ruleset ): bool {
		$stored_ruleset = $this->get_advanced_fraud_protection_settings();

		return is_array( $stored_ruleset ) && self::normalize_fraud_ruleset( $stored_ruleset ) === self::normalize_fraud_ruleset( $ruleset );
	}

	/**
	 * Normalize a ruleset, or a value inside one, for comparison: keys sorted at every level, and a whole-number float as an integer.
	 *
	 * @param mixed $value Ruleset, or a value inside one.
	 * @return mixed
	 */
	private static function normalize_fraud_ruleset( $value ) {
		if ( is_float( $value ) && floor( $value ) === $value && abs( $value ) < 9.2e18 ) {
			return (int) $value;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $child_value ) {
			$value[ $key ] = self::normalize_fraud_ruleset( $child_value );
		}

		return $value;
	}

	/**
	 * Get the single checks of a fraud rule's check, inside any `and` and `or` lists, each with whether every list above it is an `or`.
	 *
	 * @param array<int|string,mixed> $check          Fraud check.
	 * @param bool                    $is_disjunction Whether every list above this check is an `or`.
	 * @return array<int,array{0:array<int|string,mixed>,1:bool}>
	 */
	private static function get_single_fraud_checks( array $check, bool $is_disjunction = true ): array {
		$operator = $check['operator'] ?? null;
		if ( ! in_array( $operator, array( 'and', 'or' ), true ) ) {
			return array( array( $check, $is_disjunction ) );
		}

		$single_checks = array();
		foreach ( is_array( $check['checks'] ?? null ) ? $check['checks'] : array() as $child_check ) {
			if ( is_array( $child_check ) ) {
				$single_checks = array_merge( $single_checks, self::get_single_fraud_checks( $child_check, $is_disjunction && 'or' === $operator ) );
			}
		}

		return $single_checks;
	}

	/**
	 * Get the refusal message for a purchase price or item count limit.
	 *
	 * @param string $check_key Check key: order_total or item_count.
	 * @param string $problem   The refused limit: minimum, maximum or inverted.
	 * @return string
	 */
	private static function get_limit_message( string $check_key, string $problem ): string {
		$messages = array(
			'order_total' => array(
				'minimum'  => __( 'The minimum purchase price must be a number greater than zero.', 'woocommerce' ),
				'maximum'  => __( 'The maximum purchase price must be a number greater than zero.', 'woocommerce' ),
				'inverted' => __( 'Maximum purchase price must be greater than the minimum purchase price.', 'woocommerce' ),
			),
			'item_count'  => array(
				'minimum'  => __( 'The minimum items per order must be at least 1.', 'woocommerce' ),
				'maximum'  => __( 'The maximum items per order must be at least 1.', 'woocommerce' ),
				'inverted' => __( 'Maximum item count must be greater than the minimum item count.', 'woocommerce' ),
			),
		);

		return $messages[ $check_key ][ $problem ];
	}

	/**
	 * Get the protection level and ruleset a settings request asks for, or null when it asks for none.
	 *
	 * @param array<string,mixed> $params Request parameters.
	 * @return array{protection_level:string,ruleset_config:array<int|string,mixed>}|null
	 */
	private function get_requested_fraud_settings( array $params ): ?array {
		if ( ! array_key_exists( 'current_protection_level', $params ) || ! array_key_exists( 'advanced_fraud_protection_settings', $params ) ) {
			return null;
		}

		$protection_level = is_scalar( $params['current_protection_level'] ) ? (string) $params['current_protection_level'] : '';
		if ( ! in_array( $protection_level, array( 'basic', 'standard', 'high', 'advanced' ), true ) ) {
			return null;
		}

		$ruleset_config = $this->get_fraud_ruleset_for_protection_level( $protection_level, $params['advanced_fraud_protection_settings'] ?? array() );
		if ( null === $ruleset_config ) {
			return null;
		}

		return array(
			'protection_level' => $protection_level,
			'ruleset_config'   => $ruleset_config,
		);
	}

	/**
	 * Set the cached account's AVS check flag from the ruleset just saved.
	 *
	 * Runs for every protection level: the built-in rulesets never contain avs_verification, so stepping down from an
	 * advanced ruleset with AVS must overwrite the cached true.
	 *
	 * @param array{protection_level:string,ruleset_config:array<int|string,mixed>} $fraud_settings Saved fraud settings.
	 * @return void
	 */
	private function sync_cached_fraud_mitigation_settings_after_fraud_save( array $fraud_settings ): void {
		$account_data = $this->account_service->get_cached_account_data();
		if ( empty( $account_data ) ) {
			return;
		}

		$fraud_mitigation_settings                      = is_array( $account_data['fraud_mitigation_settings'] ?? null ) ? $account_data['fraud_mitigation_settings'] : array();
		$fraud_mitigation_settings['avs_check_enabled'] = $this->ruleset_contains_fraud_rule( $fraud_settings['ruleset_config'], 'avs_verification' );
		$account_data['fraud_mitigation_settings']      = $fraud_mitigation_settings;

		$this->account_service->cache_account_data( $account_data );
	}

	/**
	 * Check whether a fraud ruleset contains a rule key.
	 *
	 * @param array<int|string,mixed> $ruleset  Fraud ruleset.
	 * @param string                  $rule_key Rule key.
	 * @return bool
	 */
	private function ruleset_contains_fraud_rule( array $ruleset, string $rule_key ): bool {
		foreach ( $ruleset as $rule ) {
			if ( is_array( $rule ) && ( $rule['key'] ?? null ) === $rule_key ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the fraud ruleset for a protection level.
	 *
	 * @param string $protection_level Protection level.
	 * @param mixed  $advanced_ruleset Submitted advanced ruleset.
	 * @return array<int,array<string,mixed>>|null
	 */
	private function get_fraud_ruleset_for_protection_level( string $protection_level, $advanced_ruleset ): ?array {
		switch ( $protection_level ) {
			case 'basic':
				return array();
			case 'standard':
				return $this->get_standard_fraud_ruleset();
			case 'high':
				return $this->get_high_fraud_ruleset();
			case 'advanced':
				if ( ! is_array( $advanced_ruleset ) || ! self::is_valid_fraud_ruleset( $advanced_ruleset ) ) {
					return null;
				}

				return $advanced_ruleset;
		}

		return null;
	}

	/**
	 * Get the standard fraud protection ruleset.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_standard_fraud_ruleset(): array {
		$reviewable_outcome = $this->get_reviewable_fraud_outcome();

		return array(
			$this->get_international_ip_address_rule( $reviewable_outcome ),
			$this->get_rule( 'order_items_threshold', $reviewable_outcome, $this->get_check( 'item_count', 'greater_than', 10 ) ),
			$this->get_rule( 'purchase_price_threshold', $reviewable_outcome, $this->get_check( 'order_total', 'greater_than', $this->get_fraud_threshold_amount() ) ),
			$this->get_rule( 'ip_address_mismatch', $reviewable_outcome, $this->get_check( 'ip_billing_country_same', 'equals', false ) ),
		);
	}

	/**
	 * Get the high fraud protection ruleset.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_high_fraud_ruleset(): array {
		$reviewable_outcome = $this->get_reviewable_fraud_outcome();

		return array(
			$this->get_international_ip_address_rule( 'block' ),
			$this->get_rule( 'purchase_price_threshold', 'block', $this->get_check( 'order_total', 'greater_than', $this->get_fraud_threshold_amount() ) ),
			$this->get_rule(
				'order_items_threshold',
				$reviewable_outcome,
				array(
					'operator' => 'or',
					'checks'   => array(
						$this->get_check( 'item_count', 'less_than', 2 ),
						$this->get_check( 'item_count', 'greater_than', 10 ),
					),
				)
			),
			$this->get_rule( 'address_mismatch', $reviewable_outcome, $this->get_check( 'billing_shipping_address_same', 'equals', false ) ),
			$this->get_rule( 'ip_address_mismatch', $reviewable_outcome, $this->get_check( 'ip_billing_country_same', 'equals', false ) ),
		);
	}

	/**
	 * Get the outcome used by rules that can send fraud decisions to review.
	 *
	 * @return string
	 */
	private function get_reviewable_fraud_outcome(): string {
		return $this->is_fraud_protection_review_feature_active() ? 'review' : 'block';
	}

	/**
	 * Get the international IP address rule.
	 *
	 * @param string $outcome Rule outcome.
	 * @return array<string,mixed>
	 */
	private function get_international_ip_address_rule( string $outcome ): array {
		return $this->get_rule(
			'international_ip_address',
			$outcome,
			$this->get_check( 'ip_country', $this->get_selling_locations_type_operator(), $this->get_selling_locations_string() )
		);
	}

	/**
	 * Get a fraud protection rule array.
	 *
	 * @param string              $key     Rule key.
	 * @param string              $outcome Rule outcome.
	 * @param array<string,mixed> $check   Rule check.
	 * @return array<string,mixed>
	 */
	private function get_rule( string $key, string $outcome, array $check ): array {
		return array(
			'key'     => $key,
			'outcome' => $outcome,
			'check'   => $check,
		);
	}

	/**
	 * Get a fraud protection check array.
	 *
	 * @param string $key      Check key.
	 * @param string $operator Check operator.
	 * @param mixed  $value    Check value.
	 * @return array<string,mixed>
	 */
	private function get_check( string $key, string $operator, $value ): array {
		return array(
			'key'      => $key,
			'operator' => $operator,
			'value'    => $value,
		);
	}

	/**
	 * Validate a submitted fraud ruleset.
	 *
	 * Public so the settings REST boundary can reject a structurally invalid advanced ruleset before any persistence.
	 *
	 * @param array<int|string,mixed> $ruleset Submitted ruleset.
	 * @return bool
	 */
	public static function is_valid_fraud_ruleset( array $ruleset ): bool {
		foreach ( $ruleset as $rule ) {
			if ( ! is_array( $rule ) || ! self::is_valid_fraud_rule( $rule ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate a submitted fraud rule.
	 *
	 * @param array<int|string,mixed> $rule Submitted rule.
	 * @return bool
	 */
	private static function is_valid_fraud_rule( array $rule ): bool {
		return isset( $rule['key'], $rule['outcome'], $rule['check'] )
			&& is_string( $rule['key'] )
			&& in_array( $rule['outcome'], array( 'allow', 'block', 'review' ), true )
			&& is_array( $rule['check'] )
			&& self::is_valid_fraud_check( $rule['check'] );
	}

	/**
	 * Validate a submitted fraud check.
	 *
	 * @param array<int|string,mixed> $check Submitted check.
	 * @return bool
	 */
	private static function is_valid_fraud_check( array $check ): bool {
		if ( isset( $check['operator'] ) && in_array( $check['operator'], array( 'and', 'or' ), true ) ) {
			if ( empty( $check['checks'] ) || ! is_array( $check['checks'] ) ) {
				return false;
			}

			foreach ( $check['checks'] as $child_check ) {
				if ( ! is_array( $child_check ) || ! self::is_valid_fraud_check( $child_check ) ) {
					return false;
				}
			}

			return true;
		}

		return isset( $check['key'], $check['operator'] )
			&& in_array( $check['operator'], array( 'equals', 'not_equals', 'greater_than', 'greater_or_equal', 'less_than', 'less_or_equal', 'in', 'not_in' ), true )
			&& array_key_exists( 'value', $check );
	}

	/**
	 * Get the international selling-locations operator.
	 *
	 * @return string
	 */
	private function get_selling_locations_type_operator(): string {
		return 'specific' === get_option( 'woocommerce_allowed_countries', 'all' ) ? 'not_in' : 'in';
	}

	/**
	 * Get the lower-case pipe-separated selling-locations list.
	 *
	 * @return string
	 */
	private function get_selling_locations_string(): string {
		$selling_locations_type = get_option( 'woocommerce_allowed_countries', 'all' );
		if ( 'specific' === $selling_locations_type ) {
			$countries = get_option( 'woocommerce_specific_allowed_countries', array() );
		} elseif ( 'all_except' === $selling_locations_type ) {
			$countries = get_option( 'woocommerce_all_except_countries', array() );
		} else {
			$countries = array();
		}

		return is_array( $countries ) ? strtolower( implode( '|', $countries ) ) : '';
	}

	/**
	 * Get the Standard and High purchase-price threshold: $1,000 in the store currency, converted at the Multi-Currency USD
	 * rate when USD is an enabled currency, as client 11.1.0 builds it whatever its Multi-Currency flag says
	 * (includes/fraud-prevention/class-fraud-risk-tools.php:396-445). The platform converts the order total into the rule's
	 * currency, so with no USD rate at all the threshold stays in USD. The converted amount is written in the store currency's
	 * own minor units (whole yen for JPY), where client 11.1.0 treats it as cents whatever the currency.
	 *
	 * @return string
	 */
	private function get_fraud_threshold_amount(): string {
		$amount         = 100000;
		$store_currency = strtoupper( (string) get_option( 'woocommerce_currency', 'USD' ) );
		$enabled_codes  = get_option( StateBuilder::ENABLED_CURRENCIES_OPTION, array() );
		// The Multi-Currency rates are read only when USD is enabled, the one case that converts.
		if ( 'USD' !== $store_currency && is_array( $enabled_codes ) && in_array( 'USD', array_map( 'strtoupper', $enabled_codes ), true ) ) {
			$usd_rate = 0.0;
			// A USD rate, manual or fetched, is quoted against the store currency it was set or fetched for. Client 11.1.0
			// deletes the rates cache and asks the merchant to review manual rates when the store currency changes
			// (includes/multi-currency/MultiCurrency.php:321-336, :1832-1844); nothing does while Multi-Currency is off.
			if ( strtoupper( (string) get_option( StoreCurrencyLifecycleService::STORE_CURRENCY_OPTION, '' ) ) === $store_currency ) {
				$enabled_currencies = wc_get_container()->get( StateBuilderFactory::class )->get_state_builder()->build()->get_enabled_currencies();
				// With Multi-Currency off no rate provider runs, so an automatic rate is the one the rates cache still holds.
				$usd_rate = isset( $enabled_currencies['USD'] )
					? $enabled_currencies['USD']->get_rate()
					: (float) ( ( new DatabaseCache() )->get( DatabaseCache::CURRENCIES_KEY, true )['currencies']['USD'] ?? 0 );
			}
			if ( $usd_rate <= 0 ) {
				return '100000|usd';
			}
			$amount = WooPaymentsCurrencyUtils::amount_to_minor_units( 1000 / $usd_rate, $store_currency );
		}

		return $amount . '|' . strtolower( $store_currency );
	}

	/**
	 * Get the fraud protection level; can fetch the ruleset from the platform, and create the Basic ruleset there, when none is cached.
	 *
	 * @return string
	 */
	public function get_current_protection_level(): string {
		$this->fetch_or_create_fraud_ruleset();

		$level = get_option( 'current_protection_level', 'basic' );

		return is_scalar( $level ) ? (string) $level : 'basic';
	}

	/**
	 * Tell whether a rule is in the fraud ruleset; can fetch the ruleset from the platform, and create the Basic ruleset there, when none is cached.
	 *
	 * @since 11.0.0
	 *
	 * @param string $rule_key Fraud rule key (e.g. avs_verification).
	 * @return bool
	 */
	public function is_fraud_rule_active( string $rule_key ): bool {
		$ruleset = $this->get_advanced_fraud_protection_settings();

		return is_array( $ruleset ) && $this->ruleset_contains_fraud_rule( $ruleset, $rule_key );
	}

	/**
	 * Get the cached fraud ruleset, or "error"; can fetch it from the platform, and create the Basic ruleset there, when none is cached.
	 *
	 * @return array<int|string,mixed>|string
	 */
	public function get_advanced_fraud_protection_settings() {
		if ( ! $this->account_service->has_account() ) {
			return array();
		}

		$this->fetch_or_create_fraud_ruleset();

		$ruleset = get_transient( 'wcpay_fraud_protection_settings' );
		if ( is_array( $ruleset ) ) {
			return $ruleset;
		}

		return 'error';
	}

	/**
	 * Rebuild the advanced ruleset's international IP check from the current selling locations; can fetch the ruleset from the platform, and create the Basic ruleset there, when none is cached.
	 *
	 * Only an advanced ruleset carries the merchant's country check, and the platform is called only when that check
	 * changed (client 11.1.0 `includes/class-wc-payment-gateway-wcpay.php:3818-3853`). The rule keeps the merchant's
	 * block or review outcome, where client 11.1.0 replaces the whole rule (`:3832-3843`).
	 *
	 * @since 11.2.0
	 *
	 * @return void
	 */
	public function update_fraud_rules_for_selling_locations(): void {
		if ( 'advanced' !== $this->get_current_protection_level() ) {
			return;
		}

		$ruleset = $this->get_advanced_fraud_protection_settings();
		if ( ! is_array( $ruleset ) || ! self::is_valid_fraud_ruleset( $ruleset ) ) {
			return;
		}

		$needs_update = false;
		foreach ( $ruleset as &$rule ) {
			if ( ! is_array( $rule ) || 'international_ip_address' !== ( $rule['key'] ?? null ) ) {
				continue;
			}

			$new_check = $this->get_check( 'ip_country', $this->get_selling_locations_type_operator(), $this->get_selling_locations_string() );
			if ( isset( $rule['check'] ) && wp_json_encode( $rule['check'] ) !== wp_json_encode( $new_check ) ) {
				$rule['check'] = $new_check;
				$needs_update  = true;
			}
		}
		unset( $rule );

		if ( ! $needs_update ) {
			return;
		}

		try {
			$this->api_client->save_fraud_ruleset( $ruleset );
			set_transient( 'wcpay_fraud_protection_settings', $ruleset, DAY_IN_SECONDS );
		} catch ( ApiException $e ) {
			// The only caller runs at shutdown, after the response is sent, so a failure can only be logged.
			$this->logger->log_always(
				'WooPayments fraud ruleset update for new selling locations failed.',
				'warning',
				array(
					'error_code'  => WooPaymentsLogger::get_loggable_error_code( $e->get_error_code() ),
					'http_status' => $e->get_http_code(),
				)
			);
		}
	}

	/**
	 * Fetch the fraud ruleset from the platform when none is cached, or create the Basic ruleset there when the platform has none; runs once per request.
	 *
	 * The settings page, WooCommerce > Status, the selling-locations sync and the AVS check of a declined card payment read
	 * the ruleset through it, so each can create the Basic ruleset on the platform; so can a settings save whose ruleset limits are refused, which compares them with the stored ruleset.
	 * Client 11.1.0 also creates Basic through ruleset reads, including its Status page
	 * (`includes/class-wc-payments-status.php:552-562`, `includes/class-wc-payment-gateway-wcpay.php:3108-3111,3792-3805`).
	 *
	 * @return void
	 */
	private function fetch_or_create_fraud_ruleset(): void {
		if ( $this->fraud_protection_settings_refreshed ) {
			return;
		}

		if ( is_array( get_transient( 'wcpay_fraud_protection_settings' ) ) ) {
			return;
		}

		$this->fraud_protection_settings_refreshed = true;

		if ( ! $this->account_service->has_account() ) {
			return;
		}

		try {
			$latest_ruleset = $this->api_client->get_latest_fraud_ruleset();
			$ruleset        = $latest_ruleset['ruleset_config'] ?? null;

			if ( is_array( $ruleset ) && self::is_valid_fraud_ruleset( $ruleset ) ) {
				set_transient( 'wcpay_fraud_protection_settings', $ruleset, DAY_IN_SECONDS );
				update_option( 'current_protection_level', $this->get_matching_fraud_protection_level( $ruleset ) );
			} else {
				$this->log_fraud_ruleset_refresh_warning( 'WooPayments fraud ruleset refresh returned an invalid ruleset.' );
			}
		} catch ( ApiException $e ) {
			if ( 'wcpay_fraud_ruleset_not_found' !== $e->get_error_code() ) {
				$this->log_fraud_ruleset_refresh_warning(
					'WooPayments fraud ruleset refresh failed.',
					array(
						'error_code'  => WooPaymentsLogger::get_loggable_error_code( $e->get_error_code() ),
						'http_status' => $e->get_http_code(),
					)
				);
				return;
			}

			try {
				$basic_ruleset = $this->get_fraud_ruleset_for_protection_level( 'basic', array() );
				if ( ! is_array( $basic_ruleset ) ) {
					return;
				}

				$this->api_client->save_fraud_ruleset( $basic_ruleset );
				set_transient( 'wcpay_fraud_protection_settings', $basic_ruleset, DAY_IN_SECONDS );
				update_option( 'current_protection_level', 'basic' );
			} catch ( ApiException $save_exception ) {
				$this->log_fraud_ruleset_refresh_warning(
					'WooPayments fraud ruleset Basic initialization failed.',
					array(
						'error_code'  => WooPaymentsLogger::get_loggable_error_code( $save_exception->get_error_code() ),
						'http_status' => $save_exception->get_http_code(),
					)
				);
				return;
			}
		}
	}

	/**
	 * Match a ruleset config to a known protection level.
	 *
	 * @param array<int|string,mixed> $ruleset Ruleset config.
	 * @return string
	 */
	private function get_matching_fraud_protection_level( array $ruleset ): string {
		if ( array() === $ruleset ) {
			return 'basic';
		}

		if ( $this->get_standard_fraud_ruleset() === $ruleset ) {
			return 'standard';
		}

		if ( $this->get_high_fraud_ruleset() === $ruleset ) {
			return 'high';
		}

		return 'advanced';
	}

	/**
	 * Log a fraud ruleset refresh warning.
	 *
	 * @param string              $message Warning message.
	 * @param array<string,mixed> $context Log context.
	 * @return void
	 */
	private function log_fraud_ruleset_refresh_warning( string $message, array $context = array() ): void {
		$this->logger->log( $message, 'warning', $context );
	}

	/**
	 * Tell whether fraud-protection review outcomes are enabled.
	 *
	 * @return bool
	 */
	public function is_fraud_protection_review_feature_active(): bool {
		return '1' === (string) get_option( 'wcpay_frt_review_feature_active', '0' );
	}
}
