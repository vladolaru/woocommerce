<?php
/**
 * WooPaymentsStatusReport class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistryFactory;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
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

	private const SITE_HEALTH_TEST_ID = 'woocommerce_woopayments_native_cutover';

	private const SITE_HEALTH_TEST_ACTION = 'woocommerce-woopayments-native-cutover';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService|null
	 */
	private ?WooPaymentsAccountService $account_service = null;

	/**
	 * Frontend styles service.
	 *
	 * @var WooPaymentsFrontendStylesService|null
	 */
	private ?WooPaymentsFrontendStylesService $frontend_styles_service = null;

	/**
	 * Canceled-authorization fee remediation service.
	 *
	 * @var WooPaymentsCanceledAuthorizationFeeRemediationService|null
	 */
	private ?WooPaymentsCanceledAuthorizationFeeRemediationService $fee_remediation_service = null;

	/**
	 * Cutover controller.
	 *
	 * @var WooPaymentsCutoverController|null
	 */
	private ?WooPaymentsCutoverController $cutover_controller = null;

	/**
	 * Rate provider registry factory.
	 *
	 * @var CurrencyRateProviderRegistryFactory|null
	 */
	private ?CurrencyRateProviderRegistryFactory $provider_registry_factory = null;

	/**
	 * Native payments state store.
	 *
	 * @var NativePaymentsState
	 */
	private NativePaymentsState $native_payments_state;

	/**
	 * Cutover state store.
	 *
	 * @var WooPaymentsCutoverStateStore|null
	 */
	private ?WooPaymentsCutoverStateStore $cutover_state_store = null;

	/**
	 * Initialize the class instance.
	 *
	 * The diagnostic collaborators are resolved on first use: this class registers on every store, and their
	 * dependency graphs must not load on requests that never render a diagnostic surface.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter               Runtime owner arbiter.
	 * @param NativePaymentsState          $native_payments_state Native payments state store.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, NativePaymentsState $native_payments_state ): void {
		$this->arbiter               = $arbiter;
		$this->native_payments_state = $native_payments_state;
	}

	/**
	 * Register supportability hooks.
	 *
	 * Registered where the WooPayments client would report too: a connected native store, or
	 * while the plugin owns payments (cutover diagnostics during coexistence). Support also needs them
	 * once the kill switch is on or a cutover started. Other stores skip the preflight and account read.
	 */
	public function register(): void {
		if ( ! $this->has_support_diagnostics() ) {
			return;
		}

		add_action( 'woocommerce_system_status_report', array( $this, 'render_status_report_section' ), 1 );
		add_filter( 'woocommerce_debug_tools', array( $this, 'add_debug_tools' ) );
		add_filter( 'debug_information', array( $this, 'add_site_health_debug_info' ) );
		add_filter( 'site_status_tests', array( $this, 'add_site_status_tests' ) );
		add_action( 'wp_ajax_health-check-' . self::SITE_HEALTH_TEST_ACTION, array( $this, 'run_cutover_site_health_ajax_test' ) );
	}

	/**
	 * Tell whether this store has native payments state for support to read. Every read is autoloaded.
	 *
	 * The stored tier is checked, not the effective one, because the kill switch clamps a connected store to disabled.
	 *
	 * @return bool
	 */
	private function has_support_diagnostics(): bool {
		return in_array( $this->native_payments_state->get_stored_state(), array( NativePaymentsState::CONNECTED, NativePaymentsState::ACTIVE ), true )
			|| $this->arbiter->is_plugin_runtime_active()
			|| (bool) get_option( NativePaymentsRuntimeArbiter::NATIVE_RUNTIME_KILL_SWITCH_OPTION, false )
			|| null !== $this->get_cutover_state_store()->get_record();
	}

	/**
	 * Add the native WooPayments cutover check to Site Health.
	 *
	 * @param array<string,array<string,array<string,mixed>>> $tests Site Health tests.
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	public function add_site_status_tests( array $tests ): array {
		$tests['async'] = $tests['async'] ?? array();

		$tests['async'][ self::SITE_HEALTH_TEST_ID ] = array(
			'label'             => __( 'WooPayments native cutover readiness', 'woocommerce' ),
			'test'              => self::SITE_HEALTH_TEST_ACTION,
			'async_direct_test' => array( $this, 'run_cutover_site_health_test' ),
		);

		return $tests;
	}

	/**
	 * Run the native WooPayments cutover Site Health check over AJAX.
	 */
	public function run_cutover_site_health_ajax_test(): void {
		check_ajax_referer( 'health-check-site-status' );

		if ( ! current_user_can( 'view_site_health_checks' ) ) {
			wp_send_json_error();
		}

		wp_send_json_success( $this->run_cutover_site_health_test() );
	}

	/**
	 * Run the native WooPayments cutover Site Health check.
	 *
	 * @return array<string,mixed> Site Health test result.
	 */
	public function run_cutover_site_health_test(): array {
		$runtime_owner = $this->arbiter->get_runtime_owner();
		$failures      = $this->get_cutover_controller()->get_preflight_failures();
		$is_ready      = array() === $failures;

		if ( $is_ready ) {
			$description = sprintf(
				/* translators: %s: payments runtime owner. */
				__( 'Runtime owner: %s. Cutover preflight has no failures.', 'woocommerce' ),
				$runtime_owner
			);
		} else {
			$description = sprintf(
				/* translators: 1: payments runtime owner, 2: comma-separated preflight failure codes. */
				__( 'Runtime owner: %1$s. Preflight failures: %2$s.', 'woocommerce' ),
				$runtime_owner,
				implode( ', ', $failures )
			);
		}

		return array(
			'label'       => $is_ready
				? __( 'WooPayments native cutover is ready', 'woocommerce' )
				: __( 'WooPayments native cutover is not ready', 'woocommerce' ),
			'status'      => $is_ready ? 'good' : 'recommended',
			'badge'       => array(
				'label' => __( 'Performance', 'woocommerce' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '',
			'test'        => self::SITE_HEALTH_TEST_ID,
		);
	}

	/**
	 * Get native WooPayments diagnostic data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_status_data(): array {
		$account_connected         = $this->get_account_service()->has_account();
		$enabled_payment_methods   = $this->get_enabled_payment_methods();
		$payment_request_locations = $this->get_express_checkout_method_locations( 'payment_request' );
		$woopay_locations          = $this->get_express_checkout_method_locations( 'woopay' );
		$multi_currency_enabled    = '1' === (string) get_option( self::MULTI_CURRENCY_FLAG_OPTION, '1' );

		return array(
			'runtime_owner'           => $this->arbiter->get_runtime_owner(),
			'native_enabled'          => $this->arbiter->is_native_runtime_enabled(),
			'native_enabled_source'   => $this->get_native_enabled_source(),
			'native_enabled_filter'   => NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED,
			'native_enabled_note'     => $this->get_native_enabled_note(),
			'preflight_failures'      => $this->get_preflight_failures(),
			'account_id'              => $this->get_account_service()->get_account_id(),
			'account_connected'       => $account_connected,
			'gateway_enabled'         => $this->get_account_service()->is_gateway_enabled(),
			'test_mode'               => $this->get_account_service()->is_test_mode_enabled(),
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
				'value' => $this->format_express_checkout_status( $this->get_account_service()->is_payment_request_enabled(), $data['express_checkout']['payment_request'] ),
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
		$native_tools = array(
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
		);

		if ( $this->arbiter->is_plugin_runtime_active() ) {
			$namespaced_tools = array();
			foreach ( $native_tools as $tool_id => $tool ) {
				$namespaced_tools[ 'native-' . $tool_id ] = $tool;
			}
			$native_tools = $namespaced_tools;
		}

		return array_merge( $tools, $native_tools );
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

		$this->get_account_service()->clear_cache();

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
					'meta_value' => WooPaymentsOrderMode::TEST,
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

		$this->get_frontend_styles_service()->invalidate_styles_cache_version();

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

		if ( $this->get_fee_remediation_service()->is_complete() ) {
			return __( 'Remediation has already been completed.', 'woocommerce' );
		}

		if ( $this->is_remediation_action_scheduled() ) {
			return __( 'Remediation is already in progress. Check the Action Scheduler for status.', 'woocommerce' );
		}

		$this->get_fee_remediation_service()->schedule_remediation();

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

		if ( $this->get_fee_remediation_service()->is_complete() ) {
			return __( 'Remediation has already been completed.', 'woocommerce' );
		}

		if ( $this->is_remediation_action_scheduled() ) {
			return __( 'Remediation is already in progress. Check the Action Scheduler for status.', 'woocommerce' );
		}

		$this->get_fee_remediation_service()->schedule_dry_run();

		return __( 'Dry run has been scheduled and will run in the background.', 'woocommerce' );
	}

	/**
	 * Get enabled payment method IDs.
	 *
	 * @return string[]
	 */
	private function get_enabled_payment_methods(): array {
		$methods = $this->get_account_service()->get_gateway_setting( 'upe_enabled_payment_method_ids', array( 'card' ) );

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
			$setting = $this->get_account_service()->get_gateway_setting( 'express_checkout_' . $location . '_methods', array() );
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
			return $this->sanitize_string_list( $this->get_cutover_controller()->get_preflight_failures() );
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
			$provider = $this->get_provider_registry_factory()->create()->get_provider( $provider_id );

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
			/* translators: 1: option name, 2: filter name. */
			__( 'The %1$s option disables native runtime by default when set to true. The %2$s filter has final authority and is resolved while WooCommerce is being loaded. Use a mu-plugin or earlier bootstrap code to override this value for all native registrations.', 'woocommerce' ),
			NativePaymentsRuntimeArbiter::NATIVE_RUNTIME_KILL_SWITCH_OPTION,
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
		$value = $this->get_account_service()->get_gateway_setting( $key, $default_enabled ? 'yes' : 'no' );

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
		if ( $this->get_fee_remediation_service()->is_complete() ) {
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
		if ( $this->get_fee_remediation_service()->is_complete() ) {
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
		$stats = $this->get_fee_remediation_service()->get_stats();

		if ( $this->get_fee_remediation_service()->is_complete() ) {
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
		return $this->get_fee_remediation_service()->is_complete()
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

	/**
	 * Get the account service, resolving it on first use.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function get_account_service(): WooPaymentsAccountService {
		if ( null === $this->account_service ) {
			$this->account_service = wc_get_container()->get( WooPaymentsAccountService::class );
		}

		return $this->account_service;
	}

	/**
	 * Get the frontend styles service, resolving it on first use.
	 *
	 * @return WooPaymentsFrontendStylesService
	 */
	private function get_frontend_styles_service(): WooPaymentsFrontendStylesService {
		if ( null === $this->frontend_styles_service ) {
			$this->frontend_styles_service = wc_get_container()->get( WooPaymentsFrontendStylesService::class );
		}

		return $this->frontend_styles_service;
	}

	/**
	 * Get the fee remediation service, resolving it on first use.
	 *
	 * @return WooPaymentsCanceledAuthorizationFeeRemediationService
	 */
	private function get_fee_remediation_service(): WooPaymentsCanceledAuthorizationFeeRemediationService {
		if ( null === $this->fee_remediation_service ) {
			$this->fee_remediation_service = wc_get_container()->get( WooPaymentsCanceledAuthorizationFeeRemediationService::class );
		}

		return $this->fee_remediation_service;
	}

	/**
	 * Get the cutover controller, resolving it on first use.
	 *
	 * @return WooPaymentsCutoverController
	 */
	private function get_cutover_controller(): WooPaymentsCutoverController {
		if ( null === $this->cutover_controller ) {
			$this->cutover_controller = wc_get_container()->get( WooPaymentsCutoverController::class );
		}

		return $this->cutover_controller;
	}

	/**
	 * Get the provider registry factory, resolving it on first use.
	 *
	 * @return CurrencyRateProviderRegistryFactory
	 */
	private function get_provider_registry_factory(): CurrencyRateProviderRegistryFactory {
		if ( null === $this->provider_registry_factory ) {
			$this->provider_registry_factory = wc_get_container()->get( CurrencyRateProviderRegistryFactory::class );
		}

		return $this->provider_registry_factory;
	}

	/**
	 * Get the cutover state store, resolving it on first use.
	 *
	 * @return WooPaymentsCutoverStateStore
	 */
	private function get_cutover_state_store(): WooPaymentsCutoverStateStore {
		if ( null === $this->cutover_state_store ) {
			$this->cutover_state_store = wc_get_container()->get( WooPaymentsCutoverStateStore::class );
		}

		return $this->cutover_state_store;
	}
}
