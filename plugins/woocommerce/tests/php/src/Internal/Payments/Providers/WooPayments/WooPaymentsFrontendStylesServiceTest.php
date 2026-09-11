<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
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

		$first  = $service->get_styles_cache_version();
		$second = $service->get_styles_cache_version();

		$this->assertSame( $first, $second );
		$this->assertStringEndsWith( '|appearance-extractor-v3', $first );

		$service->invalidate_styles_cache_version();
		$third = $service->get_styles_cache_version();

		$this->assertNotSame( $first, $third );
		$this->assertStringEndsWith( '|appearance-extractor-v3', $third );
	}

	/**
	 * @testdox Should register shared style invalidation hooks only for the native owner.
	 */
	public function test_registers_style_invalidation_hooks_only_for_native_owner(): void {
		$inactive = $this->create_service( false );
		$inactive->register();

		foreach ( $this->get_style_change_hooks() as $hook ) {
			$this->assertFalse( has_action( $hook, array( $inactive, 'handle_style_change' ) ) );
		}

		$active = $this->create_service( true );
		$active->register();

		foreach ( $this->get_style_change_hooks() as $hook ) {
			$this->assertSame( 10, has_action( $hook, array( $active, 'handle_style_change' ) ) );
		}
	}

	/**
	 * @testdox Should invalidate the shared token when a registered style lifecycle hook fires.
	 */
	public function test_style_change_hook_invalidates_shared_token(): void {
		$service = $this->create_service( true );
		$service->register();
		$first = $service->get_styles_cache_version();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test trigger for the registered lifecycle hook.
		do_action( 'customize_save_after' );

		$this->assertNotSame( $first, $service->get_styles_cache_version() );
	}

	/**
	 * Create a styles service with a runtime-owner result.
	 *
	 * @param bool $native_register Whether native owns the runtime.
	 * @return WooPaymentsFrontendStylesService
	 */
	private function create_service( bool $native_register ): WooPaymentsFrontendStylesService {
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_register );

		$service = new WooPaymentsFrontendStylesService();
		$service->init( $arbiter );
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
