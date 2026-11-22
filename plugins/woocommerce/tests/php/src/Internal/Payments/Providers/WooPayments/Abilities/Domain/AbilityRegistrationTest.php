<?php
/**
 * WooPayments registration parity with the extension inventory.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities\Domain;

use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities\AbilityTestCase;

/** Compare public getters to the recorded English inventory. */
class AbilityRegistrationTest extends AbilityTestCase {
	private const NOT_YET_PORTED = array(
		'accept-dispute',
		'get-balance',
		'get-charge',
		'get-deposits',
		'get-deposits-summary',
		'get-dispute',
		'get-disputes',
		'get-disputes-summary',
		'get-fees-summary',
		'get-fraud-outcomes',
		'get-payment-intent',
		'get-timeline',
		'get-transactions',
		'get-transactions-summary',
		'refund-charge',
		'submit-dispute-evidence',
		'upload-dispute-evidence-file',
	);

	/** Provide the independent extension records without losing empty object defaults. */
	private static function inventory(): array {
		return wp_json_file_decode( dirname( __DIR__ ) . '/fixtures/abilities-11.3.0.json' );
	}

	/** Provide a separately reported case for each name in the extension inventory. */
	public static function inventory_records(): iterable {
		foreach ( self::inventory() as $record ) {
			yield $record->name => array( $record );
		}
	}

	/**
	 * Sort JSON objects recursively while keeping array order and JSON types.
	 *
	 * @param mixed $value Decoded JSON value.
	 * @return mixed Value with sorted object properties.
	 */
	private static function canonicalize( $value ) {
		if ( is_object( $value ) ) {
			$properties = get_object_vars( $value );
			ksort( $properties );
			return (object) array_map( array( self::class, 'canonicalize' ), $properties );
		}
		return is_array( $value ) ? array_map( array( self::class, 'canonicalize' ), $value ) : $value;
	}

	/** Installing transactions early makes the registered and pending sets overlap. */
	public function test_inventory_partition(): void {
		$this->install_root();
		add_filter( self::GATE, '__return_true' );
		$registered = $this->provider_names();
		$this->assertSame( array(), array_intersect( $registered, self::NOT_YET_PORTED ) );
		$all = array_merge( $registered, self::NOT_YET_PORTED );
		sort( $all );
		$expected = array_map( static fn( $record ) => substr( $record->name, strlen( self::PREFIX ) ), self::inventory() );
		sort( $expected );
		$this->assertCount( 22, $expected );
		$this->assertSame( $expected, $all );
		foreach ( self::NOT_YET_PORTED as $name ) {
			$this->assertFalse( wp_has_ability( self::PREFIX . $name ), $name );
		}
	}

	/** Omitting the Capital loan definition loses a name from the registry. */
	public function test_registered_names(): void {
		$this->install_root();
		add_filter( self::GATE, '__return_true' );
		$this->assertSame( self::NAMES, $this->provider_names() );
		foreach ( self::NAMES as $name ) {
			$this->assertSame( self::PREFIX . $name, wp_get_ability( self::PREFIX . $name )->get_name() );
		}
	}

	/**
	 * Catches a renamed payout label, an array default instead of an object, an account output schema,
	 * non-idempotent reads, hidden loan summaries, and an extra MCP tool type.
	 *
	 * @dataProvider inventory_records
	 *
	 * @param \stdClass $record Reference registration from the extension.
	 */
	public function test_inventory_fields( \stdClass $record ): void {
		$this->install_root();
		add_filter( self::GATE, '__return_true' );
		if ( in_array( substr( $record->name, strlen( self::PREFIX ) ), self::NOT_YET_PORTED, true ) ) {
			$this->assertFalse( wp_has_ability( $record->name ), $record->name );
			return;
		}
		$this->assertTrue( wp_has_ability( $record->name ), $record->name );
		$ability = wp_get_ability( $record->name );
		$this->assertSame( \WP_Ability::class, get_class( $ability ) );
		foreach ( array( 'label', 'description', 'category' ) as $field ) {
			$this->assertSame( $record->$field, $ability->{ 'get_' . $field }(), "$record->name $field" );
		}
		$meta   = $ability->get_meta();
		$fields = array(
			'input_schema'  => $ability->get_input_schema(),
			'output_schema' => $ability->get_output_schema(),
			'annotations'   => $meta['annotations'] ?? null,
			'show_in_rest'  => $meta['show_in_rest'] ?? null,
			'mcp'           => $meta['mcp'] ?? null,
		);
		foreach ( $fields as $field => $value ) {
			$actual = json_decode( wp_json_encode( $value ) );
			$this->assertSame( wp_json_encode( self::canonicalize( $record->$field ) ), wp_json_encode( self::canonicalize( $actual ) ), "$record->name $field" );
		}
	}

	/** A hard-coded public default is indistinguishable after WordPress prepares metadata. */
	public function test_raw_meta_omits_public(): void {
		$this->install_root();
		add_filter( self::GATE, '__return_true' );
		$classes = apply_filters( 'woocommerce_ability_definition_classes', array() );
		$this->assertCount( 5, $classes );
		foreach ( $classes as $class ) {
			$this->assertArrayNotHasKey( 'public', $class::get_registration_args()['meta'], $class );
		}
	}
}
