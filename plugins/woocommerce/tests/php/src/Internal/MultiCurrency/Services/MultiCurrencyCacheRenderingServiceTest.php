<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyCacheRenderingService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyCachingEnvironment;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyFrontendProjectionService;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyCacheRenderingService class.
 */
class MultiCurrencyCacheRenderingServiceTest extends WC_Unit_Test_Case {

	private const DONE_OPTION      = 'wcpay_multi_currency_cache_autodetect_done';
	private const DISMISSED_OPTION = 'wcpay_multi_currency_cache_recommendation_dismissed';
	private const MODE_OPTION      = 'wcpay_multi_currency_rendering_mode';

	/**
	 * @testdox Should skip detection and writes when the cache feature is disabled.
	 */
	public function test_skips_detection_and_writes_when_the_cache_feature_is_disabled(): void {
		update_option( MultiCurrencyFrontendProjectionService::CACHE_FEATURE_FLAG_OPTION, '0' );
		$environment = $this->create_environment( true );

		$this->create_service( $environment )->maybe_auto_enable_cache_rendering_mode();

		$this->assertSame( 0, $environment->calls );
		$this->assertFalse( get_option( self::DONE_OPTION, false ) );
		$this->assertFalse( get_option( self::MODE_OPTION, false ) );
	}

	/**
	 * @testdox Should skip detector work once auto-detection is done.
	 */
	public function test_skips_detector_work_once_auto_detection_is_done(): void {
		update_option( self::DONE_OPTION, 'yes' );
		add_filter(
			'pre_option_' . MultiCurrencyFrontendProjectionService::CACHE_FEATURE_FLAG_OPTION,
			static function () {
				throw new \RuntimeException( 'Feature option should not be read.' );
			}
		);
		add_filter(
			'pre_option_' . self::MODE_OPTION,
			static function () {
				throw new \RuntimeException( 'Rendering mode should not be read.' );
			}
		);
		$environment = $this->create_environment( true );

		try {
			$this->create_service( $environment )->maybe_auto_enable_cache_rendering_mode();
		} finally {
			remove_all_filters( 'pre_option_' . MultiCurrencyFrontendProjectionService::CACHE_FEATURE_FLAG_OPTION );
			remove_all_filters( 'pre_option_' . self::MODE_OPTION );
		}

		$this->assertSame( 0, $environment->calls );
		$this->assertFalse( get_option( self::MODE_OPTION, false ) );
	}

	/**
	 * @testdox Should atomically enable cache mode for an eligible unconfigured store.
	 */
	public function test_atomically_enables_cache_mode_for_an_eligible_unconfigured_store(): void {
		$environment = $this->create_environment( true );

		$this->create_service( $environment )->maybe_auto_enable_cache_rendering_mode();

		$this->assertSame( 1, $environment->calls );
		$this->assertSame( 'cache', get_option( self::MODE_OPTION ) );
		$this->assertSame( 'yes', get_option( self::DONE_OPTION ) );
	}

	/**
	 * @testdox Should preserve a merchant mode written while detection is running.
	 */
	public function test_preserves_a_merchant_mode_written_while_detection_is_running(): void {
		$environment = $this->create_environment(
			true,
			null,
			static function (): void {
				add_option( self::MODE_OPTION, 'speed' );
			}
		);

		$this->create_service( $environment )->maybe_auto_enable_cache_rendering_mode();

		$this->assertSame( 'speed', get_option( self::MODE_OPTION ) );
		$this->assertSame( 'yes', get_option( self::DONE_OPTION ) );
	}

	/**
	 * @testdox Should run eligible auto-detection only once.
	 */
	public function test_runs_eligible_auto_detection_only_once(): void {
		$environment = $this->create_environment( false );
		$service     = $this->create_service( $environment );

		$service->maybe_auto_enable_cache_rendering_mode();
		$service->maybe_auto_enable_cache_rendering_mode();

		$this->assertSame( 1, $environment->calls );
		$this->assertSame( 'yes', get_option( self::DONE_OPTION ) );
	}

	/**
	 * @testdox Should consume an eligible no-cache attempt without creating a mode.
	 */
	public function test_consumes_an_eligible_no_cache_attempt_without_creating_a_mode(): void {
		$environment = $this->create_environment( false );

		$this->create_service( $environment )->maybe_auto_enable_cache_rendering_mode();

		$this->assertSame( 1, $environment->calls );
		$this->assertFalse( get_option( self::MODE_OPTION, false ) );
		$this->assertSame( 'yes', get_option( self::DONE_OPTION ) );
	}

	/**
	 * @testdox Should preserve every existing rendering mode value.
	 * @dataProvider provider_existing_modes
	 *
	 * @param mixed $mode Existing stored rendering mode.
	 */
	public function test_preserves_every_existing_rendering_mode_value( $mode ): void {
		$this->assertTrue( add_option( self::MODE_OPTION, $mode ) );
		$environment = $this->create_environment( true );

		$this->create_service( $environment )->maybe_auto_enable_cache_rendering_mode();

		$this->assertSame( 0, $environment->calls );
		$this->assertSame( $mode, get_option( self::MODE_OPTION ) );
		$this->assertSame( 'yes', get_option( self::DONE_OPTION ) );
	}

	/**
	 * @testdox Should preserve a non-autoloaded stored false rendering mode.
	 */
	public function test_preserves_a_non_autoloaded_stored_false_rendering_mode(): void {
		$this->assertTrue( add_option( self::MODE_OPTION, false, '', false ) );
		$environment = $this->create_environment( true );

		$this->create_service( $environment )->maybe_auto_enable_cache_rendering_mode();

		$this->assertSame( 0, $environment->calls );
		$this->assertSame( '', get_option( self::MODE_OPTION, true ) );
		$this->assertSame( 'yes', get_option( self::DONE_OPTION ) );
	}

	/**
	 * Provide stored rendering-mode values that must be preserved.
	 *
	 * @return array<string,array{mixed}>
	 */
	public function provider_existing_modes(): array {
		return array(
			'speed'     => array( 'speed' ),
			'cache'     => array( 'cache' ),
			'malformed' => array( 'custom' ),
			'false'     => array( false ),
		);
	}

	/**
	 * @testdox Should leave auto-detection available when the detector throws.
	 */
	public function test_leaves_auto_detection_available_when_the_detector_throws(): void {
		$environment = $this->create_environment( false, new \RuntimeException( 'Detector failure' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Detector failure' );

		try {
			$this->create_service( $environment )->maybe_auto_enable_cache_rendering_mode();
		} finally {
			$this->assertFalse( get_option( self::DONE_OPTION, false ) );
		}
	}

	/**
	 * @testdox Should recommend cache mode only for active undismissed speed mode.
	 * @dataProvider provider_recommendation_states
	 *
	 * @param string $feature Feature value.
	 * @param mixed  $mode Rendering mode.
	 * @param mixed  $dismissed Dismissal value.
	 * @param bool   $active Whether caching is active.
	 * @param bool   $expected Whether recommendation is expected.
	 * @param int    $calls Expected detector calls.
	 */
	public function test_recommends_cache_mode_only_for_active_undismissed_speed_mode( string $feature, $mode, $dismissed, bool $active, bool $expected, int $calls ): void {
		update_option( MultiCurrencyFrontendProjectionService::CACHE_FEATURE_FLAG_OPTION, $feature );
		update_option( self::MODE_OPTION, $mode );
		update_option( self::DISMISSED_OPTION, $dismissed );
		$environment = $this->create_environment( $active );

		$this->assertSame( $expected, $this->create_service( $environment )->should_recommend_cache_mode() );
		$this->assertSame( $calls, $environment->calls );
	}

	/**
	 * @testdox Should treat a missing rendering mode as speed for recommendation.
	 */
	public function test_treats_a_missing_rendering_mode_as_speed_for_recommendation(): void {
		$environment = $this->create_environment( true );

		$this->assertTrue( $this->create_service( $environment )->should_recommend_cache_mode() );
		$this->assertSame( 1, $environment->calls );
	}

	/**
	 * @testdox Should report dismissal only for the exact yes value.
	 * @dataProvider provider_dismissal_values
	 *
	 * @param mixed $value Stored dismissal value.
	 * @param bool  $expected Expected dismissal state.
	 */
	public function test_reports_dismissal_only_for_the_exact_yes_value( $value, bool $expected ): void {
		update_option( self::DISMISSED_OPTION, $value );

		$this->assertSame( $expected, $this->create_service( $this->create_environment( true ) )->is_cache_recommendation_dismissed() );
	}

	/**
	 * Provide dismissal values.
	 *
	 * @return array<string,array{mixed,bool}>
	 */
	public function provider_dismissal_values(): array {
		return array(
			'yes'  => array( 'yes', true ),
			'no'   => array( 'no', false ),
			'true' => array( true, false ),
		);
	}

	/**
	 * @testdox Should retry when a malformed done marker is stored.
	 */
	public function test_retries_when_a_malformed_done_marker_is_stored(): void {
		update_option( self::DONE_OPTION, 'true' );
		$environment = $this->create_environment( false );

		$this->create_service( $environment )->maybe_auto_enable_cache_rendering_mode();

		$this->assertSame( 1, $environment->calls );
		$this->assertSame( 'yes', get_option( self::DONE_OPTION ) );
	}

	/**
	 * @testdox Should use current-blog cache settings without changing the main blog.
	 */
	public function test_uses_current_blog_cache_settings_without_changing_the_main_blog(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Current PHP test configuration is single-site.' );
		}

		$original_blog_id = get_current_blog_id();
		$site_id          = $this->factory()->blog->create();

		switch_to_blog( $site_id );
		try {
			$environment = $this->create_environment( true );

			$this->create_service( $environment )->maybe_auto_enable_cache_rendering_mode();

			$this->assertSame( 'cache', get_option( self::MODE_OPTION ) );
			$this->assertSame( 'yes', get_option( self::DONE_OPTION ) );
		} finally {
			restore_current_blog();
		}

		$this->assertSame( $original_blog_id, get_current_blog_id() );
		$this->assertFalse( get_option( self::MODE_OPTION, false ) );
	}

	/**
	 * Provide recommendation conditions.
	 *
	 * @return array<string,array{string,mixed,mixed,bool,bool,int}>
	 */
	public function provider_recommendation_states(): array {
		return array(
			'active speed'      => array( '1', 'speed', 'no', true, true, 1 ),
			'feature disabled'  => array( '0', 'speed', 'no', true, false, 0 ),
			'cache mode'        => array( '1', 'cache', 'no', true, false, 0 ),
			'dismissed'         => array( '1', 'speed', 'yes', true, false, 0 ),
			'no detected cache' => array( '1', 'speed', 'no', false, false, 1 ),
		);
	}

	/**
	 * Create the service under test.
	 *
	 * @param MultiCurrencyCachingEnvironment $environment Caching environment.
	 * @return MultiCurrencyCacheRenderingService
	 */
	private function create_service( MultiCurrencyCachingEnvironment $environment ): MultiCurrencyCacheRenderingService {
		$service = new MultiCurrencyCacheRenderingService();
		$service->init( $environment );

		return $service;
	}

	/**
	 * Create a deterministic caching environment.
	 *
	 * @param bool            $active Whether caching is active.
	 * @param \Throwable|null $exception Exception to throw.
	 * @param callable|null   $on_call Callback to run during detection.
	 * @return MultiCurrencyCachingEnvironment&object{calls:int}
	 */
	private function create_environment( bool $active, ?\Throwable $exception = null, ?callable $on_call = null ): MultiCurrencyCachingEnvironment {
		return new class( $active, $exception, $on_call ) extends MultiCurrencyCachingEnvironment {
			/** @var bool */
			private bool $active;

			/** @var \Throwable|null */
			private ?\Throwable $exception;

			/** @var callable|null */
			private $on_call;

			/** @var int */
			public int $calls = 0;

			/**
			 * @param bool            $active Whether caching is active.
			 * @param \Throwable|null $exception Exception to throw.
			 * @param callable|null   $on_call Callback to run during detection.
			 */
			public function __construct( bool $active, ?\Throwable $exception, ?callable $on_call ) {
				$this->active    = $active;
				$this->exception = $exception;
				$this->on_call   = $on_call;
			}

			/**
			 * Tell whether deterministic page caching is active.
			 *
			 * @return bool Whether page caching is active.
			 */
			public function is_page_caching_active(): bool {
				++$this->calls;
				if ( null !== $this->on_call ) {
					call_user_func( $this->on_call );
				}
				if ( null !== $this->exception ) {
					throw $this->exception;
				}

				return $this->active;
			}
		};
	}
}
