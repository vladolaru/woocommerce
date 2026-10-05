<?php
/**
 * REST endpoint to refresh feature status.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint;

use WP_REST_Server;
use WP_REST_Response;
use WP_REST_Request;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\Cache;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\SellerTypeResolver;

/**
 * REST controller for refreshing feature status.
 */
class RefreshFeatureStatusEndpoint extends RestEndpoint {
	/**
	 * The base path for this REST controller.
	 *
	 * @var string
	 */
	protected $rest_base = 'refresh-features';

	/**
	 * Cache timeout in seconds.
	 *
	 * @var int
	 */
	private const TIMEOUT = 60;

	/**
	 * Cache key for tracking request timeouts.
	 *
	 * @var string
	 */
	private const CACHE_KEY = 'refresh_feature_status_timeout';

	/**
	 * The cache.
	 *
	 * @var Cache
	 */
	protected Cache $cache;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	protected LoggerInterface $logger;

	/**
	 * The seller type resolver.
	 *
	 * @var SellerTypeResolver
	 */
	protected SellerTypeResolver $seller_type_resolver;

	/**
	 * The general settings.
	 *
	 * @var GeneralSettings
	 */
	protected GeneralSettings $general_settings;

	/**
	 * The partners endpoint.
	 *
	 * @var PartnersEndpoint
	 */
	protected PartnersEndpoint $partners_endpoint;

	/**
	 * Constructor.
	 *
	 * @param Cache              $cache The cache that stores the time of the last refresh.
	 * @param LoggerInterface    $logger The logger.
	 * @param SellerTypeResolver $seller_type_resolver The seller type resolver.
	 * @param GeneralSettings    $general_settings The general settings.
	 * @param PartnersEndpoint   $partners_endpoint The partners endpoint.
	 */
	public function __construct(
		Cache $cache,
		LoggerInterface $logger,
		SellerTypeResolver $seller_type_resolver,
		GeneralSettings $general_settings,
		PartnersEndpoint $partners_endpoint
	) {
		$this->cache                = $cache;
		$this->logger               = $logger;
		$this->seller_type_resolver = $seller_type_resolver;
		$this->general_settings     = $general_settings;
		$this->partners_endpoint    = $partners_endpoint;
	}

	/**
	 * Configure REST API routes.
	 */
	public function register_routes(): void {
		/**
		 * POST /wp-json/wc/v3/wc_paypal/refresh-features
		 */
		register_rest_route(
			static::NAMESPACE,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'refresh_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Handles the refresh status request.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function refresh_status( WP_REST_Request $request ): WP_REST_Response {
		$now                 = time();
		$cached_request_time = $this->cache->get( self::CACHE_KEY );
		$last_request_time   = $cached_request_time ? $cached_request_time : 0;
		$seconds_missing     = $last_request_time + self::TIMEOUT - $now;

		if ( $seconds_missing > 0 ) {
			return $this->return_error(
				sprintf(
				// translators: %1$s is the number of seconds remaining.
					__( 'Wait %1$s seconds before trying again.', 'woocommerce' ),
					$seconds_missing
				)
			);
		}

		$this->cache->set( self::CACHE_KEY, $now, self::TIMEOUT );

		/**
		 * Clears the seller-status cache and failure registry (see ApiModule),
		 * so the re-resolution below performs a fresh lookup.
		 *
		 * @since 11.3.0
		 */
		do_action( 'woocommerce_paypal_payments_clear_apm_product_status' );

		/**
		 * Flush the API caches so a fresh access token is issued with the
		 * merchant's current scopes. Refreshing features is exactly when newly
		 * granted capabilities (e.g. Advanced Vaulting) should take effect, and
		 * a token cached before the change would otherwise keep failing Vault
		 * calls with 403 NOT_AUTHORIZED until it expires.
		 *
		 * @since 11.3.0
		 */
		do_action( 'woocommerce_paypal_payments_flush_api_cache' );

		$this->seller_type_resolver->resolve_unknown_seller_type(
			$this->general_settings,
			$this->partners_endpoint,
			$this->logger
		);

		$this->logger->info( 'Feature status refreshed successfully' );

		return $this->return_success(
			array(
				'message' => __( 'Feature status refreshed successfully.', 'woocommerce' ),
			)
		);
	}
}
