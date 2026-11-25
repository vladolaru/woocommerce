<?php
/**
 * WooPayments registration parity with the extension inventory.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities\Domain;

use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities\AbilityTestCase;

/** Compare public getters to the recorded English inventory. */
class AbilityRegistrationTest extends AbilityTestCase {
	private const NOT_YET_PORTED = array();

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

	/**
	 * @testdox Every extension ability is accounted for exactly once.
	 */
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
	}

	/**
	 * @testdox Registering the dispute write in place of the dispute read changes which names are available.
	 */
	public function test_registered_names(): void {
		$this->install_root();
		add_filter( self::GATE, '__return_true' );
		$this->assertSame( self::NAMES, $this->provider_names() );
		foreach ( self::NAMES as $name ) {
			$this->assertSame( self::PREFIX . $name, wp_get_ability( self::PREFIX . $name )->get_name() );
		}
	}

	/**
	 * @testdox Shortening the timeline description, adding a charge prefix rule, declaring a dispute output schema or adding an MCP type changes the registration of $_dataName.
	 * @dataProvider inventory_records
	 *
	 * @param \stdClass $record Reference registration from the extension.
	 */
	public function test_inventory_fields( \stdClass $record ): void {
		$this->install_root();
		add_filter( self::GATE, '__return_true' );
		$this->assertTrue( wp_has_ability( $record->name ), $record->name );
		$ability = wp_get_ability( $record->name );
		$this->assertSame( \WP_Ability::class, get_class( $ability ) );
		if ( self::PREFIX . 'refund-charge' === $record->name ) {
			// The order balance can refuse a repeat before the provider replays the refund.
			$record->description = 'Create a full or partial refund on a Stripe charge. Amount is in minor units (e.g. cents). Send the same idempotency_key with the same request again and no second refund is made: you get the first answer back, or a refusal that says why when the first refund is already recorded on the order.';
		}
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

	/**
	 * @testdox Hard-coding the public default hides whether WordPress supplied it.
	 */
	public function test_raw_meta_omits_public(): void {
		$this->install_root();
		add_filter( self::GATE, '__return_true' );
		$classes = apply_filters( 'woocommerce_ability_definition_classes', array() );
		$this->assertCount( 22, $classes );
		foreach ( $classes as $class ) {
			$this->assertArrayNotHasKey( 'public', $class::get_registration_args()['meta'], $class );
		}
	}
}
