<?php
/**
 * Todos details class
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data;

/**
 * Class TodosModel
 *
 * Handles todos data persistence and state management.
 */
class TodosModel extends AbstractDataModel {

	/**
	 * Option key for WordPress storage.
	 */
	protected const OPTION_KEY = 'ppcp-settings';

	/**
	 * Returns the default structure for settings data.
	 *
	 * @return array
	 */
	protected function get_defaults(): array {
		return array(
			'dismissedTodos'        => array(),
			'completedOnClickTodos' => array(),
		);
	}

	/**
	 * Gets the dismissed todos.
	 *
	 * @return array
	 */
	public function get_dismissed_todos(): array {
		return $this->data['dismissedTodos'] ?? array();
	}

	/**
	 * Gets the completed onclick todos.
	 *
	 * @return array
	 */
	public function get_completed_onclick_todos(): array {
		return $this->data['completedOnClickTodos'] ?? array();
	}

	/**
	 * Updates the dismissed to-do items.
	 *
	 * @param array $ids Array of to-do IDs to mark as dismissed.
	 */
	public function update_dismissed_todos( array $ids ): void {
		$this->data['dismissedTodos'] = array_unique( $ids );
		$this->save();
	}

	/**
	 * Updates completed onclick todos.
	 *
	 * @param array $ids Array of to-do IDs to mark as completed.
	 */
	public function update_completed_onclick_todos( array $ids ): void {
		$this->data['completedOnClickTodos'] = array_unique( $ids );
		$this->save();
	}

	/**
	 * Resets dismissed todos.
	 */
	public function reset_dismissed_todos(): void {
		$this->data['dismissedTodos'] = array();
		$this->save();
	}

	/**
	 * Resets completed onclick todos.
	 */
	public function reset_completed_onclick_todos(): void {
		$this->data['completedOnClickTodos'] = array();
		$this->save();
	}

	/**
	 * Gets current todos data including dismissed and completed states.
	 *
	 * @return array The todos data array.
	 */
	public function get_todos_data(): array {
		return array(
			'dismissedTodos'        => $this->get_dismissed_todos(),
			'completedOnClickTodos' => $this->get_completed_onclick_todos(),
		);
	}
}
