<?php
/**
 * WooPaymentsStatusReport class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistryFactory;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsCurrencyRateProvider;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Adds native WooPayments diagnostic data to WooCommerce support surfaces.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsStatusReport implements RegisterHooksInterface {

	private const MULTI_CURRENCY_FLAG_OPTION = '_wcpay_feature_customer_multi_currency';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Frontend styles service.
	 *
	 * @var WooPaymentsFrontendStylesService
	 */
	private WooPaymentsFrontendStylesService $frontend_styles_service;

	/**
	 * Canceled-authorization fee remediation service.
	 *
	 * @var WooPaymentsCanceledAuthorizationFeeRemediationService
	 */
	private WooPaymentsCanceledAuthorizationFeeRemediationService $fee_remediation_service;

	/**
	 * Cutover controller.
	 *
	 * @var WooPaymentsCutoverController
	 */
	private WooPaymentsCutoverController $cutover_controller;

	/**
	 * Rate provider registry factory.
	 *
	 * @var CurrencyRateProviderRegistryFactory
	 */
	private CurrencyRateProviderRegistryFactory $provider_registry_factory;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter                          $arbiter                 Runtime owner arbiter.
	 * @param WooPaymentsAccountService                             $account_service         Account service.
	 * @param WooPaymentsFrontendStylesService                      $frontend_styles_service Frontend styles service.
	 * @param WooPaymentsCanceledAuthorizationFeeRemediationService $fee_remediation_service Fee remediation service.
	 * @param WooPaymentsCutoverController                          $cutover_controller      Cutover controller.
	 * @param CurrencyRateProviderRegistryFactory                   $provider_registry_factory Rate provider registry factory.
	 */
	final public function init(
		NativePaymentsRuntimeArbiter $arbiter,
		WooPaymentsAccountService $account_service,
		WooPaymentsFrontendStylesService $frontend_styles_service,
		WooPaymentsCanceledAuthorizationFeeRemediationService $fee_remediation_service,
		WooPaymentsCutoverController $cutover_controller,
		CurrencyRateProviderRegistryFactory $provider_registry_factory
	): void {
		$this->arbiter                   = $arbiter;
		$this->account_service           = $account_service;
		$this->frontend_styles_service   = $frontend_styles_service;
		$this->fee_remediation_service   = $fee_remediation_service;
		$this->cutover_controller        = $cutover_controller;
		$this->provider_registry_factory = $provider_registry_factory;
	}

	/**
	 * Register supportability hooks.
	 *
	 * This is intentionally arbiter-independent: support needs these diagnostics when
	 * the plugin, native runtime, or no runtime owns payments.
	 */
	public function register(): void {
		add_action( 'woocommerce_system_status_report', array( $this, 'render_status_report_section' ), 1 );
		add_filter( 'woocommerce_debug_tools', array( $this, 'add_debug_tools' ) );
		add_filter( 'debug_information', array( $this, 'add_site_health_debug_info' ) );
	}

	/**
	 * Get native WooPayments diagnostic data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_status_data(): array {
		$account_connected         = $this->account_service->has_account();
		$enabled_payment_methods   = $this->get_enabled_payment_methods();
		$payment_request_locations = $this->get_express_checkout_method_locations( 'payment_request' );
		$woopay_locations          = $this->get_express_checkout_method_locations( 'woopay' );
		$multi_currency_enabled    = '1' === (string) get_option( self::MULTI_CURRENCY_FLAG_OPTION, '0' );

		return array(
			'runtime_owner'           => $this->arbiter->get_runtime_owner(),
			'native_enabled'          => $this->arbiter->is_native_runtime_enabled(),
			'native_enabled_source'   => $this->get_native_enabled_source(),
			'native_enabled_filter'   => NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED,
			'native_enabled_note'     => $this->get_native_enabled_note(),
			'preflight_failures'      => $this->get_preflight_failures(),
			'account_id'              => $this->account_service->get_account_id(),
			'account_connected'       => $account_connected,
			'gateway_enabled'         => $this->account_service->is_gateway_enabled(),
			'test_mode'               => $this->account_service->is_test_mode_enabled(),
			'enabled_payment_methods' => $enabled_payment_methods,
			'woopay'                  => array(
				'enabled'                 => $this->is_setting_enabled( 'platform_checkout' ),
				'enabled_locations'       => $woopay_locations,
				'incompatible_extensions' => (bool) get_option( 'woopay_invalid_extension_found', false ),
			),
			'express_checkout'        => array(
				'payment_request' => $payment_request_locations,
				'woopay'          => $woopay_locations,
				'amazon_pay'      => $this->get_express_checkout_method_locations( 'amazon_pay' ),
				'link'            => $this->get_express_checkout_method_locations( 'link' ),
			),
			'multi_currency'          => array(
				'enabled'                 => $multi_currency_enabled,
				'rate_provider'           => WooPaymentsCurrencyRateProvider::PROVIDER_ID,
				'rate_provider_available' => $multi_currency_enabled && $this->is_rate_provider_available( WooPaymentsCurrencyRateProvider::PROVIDER_ID ),
			),
			'last_webhook_fetch'      => (int) get_option( WooPaymentsWebhookReliabilityService::LAST_FETCH_OPTION_KEY, 0 ),
		);
	}

	/**
	 * Get formatted status fields.
	 *
	 * @return array<string,array{label:string,value:string}>
	 */
	public function get_status_fields(): array {
		$data = $this->get_status_data();

		return array(
			'runtime_owner'                => array(
				'label' => __( 'Runtime owner', 'woocommerce' ),
				'value' => (string) $data['runtime_owner'],
			),
			'native_enabled'               => array(
				'label' => __( 'Native runtime enabled', 'woocommerce' ),
				'value' => $this->format_enabled( (bool) $data['native_enabled'] ),
			),
			'native_enabled_filter'        => array(
				'label' => __( 'Native enabled filter', 'woocommerce' ),
				'value' => sprintf(
					/* translators: 1: filter name, 2: resolution source. */
					__( '%1$s (source: %2$s)', 'woocommerce' ),
					(string) $data['native_enabled_filter'],
					(string) $data['native_enabled_source']
				),
			),
			'native_enabled_note'          => array(
				'label' => __( 'Native enabled note', 'woocommerce' ),
				'value' => (string) $data['native_enabled_note'],
			),
			'preflight_failures'           => array(
				'label' => __( 'Cutover preflight failures', 'woocommerce' ),
				'value' => $this->format_list( $data['preflight_failures'] ),
			),
			'account_id'                   => array(
				'label' => __( 'Account ID', 'woocommerce' ),
				'value' => '' !== $data['account_id'] ? (string) $data['account_id'] : '-',
			),
			'account_connected'            => array(
				'label' => __( 'Account connected', 'woocommerce' ),
				'value' => $this->format_yes_no( (bool) $data['account_connected'] ),
			),
			'gateway_enabled'              => array(
				'label' => __( 'Gateway enabled', 'woocommerce' ),
				'value' => $this->format_enabled( (bool) $data['gateway_enabled'] ),
			),
			'test_mode'                    => array(
				'label' => __( 'Test mode', 'woocommerce' ),
				'value' => $this->format_enabled( (bool) $data['test_mode'] ),
			),
			'enabled_payment_methods'      => array(
				'label' => __( 'Enabled payment methods', 'woocommerce' ),
				'value' => $this->format_list( $data['enabled_payment_methods'] ),
			),
			'woopay'                       => array(
				'label' => __( 'WooPay express checkout', 'woocommerce' ),
				'value' => $this->format_express_checkout_status( (bool) $data['woopay']['enabled'], $data['woopay']['enabled_locations'] ),
			),
			'payment_request'              => array(
				'label' => __( 'Apple Pay / Google Pay express checkout', 'woocommerce' ),
				'value' => $this->format_express_checkout_status( $this->account_service->is_payment_request_enabled(), $data['express_checkout']['payment_request'] ),
			),
			'multi_currency'               => array(
				'label' => __( 'Multi-currency', 'woocommerce' ),
				'value' => $this->format_enabled( (bool) $data['multi_currency']['enabled'] ),
			),
			'multi_currency_rate_provider' => array(
				'label' => __( 'WooPayments rate provider', 'woocommerce' ),
				'value' => sprintf(
					/* translators: 1: provider ID, 2: provider availability. */
					__( '%1$s (%2$s)', 'woocommerce' ),
					(string) $data['multi_currency']['rate_provider'],
					$this->format_available( (bool) $data['multi_currency']['rate_provider_available'] )
				),
			),
			'last_webhook_fetch'           => array(
				'label' => __( 'Last webhook fetch', 'woocommerce' ),
				'value' => $this->format_timestamp( (int) $data['last_webhook_fetch'] ),
			),
		);
	}

	/**
	 * Render WooPayments status report rows.
	 */
	public function render_status_report_section(): void {
		$fields = $this->get_status_fields();
		?>
		<table class="wc_status_table widefat" cellspacing="0">
			<thead>
				<tr>
					<th colspan="3" data-export-label="WooPayments native payments">
						<h2><?php esc_html_e( 'WooPayments native payments', 'woocommerce' ); ?></h2>
					</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $fields as $field ) : ?>
					<tr>
						<td data-export-label="<?php echo esc_attr( $field['label'] ); ?>"><?php echo esc_html( $field['label'] ); ?>:</td>
						<td class="help">&nbsp;</td>
						<td><?php echo esc_html( $field['value'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Add native WooPayments Site Health debug information.
	 *
	 * @param array<string,mixed> $info Debug information.
	 * @return array<string,mixed>
	 */
	public function add_site_health_debug_info( array $info ): array {
		$fields = array();
		foreach ( $this->get_status_fields() as $field_id => $field ) {
			$fields[ $field_id ] = array(
				'label' => $field['label'],
				'value' => $field['value'],
			);
		}

		$info['woocommerce_native_payments'] = array(
			'label'  => __( 'WooPayments native payments', 'woocommerce' ),
			'fields' => $fields,
		);

		return $info;
	}

	/**
	 * Add native WooPayments debug tools.
	 *
	 * @param array<string,array<string,mixed>> $tools Debug tools.
	 * @return array<string,array<string,mixed>>
	 */
	public function add_debug_tools( array $tools ): array {
		return array_merge(
			$tools,
			array(
				'clear_wcpay_account_cache'            => array(
					'name'     => __( 'Clear WooPayments account cache', 'woocommerce' ),
					'button'   => __( 'Clear', 'woocommerce' ),
					'desc'     => __( 'This tool clears the cached account values used by WooPayments.', 'woocommerce' ),
					'callback' => array( $this, 'clear_account_cache' ),
				),
				'delete_wcpay_test_orders'             => array(
					'name'     => __( 'Delete WooPayments test orders', 'woocommerce' ),
					'button'   => __( 'Delete', 'woocommerce' ),
					'desc'     => __( 'This tool permanently deletes test mode orders placed through WooPayments. Orders placed through other gateways are not affected.', 'woocommerce' ),
					'callback' => array( $this, 'delete_test_orders' ),
				),
				'clear_wcpay_styles_cache'             => array(
					'name'     => __( 'Clear WooPayments calculated styles', 'woocommerce' ),
					'button'   => __( 'Clear', 'woocommerce' ),
					'desc'     => __( 'This tool clears the cached styles used by WooPayments checkout elements.', 'woocommerce' ),
					'callback' => array( $this, 'clear_styles_cache' ),
				),
				'remediate_canceled_auth_fees_dry_run' => array(
					'name'     => __( 'Preview canceled authorization fix', 'woocommerce' ),
					'button'   => $this->get_dry_run_button_text(),
					'desc'     => __( 'This tool previews which orders would be affected by the canceled authorization fix without changing data.', 'woocommerce' ),
					'callback' => array( $this, 'schedule_canceled_auth_dry_run' ),
					'disabled' => $this->is_remediation_running_or_complete(),
				),
				'remediate_canceled_auth_fees'         => array(
					'name'     => __( 'Fix canceled authorization analytics', 'woocommerce' ),
					'button'   => $this->get_remediation_button_text(),
					'desc'     => $this->get_remediation_description(),
					'confirm'  => __( 'This will update order metadata and delete incorrect refund records for affected orders. Make sure you have a recent backup before continuing.', 'woocommerce' ),
					'callback' => array( $this, 'schedule_canceled_auth_remediation' ),
					'disabled' => $this->is_remediation_running_or_complete(),
				),
			)
		);
	}

	/**
	 * Clear the WooPayments account cache.
	 *
	 * @return string Result message.
	 */
	public function clear_account_cache(): string {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return __( 'You do not have permission to run this tool.', 'woocommerce' );
		}

		$this->account_service->clear_cache();

		return __( 'WooPayments account cache cleared.', 'woocommerce' );
	}

	/**
	 * Delete test-mode WooPayments orders.
	 *
	 * @return string Result message.
	 */
	public function delete_test_orders(): string {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return __( 'You do not have permission to delete orders.', 'woocommerce' );
		}

		try {
			$orders        = wc_get_orders(
				array(
					'limit'      => -1,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_key'   => '_wcpay_mode',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value' => 'test',
					'return'     => 'objects',
				)
			);
			$deleted_count = 0;

			if ( ! is_array( $orders ) ) {
				return __( 'No WooPayments test orders found.', 'woocommerce' );
			}

			foreach ( $orders as $order ) {
				if ( $order instanceof WC_Order && $order->delete( true ) ) {
					++$deleted_count;
				}
			}

			if ( 0 === $deleted_count ) {
				return __( 'No WooPayments test orders found.', 'woocommerce' );
			}

			return sprintf(
				/* translators: %d: number of deleted orders. */
				_n(
					'%d WooPayments test order deleted.',
					'%d WooPayments test orders deleted.',
					$deleted_count,
					'woocommerce'
				),
				$deleted_count
			);
		} catch ( Throwable $exception ) {
			return sprintf(
				/* translators: %s: error message. */
				__( 'Error deleting WooPayments test orders: %s', 'woocommerce' ),
				$exception->getMessage()
			);
		}
	}

	/**
	 * Clear the WooPayments frontend styles cache.
	 *
	 * @return string Result message.
	 */
	public function clear_styles_cache(): string {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return __( 'You do not have permission to run this tool.', 'woocommerce' );
		}

		$this->frontend_styles_service->invalidate_styles_cache_version();

		return __( 'WooPayments styles cache cleared.', 'woocommerce' );
	}

	/**
	 * Schedule canceled-authorization fee remediation.
	 *
	 * @return string Result message.
	 */
	public function schedule_canceled_auth_remediation(): string {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return __( 'You do not have permission to run this tool.', 'woocommerce' );
		}

		if ( $this->fee_remediation_service->is_complete() ) {
			return __( 'Remediation has already been completed.', 'woocommerce' );
		}

		if ( $this->is_remediation_action_scheduled() ) {
			return __( 'Remediation is already in progress. Check the Action Scheduler for status.', 'woocommerce' );
		}

		$this->fee_remediation_service->schedule_remediation();

		return __( 'Remediation has been scheduled and will run in the background.', 'woocommerce' );
	}

	/**
	 * Schedule canceled-authorization fee remediation dry run.
	 *
	 * @return string Result message.
	 */
	public function schedule_canceled_auth_dry_run(): string {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return __( 'You do not have permission to run this tool.', 'woocommerce' );
		}

		if ( $this->fee_remediation_service->is_complete() ) {
			return __( 'Remediation has already been completed.', 'woocommerce' );
		}

		if ( $this->is_remediation_action_scheduled() ) {
			return __( 'Remediation is already in progress. Check the Action Scheduler for status.', 'woocommerce' );
		}

		$this->fee_remediation_service->schedule_dry_run();

		return __( 'Dry run has been scheduled and will run in the background.', 'woocommerce' );
	}

	/**
	 * Get enabled payment method IDs.
	 *
	 * @return string[]
	 */
	private function get_enabled_payment_methods(): array {
		$methods = $this->account_service->get_gateway_setting( 'upe_enabled_payment_method_ids', array( 'card' ) );

		return $this->sanitize_string_list( is_array( $methods ) ? $methods : array( 'card' ) );
	}

	/**
	 * Get locations where an express checkout method is enabled.
	 *
	 * @param string $method_id Method ID.
	 * @return string[]
	 */
	private function get_express_checkout_method_locations( string $method_id ): array {
		$locations = array();

		foreach ( array( 'product', 'cart', 'checkout' ) as $location ) {
			$setting = $this->account_service->get_gateway_setting( 'express_checkout_' . $location . '_methods', array() );
			$methods = $this->sanitize_string_list( is_array( $setting ) ? $setting : array() );
			if ( in_array( $method_id, $methods, true ) ) {
				$locations[] = $location;
			}
		}

		return $locations;
	}

	/**
	 * Get cutover preflight failures without letting diagnostics fatal.
	 *
	 * @return string[]
	 */
	private function get_preflight_failures(): array {
		try {
			return $this->sanitize_string_list( $this->cutover_controller->get_preflight_failures() );
		} catch ( Throwable $exception ) {
			return array( 'preflight_unavailable' );
		}
	}

	/**
	 * Tell whether the named rate provider is available in the active registry.
	 *
	 * @param string $provider_id Rate provider ID.
	 * @return bool
	 */
	private function is_rate_provider_available( string $provider_id ): bool {
		try {
			$provider = $this->provider_registry_factory->create()->get_provider( $provider_id );

			return null !== $provider && $provider->is_available();
		} catch ( Throwable $exception ) {
			return false;
		}
	}

	/**
	 * Tell where the native enabled value resolved from.
	 *
	 * @return string
	 */
	private function get_native_enabled_source(): string {
		return false === has_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED ) ? 'default' : 'filter';
	}

	/**
	 * Get the native runtime enabled support note.
	 *
	 * @return string
	 */
	private function get_native_enabled_note(): string {
		return sprintf(
			/* translators: %s: filter name. */
			__( 'The %s filter is resolved while WooCommerce is being loaded. Use a mu-plugin or earlier bootstrap code when changing this value for all native registrations.', 'woocommerce' ),
			NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED
		);
	}

	/**
	 * Tell whether a yes/no gateway setting is enabled.
	 *
	 * @param string $key             Setting key.
	 * @param bool   $default_enabled Default value.
	 * @return bool
	 */
	private function is_setting_enabled( string $key, bool $default_enabled = false ): bool {
		$value = $this->account_service->get_gateway_setting( $key, $default_enabled ? 'yes' : 'no' );

		return 'yes' === (string) $value || true === $value || '1' === (string) $value;
	}

	/**
	 * Sanitize a list of string values.
	 *
	 * @param array<mixed> $values Values.
	 * @return string[]
	 */
	private function sanitize_string_list( array $values ): array {
		$sanitized = array();

		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = trim( (string) $value );
			if ( '' !== $value ) {
				$sanitized[] = $value;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Format a boolean as enabled/disabled.
	 *
	 * @param bool $enabled Enabled state.
	 * @return string
	 */
	private function format_enabled( bool $enabled ): string {
		return $enabled ? __( 'Enabled', 'woocommerce' ) : __( 'Disabled', 'woocommerce' );
	}

	/**
	 * Format a boolean as available/unavailable.
	 *
	 * @param bool $available Available state.
	 * @return string
	 */
	private function format_available( bool $available ): string {
		return $available ? __( 'Available', 'woocommerce' ) : __( 'Unavailable', 'woocommerce' );
	}

	/**
	 * Format a boolean as yes/no.
	 *
	 * @param bool $value Boolean value.
	 * @return string
	 */
	private function format_yes_no( bool $value ): string {
		return $value ? __( 'Yes', 'woocommerce' ) : __( 'No', 'woocommerce' );
	}

	/**
	 * Format a list.
	 *
	 * @param mixed $values Values.
	 * @return string
	 */
	private function format_list( $values ): string {
		$values = is_array( $values ) ? $this->sanitize_string_list( $values ) : array();

		return empty( $values ) ? '-' : implode( ', ', $values );
	}

	/**
	 * Format express checkout state.
	 *
	 * @param bool  $enabled   Enabled state.
	 * @param mixed $locations Enabled locations.
	 * @return string
	 */
	private function format_express_checkout_status( bool $enabled, $locations ): string {
		if ( ! $enabled ) {
			return __( 'Disabled', 'woocommerce' );
		}

		$locations = is_array( $locations ) ? $this->sanitize_string_list( $locations ) : array();

		return empty( $locations )
			? __( 'Enabled (no locations enabled)', 'woocommerce' )
			: sprintf(
				/* translators: %s: comma-separated checkout locations. */
				__( 'Enabled (%s)', 'woocommerce' ),
				implode( ', ', $locations )
			);
	}

	/**
	 * Format a timestamp.
	 *
	 * @param int $timestamp Timestamp.
	 * @return string
	 */
	private function format_timestamp( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return __( 'Never', 'woocommerce' );
		}

		return date_i18n( 'Y-m-d H:i:s P', $timestamp );
	}

	/**
	 * Get dry-run button text.
	 *
	 * @return string
	 */
	private function get_dry_run_button_text(): string {
		if ( $this->fee_remediation_service->is_complete() ) {
			return __( 'Completed', 'woocommerce' );
		}

		if ( $this->is_remediation_running_or_complete() ) {
			return __( 'Running...', 'woocommerce' );
		}

		return __( 'Preview', 'woocommerce' );
	}

	/**
	 * Get remediation button text.
	 *
	 * @return string
	 */
	private function get_remediation_button_text(): string {
		if ( $this->fee_remediation_service->is_complete() ) {
			return __( 'Completed', 'woocommerce' );
		}

		if ( $this->is_remediation_running_or_complete() ) {
			return __( 'Running...', 'woocommerce' );
		}

		return __( 'Run', 'woocommerce' );
	}

	/**
	 * Get remediation description.
	 *
	 * @return string
	 */
	private function get_remediation_description(): string {
		$stats = $this->fee_remediation_service->get_stats();

		if ( $this->fee_remediation_service->is_complete() ) {
			return sprintf(
				/* translators: 1: processed count, 2: remediated count. */
				__( 'Remediation is complete. Processed %1$d orders and remediated %2$d.', 'woocommerce' ),
				$stats['processed'],
				$stats['remediated']
			);
		}

		if ( $this->is_remediation_running_or_complete() ) {
			return sprintf(
				/* translators: %d: processed count. */
				__( 'Remediation is running. Processed %d orders so far.', 'woocommerce' ),
				$stats['processed']
			);
		}

		return __( 'This tool removes incorrect refund records and fee data from orders where payment authorization was canceled but not captured.', 'woocommerce' );
	}

	/**
	 * Tell whether remediation is running, complete, or scheduled.
	 *
	 * @return bool
	 */
	private function is_remediation_running_or_complete(): bool {
		return $this->fee_remediation_service->is_complete()
			|| 'running' === get_option( WooPaymentsCanceledAuthorizationFeeRemediationService::STATUS_OPTION_KEY, '' )
			|| $this->is_remediation_action_scheduled();
	}

	/**
	 * Tell whether a remediation action is scheduled.
	 *
	 * @return bool
	 */
	private function is_remediation_action_scheduled(): bool {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}

		return false !== as_has_scheduled_action( WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_HOOK, array(), WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_SCHEDULER_GROUP_ID )
			|| false !== as_has_scheduled_action( WooPaymentsCanceledAuthorizationFeeRemediationService::DRY_RUN_ACTION_HOOK, array(), WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_SCHEDULER_GROUP_ID );
	}
}
