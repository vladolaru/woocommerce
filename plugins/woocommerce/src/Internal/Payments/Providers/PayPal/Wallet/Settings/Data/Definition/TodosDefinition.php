<?php
/**
 * Todos Definitions
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\TodosModel;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration\MigrationManager;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\TodosEligibilityService;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;

/**
 * Class TodosDefinition
 *
 * Provides the definitions for all available todos in the system.
 * Each todo has a title, description, eligibility condition, and associated action.
 */
class TodosDefinition {

	/**
	 * The todos eligibility service.
	 *
	 * @var TodosEligibilityService
	 */
	protected TodosEligibilityService $eligibilities;

	/**
	 * The general settings service.
	 *
	 * @var GeneralSettings
	 */
	protected GeneralSettings $settings;

	protected TodosModel $todos;

	/**
	 * Constructor.
	 *
	 * @param TodosEligibilityService $eligibilities The todos eligibility service.
	 * @param GeneralSettings         $settings The general settings service.
	 * @param TodosModel              $todos The todos model instance.
	 */
	public function __construct(
		TodosEligibilityService $eligibilities,
		GeneralSettings $settings,
		TodosModel $todos
	) {
		$this->eligibilities = $eligibilities;
		$this->settings      = $settings;
		$this->todos         = $todos;
	}

	/**
	 * Returns the full list of todo definitions with their eligibility conditions.
	 *
	 * @return array The array of todo definitions.
	 */
	public function get(): array {
		$eligibility_checks = $this->eligibilities->get_eligibility_checks();

		$todo_items = array(
			'enable_pay_later_messaging'           => array(
				'title'       => __( 'Enable Pay Later messaging', 'woocommerce' ),
				'description' => __( 'Show Pay Later messaging to boost conversion rate and increase cart size', 'woocommerce' ),
				'isEligible'  => $eligibility_checks['enable_pay_later_messaging'],
				'action'      => array(
					'type' => 'tab',
					'tab'  => 'pay_later_messaging',
				),
				'priority'    => 3,
			),
			'add_pay_later_messaging_product_page' => array(
				'title'       => __( 'Add Pay Later messaging to the Product page', 'woocommerce' ),
				'description' => __( 'Present Pay Later messaging on your Product page to boost conversion rate and increase cart size', 'woocommerce' ),
				'isEligible'  => $eligibility_checks['add_pay_later_messaging_product_page'],
				'action'      => array(
					'type' => 'tab',
					'tab'  => 'pay_later_messaging',
				),
				'priority'    => 4,
			),
			'add_pay_later_messaging_cart'         => array(
				'title'       => __( 'Add Pay Later messaging to the Cart page', 'woocommerce' ),
				'description' => __( 'Present Pay Later messaging on your Cart page to boost conversion rate and increase cart size', 'woocommerce' ),
				'isEligible'  => $eligibility_checks['add_pay_later_messaging_cart'],
				'action'      => array(
					'type' => 'tab',
					'tab'  => 'pay_later_messaging',
				),
				'priority'    => 4,
			),
			'add_pay_later_messaging_checkout'     => array(
				'title'       => __( 'Add Pay Later messaging to the Checkout page', 'woocommerce' ),
				'description' => __( 'Present Pay Later messaging on your Checkout page to boost conversion rate and increase cart size', 'woocommerce' ),
				'isEligible'  => $eligibility_checks['add_pay_later_messaging_checkout'],
				'action'      => array(
					'type' => 'tab',
					'tab'  => 'pay_later_messaging',
				),
				'priority'    => 4,
			),
			'add_paypal_buttons_cart'              => array(
				'title'       => __( 'Add PayPal buttons to the Cart page', 'woocommerce' ),
				'description' => __( 'Allow customers to check out quickly and securely from the Cart page. Customers save time and get through checkout in fewer clicks.', 'woocommerce' ),
				'isEligible'  => $eligibility_checks['add_paypal_buttons_cart'],
				'action'      => array(
					'type' => 'tab',
					'tab'  => 'styling',
				),
				'priority'    => 6,
			),
			'add_paypal_buttons_block_checkout'    => array(
				'title'       => __( 'Add PayPal buttons to the Express Checkout page', 'woocommerce' ),
				'description' => __( 'Allow customers to check out quickly and securely from the Express Checkout page. Customers save time and get through checkout in fewer clicks.', 'woocommerce' ),
				'isEligible'  => $eligibility_checks['add_paypal_buttons_block_checkout'],
				'action'      => array(
					'type' => 'tab',
					'tab'  => 'styling',
				),
				'priority'    => 6,
			),
			'add_paypal_buttons_product'           => array(
				'title'       => __( 'Add PayPal buttons to the Product page', 'woocommerce' ),
				'description' => __( 'Allow customers to check out quickly and securely from the Product page. Customers save time and get through checkout in fewer clicks.', 'woocommerce' ),
				'isEligible'  => $eligibility_checks['add_paypal_buttons_product'],
				'action'      => array(
					'type' => 'tab',
					'tab'  => 'styling',
				),
				'priority'    => 6,
			),
			'enable_installments'                  => array(
				'title'       => __( 'Enable Installments', 'woocommerce' ),
				'description' => __( 'Allow your customers to pay in installments without interest while you receive the full payment in a single transaction', 'woocommerce' ),
				'isEligible'  => $eligibility_checks['enable_installments'],
				'action'      => array(
					'type' => 'external',
					'url'  => 'https://www.paypal.com/businessmanage/preferences/installmentplan',
				),
				'priority'    => 13,
			),
			'apply_for_working_capital'            => array(
				'title'       => __( 'Discover how PayPal Working Capital can fuel your business growth', 'woocommerce' ),
				'description' => __( 'Approved loans are quickly deposited, so you can put them to work right away. Check eligibility.', 'woocommerce' ),
				'isEligible'  => $eligibility_checks['apply_for_working_capital'],
				'action'      => array(
					'type' => 'external',
					'url'  => 'https://www.paypal.com/us/business/financial-services/working-capital?partner_camp_id=woocommerce_ppwc',
				),
				'priority'    => 14,
			),
		);

		$todo_items['check_settings_after_migration'] = array(
			'title'       => __( "You're now using the new PayPal Payments interface!", 'woocommerce' ),
			'description' => __( 'Complete the items below to ensure your payment configuration is optimized for your store.', 'woocommerce' ),
			'isEligible'  => fn(): bool => $this->is_settings_migration_done() && ! $this->are_all_todos_completed( $todo_items ),
			'action'      => array(
				'type' => 'tab',
				'tab'  => 'overview',
			),
			'priority'    => 0,
		);

		return apply_filters( 'woocommerce_paypal_payments_todos_list', $todo_items );
	}

	/**
	 * Checks whether the settings migration to the new UI has been completed.
	 *
	 * @return bool True if the migration is marked as done, false otherwise.
	 */
	protected function is_settings_migration_done(): bool {
		return '1' === get_option( MigrationManager::OPTION_NAME_MIGRATION_IS_DONE );
	}

	/**
	 * Determines whether all todos have been completed or dismissed appropriately.
	 *
	 * A to-do is considered completed if:
	 * - It's eligible (based on the callable `isEligible`), AND
	 * - It is either:
	 *     - A "completeOnClick" type and is present in the completed list, OR
	 *     - Not a "completeOnClick" type and is present in the dismissed list.
	 *
	 * @param array $todos The array of to-do definitions.
	 * @return bool True if all to-dos are completed or dismissed as expected, false otherwise.
	 */
	protected function are_all_todos_completed( array $todos ): bool {
		$dismissed = $this->todos->get_dismissed_todos();
		$completed = $this->todos->get_completed_onclick_todos();

		foreach ( $todos as $id => $todo ) {
			if ( ! is_callable( $todo['isEligible'] ) || ! call_user_func( $todo['isEligible'] ) ) {
				continue;
			}

			$is_click_to_complete = $todo['action']['completeOnClick'] ?? false;

			if ( $is_click_to_complete && ! in_array( $id, $completed, true ) ) {
				return false;
			}

			if ( ! $is_click_to_complete && ! in_array( $id, $dismissed, true ) ) {
				return false;
			}
		}

		return true;
	}
}
