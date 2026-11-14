<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendStylesService;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsFrontendStylesService class.
 */
class WooPaymentsFrontendStylesServiceTest extends WC_Unit_Test_Case {

	/**
	 * Services whose hooks need cleanup.
	 *
	 * @var WooPaymentsFrontendStylesService[]
	 */
	private array $services = array();

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->services as $service ) {
			foreach ( $this->get_style_change_hooks() as $hook ) {
				remove_action( $hook, array( $service, 'handle_style_change' ) );
			}
		}

		delete_option( 'wcpay_styles_cache_version' );
		parent::tearDown();
	}

	/**
	 * @testdox Should keep the cache token stable until invalidated and advance it after invalidation.
	 */
	public function test_cache_token_advances_only_after_invalidation(): void {
		$service = new WooPaymentsFrontendStylesService();

		$first  = $service->get_or_create_styles_cache_version();
		$second = $service->get_or_create_styles_cache_version();

		$this->assertSame( $first, $second );

		$service->invalidate_styles_cache_version();
		$third = $service->get_or_create_styles_cache_version();

		$this->assertNotSame( $first, $third );
	}

	/**
	 * Client 11.1.0 returns the stored version as it is (includes/class-wc-payments-styles-cache.php:45-53); a new
	 * extractor ships in a WooCommerce release, whose woocommerce_updated hook starts a new version.
	 *
	 * @testdox Should return the stored styles cache version unchanged.
	 */
	public function test_returns_the_stored_version_unchanged(): void {
		update_option( 'wcpay_styles_cache_version', 'version-one' );

		$service = new WooPaymentsFrontendStylesService();

		$this->assertSame( 'version-one', $service->get_or_create_styles_cache_version() );
	}

	/**
	 * @testdox Should register shared style invalidation hooks.
	 */
	public function test_registers_style_invalidation_hooks(): void {
		$active = $this->create_service();
		$active->register();

		foreach ( $this->get_style_change_hooks() as $hook ) {
			$this->assertSame( 10, has_action( $hook, array( $active, 'handle_style_change' ) ) );
		}
	}

	/**
	 * @testdox Should invalidate the shared token when a registered style lifecycle hook fires.
	 */
	public function test_style_change_hook_invalidates_shared_token(): void {
		// customize_save_after also starts core's image regeneration through an admin-ajax loopback; answer it empty.
		add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) {
				unset( $args );
				if ( false !== $response || false === strpos( $url, '_wc_regenerate_images' ) ) {
					return $response;
				}

				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
		$service = $this->create_service();
		$service->register();
		$first = $service->get_or_create_styles_cache_version();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test trigger for the registered lifecycle hook.
		do_action( 'customize_save_after' );

		$this->assertNotSame( $first, $service->get_or_create_styles_cache_version() );
	}

	/**
	 * Create a styles service.
	 *
	 * @return WooPaymentsFrontendStylesService
	 */
	private function create_service(): WooPaymentsFrontendStylesService {
		$service          = new WooPaymentsFrontendStylesService();
		$this->services[] = $service;

		return $service;
	}

	/**
	 * Get lifecycle hooks that invalidate shopper appearance caches.
	 *
	 * @return string[]
	 */
	private function get_style_change_hooks(): array {
		return array(
			'after_switch_theme',
			'save_post_wp_global_styles',
			'customize_save_after',
			'save_post_wp_template_part',
			'save_post_wp_template',
			'woocommerce_updated',
		);
	}
}
