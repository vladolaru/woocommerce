<?php
/**
 * Class WC_AJAX_Test file.
 *
 * @package WooCommerce\Tests\WC_AJAX.
 */

declare( strict_types = 1 );

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Orders\CouponsController;
use Automattic\WooCommerce\Internal\Orders\TaxesController;
use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Class WC_AJAX_Test file.
 */
class WC_AJAX_Test extends \WP_Ajax_UnitTestCase {

	/**
	 * Sets up the test fixture.
	 */
	public function set_up() {
		parent::set_up();

		// The WP AJAX test case removes these before the class runs, but mixed
		// test sequences can re-add core admin hooks before individual tests.
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );
	}

	/**
	 * Stock should not be reduced from AJAX when an item is added to an order.
	 */
	public function test_add_item_to_pending_payment_order() {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 1000 );
		$product->save();

		$order = WC_Helper_Order::create_order();

		$data = array(
			array(
				'id'  => $product->get_id(),
				'qty' => 10,
			),
		);
		// Call private method `maybe_add_order_item`.
		$maybe_add_order_item_func = function () use ( $order, $data ) {
			return static::maybe_add_order_item( $order->get_id(), '', $data );
		};
		$maybe_add_order_item_func->call( new WC_AJAX() );

		// Refresh from DB.
		$product = wc_get_product( $product->get_id() );

		// Stock should not have been reduced because order status is 'pending'.
		$this->assertEquals( 1000, $product->get_stock_quantity() );
		$line_items = $order->get_items();
		foreach ( $line_items as $line_item ) {
			if ( $line_item->get_product_id() === $product->get_id() ) {
				$this->assertEquals( false, $line_item->get_meta( '_reduced_stock', true ) );
			}
		}
	}

	/**
	 * Stock should be reduced from AJAX when an item is added to an order, when status is being changed
	 */
	public function test_add_item_to_processing_order() {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 1000 );
		$product->save();

		$order = WC_Helper_Order::create_order();
		$order->set_status( OrderStatus::PENDING );
		$order->save();

		$data = array(
			array(
				'id'  => $product->get_id(),
				'qty' => 10,
			),
		);
		// Call private method `maybe_add_order_item`.
		$maybe_add_order_item_func = function () use ( $order, $data ) {
			return static::maybe_add_order_item( $order->get_id(), '', $data );
		};
		$maybe_add_order_item_func->call( new WC_AJAX() );
		$order->set_status( OrderStatus::PROCESSING );
		$order->save();

		// Refresh from DB.
		$product = wc_get_product( $product->get_id() );

		$this->assertEquals( 990, $product->get_stock_quantity() );
		$line_items = $order->get_items();
		foreach ( $line_items as $line_item ) {
			if ( $line_item->get_product_id() === $product->get_id() ) {
				$this->assertEquals( 10, $line_item->get_meta( '_reduced_stock', true ) );
			}
		}
	}

	/**
	 * Creating an API Key with too long of a description should report failure.
	 */
	public function test_create_api_key_long_description_failure() {
		$this->skip_on_php_8_1();

		$this->_setRole( 'administrator' );

		$description  = 'This_description_is_really_very_long_and_is_meant_to_exceed_the_database_column_length_of_200_characters_';
		$description .= $description;

		$_POST['security']    = wp_create_nonce( 'update-api-key' );
		$_POST['key_id']      = 0;
		$_POST['user']        = 1;
		$_POST['permissions'] = 'read';
		$_POST['description'] = $description;

		$output_buffering_level = ob_get_level();

		try {
			$this->_handleAjax( 'woocommerce_update_api_key' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} finally {
			// wp_die() doesn't actually occur, so clean up any output buffer
			// WC_AJAX::update_api_key leaves open, keeping the level balanced.
			while ( ob_get_level() > $output_buffering_level ) {
				ob_end_clean();
			}
		}

		$response = json_decode( $this->_last_response, true );

		$this->assertFalse( $response['success'] );
		$this->assertEquals( $response['data']['message'], 'There was an error generating your API Key.' );
	}

	/**
	 * Skip the current test on PHP 8.1 and higher.
	 * TODO: Remove this method and its usages once WordPress is compatible with PHP 8.1. Please note that there are multiple copies of this method.
	 */
	protected function skip_on_php_8_1() {
		if ( version_compare( PHP_VERSION, '8.1', '>=' ) ) {
			$this->markTestSkipped( 'Waiting for WordPress compatibility with PHP 8.1' );
		}
	}

	/**
	 * Test to verify that term color is saved in AJAX calls, but only for terms belonging to a visual attribute.
	 *
	 * @testdox Should save term color only when adding visual attribute terms via AJAX.
	 */
	public function test_add_new_attribute_saves_color_and_image_only_for_visual_attributes(): void {
		$original_theme      = wp_get_theme()->get_stylesheet();
		$visual_attribute_id = null;
		$text_attribute_id   = null;
		$visual_taxonomy     = null;
		$text_taxonomy       = null;
		$visual_term_id      = 0;
		$image_term_id       = 0;
		$color_type_term_id  = 0;
		$text_term_id        = 0;
		$image_id            = 0;
		$suffix              = (string) wp_rand( 1000, 9999 );

		try {
			switch_theme( 'twentytwentyfour' );
			delete_option( 'woocommerce_feature_wc_visual_attribute_enabled' );
			$this->assertTrue(
				wc_get_container()->get( \Automattic\WooCommerce\Internal\Features\FeaturesController::class )->change_feature_enable( 'wc-visual-attribute', true ),
				'The visual attribute feature should be toggled on.'
			);

			$visual_attribute_id = wc_create_attribute(
				array(
					'name' => 'Visual AJAX ' . $suffix,
					'type' => 'wc-visual',
				)
			);
			$text_attribute_id   = wc_create_attribute(
				array(
					'name' => 'Text AJAX ' . $suffix,
					'type' => 'select',
				)
			);

			$this->assertIsInt( $visual_attribute_id, 'The visual attribute should be created.' );
			$this->assertIsInt( $text_attribute_id, 'The text attribute should be created.' );

			$visual_taxonomy = $this->register_attribute_taxonomy_for_test( $visual_attribute_id );
			$text_taxonomy   = $this->register_attribute_taxonomy_for_test( $text_attribute_id );

			$this->_setRole( 'administrator' );

			$_POST['security']                 = wp_create_nonce( 'add-attribute' );
			$_POST['taxonomy']                 = $visual_taxonomy;
			$_POST['term']                     = 'Cerulean ' . $suffix;
			$_POST['wc_visual_attribute_type'] = 'color';
			$_POST['term_color']               = '#336699';

			$visual_response = $this->do_ajax( 'woocommerce_add_new_attribute' );
			$visual_term_id  = isset( $visual_response['term_id'] ) ? absint( $visual_response['term_id'] ) : 0;

			$this->assertNotEmpty( $visual_term_id, 'The visual attribute term should be created.' );
			$this->assertSame( '#336699', get_term_meta( $visual_term_id, 'color', true ), 'Visual attribute terms should store the posted color.' );
			$this->assertSame( '', get_term_meta( $visual_term_id, 'image', true ), 'Visual attribute terms should not store image meta when only color is posted.' );

			$image_id = wp_insert_attachment(
				array(
					'post_title'     => 'Visual AJAX term image',
					'post_type'      => 'attachment',
					'post_mime_type' => 'image/jpeg',
				)
			);
			$this->assertIsInt( $image_id, 'The image should be created.' );

			update_post_meta( $image_id, '_wp_attached_file', 'visual-ajax-term-image.jpg' );

			$_POST['security']                 = wp_create_nonce( 'add-attribute' );
			$_POST['taxonomy']                 = $visual_taxonomy;
			$_POST['term']                     = 'Color selected ' . $suffix;
			$_POST['wc_visual_attribute_type'] = 'color';
			$_POST['term_color']               = '#445566';
			$_POST['term_image']               = (string) $image_id;

			$color_type_response = $this->do_ajax( 'woocommerce_add_new_attribute' );
			$color_type_term_id  = isset( $color_type_response['term_id'] ) ? absint( $color_type_response['term_id'] ) : 0;

			$this->assertNotEmpty( $color_type_term_id, 'The visual attribute term with selected color type should be created.' );
			$this->assertSame( '#445566', get_term_meta( $color_type_term_id, 'color', true ), 'Selected color type should store color even when image is posted.' );
			$this->assertSame( '', get_term_meta( $color_type_term_id, 'image', true ), 'Selected color type should ignore stale image values.' );

			$_POST['security']                 = wp_create_nonce( 'add-attribute' );
			$_POST['taxonomy']                 = $visual_taxonomy;
			$_POST['term']                     = 'Pattern ' . $suffix;
			$_POST['wc_visual_attribute_type'] = 'image';
			$_POST['term_color']               = '#abcdef';
			$_POST['term_image']               = (string) $image_id;

			$image_response = $this->do_ajax( 'woocommerce_add_new_attribute' );
			$image_term_id  = isset( $image_response['term_id'] ) ? absint( $image_response['term_id'] ) : 0;

			$this->assertNotEmpty( $image_term_id, 'The visual attribute term with image should be created.' );
			$this->assertSame( (string) $image_id, get_term_meta( $image_term_id, 'image', true ), 'Selected image type should store image even when color is posted.' );
			$this->assertSame( '', get_term_meta( $image_term_id, 'color', true ), 'Selected image type should ignore stale color values.' );

			$_POST['security']   = wp_create_nonce( 'add-attribute' );
			$_POST['taxonomy']   = $text_taxonomy;
			$_POST['term']       = 'Plain ' . $suffix;
			$_POST['term_color'] = '#abcdef';

			$text_response = $this->do_ajax( 'woocommerce_add_new_attribute' );
			$text_term_id  = isset( $text_response['term_id'] ) ? absint( $text_response['term_id'] ) : 0;

			$this->assertNotEmpty( $text_term_id, 'The text attribute term should be created.' );
			$this->assertSame( '', get_term_meta( $text_term_id, 'color', true ), 'Text attribute terms should ignore posted colors.' );
		} finally {
			unset( $_POST['security'], $_POST['taxonomy'], $_POST['term'], $_POST['wc_visual_attribute_type'], $_POST['term_color'], $_POST['term_image'] );

			if ( $image_id ) {
				wp_delete_attachment( $image_id, true );
			}

			if ( $visual_term_id && taxonomy_exists( $visual_taxonomy ) ) {
				wp_delete_term( $visual_term_id, $visual_taxonomy );
			}

			if ( $image_term_id && taxonomy_exists( $visual_taxonomy ) ) {
				wp_delete_term( $image_term_id, $visual_taxonomy );
			}

			if ( $color_type_term_id && taxonomy_exists( $visual_taxonomy ) ) {
				wp_delete_term( $color_type_term_id, $visual_taxonomy );
			}

			if ( $text_term_id && taxonomy_exists( $text_taxonomy ) ) {
				wp_delete_term( $text_term_id, $text_taxonomy );
			}

			if ( is_int( $visual_attribute_id ) ) {
				wc_delete_attribute( $visual_attribute_id );
			}

			if ( is_int( $text_attribute_id ) ) {
				wc_delete_attribute( $text_attribute_id );
			}

			global $wc_product_attributes;
			foreach ( array_filter( array( $visual_taxonomy, $text_taxonomy ) ) as $taxonomy ) {
				if ( taxonomy_exists( $taxonomy ) ) {
					unregister_taxonomy( $taxonomy );
				}
				unset( $wc_product_attributes[ $taxonomy ] );
			}

			delete_option( 'woocommerce_feature_wc_visual_attribute_enabled' );
			switch_theme( $original_theme );
		}//end try
	}

	/**
	 * @testdox Should include an exact taxonomy term match beyond the result limit.
	 */
	public function test_json_search_taxonomy_terms_includes_exact_name_beyond_limit(): void {
		$fixture = null;

		try {
			$term_names = array();
			for ( $index = 0; $index < 50; ++$index ) {
				$term_names[] = sprintf( 'Candidate 6 %02d', $index );
			}
			$term_names[] = '6';

			$fixture       = $this->create_attribute_taxonomy_fixture_for_test( $term_names );
			$exact_term_id = $fixture['term_ids']['6'];

			$filter_call_count = 0;
			$filter_taxonomy   = null;
			$filter_saw_exact  = false;
			$filter_callback   = function ( $terms, $taxonomy ) use ( &$filter_call_count, &$filter_taxonomy, &$filter_saw_exact, $exact_term_id ) {
				++$filter_call_count;
				$filter_taxonomy  = $taxonomy;
				$filter_saw_exact = in_array( $exact_term_id, wp_list_pluck( $terms, 'term_id' ), true );

				return $terms;
			};

			add_filter( 'woocommerce_json_search_found_product_attribute_terms', $filter_callback, 20, 2 );

			$exact_query_count = 0;
			$this->track_exact_taxonomy_term_queries_for_test( $fixture['taxonomy'], '6', $exact_query_count );

			$response = $this->search_taxonomy_terms_via_ajax_for_test( $fixture['taxonomy'], '6', 50, 'menu_order' );

			$this->assertCount( 50, $response, 'The response should respect the requested result limit.' );
			$this->assertCount( 50, array_unique( wp_list_pluck( $response, 'term_id' ) ), 'The response should not contain duplicate terms.' );
			$this->assertSame( $exact_term_id, $response[0]['term_id'], 'The exact term match should be the first response item.' );
			$this->assertSame( 1, $filter_call_count, 'The final results filter should run once.' );
			$this->assertTrue( $filter_saw_exact, 'The final results filter should receive the exact term match.' );
			$this->assertSame( $fixture['taxonomy'], $filter_taxonomy, 'The final results filter should receive the requested taxonomy unchanged.' );
			$this->assertSame( 1, $exact_query_count, 'The omitted exact match should trigger one bounded exact-name term query.' );
		} finally {
			if ( null !== $fixture ) {
				$this->unregister_attribute_taxonomy_fixture_for_test( $fixture );
			}
		}
	}

	/**
	 * @testdox Should include an exact taxonomy term match when a filter supplies the default empty offset.
	 */
	public function test_json_search_taxonomy_terms_includes_exact_name_with_empty_offset(): void {
		$fixture = null;

		try {
			$fixture = $this->create_attribute_taxonomy_fixture_for_test(
				array(
					'Candidate 6 00',
					'Candidate 6 01',
					'Candidate 6 02',
					'6',
				)
			);

			// WP_Term_Query documents an empty string as its own "no offset" default.
			add_filter(
				'woocommerce_product_attribute_terms',
				static function ( $args ) {
					$args['offset'] = '';

					return $args;
				}
			);

			$exact_query_count = 0;
			$this->track_exact_taxonomy_term_queries_for_test( $fixture['taxonomy'], '6', $exact_query_count );

			$response = $this->search_taxonomy_terms_via_ajax_for_test( $fixture['taxonomy'], '6', 3, 'menu_order' );

			$this->assertSame(
				array( '6', 'Candidate 6 00', 'Candidate 6 01' ),
				wp_list_pluck( $response, 'name' ),
				'An empty filtered offset should still recover the omitted exact match.'
			);
			$this->assertSame( 1, $exact_query_count, 'An empty filtered offset should trigger one bounded exact-name term query.' );
		} finally {
			if ( null !== $fixture ) {
				$this->unregister_attribute_taxonomy_fixture_for_test( $fixture );
			}
		}
	}

	/**
	 * @testdox Should preserve the ordering of a visible exact taxonomy term match.
	 */
	public function test_json_search_taxonomy_terms_does_not_promote_visible_exact_name(): void {
		$fixture = null;

		try {
			$fixture = $this->create_attribute_taxonomy_fixture_for_test(
				array(
					'Alpha candidate first',
					'Alpha',
					'Alpha candidate third',
					'Alpha candidate fourth',
				)
			);

			$exact_query_count = 0;
			$this->track_exact_taxonomy_term_queries_for_test( $fixture['taxonomy'], 'Alpha', $exact_query_count );

			$response = $this->search_taxonomy_terms_via_ajax_for_test( $fixture['taxonomy'], 'Alpha', 3, 'menu_order' );

			$this->assertSame(
				array( 'Alpha candidate first', 'Alpha', 'Alpha candidate third' ),
				wp_list_pluck( $response, 'name' ),
				'The visible exact match should retain its menu order position.'
			);
			$this->assertCount(
				3,
				array_unique( wp_list_pluck( $response, 'term_id' ) ),
				'The response should contain three unique term IDs.'
			);
			$this->assertSame( 0, $exact_query_count, 'A byte-identical visible exact match should not trigger a fallback term query.' );
		} finally {
			if ( null !== $fixture ) {
				$this->unregister_attribute_taxonomy_fixture_for_test( $fixture );
			}
		}
	}

	/**
	 * @testdox Should preserve the ordering of a visible database-equivalent exact taxonomy term match.
	 */
	public function test_json_search_taxonomy_terms_deduplicates_visible_collation_equivalent_name(): void {
		$fixture = null;

		try {
			$fixture = $this->create_attribute_taxonomy_fixture_for_test(
				array(
					'Alpha candidate first',
					'Álpha',
					'Alpha candidate third',
					'Alpha candidate fourth',
				)
			);

			$exact_query_count = 0;
			$this->track_exact_taxonomy_term_queries_for_test( $fixture['taxonomy'], 'alpha', $exact_query_count );

			$response = $this->search_taxonomy_terms_via_ajax_for_test( $fixture['taxonomy'], 'alpha', 3, 'menu_order' );

			$this->assertSame(
				array( 'Alpha candidate first', 'Álpha', 'Alpha candidate third' ),
				wp_list_pluck( $response, 'name' ),
				'The database-equivalent visible exact match should retain its menu order position.'
			);
			$this->assertCount( 3, array_unique( wp_list_pluck( $response, 'term_id' ) ), 'The response should contain three unique term IDs.' );
			$this->assertSame( 1, $exact_query_count, 'The database-authoritative comparison should use one bounded exact-name term query.' );
		} finally {
			if ( null !== $fixture ) {
				$this->unregister_attribute_taxonomy_fixture_for_test( $fixture );
			}
		}
	}

	/**
	 * @testdox Should respect exclusion of an exact taxonomy term match through the query arguments filter.
	 */
	public function test_json_search_taxonomy_terms_respects_filtered_exact_term_exclusion(): void {
		$fixture = null;

		try {
			$fixture = $this->create_attribute_taxonomy_fixture_for_test(
				array(
					'Candidate 6 first',
					'Candidate 6 second',
					'Candidate 6 third',
					'6',
				)
			);

			$exact_term_id     = $fixture['term_ids']['6'];
			$filter_call_count = 0;
			$filter_callback   = function ( $args ) use ( &$filter_call_count, $exact_term_id ) {
				++$filter_call_count;
				$args['exclude'] = array( $exact_term_id );

				return $args;
			};

			add_filter( 'woocommerce_product_attribute_terms', $filter_callback );

			$exact_query_count = 0;
			$this->track_exact_taxonomy_term_queries_for_test( $fixture['taxonomy'], '6', $exact_query_count );

			$response     = $this->search_taxonomy_terms_via_ajax_for_test( $fixture['taxonomy'], '6', 3, 'menu_order' );
			$response_ids = wp_list_pluck( $response, 'term_id' );

			$this->assertCount( 3, $response, 'The response should contain the requested number of terms.' );
			$this->assertNotContains( $exact_term_id, $response_ids, 'The excluded exact term should not appear in the response.' );
			$this->assertSame( 1, $filter_call_count, 'The product attribute term query arguments filter should run exactly once.' );
			$this->assertSame( 1, $exact_query_count, 'The full supported response should perform at most one exact-name term query.' );
		} finally {
			if ( null !== $fixture ) {
				$this->unregister_attribute_taxonomy_fixture_for_test( $fixture );
			}
		}
	}

	/**
	 * @testdox Should preserve a full broad response when no exact taxonomy term exists.
	 */
	public function test_json_search_taxonomy_terms_preserves_full_response_without_exact_name(): void {
		$fixture = null;

		try {
			$fixture = $this->create_attribute_taxonomy_fixture_for_test(
				array(
					'Candidate 6 first',
					'Candidate 6 second',
					'Candidate 6 third',
				)
			);

			$exact_query_count = 0;
			$this->track_exact_taxonomy_term_queries_for_test( $fixture['taxonomy'], '6', $exact_query_count );

			$response = $this->search_taxonomy_terms_via_ajax_for_test( $fixture['taxonomy'], '6', 3, 'menu_order' );

			$this->assertSame(
				array( 'Candidate 6 first', 'Candidate 6 second', 'Candidate 6 third' ),
				wp_list_pluck( $response, 'name' ),
				'The broad result should remain unchanged when no exact term exists.'
			);
			$this->assertSame( 1, $exact_query_count, 'A full supported response should perform only one bounded exact-name term query.' );
		} finally {
			if ( null !== $fixture ) {
				$this->unregister_attribute_taxonomy_fixture_for_test( $fixture );
			}
		}
	}

	/**
	 * @testdox Should reject an exact taxonomy term returned from another taxonomy.
	 */
	public function test_json_search_taxonomy_terms_rejects_exact_name_from_other_taxonomy(): void {
		$requested_fixture = null;
		$foreign_fixture   = null;

		try {
			$term_names = array(
				'Candidate 6 first',
				'Candidate 6 second',
				'Candidate 6 third',
			);

			$requested_fixture = $this->create_attribute_taxonomy_fixture_for_test( $term_names );
			$foreign_fixture   = $this->create_attribute_taxonomy_fixture_for_test( array( '6' ) );

			add_action(
				'pre_get_terms',
				static function ( $query ) use ( $requested_fixture, $foreign_fixture ) {
					$query_taxonomies = (array) ( $query->query_vars['taxonomy'] ?? array() );
					$query_names      = (array) ( $query->query_vars['name'] ?? array() );

					if ( in_array( $requested_fixture['taxonomy'], $query_taxonomies, true ) && in_array( '6', $query_names, true ) ) {
						$query->query_vars['taxonomy'] = array( $foreign_fixture['taxonomy'] );
					}
				}
			);

			$response = $this->search_taxonomy_terms_via_ajax_for_test( $requested_fixture['taxonomy'], '6', 3, 'menu_order' );

			$this->assertSame( $term_names, wp_list_pluck( $response, 'name' ), 'A foreign exact term should not displace the requested taxonomy results.' );
			$this->assertNotContains( $foreign_fixture['term_ids']['6'], wp_list_pluck( $response, 'term_id' ), 'The response should not contain a term from another taxonomy.' );
		} finally {
			if ( null !== $foreign_fixture ) {
				$this->unregister_attribute_taxonomy_fixture_for_test( $foreign_fixture );
			}

			if ( null !== $requested_fixture ) {
				$this->unregister_attribute_taxonomy_fixture_for_test( $requested_fixture );
			}
		}
	}

	/**
	 * @testdox Should treat the search string zero as a valid exact taxonomy term name.
	 */
	public function test_json_search_taxonomy_terms_includes_exact_zero_name(): void {
		$fixture = null;

		try {
			$fixture = $this->create_attribute_taxonomy_fixture_for_test(
				array(
					'Candidate 0 first',
					'Candidate 0 second',
					'Candidate 0 third',
					'0',
				)
			);

			$response = $this->search_taxonomy_terms_via_ajax_for_test( $fixture['taxonomy'], '0', 3, 'menu_order' );

			$this->assertCount( 3, $response, 'The response should retain its configured cap.' );
			$this->assertSame( $fixture['term_ids']['0'], $response[0]['term_id'], 'The exact zero-named term should be the first result.' );
		} finally {
			if ( null !== $fixture ) {
				$this->unregister_attribute_taxonomy_fixture_for_test( $fixture );
			}
		}
	}

	/**
	 * @testdox Should leave unsupported filtered taxonomy search argument shapes unchanged.
	 *
	 * @dataProvider unsupported_taxonomy_term_search_argument_provider
	 *
	 * @param Closure $filter_callback         Applies the unsupported filtered argument shape.
	 * @param Closure $project_response        Projects the AJAX response into the value under assertion.
	 * @param Closure $resolve_expected        Resolves the expected value from the runtime fixture.
	 * @param string  $response_assertion_text Explains the expected pass-through behavior.
	 */
	public function test_json_search_taxonomy_terms_leaves_unsupported_filtered_shapes_unchanged( Closure $filter_callback, Closure $project_response, Closure $resolve_expected, string $response_assertion_text ): void {
		$fixture = null;

		try {
			$term_names = array(
				'Candidate 6 00',
				'Candidate 6 01',
				'Candidate 6 02',
				'Candidate 6 03',
				'Candidate 6 04',
				'6',
			);
			$fixture    = $this->create_attribute_taxonomy_fixture_for_test( $term_names );

			add_filter(
				'woocommerce_product_attribute_terms',
				$filter_callback
			);

			$exact_query_count = 0;
			$this->track_exact_taxonomy_term_queries_for_test( $fixture['taxonomy'], '6', $exact_query_count );

			$response = $this->search_taxonomy_terms_via_ajax_for_test( $fixture['taxonomy'], '6', 3, 'menu_order' );

			$this->assertSame( 0, $exact_query_count, 'Unsupported filtered argument shapes should not trigger the exact-name term query.' );
			$this->assertSame(
				$resolve_expected(
					array(
						'fixture'    => $fixture,
						'term_names' => $term_names,
					)
				),
				$project_response( $response ),
				$response_assertion_text
			);
		} finally {
			if ( null !== $fixture ) {
				$this->unregister_attribute_taxonomy_fixture_for_test( $fixture );
			}
		}
	}

	/**
	 * Unsupported filtered taxonomy search argument shapes.
	 *
	 * @return array<string, array{Closure, Closure, Closure, string}>
	 */
	public function unsupported_taxonomy_term_search_argument_provider(): array {
		$pluck_names = static fn( $response ) => wp_list_pluck( $response, 'name' );
		$unchanged   = static fn( $response ) => $response;

		$first_three_names = static fn( $context ) => array_slice( $context['term_names'], 0, 3 );
		$offset_names      = static fn( $context ) => array_slice( $context['term_names'], 1, 3 );
		$all_names         = static fn( $context ) => $context['term_names'];
		$first_three_ids   = static fn( $context ) => array_slice( array_values( $context['fixture']['term_ids'] ), 0, 3 );

		return array(
			'string arguments' => array(
				static fn( $args ) => http_build_query( $args ),
				$pluck_names,
				$first_three_names,
				'A string argument shape should retain the broad response.',
			),
			'alternate fields' => array(
				static function ( $args ) {
					$args['fields'] = 'ids';

					return $args;
				},
				$unchanged,
				$first_three_ids,
				'Alternate field shapes should pass through unchanged.',
			),
			'taxonomy array'   => array(
				static function ( $args ) {
					$args['taxonomy'] = array( $args['taxonomy'] );

					return $args;
				},
				$pluck_names,
				$first_three_names,
				'A taxonomy array should retain the broad response.',
			),
			'nonzero offset'   => array(
				static function ( $args ) {
					$args['offset'] = 1;

					return $args;
				},
				$pluck_names,
				$offset_names,
				'A nonzero offset should retain the requested broad window.',
			),
			'absent limit'     => array(
				static function ( $args ) {
					unset( $args['number'] );

					return $args;
				},
				$pluck_names,
				$all_names,
				'An absent limit should retain the unbounded broad response.',
			),
			'non-finite limit' => array(
				static function ( $args ) {
					$args['number'] = 'INF';

					return $args;
				},
				$pluck_names,
				$all_names,
				'A non-finite limit should retain the unbounded broad response.',
			),
			'decimal limit'    => array(
				static function ( $args ) {
					$args['number'] = '3.5';

					return $args;
				},
				$pluck_names,
				$first_three_names,
				'A decimal limit should retain the broad response.',
			),
			'decimal offset'   => array(
				static function ( $args ) {
					$args['offset'] = '0.0';

					return $args;
				},
				$pluck_names,
				$first_three_names,
				'A decimal offset should retain the broad response.',
			),
			'term query error' => array(
				static function ( $args ) {
					$args['taxonomy'] = 'not_a_registered_taxonomy';

					return $args;
				},
				static fn( $response ) => isset( $response['errors']['invalid_taxonomy'] ),
				static fn() => true,
				'A term-query error should pass through unchanged.',
			),
			'competing search' => array(
				static function ( $args ) {
					$args['search'] = 'Candidate';

					return $args;
				},
				$pluck_names,
				$first_three_names,
				'A competing filtered search selector should retain the broad response.',
			),
			'competing name'   => array(
				static function ( $args ) {
					$args['name'] = array(
						'Candidate 6 00',
						'Candidate 6 01',
						'Candidate 6 02',
					);

					return $args;
				},
				$pluck_names,
				$first_three_names,
				'A competing filtered name selector should retain the broad response.',
			),
		);
	}

	/**
	 * @testdox Should leave an empty taxonomy search and hierarchical attribute taxonomy unchanged.
	 */
	public function test_json_search_taxonomy_terms_skips_empty_and_hierarchical_searches(): void {
		$fixture = null;

		try {
			$term_names = array( 'Alpha first', 'Alpha second', 'Alpha third', 'Alpha' );
			$fixture    = $this->create_attribute_taxonomy_fixture_for_test( $term_names );

			$empty_response = $this->search_taxonomy_terms_via_ajax_for_test( $fixture['taxonomy'], '', 3, 'menu_order' );
			$this->assertSame( array_slice( $term_names, 0, 3 ), wp_list_pluck( $empty_response, 'name' ), 'An empty search should retain the broad response.' );

			unregister_taxonomy( $fixture['taxonomy'] );
			register_taxonomy(
				$fixture['taxonomy'],
				array( 'product' ),
				array(
					'hierarchical' => true,
					'capabilities' => array(
						'manage_terms' => 'manage_product_terms',
						'edit_terms'   => 'edit_product_terms',
						'delete_terms' => 'delete_product_terms',
						'assign_terms' => 'assign_product_terms',
					),
				)
			);

			$exact_query_count = 0;
			$this->track_exact_taxonomy_term_queries_for_test( $fixture['taxonomy'], 'Alpha', $exact_query_count );
			$hierarchical_response = $this->search_taxonomy_terms_via_ajax_for_test( $fixture['taxonomy'], 'Alpha', 3, 'menu_order' );

			$this->assertSame( array_slice( $term_names, 0, 3 ), wp_list_pluck( $hierarchical_response, 'name' ), 'A hierarchical attribute taxonomy should retain the broad response.' );
			$this->assertSame( 0, $exact_query_count, 'A hierarchical attribute taxonomy should not trigger an exact-name term query.' );
		} finally {
			if ( null !== $fixture ) {
				$this->unregister_attribute_taxonomy_fixture_for_test( $fixture );
			}
		}
	}

	/**
	 * Count exact-name term queries for a taxonomy during a test.
	 *
	 * The parent test fixture restores ordinary hooks after each test.
	 *
	 * @param string $taxonomy         Taxonomy to observe.
	 * @param string $name             Exact name to observe.
	 * @param int    $exact_query_count Exact-query counter, passed by reference.
	 */
	private function track_exact_taxonomy_term_queries_for_test( string $taxonomy, string $name, int &$exact_query_count ): void {
		add_action(
			'pre_get_terms',
			function ( $query ) use ( $taxonomy, $name, &$exact_query_count ) {
				$query_taxonomies = (array) ( $query->query_vars['taxonomy'] ?? array() );
				$query_names      = (array) ( $query->query_vars['name'] ?? array() );

				if ( in_array( $taxonomy, $query_taxonomies, true ) && in_array( $name, $query_names, true ) ) {
					++$exact_query_count;
				}
			}
		);
	}

	/**
	 * Register a product attribute taxonomy created inside a test.
	 *
	 * @param int $attribute_id Attribute ID.
	 * @return string
	 */
	private function register_attribute_taxonomy_for_test( int $attribute_id ): string {
		global $wc_product_attributes;

		$taxonomy             = wc_attribute_taxonomy_name_by_id( $attribute_id );
		$attribute_taxonomies = wc_get_attribute_taxonomies();

		$wc_product_attributes[ $taxonomy ] = $attribute_taxonomies[ 'id:' . $attribute_id ];

		register_taxonomy(
			$taxonomy,
			array( 'product' ),
			array(
				'capabilities' => array(
					'manage_terms' => 'manage_product_terms',
					'edit_terms'   => 'edit_product_terms',
					'delete_terms' => 'delete_product_terms',
					'assign_terms' => 'assign_product_terms',
				),
			)
		);

		return $taxonomy;
	}

	/**
	 * Create a global product attribute and ordered terms for a test.
	 *
	 * @param string[] $term_names Term names in menu order.
	 * @return array{taxonomy: string, term_ids: array<array-key, int>}
	 */
	private function create_attribute_taxonomy_fixture_for_test( array $term_names ): array {
		$fixture = array(
			'taxonomy' => '',
			'term_ids' => array(),
		);

		try {
			$suffix       = wp_unique_id();
			$attribute_id = wc_create_attribute(
				array(
					'name'     => 'AJAX search fixture ' . $suffix,
					'slug'     => 'ajax_search_' . $suffix,
					'type'     => 'select',
					'order_by' => 'menu_order',
				)
			);

			if ( ! is_int( $attribute_id ) ) {
				throw new RuntimeException( 'The product attribute fixture could not be created.' );
			}

			$fixture['taxonomy'] = $this->register_attribute_taxonomy_for_test( $attribute_id );

			foreach ( $term_names as $menu_order => $term_name ) {
				$term = wp_insert_term( $term_name, $fixture['taxonomy'] );

				if ( is_wp_error( $term ) ) {
					throw new RuntimeException( 'A product attribute term fixture could not be created.' );
				}

				$term_id                           = (int) $term['term_id'];
				$fixture['term_ids'][ $term_name ] = $term_id;
				wc_set_term_order( $term_id, $menu_order, $fixture['taxonomy'] );
			}
		} catch ( Throwable $throwable ) {
			$this->unregister_attribute_taxonomy_fixture_for_test( $fixture );
			throw $throwable;
		}

		return $fixture;
	}

	/**
	 * Unregister the process state for a global product attribute fixture.
	 *
	 * Database writes are rolled back by the parent test case transaction.
	 *
	 * @param array{taxonomy: string, term_ids: array<array-key, int>} $fixture Fixture data.
	 */
	private function unregister_attribute_taxonomy_fixture_for_test( array $fixture ): void {
		global $wc_product_attributes;

		if ( taxonomy_exists( $fixture['taxonomy'] ) ) {
			unregister_taxonomy( $fixture['taxonomy'] );
		}

		unset( $wc_product_attributes[ $fixture['taxonomy'] ] );
	}

	/**
	 * Run an authenticated taxonomy term AJAX search for a test.
	 *
	 * @param string $taxonomy Taxonomy to search.
	 * @param string $term     Search term.
	 * @param int    $limit    Maximum result count.
	 * @param string $orderby  Result ordering.
	 * @return array
	 */
	private function search_taxonomy_terms_via_ajax_for_test( string $taxonomy, string $term, int $limit, string $orderby ): array {
		$original_get     = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserve test globals before building the authenticated request.
		$original_post    = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Preserve test globals before building the authenticated request.
		$original_request = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserve test globals before building the authenticated request.
		$original_user_id = get_current_user_id();

		try {
			$this->_setRole( 'administrator' );
			$_GET = array(
				'security' => wp_create_nonce( 'search-taxonomy-terms' ),
				'taxonomy' => $taxonomy,
				'term'     => $term,
				'limit'    => $limit,
				'orderby'  => $orderby,
			);

			$response = $this->do_ajax( 'woocommerce_json_search_taxonomy_terms' );

			if ( ! is_array( $response ) ) {
				throw new RuntimeException( 'The taxonomy term AJAX response should be an array.' );
			}

			return $response;
		} finally {
			$_GET     = $original_get;
			$_POST    = $original_post;
			$_REQUEST = $original_request;
			wp_set_current_user( $original_user_id );
		}
	}

	/**
	 * Test coupon and recalculation of totals sequences when product prices are tax inclusive.
	 */
	public function test_apply_coupon_with_tax_inclusive_settings() {
		update_option( 'woocommerce_prices_include_tax', 'yes' );
		update_option( 'woocommerce_tax_based_on', 'base' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_default_country', 'IN:AP' );

		$tax_rate = array(
			'tax_rate_country' => 'IN',
			'tax_rate_state'   => '',
			'tax_rate'         => '20',
			'tax_rate_name'    => 'tax',
			'tax_rate_order'   => '1',
			'tax_rate_class'   => '',
		);

		WC_Tax::_insert_tax_rate( $tax_rate );

		$product = WC_Helper_Product::create_simple_product();
		$product->set_regular_price( 120 );
		$product->save();

		$coupon = new WC_Coupon();
		$coupon->set_code( '10off' );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );
		$coupon->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );

		$container          = wc_get_container();
		$coupons_controller = $container->get( CouponsController::class );
		$taxes_controller   = $container->get( TaxesController::class );

		$item        = current( $order->get_items() );
		$item_id     = $item->get_id();
		$items_array = array(
			'order_item_id'  => array( $item_id ),
			'order_item_qty' => array( $item_id => $item->get_quantity() ),
			'line_subtotal'  => array( $item_id => $item->get_subtotal() ),
			'line_total'     => array( $item_id => $item->get_total() ),
		);

		$calc_taxes_post_variables = array(
			'order_id' => $order->get_id(),
			'items'    => http_build_query( $items_array ),
			'country'  => $tax_rate['tax_rate_country'],
			'state'    => $tax_rate['tax_rate_state'],
		);

		$add_coupon_post_variables = array(
			'order_id' => $order->get_id(),
			'coupon'   => $coupon->get_code(),
		);

		$taxes_controller->calc_line_taxes( $calc_taxes_post_variables );
		$coupons_controller->add_coupon_discount( $add_coupon_post_variables );

		$order = wc_get_order( $order->get_id() );
		$this->assertEquals( 108, $order->get_total() );
	}

	/**
	 * @testdox Should paginate tax rate search results and find rates by location code.
	 */
	public function test_json_search_tax_rates_supports_pagination_and_location_search(): void {
		global $wpdb;

		$this->_setRole( 'administrator' );

		$first_rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'US',
				'tax_rate_state'    => 'CA',
				'tax_rate'          => '7.2500',
				'tax_rate_name'     => 'Pagination fixture California rate',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 1,
				'tax_rate_class'    => '',
			)
		);
		WC_Tax::_update_tax_rate_postcodes( $first_rate_id, '90001' );

		$second_rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'US',
				'tax_rate_state'    => 'NY',
				'tax_rate'          => '8.8750',
				'tax_rate_name'     => 'Pagination fixture New York rate',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 2,
				'tax_rate_class'    => '',
			)
		);
		WC_Tax::_update_tax_rate_postcodes( $second_rate_id, '10001' );

		try {
			$_GET['security'] = wp_create_nonce( 'search-tax-rates' );
			$_GET['term']     = 'Pagination fixture';
			$_GET['page']     = 1;
			$_GET['per_page'] = 1;

			$response = $this->do_ajax( 'woocommerce_json_search_tax_rates' );

			$this->assertSame( 1, $response['pagination']['page'] );
			$this->assertSame( 1, $response['pagination']['per_page'] );
			$this->assertSame( 2, $response['pagination']['total'] );
			$this->assertSame( 2, $response['pagination']['total_pages'] );
			$this->assertFalse( $response['pagination']['has_prev'] );
			$this->assertTrue( $response['pagination']['has_next'] );
			$this->assertCount( 1, $response['results'] );
			$this->assertSame( $first_rate_id, $response['results'][0]['id'] );

			$_GET['page'] = 2;
			$response     = $this->do_ajax( 'woocommerce_json_search_tax_rates' );

			$this->assertSame( 2, $response['pagination']['page'] );
			$this->assertFalse( $response['pagination']['has_next'] );
			$this->assertTrue( $response['pagination']['has_prev'] );
			$this->assertCount( 1, $response['results'] );
			$this->assertSame( $second_rate_id, $response['results'][0]['id'] );

			$_GET['page'] = 99;
			$response     = $this->do_ajax( 'woocommerce_json_search_tax_rates' );

			$this->assertSame( 2, $response['pagination']['page'] );
			$this->assertCount( 1, $response['results'] );
			$this->assertSame( $second_rate_id, $response['results'][0]['id'] );

			$_GET['term'] = '10001';
			$_GET['page'] = 1;
			$response     = $this->do_ajax( 'woocommerce_json_search_tax_rates' );

			$this->assertSame( 1, $response['pagination']['page'] );
			$this->assertSame( 1, $response['pagination']['per_page'] );
			$this->assertSame( 1, $response['pagination']['total'] );
			$this->assertFalse( $response['pagination']['has_next'] );
			$this->assertCount( 1, $response['results'] );
			$this->assertSame( $second_rate_id, $response['results'][0]['id'] );
			$this->assertSame( 'Pagination fixture New York rate', $response['results'][0]['label'] );
			$this->assertSame( 'US-NY-PAGINATION FIXTURE NEW YORK RATE-1', $response['results'][0]['rate_code'] );
			$this->assertSame( '8.875%', $response['results'][0]['rate_percent'] );
		} finally {
			unset( $_GET['security'], $_GET['term'], $_GET['page'], $_GET['per_page'] );
			$wpdb->delete( $wpdb->prefix . 'woocommerce_tax_rate_locations', array( 'tax_rate_id' => $first_rate_id ) );
			$wpdb->delete( $wpdb->prefix . 'woocommerce_tax_rate_locations', array( 'tax_rate_id' => $second_rate_id ) );
			$wpdb->delete( $wpdb->prefix . 'woocommerce_tax_rates', array( 'tax_rate_id' => $first_rate_id ) );
			$wpdb->delete( $wpdb->prefix . 'woocommerce_tax_rates', array( 'tax_rate_id' => $second_rate_id ) );
			wp_set_current_user( 0 );
		}
	}

	/**
	 * @testdox Should find tax rates by their visible tax class labels.
	 */
	public function test_json_search_tax_rates_supports_tax_class_label_search(): void {
		global $wpdb;

		$this->_setRole( 'administrator' );

		$tax_class              = WC_Tax::create_tax_class( 'Reduced rate', 'reduced-rate' );
		$created_tax_class_slug = is_wp_error( $tax_class ) ? null : $tax_class['slug'];

		$standard_rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'US',
				'tax_rate_state'    => 'CA',
				'tax_rate'          => '7.2500',
				'tax_rate_name'     => 'California base rate',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 1,
				'tax_rate_class'    => '',
			)
		);

		$reduced_rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'US',
				'tax_rate_state'    => 'NY',
				'tax_rate'          => '4.0000',
				'tax_rate_name'     => 'Reduced class rate',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 2,
				'tax_rate_class'    => 'reduced-rate',
			)
		);

		try {
			$_GET['security'] = wp_create_nonce( 'search-tax-rates' );
			$_GET['page']     = 1;
			$_GET['per_page'] = 100;

			$_GET['term'] = 'Standard';
			$response     = $this->do_ajax( 'woocommerce_json_search_tax_rates' );
			$rate_ids     = array_column( $response['results'], 'id' );

			$this->assertContains( $standard_rate_id, $rate_ids );
			$standard_results = array_filter(
				$response['results'],
				function ( $result ) use ( $standard_rate_id ) {
					return $standard_rate_id === $result['id'];
				}
			);
			$standard_result  = current( $standard_results );
			$this->assertIsArray( $standard_result );
			$this->assertSame( 'Standard', $standard_result['tax_class'] );

			$_GET['term'] = 'Reduced rate';
			$response     = $this->do_ajax( 'woocommerce_json_search_tax_rates' );
			$rate_ids     = array_column( $response['results'], 'id' );

			$this->assertContains( $reduced_rate_id, $rate_ids );
			$reduced_results = array_filter(
				$response['results'],
				function ( $result ) use ( $reduced_rate_id ) {
					return $reduced_rate_id === $result['id'];
				}
			);
			$reduced_result  = current( $reduced_results );
			$this->assertIsArray( $reduced_result );
			$this->assertSame( 'Reduced rate', $reduced_result['tax_class'] );
		} finally {
			unset( $_GET['security'], $_GET['term'], $_GET['page'], $_GET['per_page'] );
			$wpdb->delete( $wpdb->prefix . 'woocommerce_tax_rates', array( 'tax_rate_id' => $standard_rate_id ) );
			$wpdb->delete( $wpdb->prefix . 'woocommerce_tax_rates', array( 'tax_rate_id' => $reduced_rate_id ) );
			if ( $created_tax_class_slug ) {
				WC_Tax::delete_tax_class_by( 'slug', $created_tax_class_slug );
			}
			wp_set_current_user( 0 );
		}
	}

	/**
	 * @testdox Should find tax rates by displayed percentages, rate codes, and fallback labels.
	 */
	public function test_json_search_tax_rates_supports_displayed_value_search(): void {
		global $wpdb;

		$this->_setRole( 'administrator' );

		$named_rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'US',
				'tax_rate_state'    => 'NY',
				'tax_rate'          => '8.8750',
				'tax_rate_name'     => 'Displayed value fixture rate',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 1,
				'tax_rate_class'    => '',
			)
		);

		$unnamed_rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'ZZ',
				'tax_rate_state'    => 'ZZ',
				'tax_rate'          => '3.5000',
				'tax_rate_name'     => '',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 2,
				'tax_rate_class'    => '',
			)
		);

		try {
			$_GET['security'] = wp_create_nonce( 'search-tax-rates' );
			$_GET['page']     = 1;
			$_GET['per_page'] = 100;

			// The results table shows "8.875%", so that string has to find the rate.
			$_GET['term'] = '8.875%';
			$response     = $this->do_ajax( 'woocommerce_json_search_tax_rates' );

			$this->assertContains( $named_rate_id, array_column( $response['results'], 'id' ) );

			// The full rate code is derived from several columns and must be searchable as shown.
			$_GET['term'] = 'US-NY-DISPLAYED VALUE FIXTURE RATE-1';
			$response     = $this->do_ajax( 'woocommerce_json_search_tax_rates' );

			$this->assertSame( 1, $response['pagination']['total'] );
			$this->assertSame( $named_rate_id, $response['results'][0]['id'] );

			$_GET['term'] = 'us-ny-displayed value';
			$response     = $this->do_ajax( 'woocommerce_json_search_tax_rates' );

			$this->assertContains( $named_rate_id, array_column( $response['results'], 'id' ) );

			// Rates without a name are shown under the store's tax or VAT label.
			$_GET['term'] = WC()->countries->tax_or_vat();
			$response     = $this->do_ajax( 'woocommerce_json_search_tax_rates' );

			$this->assertContains( $unnamed_rate_id, array_column( $response['results'], 'id' ) );

			$_GET['term'] = 'ZZ-ZZ-TAX-1';
			$response     = $this->do_ajax( 'woocommerce_json_search_tax_rates' );

			$this->assertSame( 1, $response['pagination']['total'] );
			$this->assertSame( $unnamed_rate_id, $response['results'][0]['id'] );
			$this->assertSame( 'ZZ-ZZ-TAX-1', $response['results'][0]['rate_code'] );
		} finally {
			unset( $_GET['security'], $_GET['term'], $_GET['page'], $_GET['per_page'] );
			$wpdb->delete( $wpdb->prefix . 'woocommerce_tax_rates', array( 'tax_rate_id' => $named_rate_id ) );
			$wpdb->delete( $wpdb->prefix . 'woocommerce_tax_rates', array( 'tax_rate_id' => $unnamed_rate_id ) );
			wp_set_current_user( 0 );
		}
	}

	/**
	 * @testdox Should return each tax rate once when listing results without a search term.
	 */
	public function test_json_search_tax_rates_without_a_term_lists_all_rates(): void {
		global $wpdb;

		$this->_setRole( 'administrator' );

		$first_rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'US',
				'tax_rate_state'    => 'CA',
				'tax_rate'          => '7.2500',
				'tax_rate_name'     => 'Unfiltered listing fixture one',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 1,
				'tax_rate_class'    => '',
			)
		);
		WC_Tax::_update_tax_rate_postcodes( $first_rate_id, '90001' );

		$second_rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'US',
				'tax_rate_state'    => 'NY',
				'tax_rate'          => '8.8750',
				'tax_rate_name'     => 'Unfiltered listing fixture two',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 2,
				'tax_rate_class'    => '',
			)
		);
		WC_Tax::_update_tax_rate_postcodes( $second_rate_id, '10001,10002,10003' );

		$expected_total = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates" ) );

		try {
			$_GET['security'] = wp_create_nonce( 'search-tax-rates' );
			$_GET['term']     = '';
			$_GET['page']     = 1;
			$_GET['per_page'] = 100;

			$response = $this->do_ajax( 'woocommerce_json_search_tax_rates' );
			$rate_ids = array_column( $response['results'], 'id' );

			// A rate with several postcodes must still be counted once.
			$this->assertSame( $expected_total, $response['pagination']['total'] );
			$this->assertContains( $first_rate_id, $rate_ids );
			$this->assertContains( $second_rate_id, $rate_ids );
			$this->assertSame( count( $rate_ids ), count( array_unique( $rate_ids ) ) );
		} finally {
			unset( $_GET['security'], $_GET['term'], $_GET['page'], $_GET['per_page'] );
			$wpdb->delete( $wpdb->prefix . 'woocommerce_tax_rate_locations', array( 'tax_rate_id' => $first_rate_id ) );
			$wpdb->delete( $wpdb->prefix . 'woocommerce_tax_rate_locations', array( 'tax_rate_id' => $second_rate_id ) );
			$wpdb->delete( $wpdb->prefix . 'woocommerce_tax_rates', array( 'tax_rate_id' => $first_rate_id ) );
			$wpdb->delete( $wpdb->prefix . 'woocommerce_tax_rates', array( 'tax_rate_id' => $second_rate_id ) );
			wp_set_current_user( 0 );
		}
	}

	/**
	 * @testdox Applying a coupon in the order editor calculates the discount from a manually edited line total.
	 */
	public function test_add_coupon_discount_uses_manually_edited_line_total() {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_regular_price( 100 );
		$product->save();

		$coupon = new WC_Coupon();
		$coupon->set_code( '10off-edited' );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );
		$coupon->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		foreach ( $order->get_items() as $item ) {
			$item->set_total( 50 );
			$item->save();
		}
		$order->calculate_totals();
		$order->save();

		wc_get_container()->get( CouponsController::class )->add_coupon_discount(
			array(
				'order_id' => $order->get_id(),
				'coupon'   => $coupon->get_code(),
			)
		);

		$order = wc_get_order( $order->get_id() );
		$item  = current( $order->get_items() );
		$this->assertEquals( 50, $item->get_subtotal(), 'The edited line total should become the new pre-discount price' );
		$this->assertEquals( 45, $item->get_total(), 'The discount should be taken off the edited price' );
		$this->assertEquals( 45, $order->get_total() );
	}

	/**
	 * @testdox Product search decodes URL-encoded characters before returning plain text names.
	 * @dataProvider product_search_name_provider
	 *
	 * @param string $search_term          Product search term.
	 * @param string $product_name         Product name.
	 * @param string $expected_result_name Expected product name in the response.
	 */
	public function test_json_search_products_returns_plain_text_names( string $search_term, string $product_name, string $expected_result_name ): void {
		$this->_setRole( 'administrator' );

		$product = WC_Helper_Product::create_simple_product();
		$product->set_name( $product_name );
		$product->save();

		$_GET['term']     = $search_term;
		$_GET['include']  = array( $product->get_id() );
		$_GET['security'] = wp_create_nonce( 'search-products' );

		try {
			$response = $this->do_ajax( 'woocommerce_json_search_products' );
		} finally {
			unset( $_GET['term'], $_GET['include'], $_GET['security'] );
		}

		$this->assertSame(
			sprintf( '%s (%s)', $expected_result_name, $product->get_sku() ),
			$response[ $product->get_id() ],
			'Product search should return a stripped, plain text product name.'
		);
	}

	/**
	 * Product names used to verify AJAX search response formatting.
	 *
	 * @return array<string, array<string>>
	 */
	public function product_search_name_provider(): array {
		return array(
			'plain punctuation'    => array( 'Ben', "Ben & Jerry's", "Ben & Jerry's" ),
			'URL-encoded space'    => array( 'Coffee', 'Coffee%20Mug', 'Coffee Mug' ),
			'URL-encoded HTML tag' => array( 'Text', 'Text %3Cspan%3Einside%3C/span%3E', 'Text inside' ),
			'HTML tag'             => array( 'Text', 'Text <span>inside</span>', 'Text inside' ),
		);
	}

	/**
	 * Describe JSON search, particularly as it relates to handling searches for users in a
	 * multisite context (it should generally not be possible to retrieve information about
	 * users who have not been added to the current blog).
	 *
	 * @throws Automattic\WooCommerce\Internal\DependencyManagement\ContainerException If the LegacyProxy cannot be retrieved.
	 */
	public function test_json_search_customers(): void {
		$this->markTestSkipped( 'Skipping this test temporarily due to intermittent failures. Needs proper investigation.' );

		// This class does not inherit from WC_Unit_Test_Case, so we're handling the legacy proxy mechanics ourselves.
		$legacy_proxy = wc_get_container()->get( LegacyProxy::class );
		$legacy_proxy->reset();

		$is_member_of_blog    = true;
		$is_multisite         = false;
		$manage_network_users = false;

		$legacy_proxy->register_function_mocks(
			array(
				'check_ajax_referer'     => fn () => true,
				'is_multisite'           => function () use ( &$is_multisite ) {
					return $is_multisite;
				},
				'is_user_member_of_blog' => function () use ( &$is_member_of_blog ) {
					return $is_member_of_blog;
				},
				'user_can'               => function ( $user_id, $capability ) use ( &$manage_network_users ) {
					if ( 'manage_network_users' === $capability ) {
						return $manage_network_users;
					}
					// Return true for other capabilities since we're testing with an admin user.
					return true;
				},
			)
		);

		$customer_id = WC_Helper_Customer::create_customer( 'test1', 'pass1', 'test1@example.com' )->get_id();
		$admin_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$_GET['term'] = $customer_id;

		$response = $this->do_ajax( 'woocommerce_json_search_customers' );
		$this->assertEquals(
			$customer_id,
			key( $response ),
			'If an admin searches for a specific customer ID, and the customer is part of the same blog, it should be possible to retrieve their details.'
		);

		// Let's repeat the test, but simulate being inside a multisite network where the user is not a member of the blog.
		$is_member_of_blog = false;
		$is_multisite      = true;
		$response          = $this->do_ajax( 'woocommerce_json_search_customers' );
		$this->assertEmpty(
			$response,
			'If an admin searches for a specific customer ID, and the customer is not part of the same blog, then it should NOT be possible to retrieve their details.'
		);

		// Clean-up.
		unset( $_GET['term'] );
		wp_set_current_user( 0 );
		$legacy_proxy->reset();
	}

	/**
	 * Describes the behavior of the `get_customer_details` ajax endpoint, particularly in relation to
	 * permissions of the requesting user.
	 *
	 * @throws Automattic\WooCommerce\Internal\DependencyManagement\ContainerException If the LegacyProxy cannot be retrieved.
	 */
	public function test_get_customer_details(): void {
		// This class does not inherit from WC_Unit_Test_Case, so we're handling the legacy proxy mechanics ourselves.
		$legacy_proxy = wc_get_container()->get( LegacyProxy::class );
		$legacy_proxy->reset();

		$customer_id       = 0;
		$is_member_of_blog = true;
		$is_multisite      = true;

		$legacy_proxy->register_function_mocks(
			array(
				'check_ajax_referer'     => fn () => true,
				'is_multisite'           => function () use ( &$is_multisite ) {
					return $is_multisite;
				},
				'is_user_member_of_blog' => function () use ( &$is_member_of_blog ) {
					return $is_member_of_blog;
				},
				'filter_input'           => function ( int $method, string $key, int $filter = FILTER_DEFAULT, $options = 0 ) use ( &$customer_id ) {
					if ( INPUT_POST === $method && 'user_id' === $key ) {
						return $customer_id;
					}

					return filter_input( $method, $key, $filter, $options );
				},
				'wp_die'                 => fn () => '',
			)
		);

		$customer_id = WC_Helper_Customer::create_customer( 'test2', 'pass2', 'test2@example.com' )->get_id();
		$admin_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin_id );
		$_POST['user_id'] = $customer_id;

		$response = $this->do_ajax( 'woocommerce_get_customer_details' );
		$this->assertIsArray(
			$response,
			'If the customer is part of the blog, an array of information is supplied.'
		);

		$is_member_of_blog = false;
		$response          = $this->do_ajax( 'woocommerce_get_customer_details' );
		$this->assertNull(
			$response,
			'If the customer is not part of the blog, we do not get back any customer information (in reality, the request was ended with wp_die).'
		);
	}

	/**
	 * @testdox Should fire internal_woocommerce_cart_item_added_from_user_request when adding an item via AJAX.
	 */
	public function test_add_to_cart_fires_cart_item_added_from_user_request(): void {
		$product = WC_Helper_Product::create_simple_product();

		$_POST['product_id'] = $product->get_id();
		$_POST['quantity']   = 3;

		$captured_args = array();
		$callback      = function ( $product_id, $quantity ) use ( &$captured_args ) {
			$captured_args = array(
				'product_id' => $product_id,
				'quantity'   => $quantity,
			);
		};

		add_action( 'internal_woocommerce_cart_item_added_from_user_request', $callback, 10, 2 );

		$this->do_ajax( 'woocommerce_add_to_cart' );

		$this->assertNotEmpty( $captured_args, 'The action should have been fired' );
		$this->assertSame( $product->get_id(), $captured_args['product_id'] );
		$this->assertEquals( 3, $captured_args['quantity'] );

		remove_action( 'internal_woocommerce_cart_item_added_from_user_request', $callback );

		WC()->cart->empty_cart();
		unset( $_POST['product_id'], $_POST['quantity'] );
		$product->delete( true );
	}

	/**
	 * @testdox Should fire internal_woocommerce_cart_item_added_from_user_request with variation ID when adding a variation via AJAX.
	 */
	public function test_add_to_cart_fires_cart_item_added_from_user_request_for_variation(): void {
		$product = new \WC_Product_Variable();
		$product->set_name( 'Test Variable Product' );
		$attribute = WC_Helper_Product::create_product_attribute_object( 'color', array( 'blue' ) );
		$product->set_attributes( array( $attribute ) );
		$product->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'pa_color' => 'blue' ) );
		$variation->set_regular_price( 10 );
		$variation->save();

		$_POST['product_id'] = $variation->get_id();
		$_POST['quantity']   = 2;

		$captured_args = array();
		$callback      = function ( $product_id, $quantity ) use ( &$captured_args ) {
			$captured_args = array(
				'product_id' => $product_id,
				'quantity'   => $quantity,
			);
		};

		add_action( 'internal_woocommerce_cart_item_added_from_user_request', $callback, 10, 2 );

		$this->do_ajax( 'woocommerce_add_to_cart' );

		$this->assertNotEmpty( $captured_args, 'The action should have been fired' );
		$this->assertSame( $variation->get_id(), $captured_args['product_id'], 'The product_id should be the variation ID, not the parent product ID' );
		$this->assertEquals( 2, $captured_args['quantity'] );

		remove_action( 'internal_woocommerce_cart_item_added_from_user_request', $callback );

		WC()->cart->empty_cart();
		unset( $_POST['product_id'], $_POST['quantity'] );
		$variation->delete( true );
		$product->delete( true );
	}

	/**
	 * @testdox Should fire internal_woocommerce_cart_item_removed_from_user_request when removing an item via AJAX.
	 */
	public function test_remove_from_cart_fires_cart_item_removed_from_user_request(): void {
		$product = WC_Helper_Product::create_simple_product();

		WC()->cart->empty_cart();
		$cart_item_key = WC()->cart->add_to_cart( $product->get_id(), 1 );

		$_POST['cart_item_key'] = $cart_item_key;

		$captured_args = array();
		$callback      = function ( $key, $cart ) use ( &$captured_args ) {
			$captured_args = array(
				'cart_item_key' => $key,
				'cart'          => $cart,
			);
		};

		add_action( 'internal_woocommerce_cart_item_removed_from_user_request', $callback, 10, 2 );

		$this->do_ajax( 'woocommerce_remove_from_cart' );

		$this->assertNotEmpty( $captured_args, 'The action should have been fired' );
		$this->assertSame( $cart_item_key, $captured_args['cart_item_key'] );
		$this->assertInstanceOf( WC_Cart::class, $captured_args['cart'] );

		remove_action( 'internal_woocommerce_cart_item_removed_from_user_request', $callback );

		WC()->cart->empty_cart();
		unset( $_POST['cart_item_key'] );
		$product->delete( true );
	}

	/**
	 * @testdox Invalid refund inputs are rejected before local or gateway mutation.
	 * @dataProvider invalid_refund_line_item_data
	 *
	 * @param array<string,int|float|string> $quantities     Quantities keyed by symbolic line name.
	 * @param array<string,int|float|string> $line_totals    Net totals keyed by symbolic line name.
	 * @param string                         $refund_amount  Aggregate refund amount.
	 * @param string                         $expected_error Expected semantic error.
	 */
	public function test_refund_line_items_rejects_invalid_input_before_mutation( array $quantities, array $line_totals, string $refund_amount, string $expected_error ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Preserves test fixture state.
		$original_post             = $_POST;
		$original_user_id          = get_current_user_id();
		$payment_gateway_registry  = WC_Payment_Gateways::instance();
		$original_payment_gateways = $payment_gateway_registry->payment_gateways;
		$gateway                   = $this->create_recording_refund_gateway();
		$order                     = null;
		$products                  = array();
		$create_refund_calls       = 0;
		$refund_created_calls      = 0;
		$create_refund_callback    = static function () use ( &$create_refund_calls ): void {
			++$create_refund_calls;
		};
		$refund_created_callback   = static function () use ( &$refund_created_calls ): void {
			++$refund_created_calls;
		};

		try {
			$payment_gateway_registry->payment_gateways[] = $gateway;
			$fixture                                      = $this->create_refundable_order( $gateway );
			$order                                        = $fixture['order'];
			$products                                     = $fixture['products'];

			add_action( 'woocommerce_create_refund', $create_refund_callback, 10, 0 );
			add_action( 'woocommerce_refund_created', $refund_created_callback, 10, 0 );
			$this->_setRole( 'administrator' );

			$response = $this->submit_refund_ajax_request( $order, $fixture['item_ids'], $quantities, $line_totals, $refund_amount );

			$this->assertFalse( $response['success'], 'Invalid refund input should return an AJAX error.' );
			$this->assertSame( $expected_error, $response['data']['error'], 'The AJAX error should identify the violated refund bound.' );
			$this->assertCount( 0, $gateway->refund_calls, 'Invalid refund input should not reach the payment gateway.' );
			$this->assertSame( 0, $create_refund_calls, 'Invalid refund input should not reach the pre-create refund hook.' );
			$this->assertSame( 0, $refund_created_calls, 'Invalid refund input should not fire the refund-created action.' );

			$stored_order = wc_get_order( $order->get_id() );
			$this->assertInstanceOf( WC_Order::class, $stored_order, 'The order should still exist after rejection.' );
			$this->assertCount( 0, $stored_order->get_refunds(), 'Invalid refund input should not create a local refund.' );
			$this->assertSame( 0.0, (float) $stored_order->get_total_refunded(), 'The refunded total should remain unchanged.' );
			$this->assertSame( 20.0, (float) $stored_order->get_remaining_refund_amount(), 'The full order amount should remain refundable.' );
		} finally {
			remove_action( 'woocommerce_create_refund', $create_refund_callback, 10 );
			remove_action( 'woocommerce_refund_created', $refund_created_callback, 10 );
			$this->delete_refund_fixture( $order, $products );
			$payment_gateway_registry->payment_gateways = $original_payment_gateways;
			$_POST                                      = $original_post;
			wp_set_current_user( $original_user_id );
		}
	}

	/**
	 * Data provider for invalid refund requests.
	 *
	 * @return array<string,array{array<string,int|float|string>,array<string,int|float|string>,string,string}>
	 */
	public static function invalid_refund_line_item_data(): array {
		return array(
			'quantity greater than maximum'     => array(
				array( 'first' => 2 ),
				array( 'first' => 5 ),
				'5',
				'Line item quantity cannot be greater than the remaining refundable quantity.',
			),
			'quantity negative'                 => array(
				array( 'first' => -1 ),
				array( 'first' => 5 ),
				'5',
				'Line item quantity must be non-negative.',
			),
			'fractional quantity negative'      => array(
				array( 'first' => '-0.5' ),
				array( 'first' => 1 ),
				'1',
				'Line item quantity must be non-negative.',
			),
			'line amount greater than maximum'  => array(
				array(),
				array( 'first' => 10 ),
				'10',
				'Refund total cannot be greater than the remaining refundable amount for this line item.',
			),
			'line amount slightly over maximum' => array(
				array(),
				array( 'first' => '5.001' ),
				'5',
				'Refund total cannot be greater than the remaining refundable amount for this line item.',
			),
			'line amount negative'              => array(
				array(),
				array(
					'first'  => -1,
					'second' => 2,
				),
				'1',
				'Refund total has the wrong sign for this line item.',
			),
			'line amount slightly negative'     => array(
				array(),
				array(
					'first'  => '-0.001',
					'second' => '1.001',
				),
				'1',
				'Refund total has the wrong sign for this line item.',
			),
			'total amount greater than maximum' => array(
				array(),
				array(),
				'21',
				'Invalid refund amount',
			),
			'total amount negative'             => array(
				array(),
				array(),
				'-1',
				'Invalid refund amount',
			),
		);
	}

	/**
	 * @testdox A refund quantity cannot exceed the units left after a prior partial refund.
	 */
	public function test_refund_line_items_rejects_quantity_above_remaining_after_partial_refund(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Preserves test fixture state.
		$original_post             = $_POST;
		$original_user_id          = get_current_user_id();
		$payment_gateway_registry  = WC_Payment_Gateways::instance();
		$original_payment_gateways = $payment_gateway_registry->payment_gateways;
		$gateway                   = $this->create_recording_refund_gateway();
		$order                     = null;
		$products                  = array();
		$refund_created_calls      = 0;
		$refund_created_callback   = static function () use ( &$refund_created_calls ): void {
			++$refund_created_calls;
		};

		try {
			$payment_gateway_registry->payment_gateways[] = $gateway;
			$fixture                                      = $this->create_refundable_order( $gateway, 2 );
			$order                                        = $fixture['order'];
			$products                                     = $fixture['products'];
			$first_item_id                                = $fixture['item_ids']['first'];

			$prior_refund = wc_create_refund(
				array(
					'amount'     => 5,
					'order_id'   => $order->get_id(),
					'line_items' => array(
						$first_item_id => array(
							'qty'          => 1,
							'refund_total' => 5,
						),
					),
				)
			);
			$this->assertInstanceOf( WC_Order_Refund::class, $prior_refund, 'The prior partial-quantity refund should be created.' );

			add_action( 'woocommerce_refund_created', $refund_created_callback, 10, 0 );
			$this->_setRole( 'administrator' );

			$response = $this->submit_refund_ajax_request(
				$order,
				$fixture['item_ids'],
				array( 'first' => 2 ),
				array( 'first' => 5 ),
				'5'
			);

			$this->assertFalse( $response['success'], 'A quantity above the remaining units should return an AJAX error.' );
			$this->assertSame( 'Line item quantity cannot be greater than the remaining refundable quantity.', $response['data']['error'] );
			$this->assertCount( 0, $gateway->refund_calls, 'The rejected follow-up refund should not reach the gateway.' );
			$this->assertSame( 0, $refund_created_calls, 'The rejected follow-up refund should not fire the refund-created action.' );

			$stored_order = wc_get_order( $order->get_id() );
			$this->assertInstanceOf( WC_Order::class, $stored_order, 'The order should still exist after rejection.' );
			$this->assertCount( 1, $stored_order->get_refunds(), 'Only the prior refund should remain.' );
			$this->assertSame( -1, $stored_order->get_qty_refunded_for_item( $first_item_id ), 'One unit should remain recorded as refunded.' );
			$this->assertSame( 5.0, (float) $stored_order->get_total_refunded(), 'The prior refunded total should remain unchanged.' );
			$this->assertSame( 20.0, (float) $stored_order->get_remaining_refund_amount(), 'The rejected request should not reduce the remaining order amount.' );
		} finally {
			remove_action( 'woocommerce_refund_created', $refund_created_callback, 10 );
			$this->delete_refund_fixture( $order, $products );
			$payment_gateway_registry->payment_gateways = $original_payment_gateways;
			$_POST                                      = $original_post;
			wp_set_current_user( $original_user_id );
		}
	}

	/**
	 * @testdox A refund total cannot exceed the net amount left after a prior amount-only refund.
	 */
	public function test_refund_line_items_rejects_total_above_remaining_after_amount_only_refund(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Preserves test fixture state.
		$original_post             = $_POST;
		$original_user_id          = get_current_user_id();
		$payment_gateway_registry  = WC_Payment_Gateways::instance();
		$original_payment_gateways = $payment_gateway_registry->payment_gateways;
		$gateway                   = $this->create_recording_refund_gateway();
		$order                     = null;
		$products                  = array();
		$refund_created_calls      = 0;
		$refund_created_callback   = static function () use ( &$refund_created_calls ): void {
			++$refund_created_calls;
		};

		try {
			$payment_gateway_registry->payment_gateways[] = $gateway;
			$fixture                                      = $this->create_refundable_order( $gateway );
			$order                                        = $fixture['order'];
			$products                                     = $fixture['products'];
			$first_item_id                                = $fixture['item_ids']['first'];

			$prior_refund = wc_create_refund(
				array(
					'amount'     => 3,
					'order_id'   => $order->get_id(),
					'line_items' => array(
						$first_item_id => array(
							'qty'          => 0,
							'refund_total' => 3,
						),
					),
				)
			);
			$this->assertInstanceOf( WC_Order_Refund::class, $prior_refund, 'The prior amount-only refund should be created.' );

			add_action( 'woocommerce_refund_created', $refund_created_callback, 10, 0 );
			$this->_setRole( 'administrator' );

			$response = $this->submit_refund_ajax_request(
				$order,
				$fixture['item_ids'],
				array( 'first' => 0 ),
				array( 'first' => 3 ),
				'3'
			);

			$this->assertFalse( $response['success'], 'A line total above the remaining net amount should return an AJAX error.' );
			$this->assertSame( 'Refund total cannot be greater than the remaining refundable amount for this line item.', $response['data']['error'] );
			$this->assertCount( 0, $gateway->refund_calls, 'The rejected follow-up refund should not reach the gateway.' );
			$this->assertSame( 0, $refund_created_calls, 'The rejected follow-up refund should not fire the refund-created action.' );

			$stored_order = wc_get_order( $order->get_id() );
			$this->assertInstanceOf( WC_Order::class, $stored_order, 'The order should still exist after rejection.' );
			$this->assertCount( 1, $stored_order->get_refunds(), 'Only the prior refund should remain.' );
			$this->assertSame( 0, $stored_order->get_qty_refunded_for_item( $first_item_id ), 'An amount-only refund should not consume units.' );
			$this->assertSame( 3.0, (float) $stored_order->get_total_refunded(), 'The prior refunded total should remain unchanged.' );
			$this->assertSame( 17.0, (float) $stored_order->get_remaining_refund_amount(), 'The rejected request should not reduce the remaining order amount.' );
		} finally {
			remove_action( 'woocommerce_refund_created', $refund_created_callback, 10 );
			$this->delete_refund_fixture( $order, $products );
			$payment_gateway_registry->payment_gateways = $original_payment_gateways;
			$_POST                                      = $original_post;
			wp_set_current_user( $original_user_id );
		}
	}

	/**
	 * @testdox The exact decimal remainder remains refundable after a prior product-line amount refund.
	 */
	public function test_refund_line_items_accepts_exact_decimal_remainder_after_prior_refund(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Preserves test fixture state.
		$original_post               = $_POST;
		$original_user_id            = get_current_user_id();
		$payment_gateway_registry    = WC_Payment_Gateways::instance();
		$original_payment_gateways   = $payment_gateway_registry->payment_gateways;
		$gateway                     = $this->create_recording_refund_gateway();
		$order                       = null;
		$products                    = array();
		$create_refund_calls         = 0;
		$refund_created_calls        = 0;
		$captured_create_refund_args = null;
		$create_refund_callback      = static function ( $refund, $args ) use ( &$create_refund_calls, &$captured_create_refund_args ): void {
			unset( $refund );
			++$create_refund_calls;
			$captured_create_refund_args = $args;
		};
		$refund_created_callback     = static function () use ( &$refund_created_calls ): void {
			++$refund_created_calls;
		};

		try {
			$payment_gateway_registry->payment_gateways[] = $gateway;
			$fixture                                      = $this->create_refundable_order( $gateway );
			$order                                        = $fixture['order'];
			$products                                     = $fixture['products'];
			$first_item_id                                = $fixture['item_ids']['first'];

			$prior_refund = wc_create_refund(
				array(
					'amount'     => 0.56,
					'order_id'   => $order->get_id(),
					'line_items' => array(
						$first_item_id => array(
							'qty'          => 0,
							'refund_total' => 0.56,
						),
					),
				)
			);
			$this->assertInstanceOf( WC_Order_Refund::class, $prior_refund, 'The prior USD 0.56 amount-only refund should be created.' );

			add_action( 'woocommerce_create_refund', $create_refund_callback, 10, 2 );
			add_action( 'woocommerce_refund_created', $refund_created_callback, 10, 0 );
			$this->_setRole( 'administrator' );

			$response = $this->submit_refund_ajax_request(
				$order,
				$fixture['item_ids'],
				array( 'first' => 0 ),
				array( 'first' => '4.44' ),
				'4.44'
			);

			$this->assertTrue( $response['success'], 'The exact USD 4.44 line remainder should succeed.' );
			$this->assertSame( 1, $create_refund_calls, 'The follow-up request should reach the pre-create hook exactly once.' );
			$this->assertIsArray( $captured_create_refund_args, 'The pre-create hook should receive the follow-up refund arguments.' );
			$this->assertSame( 0, $captured_create_refund_args['line_items'][ $first_item_id ]['qty'], 'The exact-remainder refund should remain amount-only.' );
			$this->assertSame( '4.44', $captured_create_refund_args['line_items'][ $first_item_id ]['refund_total'], 'The exact line remainder should retain its submitted allocation.' );
			$this->assertSame(
				array(
					array(
						'order_id' => $order->get_id(),
						'amount'   => 4.44,
						'reason'   => 'AJAX refund validation test',
					),
				),
				$gateway->refund_calls,
				'The exact-remainder request should reach the gateway exactly once.'
			);
			$this->assertSame( 1, $refund_created_calls, 'The follow-up request should fire the refund-created action exactly once.' );

			$stored_order = wc_get_order( $order->get_id() );
			$this->assertInstanceOf( WC_Order::class, $stored_order, 'The order should still exist after both refunds.' );
			$refunds = $stored_order->get_refunds();
			$this->assertCount( 2, $refunds, 'The prior and exact-remainder refunds should both be persisted.' );
			$follow_up_refunds = array_filter(
				$refunds,
				static function ( $refund ): bool {
					return 4.44 === (float) $refund->get_amount();
				}
			);
			$this->assertCount( 1, $follow_up_refunds, 'Exactly one persisted refund should represent the USD 4.44 follow-up.' );
			$follow_up_refund = reset( $follow_up_refunds );
			$follow_up_items  = $follow_up_refund->get_items( 'line_item' );
			$this->assertCount( 1, $follow_up_items, 'The follow-up refund should retain one product-line allocation.' );
			$follow_up_item = reset( $follow_up_items );
			$this->assertSame( $first_item_id, (int) $follow_up_item->get_meta( '_refunded_item_id' ), 'The follow-up line should reference the original USD 5 item.' );
			$this->assertSame( 0.0, (float) $follow_up_item->get_quantity(), 'The follow-up line should not consume product quantity.' );
			$this->assertSame( -4.44, (float) $follow_up_item->get_total(), 'The follow-up line should persist the exact USD 4.44 allocation.' );
			$this->assertSame( 0, $stored_order->get_qty_refunded_for_item( $first_item_id ), 'Both amount-only refunds should leave quantity available.' );
			$this->assertSame( 5.0, $stored_order->get_total_refunded_for_item( $first_item_id ), 'The product line should be fully allocated across the two refunds.' );
			$this->assertSame( 5.0, (float) $stored_order->get_total_refunded(), 'The stored aggregate refunded amount should equal USD 5.' );
			$this->assertSame( 15.0, (float) $stored_order->get_remaining_refund_amount(), 'The remaining order amount should equal USD 15.' );
		} finally {
			remove_action( 'woocommerce_create_refund', $create_refund_callback, 10 );
			remove_action( 'woocommerce_refund_created', $refund_created_callback, 10 );
			$this->delete_refund_fixture( $order, $products );
			$payment_gateway_registry->payment_gateways = $original_payment_gateways;
			$_POST                                      = $original_post;
			wp_set_current_user( $original_user_id );
		}
	}

	/**
	 * @testdox A valid amount-only refund with zero quantity succeeds without consuming units.
	 */
	public function test_refund_line_items_accepts_amount_only_refund_with_zero_quantity(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Preserves test fixture state.
		$original_post             = $_POST;
		$original_user_id          = get_current_user_id();
		$payment_gateway_registry  = WC_Payment_Gateways::instance();
		$original_payment_gateways = $payment_gateway_registry->payment_gateways;
		$gateway                   = $this->create_recording_refund_gateway();
		$order                     = null;
		$products                  = array();
		$refund_created_calls      = 0;
		$refund_created_callback   = static function () use ( &$refund_created_calls ): void {
			++$refund_created_calls;
		};

		try {
			$payment_gateway_registry->payment_gateways[] = $gateway;
			$fixture                                      = $this->create_refundable_order( $gateway );
			$order                                        = $fixture['order'];
			$products                                     = $fixture['products'];

			add_action( 'woocommerce_refund_created', $refund_created_callback, 10, 0 );
			$this->_setRole( 'administrator' );

			$response = $this->submit_refund_ajax_request(
				$order,
				$fixture['item_ids'],
				array( 'first' => '-0' ),
				array( 'first' => 3 ),
				'3'
			);

			$this->assertTrue( $response['success'], 'A valid amount-only refund should succeed.' );
			$this->assertSame(
				array(
					array(
						'order_id' => $order->get_id(),
						'amount'   => 3.0,
						'reason'   => 'AJAX refund validation test',
					),
				),
				$gateway->refund_calls,
				'A valid API refund should send the exact order, amount, and reason to the gateway.'
			);
			$this->assertSame( 1, $refund_created_calls, 'A valid refund should fire the refund-created action once.' );

			$stored_order = wc_get_order( $order->get_id() );
			$this->assertInstanceOf( WC_Order::class, $stored_order, 'The order should still exist after the refund.' );
			$this->assertCount( 1, $stored_order->get_refunds(), 'A valid amount-only request should create one refund.' );
			$this->assertSame( 0, $stored_order->get_qty_refunded_for_item( $fixture['item_ids']['first'] ), 'An amount-only refund should not consume units.' );
			$this->assertSame( 3.0, $stored_order->get_total_refunded_for_item( $fixture['item_ids']['first'] ), 'The amount-only refund should remain allocated to the requested line.' );
			$this->assertSame( 3.0, (float) $stored_order->get_total_refunded(), 'The amount-only refund should update the refunded total.' );
			$this->assertSame( 17.0, (float) $stored_order->get_remaining_refund_amount(), 'The unrefunded order amount should remain available.' );
		} finally {
			remove_action( 'woocommerce_refund_created', $refund_created_callback, 10 );
			$this->delete_refund_fixture( $order, $products );
			$payment_gateway_registry->payment_gateways = $original_payment_gateways;
			$_POST                                      = $original_post;
			wp_set_current_user( $original_user_id );
		}
	}

	/**
	 * @testdox Extra-precision product line totals survive refund creation while aggregate payment uses store precision.
	 */
	public function test_refund_line_items_preserves_extra_precision_line_total(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Preserves test fixture state.
		$original_post               = $_POST;
		$original_user_id            = get_current_user_id();
		$payment_gateway_registry    = WC_Payment_Gateways::instance();
		$original_payment_gateways   = $payment_gateway_registry->payment_gateways;
		$gateway                     = $this->create_recording_refund_gateway();
		$order                       = null;
		$products                    = array();
		$intval_filter_priority      = has_filter( 'woocommerce_stock_amount', 'intval' );
		$floatval_filter_priority    = has_filter( 'woocommerce_stock_amount', 'floatval' );
		$captured_create_refund_args = null;
		$create_refund_callback      = static function ( $refund, $args ) use ( &$captured_create_refund_args ): void {
			unset( $refund );
			$captured_create_refund_args = $args;
		};

		try {
			if ( false !== $intval_filter_priority ) {
				remove_filter( 'woocommerce_stock_amount', 'intval', $intval_filter_priority );
			}
			if ( false !== $floatval_filter_priority ) {
				remove_filter( 'woocommerce_stock_amount', 'floatval', $floatval_filter_priority );
			}
			add_filter( 'woocommerce_stock_amount', 'floatval' );

			$payment_gateway_registry->payment_gateways[] = $gateway;
			$fixture                                      = $this->create_refundable_order( $gateway );
			$order                                        = $fixture['order'];
			$products                                     = $fixture['products'];
			$first_item_id                                = $fixture['item_ids']['first'];

			add_action( 'woocommerce_create_refund', $create_refund_callback, 10, 2 );
			$this->_setRole( 'administrator' );

			$response = $this->submit_refund_ajax_request(
				$order,
				$fixture['item_ids'],
				array( 'first' => '0.5' ),
				array( 'first' => '3.4567' ),
				'3.4567'
			);

			$this->assertTrue( $response['success'], 'A valid extra-precision line refund should succeed.' );
			$this->assertIsArray( $captured_create_refund_args, 'The pre-create hook should receive the refund arguments.' );
			$this->assertSame( '3.4567', $captured_create_refund_args['line_items'][ $first_item_id ]['refund_total'], 'The line total should retain submitted precision in pre-create arguments.' );
			$this->assertSame(
				array(
					array(
						'order_id' => $order->get_id(),
						'amount'   => 3.46,
						'reason'   => 'AJAX refund validation test',
					),
				),
				$gateway->refund_calls,
				'The gateway amount should use store currency precision.'
			);

			$stored_order = wc_get_order( $order->get_id() );
			$this->assertInstanceOf( WC_Order::class, $stored_order, 'The order should still exist after the refund.' );
			$refunds = $stored_order->get_refunds();
			$this->assertCount( 1, $refunds, 'A valid extra-precision request should create one refund.' );
			$refund       = reset( $refunds );
			$refund_items = $refund->get_items( 'line_item' );
			$this->assertCount( 1, $refund_items, 'The refund should retain its product line allocation.' );
			$refund_item = reset( $refund_items );
			$this->assertSame( $first_item_id, (int) $refund_item->get_meta( '_refunded_item_id' ), 'The persisted refund line should reference the submitted order item.' );
			$this->assertSame( -0.5, (float) $refund_item->get_quantity(), 'Fractional refund quantities should remain supported.' );
			$this->assertSame( -3.4567, (float) $refund_item->get_total(), 'The persisted refund line should retain submitted precision.' );
			$this->assertSame( 3.46, (float) $refund->get_amount(), 'The persisted aggregate refund should use store currency precision.' );
		} finally {
			remove_action( 'woocommerce_create_refund', $create_refund_callback, 10 );
			remove_filter( 'woocommerce_stock_amount', 'floatval' );
			if ( false !== $floatval_filter_priority ) {
				add_filter( 'woocommerce_stock_amount', 'floatval', $floatval_filter_priority );
			}
			if ( false !== $intval_filter_priority ) {
				add_filter( 'woocommerce_stock_amount', 'intval', $intval_filter_priority );
			}
			$this->delete_refund_fixture( $order, $products );
			$payment_gateway_registry->payment_gateways = $original_payment_gateways;
			$_POST                                      = $original_post;
			wp_set_current_user( $original_user_id );
		}
	}

	/**
	 * @testdox Fractional non-product quantities retain legacy normalization through refund creation.
	 */
	public function test_refund_line_items_preserves_fractional_non_product_quantity(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Preserves test fixture state.
		$original_post               = $_POST;
		$original_user_id            = get_current_user_id();
		$payment_gateway_registry    = WC_Payment_Gateways::instance();
		$original_payment_gateways   = $payment_gateway_registry->payment_gateways;
		$gateway                     = $this->create_recording_refund_gateway();
		$order                       = null;
		$products                    = array();
		$captured_create_refund_args = null;
		$create_refund_callback      = static function ( $refund, $args ) use ( &$captured_create_refund_args ): void {
			unset( $refund );
			$captured_create_refund_args = $args;
		};

		try {
			$payment_gateway_registry->payment_gateways[] = $gateway;
			$fixture                                      = $this->create_refundable_order( $gateway );
			$order                                        = $fixture['order'];
			$products                                     = $fixture['products'];
			$fee_item                                     = new WC_Order_Item_Fee();
			$fee_item->set_name( 'Fractional refund fee' );
			$fee_item->set_tax_status( 'none' );
			$fee_item->set_amount( '2' );
			$fee_item->set_total( '2' );
			$this->assertNotFalse( $order->add_item( $fee_item ), 'The fee item should be accepted by the refundable order.' );
			$order->calculate_totals();
			$order->save();

			$fee_item_id = $fee_item->get_id();
			$this->assertGreaterThan( 0, $fee_item_id, 'The fee item should be added to the refundable order.' );
			$item_ids        = $fixture['item_ids'];
			$item_ids['fee'] = $fee_item_id;

			add_action( 'woocommerce_create_refund', $create_refund_callback, 10, 2 );
			$this->_setRole( 'administrator' );

			$response = $this->submit_refund_ajax_request(
				$order,
				$item_ids,
				array( 'fee' => '0.5' ),
				array( 'fee' => '1' ),
				'1'
			);

			$this->assertTrue( $response['success'], 'A valid fractional fee refund should succeed.' );
			$this->assertIsArray( $captured_create_refund_args, 'The pre-create hook should receive the refund arguments.' );
			$this->assertSame( '0.5', $captured_create_refund_args['line_items'][ $fee_item_id ]['qty'], 'Non-product quantities should retain the legacy untruncated value.' );
			$this->assertSame(
				array(
					array(
						'order_id' => $order->get_id(),
						'amount'   => 1.0,
						'reason'   => 'AJAX refund validation test',
					),
				),
				$gateway->refund_calls,
				'A valid fractional fee refund should retain the gateway contract.'
			);

			$stored_order = wc_get_order( $order->get_id() );
			$this->assertInstanceOf( WC_Order::class, $stored_order, 'The order should still exist after the refund.' );
			$this->assertCount( 1, $stored_order->get_refunds(), 'A valid fractional fee request should create one refund.' );
			$this->assertSame( 1.0, (float) $stored_order->get_total_refunded(), 'The fractional fee request should update the refunded total.' );
		} finally {
			remove_action( 'woocommerce_create_refund', $create_refund_callback, 10 );
			$this->delete_refund_fixture( $order, $products );
			$payment_gateway_registry->payment_gateways = $original_payment_gateways;
			$_POST                                      = $original_post;
			wp_set_current_user( $original_user_id );
		}
	}

	/**
	 * @testdox Refund requests reject product item IDs that do not belong to the target order.
	 * @dataProvider invalid_refund_item_id_data
	 *
	 * @param bool $use_foreign_order_item Whether to submit an item from another order.
	 */
	public function test_refund_line_items_rejects_foreign_and_unknown_item_ids( bool $use_foreign_order_item ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Preserves test fixture state.
		$original_post             = $_POST;
		$original_user_id          = get_current_user_id();
		$payment_gateway_registry  = WC_Payment_Gateways::instance();
		$original_payment_gateways = $payment_gateway_registry->payment_gateways;
		$gateway                   = $this->create_recording_refund_gateway();
		$order                     = null;
		$products                  = array();
		$foreign_order             = null;
		$foreign_products          = array();
		$refund_created_calls      = 0;
		$refund_created_callback   = static function () use ( &$refund_created_calls ): void {
			++$refund_created_calls;
		};

		try {
			$payment_gateway_registry->payment_gateways[] = $gateway;
			$fixture                                      = $this->create_refundable_order( $gateway );
			$order                                        = $fixture['order'];
			$products                                     = $fixture['products'];
			$submitted_item_id                            = 999999999;

			if ( $use_foreign_order_item ) {
				$foreign_fixture   = $this->create_refundable_order( $gateway );
				$foreign_order     = $foreign_fixture['order'];
				$foreign_products  = $foreign_fixture['products'];
				$submitted_item_id = $foreign_fixture['item_ids']['first'];
			}

			add_action( 'woocommerce_refund_created', $refund_created_callback, 10, 0 );
			$this->_setRole( 'administrator' );

			$response = $this->submit_refund_ajax_request(
				$order,
				array(
					'first'  => $submitted_item_id,
					'second' => $fixture['item_ids']['second'],
				),
				array( 'first' => 0 ),
				array( 'first' => 3 ),
				'3'
			);

			$this->assert_fresh_refund_rejected(
				$response,
				'Refund line item must belong to this order.',
				$gateway,
				$refund_created_calls,
				$order
			);
		} finally {
			remove_action( 'woocommerce_refund_created', $refund_created_callback, 10 );
			$this->delete_refund_fixture( $foreign_order, $foreign_products );
			$this->delete_refund_fixture( $order, $products );
			$payment_gateway_registry->payment_gateways = $original_payment_gateways;
			$_POST                                      = $original_post;
			wp_set_current_user( $original_user_id );
		}
	}

	/**
	 * Data provider for refund item IDs outside the target order.
	 *
	 * @return array<string,array{bool}>
	 */
	public static function invalid_refund_item_id_data(): array {
		return array(
			'foreign order item' => array( true ),
			'nonexistent item'   => array( false ),
		);
	}

	/**
	 * @testdox Refund requests reject structured and non-finite line values before normalization.
	 * @dataProvider invalid_refund_line_value_data
	 *
	 * @param string $field          Payload field containing the invalid value.
	 * @param mixed  $invalid_value  Invalid decoded JSON value.
	 * @param string $expected_error Expected semantic error.
	 */
	public function test_refund_line_items_rejects_invalid_numeric_values( string $field, $invalid_value, string $expected_error ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Preserves test fixture state.
		$original_post             = $_POST;
		$original_user_id          = get_current_user_id();
		$payment_gateway_registry  = WC_Payment_Gateways::instance();
		$original_payment_gateways = $payment_gateway_registry->payment_gateways;
		$gateway                   = $this->create_recording_refund_gateway();
		$order                     = null;
		$products                  = array();
		$refund_created_calls      = 0;
		$refund_created_callback   = static function () use ( &$refund_created_calls ): void {
			++$refund_created_calls;
		};

		try {
			$payment_gateway_registry->payment_gateways[] = $gateway;
			$fixture                                      = $this->create_refundable_order( $gateway );
			$order                                        = $fixture['order'];
			$products                                     = $fixture['products'];
			$quantities                                   = array( 'first' => 0 );
			$line_totals                                  = array( 'first' => 1 );

			if ( 'quantity' === $field ) {
				$quantities['first'] = $invalid_value;
			} else {
				$line_totals['first'] = $invalid_value;
			}

			add_action( 'woocommerce_refund_created', $refund_created_callback, 10, 0 );
			$this->_setRole( 'administrator' );

			$response = $this->submit_refund_ajax_request( $order, $fixture['item_ids'], $quantities, $line_totals, '1' );

			$this->assert_fresh_refund_rejected( $response, $expected_error, $gateway, $refund_created_calls, $order );
		} finally {
			remove_action( 'woocommerce_refund_created', $refund_created_callback, 10 );
			$this->delete_refund_fixture( $order, $products );
			$payment_gateway_registry->payment_gateways = $original_payment_gateways;
			$_POST                                      = $original_post;
			wp_set_current_user( $original_user_id );
		}
	}

	/**
	 * Data provider for invalid decoded refund values.
	 *
	 * @return array<string,array{string,mixed,string}>
	 */
	public static function invalid_refund_line_value_data(): array {
		return array(
			'quantity array'              => array( 'quantity', array( 1 ), 'Line item quantity must be a finite number.' ),
			'total object'                => array( 'total', (object) array( 'value' => 1 ), 'Refund total must be a finite number.' ),
			'quantity boolean'            => array( 'quantity', true, 'Line item quantity must be a finite number.' ),
			'total null'                  => array( 'total', null, 'Refund total must be a finite number.' ),
			'quantity non-numeric string' => array( 'quantity', 'not-a-number', 'Line item quantity must be a finite number.' ),
			'total NaN spelling'          => array( 'total', 'NaN', 'Refund total must be a finite number.' ),
			'quantity INF spelling'       => array( 'quantity', 'INF', 'Line item quantity must be a finite number.' ),
			'total exponent overflow'     => array( 'total', '1e309', 'Refund total must be a finite number.' ),
		);
	}

	/**
	 * @testdox Should clear variation sale dates when bulk schedule dates are blank.
	 * @group ajax
	 */
	public function test_bulk_sale_schedule_clears_blank_dates(): void {
		$variation = new WC_Product_Variation();
		$variation->set_date_on_sale_from( '2026-06-01 00:00:00' );
		$variation->set_date_on_sale_to( '2026-06-30 23:59:59' );
		$variation->save();

		$method = new ReflectionMethod( WC_AJAX::class, 'variation_bulk_action_variable_sale_schedule' );
		$method->setAccessible( true );

		$method->invokeArgs(
			null,
			array(
				array( $variation->get_id() ),
				array(
					'date_from' => '',
					'date_to'   => '',
				),
			)
		);

		$variation = wc_get_product( $variation->get_id() );

		$this->assertNull( $variation->get_date_on_sale_from( 'edit' ), 'The sale start date should be cleared when the bulk action start date is blank.' );
		$this->assertNull( $variation->get_date_on_sale_to( 'edit' ), 'The sale end date should be cleared when the bulk action end date is blank.' );

		$variation->delete( true );
	}

	/**
	 * @testdox Adding a custom field renders a Delete button with a valid delete nonce.
	 */
	public function test_order_add_meta_delete_button_uses_name_value_nonce(): void {
		$this->_setRole( 'administrator' );
		$order = WC_Helper_Order::create_order();

		$_POST['_ajax_nonce-add-meta'] = wp_create_nonce( 'add-meta' );
		$_POST['order_id']             = $order->get_id();
		$_POST['metakeyinput']         = 'my_test_key';
		$_POST['metavalue']            = 'my_test_value';

		$output_buffering_level = ob_get_level();

		try {
			// Note that _handleAjax makes use of output buffering, which the die
			// handler usually cleans up; the finally block below closes only any
			// buffer it leaves dangling so the buffer level stays balanced.
			$this->_handleAjax( 'woocommerce_order_add_meta' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} finally {
			while ( ob_get_level() > $output_buffering_level ) {
				ob_end_clean();
			}
		}

		$this->assertStringContainsString(
			'::_ajax_nonce=',
			(string) $this->_last_response,
			'Delete button should use the _ajax_nonce= token.'
		);
	}

	/**
	 * Data provider for test_product_ordering.
	 *
	 * Columns: sorting_idx, previd_idx (-1 = none), nextid_idx (-1 = none), expected menu_orders [P1..P5].
	 */
	public function product_ordering_provider(): array {
		return array(
			'last to first'             => array( 4, -1, 0, array( 2, 3, 4, 5, 1 ) ),
			'first to last'             => array( 0, 4, -1, array( 5, 1, 2, 3, 4 ) ),
			'middle one position left'  => array( 2, 0, 1, array( 1, 3, 2, 4, 5 ) ),
			'middle one position right' => array( 2, 3, 4, array( 1, 2, 4, 3, 5 ) ),
			'middle to first'           => array( 2, -1, 0, array( 2, 3, 1, 4, 5 ) ),
			'middle to last'            => array( 2, 4, -1, array( 1, 2, 5, 3, 4 ) ),
			'drop in place'             => array( 2, 1, 3, array( 1, 2, 3, 4, 5 ) ),
		);
	}

	/**
	 * @testdox 'product_ordering' (legacy algorithm) moves a product to the correct position and shifts the affected range.
	 * @dataProvider product_ordering_provider
	 *
	 * @param int   $sorting_idx     Index (0-based) of the product being dragged.
	 * @param int   $previd_idx      Index of the product immediately before the drop target, or -1 if dropped at the top.
	 * @param int   $nextid_idx      Index of the product immediately after the drop target, or -1 if dropped at the bottom.
	 * @param int[] $expected_orders Expected menu_order values indexed by original product position [P1..P5].
	 */
	public function test_product_ordering_using_legacy_algorithm( int $sorting_idx, int $previd_idx, int $nextid_idx, array $expected_orders ): void {
		global $wpdb;

		$this->_setRole( 'administrator' );
		$this->setExpectedDeprecated( 'woocommerce_after_single_product_ordering' );

		// Attach a listener to force the legacy branching path.
		$legacy_hook = function () {};
		add_action( 'woocommerce_after_single_product_ordering', $legacy_hook );

		$products = array();
		for ( $i = 1; $i <= 5; ++$i ) {
			$product                 = WC_Helper_Product::create_simple_product();
			$product_id              = $product->get_id();
			$products[ $product_id ] = $product;
			wp_update_post(
				array(
					'ID'         => $product_id,
					'menu_order' => $i,
				)
			);
		}
		$product_ids = array_keys( $products );

		$_POST['security'] = wp_create_nonce( 'product-ordering' );
		$_POST['id']       = $product_ids[ $sorting_idx ];
		$_POST['previd']   = $previd_idx >= 0 ? $product_ids[ $previd_idx ] : 0;
		$_POST['nextid']   = $nextid_idx >= 0 ? $product_ids[ $nextid_idx ] : 0;

		$this->do_ajax( 'woocommerce_product_ordering' );

		unset( $_POST['security'], $_POST['id'], $_POST['previd'], $_POST['nextid'] );
		remove_action( 'woocommerce_after_single_product_ordering', $legacy_hook );

		foreach ( $product_ids as $idx => $product_id ) {
			$actual = (int) $wpdb->get_var( $wpdb->prepare( "SELECT menu_order FROM {$wpdb->posts} WHERE ID = %d", $product_id ) );
			$this->assertSame( $expected_orders[ $idx ], $actual, "Product at index {$idx} has wrong menu_order." );
			$products[ $product_id ]->delete( true );
		}
	}

	/**
	 * @testdox 'product_ordering' (range algorithm) moves a product to the correct position and shifts the affected range.
	 * @dataProvider product_ordering_provider
	 *
	 * @param int   $sorting_idx     Index (0-based) of the product being dragged.
	 * @param int   $previd_idx      Index of the product immediately before the drop target, or -1 if dropped at the top.
	 * @param int   $nextid_idx      Index of the product immediately after the drop target, or -1 if dropped at the bottom.
	 * @param int[] $expected_orders Expected menu_order values indexed by original product position [P1..P5].
	 */
	public function test_product_ordering_using_range_algorithm( int $sorting_idx, int $previd_idx, int $nextid_idx, array $expected_orders ): void {
		global $wpdb;

		$this->_setRole( 'administrator' );

		$products = array();
		for ( $i = 1; $i <= 5; ++$i ) {
			$product                 = WC_Helper_Product::create_simple_product();
			$product_id              = $product->get_id();
			$products[ $product_id ] = $product;
			wp_update_post(
				array(
					'ID'         => $product_id,
					'menu_order' => $i,
				)
			);
		}
		$product_ids = array_keys( $products );

		$_POST['security'] = wp_create_nonce( 'product-ordering' );
		$_POST['id']       = $product_ids[ $sorting_idx ];
		$_POST['previd']   = $previd_idx >= 0 ? $product_ids[ $previd_idx ] : 0;
		$_POST['nextid']   = $nextid_idx >= 0 ? $product_ids[ $nextid_idx ] : 0;

		$this->do_ajax( 'woocommerce_product_ordering' );

		unset( $_POST['security'], $_POST['id'], $_POST['previd'], $_POST['nextid'] );
		foreach ( $product_ids as $idx => $product_id ) {
			$actual = (int) $wpdb->get_var( $wpdb->prepare( "SELECT menu_order FROM {$wpdb->posts} WHERE ID = %d", $product_id ) );
			$this->assertSame( $expected_orders[ $idx ], $actual, "Product at index {$idx} has wrong menu_order." );
			$products[ $product_id ]->delete( true );
		}
	}

	/**
	 * @testdox 'product_ordering' fires 'woocommerce_after_product_ordering' with the moved product ID and full positions map.
	 */
	public function test_product_ordering_fires_after_product_ordering_action(): void {
		$this->_setRole( 'administrator' );
		$this->setExpectedDeprecated( 'woocommerce_after_product_ordering' );

		$products = array();
		for ( $i = 1; $i <= 2; ++$i ) {
			$product                 = WC_Helper_Product::create_simple_product();
			$product_id              = $product->get_id();
			$products[ $product_id ] = $product;
			wp_update_post(
				array(
					'ID'         => $product_id,
					'menu_order' => $i,
				)
			);
		}
		$product_ids = array_keys( $products );

		$hook_fired = false;
		$captured   = array();
		$hook       = function ( $sorting_id, $all_positions ) use ( &$hook_fired, &$captured ) {
			$hook_fired = true;
			$captured   = array(
				'sorting_id'    => $sorting_id,
				'all_positions' => $all_positions,
			);
		};
		add_action( 'woocommerce_after_product_ordering', $hook, 10, 2 );

		// Move the last one to the front.
		$_POST['security'] = wp_create_nonce( 'product-ordering' );
		$_POST['id']       = $product_ids[1];
		$_POST['previd']   = 0;
		$_POST['nextid']   = $product_ids[0];

		$this->do_ajax( 'woocommerce_product_ordering' );

		unset( $_POST['security'], $_POST['id'], $_POST['previd'], $_POST['nextid'] );
		remove_action( 'woocommerce_after_product_ordering', $hook, 10 );

		$this->assertTrue( $hook_fired, 'woocommerce_after_product_ordering was not fired.' );
		$this->assertSame( $product_ids[1], $captured['sorting_id'] );
		$this->assertSame(
			array(
				$product_ids[0] => 2,
				$product_ids[1] => 1,
			),
			$captured['all_positions']
		);

		foreach ( $product_ids as $product_id ) {
			$products[ $product_id ]->delete( true );
		}
	}

	/**
	 * @testdox 'product_ordering' (fast path) fires the process_moved and process_reindexed hooks with correct payloads.
	 */
	public function test_product_ordering_fires_fast_path_hooks(): void {
		$this->_setRole( 'administrator' );

		$setup    = array(
			'Alpha' => 1,
			'Beta'  => 2,
			'Gamma' => 3,
			'Delta' => 0,
			'Echo'  => 0,
		);
		$ids      = array();
		$products = array();
		foreach ( $setup as $name => $menu_order ) {
			$product = new \WC_Product_Simple();
			$product->set_name( $name );
			$product->set_menu_order( $menu_order );
			$product->save();
			$ids[ $name ]                   = $product->get_id();
			$products[ $product->get_id() ] = $product;
		}

		$moved_captured     = array();
		$reindexed_captured = array();
		$moved_hook         = function ( $sorting_id, $moved ) use ( &$moved_captured ) {
			$moved_captured = array(
				'sorting_id' => $sorting_id,
				'moved'      => $moved,
			);
		};
		$reindexed_hook     = function ( $sorting_id, $reindexed ) use ( &$reindexed_captured ) {
			$reindexed_captured = array(
				'sorting_id' => $sorting_id,
				'reindexed'  => $reindexed,
			);
		};
		add_action( 'woocommerce_product_ordering_process_moved_products', $moved_hook, 10, 2 );
		add_action( 'woocommerce_product_ordering_process_reindexed_products', $reindexed_hook, 10, 2 );

		$_POST['security'] = wp_create_nonce( 'product-ordering' );
		$_POST['id']       = $ids['Gamma'];
		$_POST['previd']   = $ids['Delta'];
		$_POST['nextid']   = $ids['Echo'];

		$this->do_ajax( 'woocommerce_product_ordering' );

		unset( $_POST['security'], $_POST['id'], $_POST['previd'], $_POST['nextid'] );
		remove_action( 'woocommerce_product_ordering_process_moved_products', $moved_hook, 10 );
		remove_action( 'woocommerce_product_ordering_process_reindexed_products', $reindexed_hook, 10 );

		$this->assertSame(
			array(
				'sorting_id' => $ids['Gamma'],
				'reindexed'  => array( $ids['Delta'] => 1 ),
			),
			$reindexed_captured
		);
		$this->assertSame(
			array(
				'sorting_id' => $ids['Gamma'],
				'moved'      => array(
					$ids['Gamma'] => 2,
					$ids['Echo']  => 3,
					$ids['Alpha'] => 4,
					$ids['Beta']  => 5,
				),
			),
			$moved_captured
		);

		array_walk( $products, static fn( $p ) => $p->delete( true ) );
	}

	/**
	 * @testdox Refunding a 0% taxed line item via the AJAX handler preserves the 0-rate tax line on the refund order.
	 */
	public function test_refund_line_items_preserves_zero_rate_tax(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );

		$rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '',
				'tax_rate_state'    => '',
				'tax_rate'          => '0.0000',
				'tax_rate_name'     => 'Zero Rate',
				'tax_rate_priority' => '1',
				'tax_rate_compound' => '0',
				'tax_rate_shipping' => '1',
				'tax_rate_order'    => '1',
				'tax_rate_class'    => '',
			)
		);

		$product = WC_Helper_Product::create_simple_product();

		$order = new WC_Order();
		$order->add_product( $product, 1 );
		$order->calculate_totals( true );
		$order->save();

		$item_id = array_keys( $order->get_items( 'line_item' ) )[0];

		$this->_setRole( 'administrator' );

		// The exact payload shape the admin refund form serializes: the tax
		// amount arrives as a numeric 0 (accounting.unformat of an empty field).
		$_POST['security']             = wp_create_nonce( 'order-item' );
		$_POST['order_id']             = $order->get_id();
		$_POST['refund_amount']        = $order->get_total();
		$_POST['refunded_amount']      = '0';
		$_POST['refund_reason']        = '';
		$_POST['line_item_qtys']       = wp_json_encode( array( $item_id => 1 ) );
		$_POST['line_item_totals']     = wp_json_encode( array( $item_id => $order->get_total() ) );
		$_POST['line_item_tax_totals'] = wp_json_encode( array( $item_id => array( $rate_id => 0 ) ) );
		$_POST['api_refund']           = 'false';

		$response = $this->do_ajax( 'woocommerce_refund_line_items' );

		$this->assertTrue( $response['success'] ?? false, 'The AJAX refund request should succeed.' );

		$refunds = wc_get_order( $order->get_id() )->get_refunds();
		$this->assertCount( 1, $refunds, 'One refund should be created for the order.' );

		$refund_tax_items = $refunds[0]->get_items( 'tax' );
		$this->assertCount( 1, $refund_tax_items, 'The 0% tax line must be carried over to the refund order.' );
		$this->assertEquals(
			$rate_id,
			array_values( $refund_tax_items )[0]->get_rate_id(),
			'The preserved tax line must reference the 0% rate.'
		);

		unset( $_POST['security'], $_POST['order_id'], $_POST['refund_amount'], $_POST['refunded_amount'], $_POST['refund_reason'], $_POST['line_item_qtys'], $_POST['line_item_totals'], $_POST['line_item_tax_totals'], $_POST['api_refund'] );
		WC_Tax::_delete_tax_rate( $rate_id );
		update_option( 'woocommerce_calc_taxes', 'no' );
	}

	/**
	 * @testdox An amount-only AJAX refund on a multi-item order creates no line items and keeps downloads of unrefunded products.
	 */
	public function test_refund_line_items_amount_only_skips_untouched_items(): void {
		$product = WC_Helper_Product::create_simple_product();

		$downloadable_product = WC_Helper_Product::create_simple_product();
		$downloadable_product->set_downloadable( true );
		$downloadable_product->save();

		$order = new WC_Order();
		$order->add_product( $product, 1 );
		$order->add_product( $downloadable_product, 1 );
		$order->calculate_totals();
		$order->save();

		$download = new WC_Customer_Download();
		$download->set_user_id( 1 );
		$download->set_order_id( $order->get_id() );
		$download->set_product_id( $downloadable_product->get_id() );
		$download->set_download_id( wp_generate_uuid4() );
		$download->save();

		$item_ids = array_keys( $order->get_items( 'line_item' ) );

		$this->_setRole( 'administrator' );

		// An amount-only refund: the form posts no qtys, but a 0 total and a 0
		// tax amount for every row in the order (those inputs are not gated).
		$totals     = array();
		$tax_totals = array();
		foreach ( $item_ids as $item_id ) {
			$totals[ $item_id ]     = 0;
			$tax_totals[ $item_id ] = array( 1 => 0 );
		}

		$_POST['security']             = wp_create_nonce( 'order-item' );
		$_POST['order_id']             = $order->get_id();
		$_POST['refund_amount']        = '5';
		$_POST['refunded_amount']      = '0';
		$_POST['refund_reason']        = '';
		$_POST['line_item_qtys']       = wp_json_encode( array() );
		$_POST['line_item_totals']     = wp_json_encode( $totals );
		$_POST['line_item_tax_totals'] = wp_json_encode( $tax_totals );
		$_POST['api_refund']           = 'false';

		$response = $this->do_ajax( 'woocommerce_refund_line_items' );

		$this->assertTrue( $response['success'] ?? false, 'The AJAX refund request should succeed.' );

		$refunds = wc_get_order( $order->get_id() )->get_refunds();
		$this->assertCount( 1, $refunds, 'One refund should be created for the order.' );
		$this->assertCount( 0, $refunds[0]->get_items( 'line_item' ), 'Untouched items must not become refund line items.' );
		$this->assertCount( 0, $refunds[0]->get_items( 'tax' ), 'Untouched items must not produce refund tax items.' );

		$download_data_store = WC_Data_Store::load( 'customer-download' );
		$remaining_downloads = $download_data_store->get_downloads(
			array(
				'order_id'   => $order->get_id(),
				'product_id' => $downloadable_product->get_id(),
			)
		);
		$this->assertCount( 1, $remaining_downloads, 'Download permissions for a product that was not refunded must be kept.' );

		unset( $_POST['security'], $_POST['order_id'], $_POST['refund_amount'], $_POST['refunded_amount'], $_POST['refund_reason'], $_POST['line_item_qtys'], $_POST['line_item_totals'], $_POST['line_item_tax_totals'], $_POST['api_refund'] );
	}

	/**
	 * The ?wc-ajax=get_variation endpoint renders the matched variation's description through
	 * wc_format_content(), which fires the woocommerce_short_description filter. Eager block registration is
	 * skipped on AJAX requests, so Bootstrap registers WooCommerce block types on demand there — otherwise a
	 * block in a variation description would render empty. See Bootstrap::maybe_register_blocks_from_content.
	 *
	 * @testdox The get_variation AJAX endpoint registers WooCommerce block types on demand for a variation description block.
	 */
	public function test_get_variation_registers_block_types_on_demand_for_description(): void {
		$registry = WP_Block_Type_Registry::get_instance();

		// Snapshot and unregister WooCommerce blocks so this test mirrors a request whose eager registration
		// was skipped; on-demand registration should then re-register them when the description is rendered.
		$snapshot = array();
		foreach ( $registry->get_all_registered() as $name => $block_type ) {
			if ( 0 === strpos( $name, 'woocommerce/' ) ) {
				$snapshot[ $name ] = $block_type;
				$registry->unregister( $name );
			}
		}

		// The on-demand registration asks the shared BlockTypesController whether register_blocks() already ran
		// this request; the test bootstrap ran it once for the whole PHPUnit process, so clear the flag too.
		$this->set_register_blocks_has_run_flag( false );

		// A foundational block register_blocks() always registers (not gated behind a theme/feature flag).
		$sample = 'woocommerce/product-price';
		$this->assertNotEmpty( $snapshot, 'The test bootstrap should have registered WooCommerce blocks to snapshot.' );

		$posted_keys = array();

		try {
			$product   = WC_Helper_Product::create_variation_product();
			$children  = $product->get_children();
			$variation = wc_get_product( $children[0] );
			$variation->set_description( '<!-- wp:woocommerce/product-price /-->' );
			$variation->save();

			$_POST['product_id'] = $product->get_id();
			$posted_keys[]       = 'product_id';
			foreach ( $variation->get_attributes() as $attribute_name => $attribute_value ) {
				$key           = 'attribute_' . $attribute_name;
				$_POST[ $key ] = $attribute_value;
				$posted_keys[] = $key;
			}

			$this->assertFalse( $registry->is_registered( $sample ), 'Blocks should start unregistered for this test.' );

			$response = $this->do_ajax( 'woocommerce_get_variation' );

			$this->assertIsArray( $response, 'The get_variation endpoint should return the matched variation.' );
			$this->assertSame(
				$variation->get_id(),
				$response['variation_id'],
				'The endpoint should match the variation carrying the block description.'
			);
			$this->assertTrue(
				$registry->is_registered( $sample ),
				'Hitting ?wc-ajax=get_variation should register block types on demand so a variation description block renders.'
			);
		} finally {
			foreach ( $posted_keys as $key ) {
				unset( $_POST[ $key ] );
			}

			// Delete the created posts so they do not leak into later tests. Guarded because
			// create_variation_product() could throw before either is assigned.
			if ( isset( $variation ) ) {
				$variation->delete( true );
			}
			if ( isset( $product ) ) {
				$product->delete( true );
			}

			foreach ( array_keys( $registry->get_all_registered() ) as $name ) {
				if ( 0 === strpos( (string) $name, 'woocommerce/' ) ) {
					$registry->unregister( $name );
				}
			}
			foreach ( $snapshot as $block_type ) {
				$registry->register( $block_type );
			}
			$this->set_register_blocks_has_run_flag( true );
		}
	}

	/**
	 * Create a refundable order with two product lines.
	 *
	 * @param WC_Payment_Gateway $gateway       Payment gateway assigned to the order.
	 * @param int                $first_quantity Quantity of the USD 5 product.
	 * @return array{order:WC_Order,products:array{WC_Product,WC_Product},item_ids:array{first:int,second:int}}
	 */
	private function create_refundable_order( WC_Payment_Gateway $gateway, int $first_quantity = 1 ): array {
		$first_product  = WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Five dollar refund product',
				'regular_price' => '5',
				'price'         => '5',
				'tax_status'    => 'none',
			)
		);
		$second_product = WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Fifteen dollar refund product',
				'regular_price' => '15',
				'price'         => '15',
				'tax_status'    => 'none',
			)
		);

		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order, 'The refundable order should be created.' );
		$order->set_currency( 'USD' );
		$order->save();

		$first_item_id  = $order->add_product( $first_product, $first_quantity );
		$second_item_id = $order->add_product( $second_product, 1 );
		$order->calculate_totals();
		$order->set_status( OrderStatus::COMPLETED );
		$order->set_payment_method( $gateway->id );
		$order->save();

		return array(
			'order'    => $order,
			'products' => array( $first_product, $second_product ),
			'item_ids' => array(
				'first'  => $first_item_id,
				'second' => $second_item_id,
			),
		);
	}

	/**
	 * Create a refundable gateway that records provider calls.
	 *
	 * @return WC_Payment_Gateway&object{refund_calls:array<int,array{order_id:int,amount:float|null,reason:string}>}
	 */
	private function create_recording_refund_gateway(): WC_Payment_Gateway {
		return new class() extends WC_Payment_Gateway {
			/**
			 * Recorded refund calls.
			 *
			 * @var array<int,array{order_id:int,amount:float|null,reason:string}>
			 */
			public array $refund_calls = array();

			/**
			 * Constructor.
			 */
			public function __construct() {
				$this->id           = 'ajax_recording_refund_gateway';
				$this->method_title = 'AJAX recording refund gateway';
				$this->title        = 'AJAX recording refund gateway';
				$this->supports     = array( 'refunds' );
			}

			/**
			 * Record a gateway refund call.
			 *
			 * @param int        $order_id Order ID.
			 * @param float|null $amount   Refund amount.
			 * @param string     $reason   Refund reason.
			 * @return bool
			 */
			public function process_refund( $order_id, $amount = null, $reason = '' ) {
				$this->refund_calls[] = array(
					'order_id' => (int) $order_id,
					'amount'   => null === $amount ? null : (float) $amount,
					'reason'   => (string) $reason,
				);

				return true;
			}
		};
	}

	/**
	 * Submit a refund request through the authenticated classic AJAX boundary.
	 *
	 * @param WC_Order                       $order         Order to refund.
	 * @param array{first:int,second:int}    $item_ids      Actual order-item IDs keyed by symbolic line name.
	 * @param array<string,int|float|string> $quantities    Quantities keyed by symbolic line name.
	 * @param array<string,int|float|string> $line_totals   Net totals keyed by symbolic line name.
	 * @param string                         $refund_amount Aggregate refund amount.
	 * @return array<string,mixed>
	 */
	private function submit_refund_ajax_request( WC_Order $order, array $item_ids, array $quantities, array $line_totals, string $refund_amount ): array {
		$_POST = array(
			'security'               => wp_create_nonce( 'order-item' ),
			'order_id'               => $order->get_id(),
			'refund_amount'          => $refund_amount,
			'refunded_amount'        => wc_format_decimal( $order->get_total_refunded(), wc_get_price_decimals() ),
			'refund_reason'          => 'AJAX refund validation test',
			'line_item_qtys'         => wp_json_encode( $this->resolve_refund_line_ids( $quantities, $item_ids ) ),
			'line_item_totals'       => wp_json_encode( $this->resolve_refund_line_ids( $line_totals, $item_ids ) ),
			'line_item_tax_totals'   => wp_json_encode( array() ),
			'api_refund'             => 'true',
			'restock_refunded_items' => 'false',
		);

		$response = $this->do_ajax( 'woocommerce_refund_line_items' );
		$this->assertIsArray( $response, 'The AJAX handler should return a JSON object.' );

		return $response;
	}

	/**
	 * Replace symbolic refund line names with fixture item IDs.
	 *
	 * @param array<string,int|float|string> $values   Values keyed by symbolic line name.
	 * @param array{first:int,second:int}    $item_ids Actual order-item IDs keyed by symbolic line name.
	 * @return array<int,int|float|string>
	 */
	private function resolve_refund_line_ids( array $values, array $item_ids ): array {
		$resolved = array();

		foreach ( $values as $line_name => $value ) {
			$resolved[ $item_ids[ $line_name ] ] = $value;
		}

		return $resolved;
	}

	/**
	 * Delete every order, refund, and product created by a refund fixture.
	 *
	 * @param WC_Order|null     $order    Fixture order.
	 * @param array<WC_Product> $products Fixture products.
	 */
	private function delete_refund_fixture( ?WC_Order $order, array $products ): void {
		if ( $order instanceof WC_Order ) {
			$stored_order = wc_get_order( $order->get_id() );

			if ( $stored_order instanceof WC_Order ) {
				foreach ( $stored_order->get_refunds() as $refund ) {
					$refund->delete( true );
				}
				$stored_order->delete( true );
			}
		}

		foreach ( $products as $product ) {
			$product->delete( true );
		}
	}

	/**
	 * Set the static registration flag on BlockTypesController.
	 *
	 * The flag records whether register_blocks() ran in the current request, and Bootstrap's on-demand block
	 * registration consults it. Tests that simulate a request whose eager registration was skipped must clear
	 * it alongside unregistering the block types, and restore it afterwards. It is static, so this sets it on
	 * the class, not on any one container instance.
	 *
	 * @param bool $has_run The flag value to set.
	 */
	private function set_register_blocks_has_run_flag( bool $has_run ): void {
		$property = new \ReflectionProperty( \Automattic\WooCommerce\Blocks\BlockTypesController::class, 'register_blocks_has_run' );
		$property->setAccessible( true );
		$property->setValue( null, $has_run );
	}

	/**
	 * @testdox add_order_item rejects a negative quantity with a JSON error and adds nothing to the order.
	 */
	public function test_add_order_item_rejects_negative_quantity() {
		$this->_setRole( 'administrator' );

		$product            = \WC_Helper_Product::create_simple_product();
		$order              = \WC_Helper_Order::create_order();
		$initial_item_count = count( $order->get_items() );

		$_POST['order_id'] = $order->get_id();
		$_POST['security'] = wp_create_nonce( 'order-item' );
		$_POST['data']     = array(
			array(
				'id'  => (string) $product->get_id(),
				'qty' => '-2',
			),
		);

		$response = $this->do_ajax( 'woocommerce_add_order_item' );

		$this->assertFalse( $response['success'] );

		$order = wc_get_order( $order->get_id() );
		$this->assertCount( $initial_item_count, $order->get_items() );
	}

	/**
	 * @testdox add_order_item still accepts a positive quantity.
	 */
	public function test_add_order_item_accepts_positive_quantity() {
		$this->_setRole( 'administrator' );

		$product            = \WC_Helper_Product::create_simple_product();
		$order              = \WC_Helper_Order::create_order();
		$initial_item_count = count( $order->get_items() );

		$_POST['order_id'] = $order->get_id();
		$_POST['security'] = wp_create_nonce( 'order-item' );
		$_POST['data']     = array(
			array(
				'id'  => (string) $product->get_id(),
				'qty' => '2',
			),
		);

		$response = $this->do_ajax( 'woocommerce_add_order_item' );

		$this->assertTrue( $response['success'] );

		$order = wc_get_order( $order->get_id() );
		$this->assertCount( $initial_item_count + 1, $order->get_items() );
	}

	/**
	 * @testdox save_order_items rejects a negative quantity and leaves the stored item untouched.
	 */
	public function test_save_order_items_rejects_negative_quantity() {
		$this->_setRole( 'administrator' );

		$order        = \WC_Helper_Order::create_order();
		$items        = array_values( $order->get_items() );
		$item         = $items[0];
		$item_id      = $item->get_id();
		$original_qty = $item->get_quantity();

		$_POST['order_id'] = $order->get_id();
		$_POST['security'] = wp_create_nonce( 'order-item' );
		$_POST['items']    = http_build_query(
			array(
				'order_item_id'  => array( $item_id ),
				'order_item_qty' => array( $item_id => '-1' ),
				'line_total'     => array( $item_id => '-10' ),
				'line_subtotal'  => array( $item_id => '-10' ),
			)
		);

		$response = $this->do_ajax( 'woocommerce_save_order_items' );

		$this->assertFalse( $response['success'] );

		$fresh_item = \WC_Order_Factory::get_order_item( $item_id );
		$this->assertEquals( $original_qty, $fresh_item->get_quantity() );
	}

	/**
	 * @testdox save_order_items accepts a valid positive quantity change.
	 */
	public function test_save_order_items_accepts_positive_quantity() {
		$this->_setRole( 'administrator' );

		$order   = \WC_Helper_Order::create_order();
		$items   = array_values( $order->get_items() );
		$item    = $items[0];
		$item_id = $item->get_id();

		$_POST['order_id'] = $order->get_id();
		$_POST['security'] = wp_create_nonce( 'order-item' );
		$_POST['items']    = http_build_query(
			array(
				'order_item_id'  => array( $item_id ),
				'order_item_qty' => array( $item_id => '3' ),
				'line_total'     => array( $item_id => '30' ),
				'line_subtotal'  => array( $item_id => '30' ),
			)
		);

		$response = $this->do_ajax( 'woocommerce_save_order_items' );

		$this->assertTrue( $response['success'] );

		$fresh_item = \WC_Order_Factory::get_order_item( $item_id );
		$this->assertEquals( 3, $fresh_item->get_quantity() );
	}

	/**
	 * @testdox remove_order_item rejects a negative quantity passed through the pre-delete save and deletes nothing.
	 */
	public function test_remove_order_item_rejects_negative_quantity_in_passthrough() {
		$this->_setRole( 'administrator' );

		$order        = \WC_Helper_Order::create_order();
		$items        = array_values( $order->get_items() );
		$item         = $items[0];
		$item_id      = $item->get_id();
		$original_qty = $item->get_quantity();

		$_POST['order_id']       = $order->get_id();
		$_POST['security']       = wp_create_nonce( 'order-item' );
		$_POST['order_item_ids'] = array( $item_id );
		$_POST['items']          = http_build_query(
			array(
				'order_item_id'  => array( $item_id ),
				'order_item_qty' => array( $item_id => '-1' ),
				'line_total'     => array( $item_id => '-10' ),
				'line_subtotal'  => array( $item_id => '-10' ),
			)
		);

		$response = $this->do_ajax( 'woocommerce_remove_order_item' );

		$this->assertFalse( $response['success'] );

		$fresh_item = \WC_Order_Factory::get_order_item( $item_id );
		$this->assertInstanceOf( \WC_Order_Item_Product::class, $fresh_item, 'The item should not have been deleted.' );
		$this->assertEquals( $original_qty, $fresh_item->get_quantity() );
	}

	/**
	 * Assert a fresh order was not mutated by a rejected refund request.
	 *
	 * @param array<string,mixed>                                                                                    $response             AJAX response.
	 * @param string                                                                                                 $expected_error       Expected semantic error.
	 * @param WC_Payment_Gateway&object{refund_calls:array<int,array{order_id:int,amount:float|null,reason:string}>} $gateway              Recording gateway.
	 * @param int                                                                                                    $refund_created_calls Number of refund-created action calls.
	 * @param WC_Order                                                                                               $order                Target order.
	 */
	private function assert_fresh_refund_rejected( array $response, string $expected_error, WC_Payment_Gateway $gateway, int $refund_created_calls, WC_Order $order ): void {
		$this->assertFalse( $response['success'], 'Invalid refund input should return an AJAX error.' );
		$this->assertSame( $expected_error, $response['data']['error'], 'The AJAX error should identify the invalid refund input.' );
		$this->assertCount( 0, $gateway->refund_calls, 'Invalid refund input should not reach the payment gateway.' );
		$this->assertSame( 0, $refund_created_calls, 'Invalid refund input should not fire the refund-created action.' );

		$stored_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $stored_order, 'The order should still exist after rejection.' );
		$this->assertCount( 0, $stored_order->get_refunds(), 'Invalid refund input should not create a local refund.' );
		$this->assertSame( 0.0, (float) $stored_order->get_total_refunded(), 'The refunded total should remain unchanged.' );
		$this->assertSame( 20.0, (float) $stored_order->get_remaining_refund_amount(), 'The full order amount should remain refundable.' );
	}

	/**
	 * Does the 'hard work' of triggering an ajax endpoint and capturing the response.
	 *
	 * @param string $ajax_action The action to be triggered.
	 *
	 * @return array|null
	 */
	private function do_ajax( string $ajax_action ) {
		$output_buffering_level = ob_get_level();

		try {
			// Note that _handleAjax makes use of output buffering, which the die
			// handler usually cleans up; the finally block below closes only any
			// buffer it leaves dangling so the buffer level stays balanced.
			$this->_handleAjax( $ajax_action );
		} catch ( Exception $e ) {
			unset( $e );
		} finally {
			while ( ob_get_level() > $output_buffering_level ) {
				ob_end_clean();
			}
		}

		$result               = json_decode( $this->_last_response, true );
		$this->_last_response = false;

		return $result;
	}
}
