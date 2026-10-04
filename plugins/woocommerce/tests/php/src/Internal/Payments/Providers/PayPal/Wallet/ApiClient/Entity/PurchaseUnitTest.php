<?php
/**
 * Tests for the purchase unit entity.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Amount;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\AmountBreakdown;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Item;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Money;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Shipping;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\PurchaseUnitSanitizer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The purchase unit of an order and its sanitizer, which drops the items breakdown (ditch mode) or adds a line for the
 * rounding difference (extra line mode) when the items do not add up to the total.
 *
 * The data providers keep their data only: the mocks are built inside each test, so a data set holds no mock that
 * outlives the test it belongs to.
 *
 * @group paypal-wallet
 */
class PurchaseUnitTest extends WalletTestCase {

	/**
	 * Items as mocks (each with a unit amount, a tax, a quantity and a category), an amount mock and its breakdown mock,
	 * all in euros.
	 *
	 * @param array      $items     The items: value, quantity, tax and category of each.
	 * @param float|int  $amount    The order total.
	 * @param array|null $breakdown The breakdown parts (a number, or null for a part that is not set), or null for none.
	 * @return array{items: Item[], amount: Amount}
	 */
	private function make_inputs( array $items, $amount, ?array $breakdown ): array {
		$item_mocks = array();
		foreach ( $items as $key => $item ) {
			$unit_amount = new Money( $item['value'], 'EUR' );
			$tax         = new Money( $item['tax'], 'EUR' );

			$item_mock = $this->mock( Item::class );
			$item_mock->shouldReceive( 'unit_amount' )->andReturn( $unit_amount );
			$item_mock->shouldReceive( 'tax' )->andReturn( $tax );
			$item_mock->shouldReceive( 'quantity' )->andReturn( $item['quantity'] );
			$item_mock->shouldReceive( 'category' )->andReturn( $item['category'] );
			$item_mock->shouldReceive( 'to_array' )->andReturnUsing(
				static function ( bool $round_to_floor = false ) use ( $unit_amount, $tax, $item ) {
					return array(
						'unit_amount' => $unit_amount->to_array( $round_to_floor ),
						'tax'         => $tax->to_array(),
						'quantity'    => $item['quantity'],
						'category'    => $item['category'],
					);
				}
			);

			$item_mocks[ $key ] = $item_mock;
		}

		$breakdown_mock = null;
		if ( $breakdown ) {
			$breakdown_mock = $this->mock( AmountBreakdown::class );
			foreach ( $breakdown as $method => $value ) {
				$breakdown_mock->shouldReceive( $method )->andReturnUsing(
					static function () use ( $value ) {
						return is_numeric( $value ) ? new Money( $value, 'EUR' ) : null;
					}
				);
			}
			$breakdown_mock->shouldReceive( 'to_array' )->andReturn(
				array_map(
					static function ( $value ) {
						return $value ? ( new Money( $value, 'EUR' ) )->to_array() : null;
					},
					$breakdown
				)
			);
		}

		$amount_money = new Money( $amount, 'EUR' );
		$amount_mock  = $this->mock( Amount::class );
		$amount_mock->shouldReceive( 'to_array' )->andReturn(
			array(
				'value'         => $amount_money->value_str(),
				'currency_code' => $amount_money->currency_code(),
				'breakdown'     => $breakdown_mock ? $breakdown_mock->to_array() : array(),
			)
		);
		$amount_mock->shouldReceive( 'value_str' )->andReturn( $amount_money->value_str() );
		$amount_mock->shouldReceive( 'currency_code' )->andReturn( 'EUR' );
		$amount_mock->shouldReceive( 'breakdown' )->andReturn( $breakdown_mock );

		return array(
			'items'  => $item_mocks,
			'amount' => $amount_mock,
		);
	}

	/**
	 * The breakdown of 20 in items and 6 in tax, with the given parts set on top.
	 *
	 * @param array $parts The parts to set: shipping, discount, shipping_discount, handling, insurance or a replacement total.
	 * @return array
	 */
	private static function breakdown( array $parts = array() ): array {
		return array_merge(
			array(
				'item_total'        => 20,
				'tax_total'         => 6,
				'shipping'          => null,
				'discount'          => null,
				'shipping_discount' => null,
				'handling'          => null,
				'insurance'         => null,
			),
			$parts
		);
	}

	/**
	 * One physical item at 10 with a tax of 3, twice, unless the given values replace some of that.
	 *
	 * @param array $values Replacements for value, quantity or tax.
	 * @return array
	 */
	private static function items( array $values = array() ): array {
		return array(
			array_merge(
				array(
					'value'    => 10,
					'quantity' => 2,
					'tax'      => 3,
					'category' => Item::PHYSICAL_GOODS,
				),
				$values
			),
		);
	}

	/**
	 * @testdox Should hold every value it is given and write them into the array.
	 */
	public function test(): void {
		$amount = $this->mock( Amount::class );
		$amount->shouldReceive( 'breakdown' )->andReturn( null );
		$amount->shouldReceive( 'to_array' )->andReturn( array( 'amount' ) );

		$item1 = $this->mock( Item::class );
		$item1->shouldReceive( 'to_array' )->andReturn( array( 'item1' ) );
		$item1->shouldReceive( 'category' )->andReturn( Item::DIGITAL_GOODS );

		$item2 = $this->mock( Item::class );
		$item2->shouldReceive( 'to_array' )->andReturn( array( 'item2' ) );
		$item2->shouldReceive( 'category' )->andReturn( Item::PHYSICAL_GOODS );

		$shipping = $this->mock( Shipping::class );
		$shipping->shouldReceive( 'to_array' )->andReturn( array( 'shipping' ) );

		$testee = new PurchaseUnit( $amount, array( $item1, $item2 ), $shipping, 'referenceId', 'description', 'customId', 'invoiceId', 'softDescriptor' );

		$this->assertSame( $amount, $testee->amount() );
		$this->assertSame( 'referenceId', $testee->reference_id() );
		$this->assertSame( 'description', $testee->description() );
		$this->assertSame( 'customId', $testee->custom_id() );
		$this->assertSame( 'invoiceId', $testee->invoice_id() );
		$this->assertSame( 'softDescriptor', $testee->soft_descriptor() );
		$this->assertSame( $shipping, $testee->shipping() );
		$this->assertSame( array( $item1, $item2 ), $testee->items() );
		$this->assertTrue( $testee->contains_physical_goods() );

		$expected = array(
			'reference_id'    => 'referenceId',
			'amount'          => array( 'amount' ),
			'description'     => 'description',
			'items'           => array( array( 'item1' ), array( 'item2' ) ),
			'shipping'        => array( 'shipping' ),
			'custom_id'       => 'customId',
			'invoice_id'      => 'invoiceId',
			'soft_descriptor' => 'softDescriptor',
		);

		$this->assertSame( $expected, $testee->to_array() );
	}

	/**
	 * The cases of the ditch mode: the message, whether to ditch (a bool for all three, or an array that says it for the
	 * items, the breakdown and the tax of the items), the items, the total and the breakdown.
	 *
	 * @return array<string, array>
	 */
	public function data_for_ditch_tests(): array {
		return array(
			'default'                                  => array(
				'message'   => 'Items should not be ditched.',
				'ditch'     => false,
				'items'     => self::items(),
				'amount'    => 26,
				'breakdown' => self::breakdown(),
			),
			'dont_ditch_with_discount'                 => array(
				'message'   => 'Items should not be ditched.',
				'ditch'     => false,
				'items'     => self::items(),
				'amount'    => 23,
				'breakdown' => self::breakdown( array( 'discount' => 3 ) ),
			),
			'ditch_with_discount'                      => array(
				'message'   => 'Items should be ditched because of discount.',
				'ditch'     => true,
				'items'     => self::items(),
				'amount'    => 25,
				'breakdown' => self::breakdown( array( 'discount' => 3 ) ),
			),
			'dont_ditch_with_shipping_discount'        => array(
				'message'   => 'Items should not be ditched.',
				'ditch'     => false,
				'items'     => self::items(),
				'amount'    => 23,
				'breakdown' => self::breakdown( array( 'shipping_discount' => 3 ) ),
			),
			'ditch_with_handling'                      => array(
				'message'   => 'Items should be ditched because of handling.',
				'ditch'     => true,
				'items'     => self::items(),
				'amount'    => 26,
				'breakdown' => self::breakdown( array( 'handling' => 3 ) ),
			),
			'dont_ditch_with_handling'                 => array(
				'message'   => 'Items should not be ditched.',
				'ditch'     => false,
				'items'     => self::items(),
				'amount'    => 29,
				'breakdown' => self::breakdown( array( 'handling' => 3 ) ),
			),
			'ditch_with_insurance'                     => array(
				'message'   => 'Items should be ditched because of insurance.',
				'ditch'     => true,
				'items'     => self::items(),
				'amount'    => 26,
				'breakdown' => self::breakdown( array( 'insurance' => 3 ) ),
			),
			'dont_ditch_with_insurance'                => array(
				'message'   => 'Items should not be ditched.',
				'ditch'     => false,
				'items'     => self::items(),
				'amount'    => 29,
				'breakdown' => self::breakdown( array( 'insurance' => 3 ) ),
			),
			'ditch_with_shipping_discount'             => array(
				'message'   => 'Items should be ditched because of shipping discount.',
				'ditch'     => true,
				'items'     => self::items(),
				'amount'    => 25,
				'breakdown' => self::breakdown( array( 'shipping_discount' => 3 ) ),
			),
			'dont_ditch_with_shipping'                 => array(
				'message'   => 'Items should not be ditched.',
				'ditch'     => false,
				'items'     => self::items(),
				'amount'    => 29,
				'breakdown' => self::breakdown( array( 'shipping' => 3 ) ),
			),
			'ditch_because_shipping'                   => array(
				'message'   => 'Items should be ditched because of shipping.',
				'ditch'     => true,
				'items'     => self::items(),
				'amount'    => 28,
				'breakdown' => self::breakdown( array( 'shipping' => 3 ) ),
			),
			'ditch_items_total'                        => array(
				'message'   => 'Items should be ditched because the item total does not add up.',
				'ditch'     => true,
				'items'     => self::items(),
				'amount'    => 26,
				'breakdown' => self::breakdown( array( 'item_total' => 11 ) ),
			),
			'ditch_tax_total'                          => array(
				'message'   => 'Items should be ditched because the tax total does not add up.',
				'ditch'     => true,
				'items'     => self::items(),
				'amount'    => 26,
				'breakdown' => self::breakdown( array( 'tax_total' => 5 ) ),
			),
			'ditch_total_amount'                       => array(
				'message'   => 'Items should be ditched because the total amount is way out of order.',
				'ditch'     => true,
				'items'     => self::items(),
				'amount'    => 260,
				'breakdown' => self::breakdown(),
			),
			'ditch_items_total_but_not_breakdown'      => array(
				'message'   => 'Items should be ditched because the item total does not add up. But not breakdown because it adds up.',
				'ditch'     => array(
					'items'     => true,
					'breakdown' => false,
					'tax'       => true,
				),
				'items'     => self::items( array( 'value' => 11 ) ),
				'amount'    => 26,
				'breakdown' => self::breakdown(),
			),
			'ditch_items_tax_with_incorrect_tax_total' => array(
				'message'   => 'Ditch tax from items. Items should not be ditched because the mismatch is on the tax.',
				'ditch'     => array(
					'items'     => false,
					'breakdown' => false,
					'tax'       => true,
				),
				'items'     => self::items( array( 'tax' => 4 ) ),
				'amount'    => 26,
				'breakdown' => self::breakdown(),
			),
		);
	}

	/**
	 * @testdox Should ditch the items, the breakdown or the tax of the items only when they cannot add up to the total.
	 * @dataProvider data_for_ditch_tests
	 *
	 * @param string     $message The message of the case.
	 * @param bool|array $ditch   Whether to ditch, for all three or for each of items, breakdown and tax.
	 * @param array      $items   The items.
	 * @param int        $amount  The order total.
	 * @param array      $breakdown The breakdown parts.
	 */
	public function test_ditch_method( string $message, $ditch, array $items, int $amount, array $breakdown ): void {
		$inputs = $this->make_inputs( $items, $amount, $breakdown );

		if ( is_array( $ditch ) ) {
			$ditch_items     = $ditch['items'];
			$ditch_breakdown = $ditch['breakdown'];
			$ditch_tax       = $ditch['tax'];
		} else {
			$ditch_items     = $ditch;
			$ditch_breakdown = $ditch;
			$ditch_tax       = $ditch;
		}

		$testee = new PurchaseUnit( $inputs['amount'], $inputs['items'] );
		$testee->set_sanitizer( new PurchaseUnitSanitizer( PurchaseUnitSanitizer::MODE_DITCH ) );

		$array = $testee->to_array();

		$this->assertSame( $ditch_items, ! array_key_exists( 'items', $array ), $message );
		$this->assertSame( $ditch_breakdown, ! array_key_exists( 'breakdown', $array['amount'] ), $message );
		foreach ( $array['items'] ?? array() as $item ) {
			$this->assertSame( $ditch_tax, ! array_key_exists( 'tax', $item ), $message );
		}
	}

	/**
	 * The cases of the extra line mode: the message, the values expected and the items, the total and the breakdown.
	 *
	 * Upstream defect: "with_custom_name" never sets a name on the sanitizer, so it duplicates "default".
	 *
	 * @return array<string, array>
	 */
	public function data_for_extra_line_tests(): array {
		return array(
			'default'                  => array(
				'message'   => 'Extra line should be added with price 0.01 and line amount 10.',
				'expected'  => array(
					'item_value'       => array( 10 ),
					'extra_line_value' => 0.01,
				),
				'items'     => self::items(),
				'amount'    => 26.01,
				'breakdown' => self::breakdown( array( 'item_total' => 20.01 ) ),
			),
			'with_custom_name'         => array(
				'message'   => 'Extra line should be added with price 0.01 and line amount 10.',
				'expected'  => array(
					'item_value'       => array( 10 ),
					'extra_line_value' => 0.01,
					'extra_line_name'  => 'My custom line name',
				),
				'items'     => self::items(),
				'amount'    => 26.01,
				'breakdown' => self::breakdown( array( 'item_total' => 20.01 ) ),
			),
			'with_rounding_down'       => array(
				'message'   => 'Extra line should be added with price 0.01 and line amount 10.00.',
				'expected'  => array(
					'item_value'       => array( 10.00 ),
					'extra_line_value' => 0.01,
				),
				'items'     => self::items( array( 'value' => 10.005 ) ),
				'amount'    => 26.01,
				'breakdown' => self::breakdown( array( 'item_total' => 20.01 ) ),
			),
			'with_two_rounding_down'   => array(
				'message'   => 'Extra line should be added with price 0.03 and lines amount 10.00 and 4.99.',
				'expected'  => array(
					'item_value'       => array( 10.00, 4.99 ),
					'extra_line_value' => 0.03,
				),
				'items'     => array_merge( self::items( array( 'value' => 10.005 ) ), self::items( array( 'value' => 5 ) ) ),
				'amount'    => 36.01,
				'breakdown' => self::breakdown( array( 'item_total' => 30.01 ) ),
			),
			'with_many_roundings_down' => array(
				'message'   => 'Extra line should be added with price 0.01 and lines amount 10.00, 5.00 and 6.66.',
				'expected'  => array(
					'item_value'       => array( 10.00, 4.99, 6.66 ),
					'extra_line_value' => 0.02,
				),
				'items'     => array_merge(
					self::items(
						array(
							'value'    => 10.005,
							'quantity' => 1,
						)
					),
					self::items(
						array(
							'value'    => 5.001,
							'quantity' => 1,
						)
					),
					self::items(
						array(
							'value'    => 6.666,
							'quantity' => 1,
						)
					)
				),
				'amount'    => 27.67,
				'breakdown' => self::breakdown( array( 'item_total' => 21.67 ) ),
			),
		);
	}

	/**
	 * @testdox Should add a line for the rounding difference and round each item down.
	 * @dataProvider data_for_extra_line_tests
	 *
	 * @param string    $message   The message of the case.
	 * @param array     $expected  The value of the extra line and of each item.
	 * @param array     $items     The items.
	 * @param float|int $amount    The order total.
	 * @param array     $breakdown The breakdown parts.
	 */
	public function test_extra_line_method( string $message, array $expected, array $items, $amount, array $breakdown ): void {
		$inputs = $this->make_inputs( $items, $amount, $breakdown );

		$testee = new PurchaseUnit( $inputs['amount'], $inputs['items'] );
		$testee->set_sanitizer( new PurchaseUnitSanitizer( PurchaseUnitSanitizer::MODE_EXTRA_LINE ) );

		$count_items_before = count( $inputs['items'] );
		$array              = $testee->to_array();
		$count_items_after  = count( $array['items'] );
		$extra_item         = array_pop( $array['items'] );

		$this->assertSame( $count_items_before + 1, $count_items_after, $message );
		$this->assertEquals( $expected['extra_line_value'], $extra_item['unit_amount']['value'], $message );
		$this->assertSame( PurchaseUnitSanitizer::EXTRA_LINE_NAME, $extra_item['name'], $message );

		foreach ( $array['items'] as $i => $item ) {
			$this->assertEquals( $expected['item_value'][ $i ], $item['unit_amount']['value'], $message );
		}
	}
}
