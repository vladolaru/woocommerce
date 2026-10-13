<?php
/**
 * WooPaymentsExpressCheckoutAddressNormalizer class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use WP_REST_Request;

/**
 * Normalizes the addresses express checkout wallets send to the Store API (state keys, Hong Kong regions, address
 * lines and redacted postcodes) before Store API validation, for requests that carry a valid tokenized cart nonce.
 *
 * @since 11.2.0
 * @internal
 */
final class WooPaymentsExpressCheckoutAddressNormalizer {

	/**
	 * The tokenized cart nonce action, owned by the tokenized cart session controller.
	 */
	private const TOKENIZED_CART_NONCE_ACTION = WooPaymentsTokenizedCartSessionController::TOKENIZED_CART_NONCE_ACTION;

	private const TOKENIZED_CART_HEADER = 'X-WooPayments-Tokenized-Cart';

	private const TOKENIZED_CART_NONCE_HEADER = 'X-WooPayments-Tokenized-Cart-Nonce';

	private const HONG_KONG_REGION_CANDIDATES = array(
		'HONG KONG'       => array(
			'hong kong',
			'hongkong',
			'hong kong island',
			'港島',
			'central and western',
			'中西區',
			'kennedy town',
			'shek tong tsui',
			'sai ying pun',
			'sheung wan',
			'central',
			'admiralty',
			'mid-levels',
			'peak',
			'堅尼地城',
			'石塘咀',
			'西營盤',
			'上環',
			'中環',
			'金鐘',
			'半山區',
			'山頂',
			'wan chai',
			'灣仔',
			'causeway bay',
			'happy valley',
			'tai hang',
			'so kon po',
			"jardine's lookout",
			'銅鑼灣',
			'跑馬地',
			'大坑',
			'掃桿埔',
			'渣甸山',
			'eastern',
			'東區',
			'tin hau',
			'braemar hill',
			'north point',
			'quarry bay',
			'sai wan ho',
			'shau kei wan',
			'chai wan',
			'siu sai wan',
			'天后',
			'寶馬山',
			'北角',
			'鰂魚涌',
			'西灣河',
			'筲箕灣',
			'柴灣',
			'小西灣',
			'southern',
			'南區',
			'pok fu lam',
			'aberdeen',
			'ap lei chau',
			'wong chuk hang',
			'shouson hill',
			'repulse bay',
			'chung hom kok',
			'stanley',
			'tai tam',
			'shek o',
			'薄扶林',
			'香港仔',
			'鴨脷洲',
			'黃竹坑',
			'壽臣山',
			'淺水灣',
			'舂磡角',
			'赤柱',
			'大潭',
			'石澳',
		),
		'KOWLOON'         => array(
			'kowloon',
			'九龍',
			'yau tsim mong',
			'油尖旺',
			'tsim sha tsui',
			'yau ma tei',
			'west kowloon reclamation',
			"king's park, mong kok",
			'tai kok tsui',
			'尖沙咀',
			'油麻地',
			'西九龍填海區',
			'京士柏',
			'mong kok',
			'旺角',
			'大角咀',
			'sham shui po',
			'深水埗',
			'mei foo',
			'lai chi kok',
			'cheung sha wan',
			'shek kip mei',
			'yau yat tsuen',
			'tai wo ping',
			'stonecutters island',
			'美孚',
			'荔枝角',
			'長沙灣',
			'石硤尾',
			'又一村',
			'大窩坪',
			'昂船洲',
			'kowloon city',
			'九龍城',
			'hung hom',
			'to kwa wan',
			'ma tau kok',
			'ma tau wai',
			'kai tak',
			'ho man tin',
			'kowloon tong',
			'beacon hill',
			'紅磡',
			'土瓜灣',
			'馬頭角',
			'馬頭圍',
			'啟德',
			'何文田',
			'九龍塘',
			'筆架山',
			'wong tai sin',
			'黃大仙',
			'san po kong',
			'tung tau',
			'wang tau hom',
			'lok fu',
			'diamond hill',
			'tsz wan shan',
			'ngau chi wan',
			'新蒲崗',
			'東頭',
			'橫頭磡',
			'樂富',
			'鑽石山',
			'慈雲山',
			'牛池灣',
			'kwun tong',
			'觀塘',
			'ping shek',
			'kowloon bay',
			'ngau tau kok',
			'jordan valley',
			'sau mau ping',
			'lam tin',
			'yau tong',
			'lei yue mun',
			'坪石',
			'九龍灣',
			'牛頭角',
			'佐敦谷',
			'秀茂坪',
			'藍田',
			'油塘',
			'鯉魚門',
		),
		'NEW TERRITORIES' => array(
			'new territories',
			'新界',
			'kwai tsing',
			'葵青',
			'kwai chung',
			'tsing yi',
			'葵涌',
			'青衣',
			'tsuen wan',
			'荃灣',
			'lei muk shue',
			'ting kau',
			'sham tseng',
			'tsing lung tau',
			'ma wan',
			'sunny bay',
			'梨木樹',
			'汀九',
			'深井',
			'青龍頭',
			'馬灣',
			'欣澳',
			'tuen mun',
			'屯門',
			'tai lam chung',
			'so kwun wat',
			'lam tei',
			'大欖涌',
			'掃管笏',
			'藍地',
			'yuen long',
			'元朗',
			'hung shui kiu',
			'ha tsuen',
			'lau fau shan',
			'tin shui wai',
			'san tin',
			'lok ma chau',
			'kam tin',
			'shek kong',
			'pat heung',
			'洪水橋',
			'廈村',
			'流浮山',
			'天水圍',
			'新田',
			'落馬洲',
			'錦田',
			'石崗',
			'八鄉',
			'north',
			'north district',
			'北區',
			'fanling',
			'luen wo hui',
			'sheung shui',
			'shek wu hui',
			'sha tau kok',
			'luk keng',
			'wu kau tang',
			'粉嶺',
			'聯和墟',
			'上水',
			'石湖墟',
			'沙頭角',
			'鹿頸',
			'烏蛟騰',
			'tai po',
			'大埔',
			'tai po market',
			'tai po kau',
			'tai mei tuk',
			'shuen wan',
			'cheung muk tau',
			'kei ling ha',
			'大埔墟',
			'大埔滘',
			'大尾篤',
			'船灣',
			'樟木頭',
			'企嶺下',
			'sha tin',
			'沙田',
			'tai wai',
			'fo tan',
			'ma liu shui',
			'wu kai sha',
			'ma on shan',
			'大圍',
			'火炭',
			'馬料水',
			'烏溪沙',
			'馬鞍山',
			'sai kung',
			'西貢',
			'clear water bay',
			'tai mong tsai',
			'tseung kwan o',
			'hang hau',
			'tiu keng leng',
			'ma yau tong',
			'清水灣',
			'大網仔',
			'將軍澳',
			'坑口',
			'調景嶺',
			'馬游塘',
			'islands',
			'離島',
			'cheung chau',
			'peng chau',
			'lantau island (including tung chung)',
			'lamma island',
			'長洲',
			'坪洲',
			'大嶼山(包括東涌)',
			'南丫島',
		),
	);

	/**
	 * Normalized postcodes allowed to bypass validation for the active tokenized update-customer request.
	 *
	 * @var array<string,array<string,bool>>
	 */
	private array $tokenized_postcode_validation_allowlist = array();

	/**
	 * Normalize tokenized Store API address values before Store API validation.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 */
	public function normalize_tokenized_cart_store_api_addresses( WP_REST_Request $request ): void {
		if ( ! $this->should_normalize_tokenized_cart_address( $request ) ) {
			return;
		}

		$is_update_customer_route = '/wc/store/v1/cart/update-customer' === $request->get_route();
		if ( $is_update_customer_route ) {
			$this->tokenized_postcode_validation_allowlist = array();
			if ( false === has_filter( 'woocommerce_validate_postcode', array( $this, 'maybe_skip_postcode_validation' ) ) ) {
				add_filter( 'woocommerce_validate_postcode', array( $this, 'maybe_skip_postcode_validation' ), 10, 3 );
			}
			if ( false === has_filter( 'rest_post_dispatch', array( $this, 'clear_tokenized_postcode_validation' ) ) ) {
				add_filter( 'rest_post_dispatch', array( $this, 'clear_tokenized_postcode_validation' ), 10, 3 );
			}
		}

		foreach ( array( 'shipping_address', 'billing_address' ) as $address_key ) {
			$address = $request->get_param( $address_key );
			if ( is_array( $address ) ) {
				$address = $this->normalize_ece_address( $address, $is_update_customer_route );
				if ( $is_update_customer_route ) {
					$this->add_tokenized_postcode_validation_exception( $address );
				}
				$request->set_param( $address_key, $address );
			}
		}
	}

	/**
	 * Allow redacted postcodes that were padded for tokenized express-checkout shipping lookups.
	 *
	 * @param bool   $valid    Whether the postcode is valid.
	 * @param string $postcode Postcode value.
	 * @param string $country  Country code.
	 * @return bool
	 */
	public function maybe_skip_postcode_validation( $valid, $postcode, $country ): bool {
		$country = strtoupper( (string) $country );
		if ( ! in_array( $country, array( 'GB', 'CA' ), true ) ) {
			return (bool) $valid;
		}

		$postcode = $this->normalize_postcode_for_validation_allowlist( (string) $postcode );

		return isset( $this->tokenized_postcode_validation_allowlist[ $country ][ $postcode ] ) ? true : (bool) $valid;
	}

	/**
	 * Clear tokenized postcode validation state after the current Store API dispatch.
	 *
	 * @internal
	 *
	 * @param mixed $response REST response.
	 * @param mixed $server   REST server.
	 * @param mixed $request  REST request.
	 * @return mixed
	 */
	public function clear_tokenized_postcode_validation( $response, $server = null, $request = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- rest_post_dispatch passes three arguments.
		remove_filter( 'woocommerce_validate_postcode', array( $this, 'maybe_skip_postcode_validation' ), 10 );
		remove_filter( 'rest_post_dispatch', array( $this, 'clear_tokenized_postcode_validation' ), 10 );
		$this->tokenized_postcode_validation_allowlist = array();

		return $response;
	}

	/**
	 * Tell whether tokenized Store API address values may be normalized.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return bool
	 */
	private function should_normalize_tokenized_cart_address( WP_REST_Request $request ): bool {
		if ( 'true' !== $request->get_header( self::TOKENIZED_CART_HEADER ) ) {
			return false;
		}

		$nonce = (string) $request->get_header( self::TOKENIZED_CART_NONCE_HEADER );

		return '' !== $nonce && function_exists( 'wp_verify_nonce' ) && (bool) \wp_verify_nonce( $nonce, self::TOKENIZED_CART_NONCE_ACTION );
	}

	/**
	 * Normalize express-checkout address fields that Stripe wallet payloads can redact or reshape.
	 *
	 * @param array<string,mixed> $address            Address values.
	 * @param bool                $normalize_postcode Whether to normalize redacted postcode values.
	 * @return array<string,mixed>
	 */
	private function normalize_ece_address( array $address, bool $normalize_postcode ): array {
		$address = $this->normalize_ece_address_state( $address );
		$address = $this->normalize_ece_address_lines( $address );

		return $normalize_postcode ? $this->normalize_ece_address_postcode( $address ) : $address;
	}

	/**
	 * Normalize address state values to WooCommerce state keys when possible.
	 *
	 * @param array<string,mixed> $address Address values.
	 * @return array<string,mixed>
	 */
	private function normalize_ece_address_state( array $address ): array {
		$country = $this->sanitize_address_value( $address['country'] ?? '' );
		if ( '' === $country ) {
			return $address;
		}

		if ( 'HK' === $country ) {
			foreach ( array( $address['state'] ?? '', $address['postcode'] ?? '', $address['city'] ?? '' ) as $candidate ) {
				$region = $this->get_hong_kong_region_for_candidate( $this->sanitize_address_value( $candidate ) );
				if ( '' !== $region ) {
					$address['state'] = $region;
					break;
				}
			}
		}

		$state = $this->sanitize_address_value( $address['state'] ?? '' );
		if ( '' !== $state ) {
			$address['state'] = $this->get_normalized_state( $state, $country );
		}

		return $address;
	}

	/**
	 * Shift populated address lines so address_1 is present when any line is.
	 *
	 * @param array<string,mixed> $address Address values.
	 * @return array<string,mixed>
	 */
	private function normalize_ece_address_lines( array $address ): array {
		$lines = array_values(
			array_filter(
				array(
					trim( $this->sanitize_address_value( $address['address_1'] ?? '' ) ),
					trim( $this->sanitize_address_value( $address['address_2'] ?? '' ) ),
				),
				static function ( string $line ): bool {
					return '' !== $line;
				}
			)
		);

		if ( empty( $lines ) ) {
			return $address;
		}

		$address['address_1'] = $lines[0];
		$address['address_2'] = $lines[1] ?? '';

		return $address;
	}

	/**
	 * Normalize redacted postcode values used for tokenized shipping-rate lookup.
	 *
	 * @param array<string,mixed> $address Address values.
	 * @return array<string,mixed>
	 */
	private function normalize_ece_address_postcode( array $address ): array {
		$country  = $this->sanitize_address_value( $address['country'] ?? '' );
		$postcode = $this->sanitize_address_value( $address['postcode'] ?? '' );
		if ( '' !== $country && '' !== $postcode ) {
			$address['postcode'] = $this->get_normalized_postal_code( $postcode, $country );
		}

		return $address;
	}

	/**
	 * Normalize redacted postal-code values for countries where wallets can send partial values.
	 *
	 * @param string $postcode Postcode value.
	 * @param string $country  Country code.
	 * @return string
	 */
	private function get_normalized_postal_code( string $postcode, string $country ): string {
		$country = strtoupper( $country );

		if ( 'GB' === $country ) {
			$cleaned_postcode = preg_replace( '/[^A-Za-z0-9]/', '', $postcode );
			$cleaned_postcode = substr( is_string( $cleaned_postcode ) ? $cleaned_postcode : '', 0, 7 );

			return strlen( $cleaned_postcode ) >= 5 ? $cleaned_postcode : $cleaned_postcode . '000';
		}

		if ( 'CA' === $country ) {
			$cleaned_postcode = preg_replace( '/\s+/', '', $postcode );

			return str_pad( is_string( $cleaned_postcode ) ? $cleaned_postcode : '', 6, '0' );
		}

		return $postcode;
	}

	/**
	 * Add a normalized postcode to the request-scoped validation allowlist.
	 *
	 * @param array<string,mixed> $address Address values.
	 */
	private function add_tokenized_postcode_validation_exception( array $address ): void {
		$country  = strtoupper( $this->sanitize_address_value( $address['country'] ?? '' ) );
		$postcode = $this->normalize_postcode_for_validation_allowlist( $this->sanitize_address_value( $address['postcode'] ?? '' ) );
		if ( '' === $postcode || ! in_array( $country, array( 'GB', 'CA' ), true ) ) {
			return;
		}

		$this->tokenized_postcode_validation_allowlist[ $country ][ $postcode ] = true;
	}

	/**
	 * Normalize postcode strings for request-scoped validation comparisons.
	 *
	 * @param string $postcode Postcode value.
	 * @return string
	 */
	private function normalize_postcode_for_validation_allowlist( string $postcode ): string {
		$postcode = preg_replace( '/[^A-Za-z0-9]/', '', strtoupper( $postcode ) );

		return is_string( $postcode ) ? $postcode : '';
	}

	/**
	 * Normalize a wallet state value to a WooCommerce state key.
	 *
	 * @param string $state   State value.
	 * @param string $country Country code.
	 * @return string
	 */
	private function get_normalized_state( string $state, string $country ): string {
		if ( '' === $state || $this->is_normalized_state( $state, $country ) ) {
			return $state;
		}

		return $this->get_normalized_state_from_wc_states( $state, $country );
	}

	/**
	 * Tell whether a state value is already a WooCommerce state key.
	 *
	 * @param string $state   State value.
	 * @param string $country Country code.
	 * @return bool
	 */
	private function is_normalized_state( string $state, string $country ): bool {
		if ( ! WC() || ! WC()->countries ) {
			return false;
		}

		$wc_states = WC()->countries->get_states( $country );

		return is_array( $wc_states ) && array_key_exists( $state, $wc_states );
	}

	/**
	 * Match a state value against WooCommerce's translated state labels.
	 *
	 * @param string $state   State value.
	 * @param string $country Country code.
	 * @return string
	 */
	private function get_normalized_state_from_wc_states( string $state, string $country ): string {
		if ( ! WC() || ! WC()->countries ) {
			return $state;
		}

		$wc_states        = WC()->countries->get_states( $country );
		$normalized_state = $this->normalize_address_candidate( $state );
		if ( ! is_array( $wc_states ) || '' === $normalized_state ) {
			return $state;
		}

		foreach ( $wc_states as $wc_state_abbr => $wc_state_value ) {
			$normalized_state_abbr  = $this->normalize_address_candidate( (string) $wc_state_abbr );
			$normalized_state_value = $this->normalize_address_candidate( (string) $wc_state_value );

			if (
				$normalized_state === $normalized_state_abbr ||
				$normalized_state === $normalized_state_value ||
				( '' !== $normalized_state_value && false !== strpos( $normalized_state, $normalized_state_value ) )
			) {
				return (string) $wc_state_abbr;
			}
		}

		return $state;
	}

	/**
	 * Resolve a Hong Kong region from wallet state, postcode, or city values.
	 *
	 * @param string $candidate Address candidate value.
	 * @return string
	 */
	private function get_hong_kong_region_for_candidate( string $candidate ): string {
		$normalized_candidate = $this->normalize_address_candidate( $candidate );
		if ( 'hongkong' === $normalized_candidate ) {
			$normalized_candidate = 'hong kong';
		}

		foreach ( self::HONG_KONG_REGION_CANDIDATES as $region => $region_candidates ) {
			foreach ( $region_candidates as $region_candidate ) {
				$normalized_region_candidate = $this->normalize_address_candidate( $region_candidate );
				if ( $normalized_candidate === $normalized_region_candidate ) {
					return $region;
				}
			}
		}

		return '';
	}

	/**
	 * Normalize an address value for loose matching.
	 *
	 * @param string $value Address value.
	 * @return string
	 */
	private function normalize_address_candidate( string $value ): string {
		$value = function_exists( 'remove_accents' ) ? remove_accents( $value ) : $value;
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/\s+/', ' ', $value );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Sanitize a scalar address value.
	 *
	 * @param mixed $value Address value.
	 * @return string
	 */
	private function sanitize_address_value( $value ): string {
		$sanitized_value = is_scalar( $value ) ? wc_clean( (string) $value ) : '';

		return is_scalar( $sanitized_value ) ? (string) $sanitized_value : '';
	}
}
