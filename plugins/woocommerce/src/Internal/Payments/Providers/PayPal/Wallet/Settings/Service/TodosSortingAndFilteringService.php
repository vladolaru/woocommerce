<?php
/**
 * Service for sorting and filtering to-do items.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\TodosModel;

/**
 * Service class that provides to-do sorting and filtering functionality.
 */
class TodosSortingAndFilteringService {

	/**
	 * Pay Later messaging to-do IDs in priority order.
	 *
	 * @var array
	 */
	private const PAY_LATER_IDS = array(
		'add_pay_later_messaging_product_page',
		'add_pay_later_messaging_cart',
		'add_pay_later_messaging_checkout',
	);

	/**
	 * Button placement to-do IDs in priority order.
	 *
	 * @var array
	 */
	private const BUTTON_PLACEMENT_IDS = array(
		'add_paypal_buttons_cart',
		'add_paypal_buttons_block_checkout',
		'add_paypal_buttons_product',
	);

	/**
	 * The TodosModel instance.
	 *
	 * @var TodosModel
	 */
	private TodosModel $todos_model;

	/**
	 * Constructor.
	 *
	 * @param TodosModel $todos_model The TodosModel instance.
	 */
	public function __construct( TodosModel $todos_model ) {
		$this->todos_model = $todos_model;
	}

	/**
	 * Returns Pay Later messaging to-do IDs in priority order.
	 *
	 * @return array Pay Later messaging to-do IDs.
	 */
	public function get_pay_later_ids(): array {
		return self::PAY_LATER_IDS;
	}

	/**
	 * Returns Button Placement to-do IDs in priority order.
	 *
	 * @return array Button Placement to-do IDs.
	 */
	public function get_button_placement_ids(): array {
		return self::BUTTON_PLACEMENT_IDS;
	}

	/**
	 * Sorts todos by their priority value.
	 *
	 * @param array $todos Array of todos to sort.
	 * @return array Sorted array of todos.
	 */
	public function sort_todos_by_priority( array $todos ): array {
		usort(
			$todos,
			function ( $a, $b ) {
				$priority_a = $a['priority'] ?? 999;
				$priority_b = $b['priority'] ?? 999;
				return $priority_a <=> $priority_b;
			}
		);

		return $todos;
	}

	/**
	 * Filters a group of todos to show only the highest priority one.
	 * Takes into account dismissed todos.
	 *
	 * @param array $todos The array of todos to filter.
	 * @param array $group_ids Array of to-do IDs in priority order.
	 * @return array Filtered todos with only one to-do from the specified group.
	 */
	public function filter_highest_priority_todo( array $todos, array $group_ids ): array {
		$dismissed_todos = $this->todos_model->get_dismissed_todos();

		$group_todos = array_filter(
			$todos,
			function ( $todo ) use ( $group_ids ) {
				return in_array( $todo['id'], $group_ids, true );
			}
		);

		$other_todos = array_filter(
			$todos,
			function ( $todo ) use ( $group_ids ) {
				return ! in_array( $todo['id'], $group_ids, true );
			}
		);

		// Find the highest priority to-do from the group that's eligible AND not dismissed.
		$priority_todo = null;
		foreach ( $group_ids as $todo_id ) {
			// Skip if this to-do ID is dismissed.
			if ( in_array( $todo_id, $dismissed_todos, true ) ) {
				continue;
			}

			$matching_todo = current(
				array_filter(
					$group_todos,
					function ( $todo ) use ( $todo_id ) {
						return $todo['id'] === $todo_id;
					}
				)
			);

			if ( $matching_todo ) {
				$priority_todo = $matching_todo;
				break;
			}
		}

		return $priority_todo
			? array_merge( $other_todos, array( $priority_todo ) )
			: $other_todos;
	}

	/**
	 * Filter pay later todos to show only the highest priority eligible one.
	 *
	 * @param array $todos The array of todos to filter.
	 * @return array Filtered todos.
	 */
	public function filter_pay_later_todos( array $todos ): array {
		return $this->filter_highest_priority_todo( $todos, self::PAY_LATER_IDS );
	}

	/**
	 * Filter button placement todos to show only the highest priority eligible one.
	 *
	 * @param array $todos The array of todos to filter.
	 * @return array Filtered todos.
	 */
	public function filter_button_placement_todos( array $todos ): array {
		return $this->filter_highest_priority_todo( $todos, self::BUTTON_PLACEMENT_IDS );
	}

	/**
	 * Apply all priority filters to the todos list.
	 *
	 * This method applies sorting and all priority filtering in the correct order.
	 *
	 * @param array $todos The original todos array.
	 * @return array Fully filtered and sorted todos.
	 */
	public function apply_all_priority_filters( array $todos ): array {
		$sorted_todos   = $this->sort_todos_by_priority( $todos );
		$filtered_todos = $this->filter_pay_later_todos( $sorted_todos );
		$filtered_todos = $this->filter_button_placement_todos( $filtered_todos );

		return $filtered_todos;
	}
}
