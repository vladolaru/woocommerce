<?php
/**
 * Shared registration and REST delegation for WooPayments abilities.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

use Automattic\WooCommerce\Abilities\AbilityDefinition;
use Automattic\WooCommerce\Internal\Abilities\Domain\AbstractDomainAbility;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Register WooPayments reads and execute them through their existing routes.
 *
 * @since 11.4.0
 */
abstract class AbstractAbility extends AbstractDomainAbility implements AbilityDefinition {
	protected const ROUTE            = '';
	protected const COLLECTION_KEY   = '';
	protected const DEFAULT_PER_PAGE = 25;
	protected const MAX_PER_PAGE     = 100;

	/**
	 * Arguments unique to this ability.
	 *
	 * @since 11.4.0
	 */
	abstract protected static function get_own_args(): array;

	/**
	 * Register the concrete callback with its own description and schemas.
	 *
	 * @since 11.4.0
	 */
	public static function get_registration_args(): array {
		$args = static::get_own_args() + array(
			'category'            => 'woocommerce',
			'execute_callback'    => array( static::class, 'execute' ),
			'permission_callback' => array( self::class, 'current_user_can_manage_woocommerce' ),
			'meta'                => static::get_meta(),
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => new \stdClass(),
				'properties'           => array(),
				'additionalProperties' => false,
			),
		);
		if ( '' !== static::COLLECTION_KEY ) {
			$args['output_schema'] = self::get_collection_output_schema( static::COLLECTION_KEY, array( 'type' => 'object' ) );
		}
		return $args;
	}

	/**
	 * Require the same merchant capability as the WooPayments routes.
	 *
	 * @since 11.4.0
	 */
	public static function current_user_can_manage_woocommerce(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Describe a read-only ability exposed through REST and MCP.
	 *
	 * @since 11.4.0
	 */
	protected static function get_meta(): array {
		return array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
		);
	}

	/**
	 * Describe a write with its own destructive and idempotent flags.
	 *
	 * @since 11.4.0
	 *
	 * @param bool $destructive Whether the write can destroy data.
	 * @param bool $idempotent  Whether repeating the write has the same effect.
	 * @return array Ability metadata.
	 */
	protected static function get_write_meta( bool $destructive, bool $idempotent ): array {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
			'show_in_rest' => true,
			'mcp'          => array( 'public' => false ),
		);
	}

	/**
	 * Paging fields shared by WooPayments collection schemas.
	 *
	 * @since 11.4.0
	 */
	protected static function get_pagination_input_properties(): array {
		return array(
			'page'     => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'default'     => 1,
				'description' => __( 'Page number to return (1-indexed).', 'woocommerce' ),
			),
			'per_page' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => self::MAX_PER_PAGE,
				'default'     => self::DEFAULT_PER_PAGE,
				'description' => __( 'Maximum number of items per page.', 'woocommerce' ),
			),
		);
	}

	/**
	 * Dispatch a single read or fetch the requested list page and its total.
	 *
	 * @since 11.4.0
	 *
	 * @param mixed $input Ability input after schema validation.
	 * @return array|WP_Error Route data or a delegation error.
	 */
	public static function execute( $input = null ) {
		$params = (array) $input;
		if ( '' === static::COLLECTION_KEY ) {
			return self::delegate_to_rest_controller( 'GET', static::ROUTE, $params );
		}

		$params += array(
			'page'     => 1,
			'per_page' => self::DEFAULT_PER_PAGE,
		);
		foreach ( array(
			'per_page' => 'pagesize',
			'orderby'  => 'sort',
			'order'    => 'direction',
		) as $alias => $key ) {
			if ( array_key_exists( $alias, $params ) ) {
				$params += array( $key => $params[ $alias ] );
				unset( $params[ $alias ] );
			}
		}
		$collection = self::delegate_to_rest_controller( 'GET', static::ROUTE, $params );
		if ( is_wp_error( $collection ) ) {
			return $collection;
		}

		return static::wrap_paginated_response( $collection, (int) $params['page'], max( 1, (int) $params['pagesize'] ) );
	}

	/**
	 * Count records through the matching summary, including when the requested page is empty.
	 *
	 * @since 11.4.0
	 *
	 * @param array $collection Response body from the list route.
	 * @param int   $page       Requested page number.
	 * @param int   $per_page   Requested page size.
	 * @return array|WP_Error Page of records or an error reading the total.
	 */
	protected static function wrap_paginated_response( array $collection, int $page, int $per_page ) {
		$summary = self::delegate_to_rest_controller( 'GET', static::ROUTE . '/summary' );
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		$count = $summary['count'] ?? null;
		if ( is_string( $count ) && ctype_digit( $count ) ) {
			$digits = ltrim( $count, '0' );
			$count  = '' === $digits ? 0 : filter_var( $digits, FILTER_VALIDATE_INT );
		} elseif ( is_float( $count ) && $count >= 0 && $count < PHP_INT_MAX && floor( $count ) === $count ) {
			$count = (int) $count;
		}
		if ( ! is_int( $count ) || $count < 0 ) {
			return new WP_Error( 'woopayments_ability_count_unavailable', __( 'The authorization count is unavailable.', 'woocommerce' ), array( 'status' => 502 ) );
		}

		return array(
			static::COLLECTION_KEY => $collection['data'] ?? array(),
			'total_pages'          => intdiv( $count, $per_page ) + (int) ( 0 !== $count % $per_page ),
			'page'                 => $page,
			'per_page'             => $per_page,
		);
	}

	/**
	 * Dispatch through WordPress so route permissions and REST filters also apply to ability callers.
	 *
	 * @since 11.4.0
	 *
	 * @param string $http_method Request method.
	 * @param string $route       Full REST route.
	 * @param array  $params      REST request parameters.
	 * @return array|WP_Error Response data or the route error.
	 */
	protected static function delegate_to_rest_controller( string $http_method, string $route, array $params = array() ) {
		$request = new WP_REST_Request( $http_method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = rest_do_request( $request );
		if ( $response instanceof WP_REST_Response && $response->is_error() ) {
			$response = $response->as_error();
		}
		if ( is_wp_error( $response ) ) {
			wc_get_logger()->error(
				sprintf( 'AbilitiesRegistrar delegation failed [%s %s]: %s (%s)', $http_method, $route, $response->get_error_message(), $response->get_error_code() ),
				array( 'source' => 'woopayments-abilities' )
			);
			return $response;
		}

		$data = $response instanceof WP_REST_Response ? $response->get_data() : null;
		return is_array( $data ) ? $data : array();
	}
}
