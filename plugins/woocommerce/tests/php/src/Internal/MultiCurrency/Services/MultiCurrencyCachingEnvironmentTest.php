<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyCachingEnvironment;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyCachingEnvironment class.
 */
class MultiCurrencyCachingEnvironmentTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should require truthy WP_CACHE and the derived advanced-cache drop-in.
	 */
	public function test_requires_truthy_wp_cache_and_the_derived_advanced_cache_drop_in(): void {
		$sut = $this->create_environment(
			array( 'WP_CACHE' => false ),
			array( '/relocated-content/advanced-cache.php' => true )
		);

		$this->assertFalse( $sut->is_page_caching_active() );

		$sut = $this->create_environment(
			array( 'WP_CACHE' => true ),
			array( '/relocated-content/advanced-cache.php' => false )
		);

		$this->assertFalse( $sut->is_page_caching_active() );

		$sut = $this->create_environment(
			array( 'WP_CACHE' => true ),
			array( '/relocated-content/advanced-cache.php' => true )
		);

		$this->assertTrue( $sut->is_page_caching_active() );
		$this->assertSame( array( '/relocated-content/advanced-cache.php' ), $sut->file_paths );
	}

	/**
	 * @testdox Should detect supported page-cache signals in precedence order.
	 * @dataProvider provider_detection_data
	 *
	 * @param array<string,mixed> $constants Defined constants and values.
	 * @param string[]            $classes Available classes.
	 */
	public function test_detects_supported_page_cache_signals_in_precedence_order( array $constants, array $classes ): void {
		$sut = $this->create_environment( $constants, array(), $classes );

		$this->assertTrue( $sut->is_page_caching_active() );
	}

	/**
	 * @testdox Should stop at the first matching cache signal.
	 */
	public function test_stops_at_the_first_matching_cache_signal(): void {
		$sut = $this->create_environment(
			array(
				'WP_CACHE'     => true,
				'LSCWP_V'      => '1',
				'IS_ATOMIC'    => true,
				'IS_PRESSABLE' => true,
			),
			array( '/relocated-content/advanced-cache.php' => true ),
			array( 'WpFastestCache' )
		);

		$this->assertTrue( $sut->is_page_caching_active() );
		$this->assertSame( array( 'WP_CACHE' ), $sut->constant_names );
		$this->assertSame( array(), $sut->class_names );
		$this->assertSame( array( '/relocated-content/advanced-cache.php' ), $sut->file_paths );

		$sut = $this->create_environment(
			array(
				'LSCWP_V'      => '1',
				'IS_ATOMIC'    => true,
				'IS_PRESSABLE' => true,
			),
			array(),
			array( 'WpFastestCache' )
		);

		$this->assertTrue( $sut->is_page_caching_active() );
		$this->assertSame( array( 'WP_CACHE', 'LSCWP_V' ), $sut->constant_names );
		$this->assertSame( array(), $sut->class_names );
	}

	/**
	 * @testdox Should preserve managed-host precedence over later signals.
	 * @dataProvider provider_managed_host_precedence_probe_traces
	 *
	 * @param array<string,mixed> $constants Defined constants and values.
	 * @param string[]            $classes Available classes.
	 * @param string[]            $expected_constants Expected constant probe trace.
	 * @param string[]            $expected_classes Expected class probe trace.
	 */
	public function test_preserves_managed_host_precedence_over_later_signals( array $constants, array $classes, array $expected_constants, array $expected_classes ): void {
		$sut = $this->create_environment( $constants, array(), $classes );

		$this->assertTrue( $sut->is_page_caching_active() );
		$this->assertSame( $expected_constants, $sut->constant_names );
		$this->assertSame( $expected_classes, $sut->class_names );
	}

	/**
	 * Provide managed-host precedence probe traces.
	 *
	 * @return array<string,array{array<string,mixed>,string[],string[],string[]}>
	 */
	public function provider_managed_host_precedence_probe_traces(): array {
		return array(
			'fastest cache wins over Atomic and Pressable' => array(
				array(
					'IS_ATOMIC'    => true,
					'IS_PRESSABLE' => true,
				),
				array( 'WpFastestCache' ),
				array( 'WP_CACHE', 'LSCWP_V' ),
				array( 'WpFastestCache' ),
			),
			'Atomic wins over Pressable'                   => array(
				array(
					'IS_ATOMIC'    => true,
					'IS_PRESSABLE' => true,
				),
				array(),
				array( 'WP_CACHE', 'LSCWP_V', 'IS_ATOMIC' ),
				array( 'WpFastestCache' ),
			),
			'Atomic site ID wins over Pressable'           => array(
				array(
					'ATOMIC_SITE_ID' => 'site',
					'IS_PRESSABLE'   => true,
				),
				array(),
				array( 'WP_CACHE', 'LSCWP_V', 'IS_ATOMIC', 'ATOMIC_SITE_ID' ),
				array( 'WpFastestCache' ),
			),
		);
	}

	/**
	 * Provide supported cache detection signals.
	 *
	 * @return array<string,array{array<string,mixed>,string[]}>
	 */
	public function provider_detection_data(): array {
		return array(
			'litespeed constant is defined even when false' => array( array( 'LSCWP_V' => false ), array() ),
			'fastest cache class exists'                 => array( array(), array( 'WpFastestCache' ) ),
			'atomic constant is defined even when false' => array( array( 'IS_ATOMIC' => false ), array() ),
			'atomic site constant is defined'            => array( array( 'ATOMIC_SITE_ID' => 'site' ), array() ),
			'pressable constant is defined even when false' => array( array( 'IS_PRESSABLE' => false ), array() ),
			'litespeed wins over later signals'          => array(
				array(
					'LSCWP_V'      => '1',
					'IS_PRESSABLE' => true,
				),
				array( 'WpFastestCache' ),
			),
		);
	}

	/**
	 * @testdox Should ignore object-cache-only and unknown environments.
	 */
	public function test_ignores_object_cache_only_and_unknown_environments(): void {
		$this->assertFalse( $this->create_environment()->is_page_caching_active() );
		$this->assertFalse( $this->create_environment( array( 'WP_REDIS_DISABLED' => false ) )->is_page_caching_active() );
	}

	/**
	 * @testdox Should apply the public filter once and coerce its final return.
	 * @dataProvider provider_filter_returns
	 *
	 * @param mixed $filtered_value Filter callback return value.
	 * @param bool  $expected Expected final boolean.
	 */
	public function test_applies_the_public_filter_once_and_coerces_its_final_return( $filtered_value, bool $expected ): void {
		$calls = array();
		add_filter(
			'wcpay_multi_currency_page_caching_active',
			static function ( bool $is_active ) use ( &$calls, $filtered_value ) {
				$calls[] = $is_active;

				return $filtered_value;
			}
		);

		$this->assertSame( $expected, $this->create_environment()->is_page_caching_active() );
		$this->assertSame( array( false ), $calls );
	}

	/**
	 * @testdox Should allow the public filter to disable a detected cache environment.
	 */
	public function test_allows_the_public_filter_to_disable_a_detected_cache_environment(): void {
		$calls = array();
		add_filter(
			'wcpay_multi_currency_page_caching_active',
			static function ( bool $is_active ) use ( &$calls ): bool {
				$calls[] = $is_active;

				return false;
			}
		);

		$sut = $this->create_environment( array( 'LSCWP_V' => '1' ) );

		$this->assertFalse( $sut->is_page_caching_active() );
		$this->assertSame( array( true ), $calls );
	}

	/**
	 * @testdox Should run detection and the public filter on every call.
	 */
	public function test_runs_detection_and_the_public_filter_on_every_call(): void {
		$calls = array();
		add_filter(
			'wcpay_multi_currency_page_caching_active',
			static function ( bool $is_active ) use ( &$calls ): bool {
				$calls[] = $is_active;

				return $is_active;
			}
		);
		$sut = $this->create_environment( array( 'LSCWP_V' => '1' ) );

		try {
			$this->assertTrue( $sut->is_page_caching_active() );
			$this->assertTrue( $sut->is_page_caching_active() );
		} finally {
			remove_all_filters( 'wcpay_multi_currency_page_caching_active' );
		}

		$this->assertSame( array( 'WP_CACHE', 'LSCWP_V', 'WP_CACHE', 'LSCWP_V' ), $sut->constant_names );
		$this->assertSame( array( true, true ), $calls );
	}

	/**
	 * Provide filter return coercion cases.
	 *
	 * @return array<string,array{mixed,bool}>
	 */
	public function provider_filter_returns(): array {
		return array(
			'force true'      => array( true, true ),
			'force false'     => array( false, false ),
			'string zero'     => array( '0', false ),
			'non-empty array' => array( array( 'cache' ), true ),
		);
	}

	/**
	 * @testdox Should propagate page-cache filter exceptions.
	 */
	public function test_propagates_page_cache_filter_exceptions(): void {
		add_filter(
			'wcpay_multi_currency_page_caching_active',
			static function (): bool {
				throw new \RuntimeException( 'Filter failure' );
			}
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Filter failure' );

		$this->create_environment()->is_page_caching_active();
	}

	/**
	 * Create a caching environment with deterministic signal probes.
	 *
	 * @param array<string,mixed> $constants Defined constants and values.
	 * @param array<string,bool>  $files Existing files.
	 * @param string[]            $classes Available classes.
	 * @return MultiCurrencyCachingEnvironment&object{file_paths:string[],constant_names:string[],class_names:string[]}
	 */
	private function create_environment( array $constants = array(), array $files = array(), array $classes = array() ): MultiCurrencyCachingEnvironment {
		return new class( $constants, $files, $classes ) extends MultiCurrencyCachingEnvironment {
			/** @var array<string,mixed> */
			private array $constants;

			/** @var array<string,bool> */
			private array $files;

			/** @var string[] */
			private array $classes;

			/** @var string[] */
			public array $file_paths = array();

			/** @var string[] */
			public array $constant_names = array();

			/** @var string[] */
			public array $class_names = array();

			/**
			 * @param array<string,mixed> $constants Defined constants and values.
			 * @param array<string,bool>  $files Existing files.
			 * @param string[]            $classes Available classes.
			 */
			public function __construct( array $constants, array $files, array $classes ) {
				$this->constants = $constants;
				$this->files     = $files;
				$this->classes   = $classes;
			}

			/**
			 * Tell whether the deterministic constant exists.
			 *
			 * @param string $name Constant name.
			 * @return bool Whether the constant exists.
			 */
			protected function is_constant_defined( string $name ): bool {
				$this->constant_names[] = $name;

				return array_key_exists( $name, $this->constants );
			}

			/**
			 * Get the deterministic constant value.
			 *
			 * @param string $name Constant name.
			 * @return mixed Constant value.
			 */
			protected function get_constant_value( string $name ) {
				return $this->constants[ $name ] ?? null;
			}

			/**
			 * Get the deterministic content directory.
			 *
			 * @return string Content directory.
			 */
			protected function get_content_directory(): string {
				return '/relocated-content';
			}

			/**
			 * Tell whether the deterministic file exists.
			 *
			 * @param string $path File path.
			 * @return bool Whether the file exists.
			 */
			protected function does_file_exist( string $path ): bool {
				$this->file_paths[] = $path;

				return $this->files[ $path ] ?? false;
			}

			/**
			 * Tell whether the deterministic class is available.
			 *
			 * @param string $class_name Class name.
			 * @return bool Whether the class is available.
			 */
			protected function is_class_available( string $class_name ): bool {
				$this->class_names[] = $class_name;

				return in_array( $class_name, $this->classes, true );
			}
		};
	}
}
