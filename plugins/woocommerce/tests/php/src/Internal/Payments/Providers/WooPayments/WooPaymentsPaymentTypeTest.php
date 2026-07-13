<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentType;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsPaymentType class.
 */
class WooPaymentsPaymentTypeTest extends WC_Unit_Test_Case {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		WooPaymentsPaymentType::register_legacy_alias();
	}

	/**
	 * @testdox The legacy Payment_Type alias supports the oracle's static construction idiom.
	 */
	public function test_legacy_alias_static_construction_returns_payment_type_instance(): void {
		$this->assertTrue( class_exists( '\\WCPay\\Constants\\Payment_Type' ) );

		$payment_type = \WCPay\Constants\Payment_Type::SINGLE();

		$this->assertSame( 'SINGLE', $payment_type->get_value() );
		$this->assertInstanceOf( WooPaymentsPaymentType::class, $payment_type );
		$this->assertInstanceOf( \WCPay\Constants\Payment_Type::class, $payment_type );
	}

	/**
	 * @testdox Static construction caches one instance per oracle constant name.
	 */
	public function test_static_construction_reuses_constant_instance(): void {
		$this->assertSame(
			\WCPay\Constants\Payment_Type::SINGLE(),
			\WCPay\Constants\Payment_Type::SINGLE()
		);
	}

	/**
	 * @testdox An oracle-compatible subclass can redeclare object_cache to isolate its instances.
	 */
	public function test_subclass_can_redeclare_oracle_object_cache_for_isolation(): void {
		$base_single     = WooPaymentsPaymentType::single();
		$subclass_single = Task31IsolatedPaymentType::single();

		$this->assertInstanceOf( Task31IsolatedPaymentType::class, $subclass_single );
		$this->assertNotSame( $base_single, $subclass_single );
	}

	/**
	 * @testdox An oracle-compatible subclass can access the inherited protected value.
	 */
	public function test_subclass_can_access_inherited_oracle_value(): void {
		$subclass_instance = Task31IsolatedPaymentType::recurring();

		$this->assertSame( 'RECURRING', $subclass_instance->read_inherited_value() );
	}

	/**
	 * @testdox The protected value property is untyped like the oracle Base_Constant contract.
	 */
	public function test_value_property_is_untyped(): void {
		$value_property = new \ReflectionProperty( WooPaymentsPaymentType::class, 'value' );

		$this->assertFalse( $value_property->hasType() );
	}

	/**
	 * @testdox The protected object_cache property is untyped like the oracle Base_Constant contract.
	 */
	public function test_object_cache_property_is_untyped(): void {
		$object_cache_property = new \ReflectionProperty( WooPaymentsPaymentType::class, 'object_cache' );

		$this->assertFalse( $object_cache_property->hasType() );
	}

	/**
	 * @testdox get_value returns the exact uppercase oracle constant names.
	 */
	public function test_get_value_returns_oracle_constant_name(): void {
		$this->assertSame( 'SINGLE', \WCPay\Constants\Payment_Type::SINGLE()->get_value() );
		$this->assertSame( 'RECURRING', \WCPay\Constants\Payment_Type::RECURRING()->get_value() );
	}

	/**
	 * @testdox String conversion returns the exact lowercase oracle constant values.
	 */
	public function test_to_string_returns_oracle_constant_value(): void {
		$this->assertSame( 'single', (string) \WCPay\Constants\Payment_Type::SINGLE() );
		$this->assertSame( 'recurring', (string) \WCPay\Constants\Payment_Type::RECURRING() );
	}

	/**
	 * @testdox Undefined static constant construction throws the oracle exception.
	 */
	public function test_call_static_rejects_unknown_constant_name(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( "Constant with name 'UNKNOWN' does not exist." );

		\WCPay\Constants\Payment_Type::UNKNOWN();
	}

	/**
	 * @testdox equals follows the oracle's cached-instance identity semantics.
	 */
	public function test_equals_compares_cached_constant_instances(): void {
		$single = \WCPay\Constants\Payment_Type::SINGLE();

		$this->assertTrue( $single->equals( \WCPay\Constants\Payment_Type::SINGLE() ) );
		$this->assertFalse( $single->equals( \WCPay\Constants\Payment_Type::RECURRING() ) );
		$this->assertFalse( $single->equals( \WCPay\Constants\Payment_Type::SINGLE ) );
		$this->assertFalse( $single->equals( new \stdClass() ) );
	}

	/**
	 * @testdox equals is final like the oracle Base_Constant contract.
	 */
	public function test_equals_is_final(): void {
		$equals_method = new \ReflectionMethod( WooPaymentsPaymentType::class, 'equals' );

		$this->assertTrue( $equals_method->isFinal() );
	}

	/**
	 * @testdox search maps an exact oracle value back to its constant name.
	 */
	public function test_search_returns_constant_name_for_exact_value(): void {
		$this->assertTrue( is_callable( array( \WCPay\Constants\Payment_Type::class, 'search' ) ) );
		$this->assertSame( 'SINGLE', \WCPay\Constants\Payment_Type::search( 'single' ) );
		$this->assertSame( 'RECURRING', \WCPay\Constants\Payment_Type::search( 'recurring' ) );
	}

	/**
	 * @testdox search rejects values absent from the oracle constants.
	 */
	public function test_search_throws_for_unknown_value(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( "Constant with value 'missing' does not exist." );

		\WCPay\Constants\Payment_Type::search( 'missing' );
	}

	/**
	 * @testdox JSON serialization uses the oracle's lowercase string value.
	 */
	public function test_json_serialize_returns_oracle_constant_value(): void {
		$this->assertSame( '"single"', wp_json_encode( \WCPay\Constants\Payment_Type::SINGLE() ) );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName

/**
 * Payment type subclass preserving the WooPayments Base_Constant inheritance shape.
 */
class Task31IsolatedPaymentType extends WooPaymentsPaymentType {

	/**
	 * Subclass-local oracle value.
	 *
	 * @var string
	 */
	protected $value;

	/**
	 * Subclass-local oracle cache.
	 *
	 * @var array<string,self>
	 */
	protected static $object_cache = array();

	/**
	 * Read the inherited oracle value.
	 *
	 * @return string|null
	 */
	public function read_inherited_value(): ?string {
		return $this->value ?? null;
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName
