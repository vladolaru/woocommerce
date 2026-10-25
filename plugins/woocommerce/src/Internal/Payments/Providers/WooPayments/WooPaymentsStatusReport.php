<?php
/**
 * WooPaymentsStatusReport class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRateService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\WooPaymentsDebugTools;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsHttpClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverPreflightService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverStateStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsCurrencyRateProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsWebhookReliabilityService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayEligibility;

defined( 'ABSPATH' ) || exit;

/**
 * Adds WooPayments diagnostics to the system status report and Site Health, and its tools to Status > Tools.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsStatusReport implements RegisterHooksInterface {

	private const WOOPAY_EXPRESS_CHECKOUT_FLAG_OPTION = '_wcpay_feature_woopay_express_checkout';

	/**
	 * The built-in WooPayments diagnostics the status report shows in its own table, by field, with their untranslated export labels.
	 */
	private const RUNTIME_FIELDS = array(
		'runtime_owner'                => 'Runtime owner',
		'builtin_enabled'              => 'WooPayments feature',
		'setup_tier'                   => 'Stored setup tier',
		'effective_setup_tier'         => 'Effective setup tier',
		'preflight_failures'           => 'Cutover preflight failures',
		'multi_currency_rate_provider' => 'WooPayments rate provider',
		'last_webhook_fetch'           => 'Last fetch of failed webhook events',
	);

	private const SITE_HEALTH_TEST_ID = 'woocommerce_woopayments_cutover';

	private const SITE_HEALTH_TEST_ACTION = 'woocommerce-woopayments-cutover';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService|null
	 */
	private ?WooPaymentsAccountService $account_service = null;

	/**
	 * Cutover preflight service.
	 *
	 * @var WooPaymentsCutoverPreflightService|null
	 */
	private ?WooPaymentsCutoverPreflightService $cutover_preflight_service = null;

	/**
	 * Setup tier.
	 *
	 * @var WooPaymentsSetupTier
	 */
	private WooPaymentsSetupTier $setup_tier;

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
	 * @param WooPaymentsRuntimeArbiter $arbiter               Runtime owner arbiter.
	 * @param WooPaymentsSetupTier      $setup_tier Setup tier.
	 */
	final public function init( WooPaymentsRuntimeArbiter $arbiter, WooPaymentsSetupTier $setup_tier ): void {
		$this->arbiter    = $arbiter;
		$this->setup_tier = $setup_tier;
	}

	/**
	 * Register supportability hooks.
	 *
	 * Registered on a store with something for support to read: a connected or active tier, the WooPayments extension owning
	 * payments with the feature on, a wait for the switch, or a cutover record. Other stores register nothing.
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
	 * Tell whether this store has WooPayments state for support to read. Every read is autoloaded.
	 *
	 * The stored tier is checked, not the effective one, because a connected store whose WooPayments feature is off reads as
	 * disabled. A store the WooPayments extension owns reports only while the feature is on, since only then can it switch. A store
	 * waiting for its switch reports too, so support sees why nothing runs WooPayments there.
	 *
	 * @return bool
	 */
	private function has_support_diagnostics(): bool {
		return in_array( $this->setup_tier->get_stored_tier(), array( WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ), true )
			|| $this->can_offer_switch()
			|| null !== $this->get_cutover_state_store()->get_record();
	}

	/**
	 * Tell whether the store can be offered the switch to the built-in WooPayments: the extension owns payments with the feature
	 * on, or the store waits for its switch.
	 *
	 * @return bool
	 */
	private function can_offer_switch(): bool {
		return ( $this->arbiter->is_extension_owner() && $this->arbiter->is_builtin_enabled() ) || $this->arbiter->is_awaiting_switch();
	}

	/**
	 * Add the cutover check to Site Health while the store can be offered the switch.
	 *
	 * @param mixed $tests Site Health tests.
	 * @return mixed
	 */
	public function add_site_status_tests( $tests ) {
		if ( ! is_array( $tests ) || ! $this->can_offer_switch() ) {
			return $tests;
		}

		$tests['async'] = $tests['async'] ?? array();

		$tests['async'][ self::SITE_HEALTH_TEST_ID ] = array(
			'label'             => __( 'Ready to switch to the built-in WooPayments', 'woocommerce' ),
			'test'              => self::SITE_HEALTH_TEST_ACTION,
			'async_direct_test' => array( $this, 'run_cutover_site_health_test' ),
		);

		return $tests;
	}

	/**
	 * Run the cutover Site Health check over AJAX.
	 */
	public function run_cutover_site_health_ajax_test(): void {
		check_ajax_referer( 'health-check-site-status' );

		if ( ! current_user_can( 'view_site_health_checks' ) ) {
			wp_send_json_error();
		}

		wp_send_json_success( $this->run_cutover_site_health_test() );
	}

	/**
	 * Run the cutover Site Health check.
	 *
	 * @return array<string,mixed> Site Health test result.
	 */
	public function run_cutover_site_health_test(): array {
		$runtime_owner = $this->arbiter->get_runtime_owner();
		$failures      = $this->get_cutover_preflight_service()->get_failures();
		$is_ready      = array() === $failures;

		if ( $is_ready ) {
			$description = sprintf(
				/* translators: %s: who runs WooPayments: the WooPayments extension, the built-in WooPayments, or neither. */
				__( 'WooPayments runs as %s. The switch checks found no failures.', 'woocommerce' ),
				$this->describe_runtime_owner( $runtime_owner )
			);
		} else {
			$description = sprintf(
				/* translators: 1: who runs WooPayments: the WooPayments extension, the built-in WooPayments, or neither, 2: comma-separated codes of the failed switch checks. */
				__( 'WooPayments runs as %1$s. Failed switch checks: %2$s.', 'woocommerce' ),
				$this->describe_runtime_owner( $runtime_owner ),
				implode( ', ', $failures )
			);
		}

		return array(
			'label'       => $is_ready
				? __( 'The switch to the built-in WooPayments is ready', 'woocommerce' )
				: __( 'The switch to the built-in WooPayments is not ready', 'woocommerce' ),
			'status'      => $is_ready ? 'good' : 'recommended',
			'badge'       => array(
				'label' => __( 'Payments', 'woocommerce' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '',
			'test'        => self::SITE_HEALTH_TEST_ID,
		);
	}

	/**
	 * Get the WooPayments diagnostic data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_status_data(): array {
		$account_connected         = $this->get_account_service()->has_account();
		$enabled_payment_methods   = $this->get_enabled_payment_methods();
		$payment_request_locations = $this->get_express_checkout_method_locations( 'payment_request' );
		$woopay_locations          = $this->get_express_checkout_method_locations( 'woopay' );
		$multi_currency_enabled    = $this->is_multi_currency_running();
		$woopay_setting            = $this->get_account_service()->get_gateway_setting( 'platform_checkout', 'no' );

		return array(
			'runtime_owner'           => $this->arbiter->get_runtime_owner(),
			'awaiting_switch'         => $this->arbiter->is_awaiting_switch(),
			'builtin_enabled'         => $this->arbiter->is_builtin_enabled(),
			'setup_tier'              => $this->setup_tier->get_stored_tier(),
			'effective_setup_tier'    => $this->setup_tier->get_effective_tier(),
			'preflight_failures'      => $this->get_preflight_failures(),
			'account_id'              => $this->get_account_service()->get_account_id(),
			'account_connected'       => $account_connected,
			'gateway_enabled'         => $this->get_account_service()->is_gateway_enabled(),
			'test_mode'               => $this->get_account_service()->is_test_mode_enabled(),
			'enabled_payment_methods' => $enabled_payment_methods,
			'woopay'                  => array(
				'enabled'           => 'yes' === (string) $woopay_setting || true === $woopay_setting || '1' === (string) $woopay_setting,
				'enabled_locations' => $woopay_locations,
			),
			'express_checkout'        => array(
				'payment_request' => $payment_request_locations,
			),
			'multi_currency'          => array(
				'enabled'                 => $multi_currency_enabled,
				'rate_provider'           => WooPaymentsCurrencyRateProvider::PROVIDER_ID,
				'rate_provider_available' => $multi_currency_enabled && $this->is_rate_provider_available(),
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
				'value' => $data['awaiting_switch'] ? __( 'none (awaiting the switch)', 'woocommerce' ) : (string) $data['runtime_owner'],
			),
			'builtin_enabled'              => array(
				'label' => __( 'WooPayments feature', 'woocommerce' ),
				'value' => $this->format_enabled( (bool) $data['builtin_enabled'] ),
			),
			'setup_tier'                   => array(
				'label' => __( 'Stored setup tier', 'woocommerce' ),
				'value' => (string) $data['setup_tier'],
			),
			'effective_setup_tier'         => array(
				'label' => __( 'Effective setup tier', 'woocommerce' ),
				'value' => (string) $data['effective_setup_tier'],
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
				'label' => __( 'Last fetch of failed webhook events', 'woocommerce' ),
				'value' => $this->format_timestamp( (int) $data['last_webhook_fetch'] ),
			),
		);
	}

	/**
	 * Render the WooPayments status section while the built-in WooPayments owns payments, then the built-in WooPayments
	 * diagnostics as a separate table.
	 */
	public function render_status_report_section(): void {
		$fields       = $this->get_status_fields();
		$runtime_rows = array();
		foreach ( self::RUNTIME_FIELDS as $field_id => $export_label ) {
			$runtime_rows[] = $this->status_row( $export_label, $fields[ $field_id ]['label'], '', $fields[ $field_id ]['value'] );
		}

		// The extension renders this section itself while it owns payments (client 11.1.0 `includes/class-wc-payments-status.php:55-56`), and no gateway is registered while the store waits for its switch or the feature is off.
		if ( $this->arbiter->is_builtin_owner() ) {
			$this->render_status_table( 'WooPayments', $this->get_woopayments_status_rows() );
		}
		$this->render_status_table( 'Built-in WooPayments', $runtime_rows );
	}

	/**
	 * Get the rows of the WooPayments status section, with the extension's labels, read from the built-in WooPayments services.
	 *
	 * Client 11.1.0 `includes/class-wc-payments-status.php:427-641`: the account rows show only with a WPCOM
	 * connection, and the settings rows only with a connected account.
	 *
	 * @return array<int,array{export_label:string,label:string,help:string,value:string,warning:bool}>
	 */
	public function get_woopayments_status_rows(): array {
		$http_client = wc_get_container()->get( WooPaymentsHttpClient::class );
		$rows        = array(
			$this->status_row(
				'Version',
				__( 'Version', 'woocommerce' ),
				/* translators: %s: WooPayments */
				sprintf( __( 'The %s version this store reports to the WooPayments platform.', 'woocommerce' ), 'WooPayments' ),
				WooPaymentsClientVersion::get_reported_version()
			),
		);

		$wpcom_connected = $http_client->is_connected();
		$rows[]          = $this->status_row(
			'Connected to WPCOM',
			__( 'Connected to WPCOM', 'woocommerce' ),
			/* translators: %s: WooPayments */
			sprintf( __( "Can your store connect securely to wordpress.com? Without a proper WPCOM connection %s can't function!", 'woocommerce' ), 'WooPayments' ),
			$this->format_yes_no( $wpcom_connected ),
			! $wpcom_connected
		);

		if ( $wpcom_connected ) {
			$gateway           = wc_get_container()->get( WooPaymentsGateway::class );
			$account_connected = $gateway->is_connected();
			$account_id        = $this->get_account_service()->get_account_id();
			$rows[]            = $this->status_row(
				'WPCOM Blog ID',
				__( 'WPCOM Blog ID', 'woocommerce' ),
				__( 'The corresponding wordpress.com blog ID for this store.', 'woocommerce' ),
				(string) ( $http_client->get_blog_id() ?? '-' )
			);
			$rows[]            = $this->status_row(
				'Account ID',
				__( 'Account ID', 'woocommerce' ),
				__( 'The merchant account ID you are currently using to process payments with.', 'woocommerce' ),
				$account_connected ? ( '' !== $account_id ? $account_id : '-' ) : __( 'Not connected', 'woocommerce' ),
				! $account_connected
			);

			if ( $account_connected ) {
				$rows = array_merge( $rows, $this->get_woopayments_account_status_rows( $gateway ) );
			}
		}

		$rows[] = $this->status_row(
			'Logging',
			__( 'Logging', 'woocommerce' ),
			__( 'Whether debug logging is enabled and working or not.', 'woocommerce' ),
			$this->format_enabled( wc_get_container()->get( WooPaymentsLogger::class )->can_log() )
		);

		return $rows;
	}

	/**
	 * Get the extension's status rows that need a connected account.
	 *
	 * @param WooPaymentsGateway $gateway The WooPayments gateway.
	 * @return array<int,array{export_label:string,label:string,help:string,value:string,warning:bool}>
	 */
	private function get_woopayments_account_status_rows( WooPaymentsGateway $gateway ): array {
		$account_service       = $this->get_account_service();
		$woopay_eligibility    = wc_get_container()->get( WooPaymentsWooPayEligibility::class );
		$fraud_ruleset_service = wc_get_container()->get( WooPaymentsFraudRulesetService::class );
		$needs_setup           = $gateway->needs_setup();
		$dev_triggers          = $account_service->get_dev_mode_triggers();
		$woopay_eligible       = $woopay_eligibility->is_woopay_eligible();
		$protection_level      = $fraud_ruleset_service->get_current_protection_level();
		$support_phone         = $account_service->get_cached_account_data()['business_profile']['support_phone'] ?? '';
		$support_phone         = is_scalar( $support_phone ) ? (string) $support_phone : '';

		$rows = array(
			$this->status_row(
				'Payment Gateway',
				__( 'Payment Gateway', 'woocommerce' ),
				__( 'Is the payment gateway ready and enabled for use on your store?', 'woocommerce' ),
				$needs_setup ? __( 'Needs setup', 'woocommerce' ) : $this->format_enabled( $account_service->is_gateway_enabled() ),
				$needs_setup
			),
			$this->status_row(
				'Test Mode',
				__( 'Test Mode', 'woocommerce' ),
				__( 'Whether the payment gateway has test payments enabled or not.', 'woocommerce' ),
				$this->format_enabled( $account_service->is_test_mode_enabled() )
			),
			$this->status_row(
				'Dev Mode',
				__( 'Dev Mode', 'woocommerce' ),
				__( 'Whether WooPayments is running in dev (sandbox) mode and what triggered it.', 'woocommerce' ),
				array() !== $dev_triggers ? __( 'Enabled', 'woocommerce' ) . ' (' . implode( ', ', $dev_triggers ) . ')' : __( 'Disabled', 'woocommerce' )
			),
			$this->status_row(
				'Enabled APMs',
				__( 'Enabled APMs', 'woocommerce' ),
				__( 'What payment methods are enabled for the store.', 'woocommerce' ),
				implode( ',', $this->get_enabled_payment_methods() )
			),
		);

		if ( ! $woopay_eligible || '1' !== (string) get_option( self::WOOPAY_EXPRESS_CHECKOUT_FLAG_OPTION, '1' ) ) {
			$rows[] = $this->status_row(
				'WooPay',
				__( 'WooPay Express Checkout', 'woocommerce' ),
				/* translators: %s: WooPayments */
				sprintf( __( 'WooPay is not available, as a %s feature, or the store is not yet eligible.', 'woocommerce' ), 'WooPayments' ),
				$woopay_eligible ? __( 'Not active', 'woocommerce' ) : __( 'Not eligible', 'woocommerce' )
			);
		} else {
			$rows[] = $this->status_row(
				'WooPay',
				__( 'WooPay Express Checkout', 'woocommerce' ),
				__( 'Whether the new WooPay Express Checkout is enabled or not.', 'woocommerce' ),
				$this->format_woopayments_express_checkout_status( 'yes' === $account_service->get_gateway_setting( 'platform_checkout' ), $this->get_express_checkout_method_locations( 'woopay' ) )
			);
			$rows[] = $this->status_row(
				'WooPay Incompatible Extensions',
				__( 'WooPay Incompatible Extensions', 'woocommerce' ),
				__( 'Whether there are extensions active that are have known incompatibilities with the functioning of the new WooPay Express Checkout.', 'woocommerce' ),
				$this->format_yes_no( (bool) get_option( 'woopay_invalid_extension_found', false ) )
			);
		}

		$rows[] = $this->status_row(
			'Apple Pay / Google Pay',
			__( 'Apple Pay / Google Pay Express Checkout', 'woocommerce' ),
			__( 'Whether the store has Payment Request enabled or not.', 'woocommerce' ),
			$this->format_woopayments_express_checkout_status( $account_service->is_payment_request_enabled(), $this->get_express_checkout_method_locations( 'payment_request' ) )
		);
		$rows[] = $this->status_row(
			'Fraud Protection Level',
			__( 'Fraud Protection Level', 'woocommerce' ),
			__( 'The current fraud protection level the payment gateway is using.', 'woocommerce' ),
			$protection_level
		);
		if ( 'advanced' === $protection_level ) {
			$filters = $this->get_enabled_fraud_filter_names( $fraud_ruleset_service->get_advanced_fraud_protection_settings() );
			$rows[]  = $this->status_row(
				'Enabled Fraud Filters',
				__( 'Enabled Fraud Filters', 'woocommerce' ),
				__( 'The advanced fraud protection filters currently enabled.', 'woocommerce' ),
				array() !== $filters ? implode( ',', $filters ) : '-'
			);
		}

		$rows[] = $this->status_row(
			'Multi-currency',
			__( 'Multi-currency', 'woocommerce' ),
			__( 'Whether the store has the Multi-currency feature enabled or not.', 'woocommerce' ),
			$this->format_enabled( $this->is_multi_currency_running() )
		);
		$rows[] = $this->status_row(
			'Auth and Capture',
			__( 'Auth and Capture', 'woocommerce' ),
			__( 'Whether the store has the Auth & Capture feature enabled or not.', 'woocommerce' ),
			$this->format_enabled( 'yes' === $account_service->get_gateway_setting( 'manual_capture' ) )
		);
		$rows[] = $this->status_row(
			'Support Phone',
			__( 'Support Phone', 'woocommerce' ),
			__( 'The support phone number set in WooPayments settings. If not set, the settings Save button will be disabled.', 'woocommerce' ),
			'' !== $support_phone ? $support_phone : __( 'Not set', 'woocommerce' ),
			'' === $support_phone
		);
		$rows[] = $this->status_row(
			'Documents',
			__( 'Documents', 'woocommerce' ),
			__( 'Whether the tax documents section is enabled or not.', 'woocommerce' ),
			$this->format_enabled( $account_service->is_documents_enabled() )
		);

		return $rows;
	}

	/**
	 * Build one status row.
	 *
	 * @param string $export_label Untranslated label copied into the text report.
	 * @param string $label        Shown label.
	 * @param string $help         Help tip text, or empty for none.
	 * @param string $value        Value text.
	 * @param bool   $warning      Whether to mark the value as a problem.
	 * @return array{export_label:string,label:string,help:string,value:string,warning:bool}
	 */
	private function status_row( string $export_label, string $label, string $help, string $value, bool $warning = false ): array {
		return array(
			'export_label' => $export_label,
			'label'        => $label,
			'help'         => $help,
			'value'        => $value,
			'warning'      => $warning,
		);
	}

	/**
	 * Render one status report table.
	 *
	 * @param string                                                                                   $title Table heading, also its export label.
	 * @param array<int,array{export_label:string,label:string,help:string,value:string,warning:bool}> $rows  Rows.
	 */
	private function render_status_table( string $title, array $rows ): void {
		?>
		<table class="wc_status_table widefat" cellspacing="0">
			<thead>
				<tr>
					<th colspan="3" data-export-label="<?php echo esc_attr( $title ); ?>">
						<h2><?php echo esc_html( $title ); ?></h2>
					</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td data-export-label="<?php echo esc_attr( $row['export_label'] ); ?>"><?php echo esc_html( $row['label'] ); ?>:</td>
						<td class="help"><?php echo '' !== $row['help'] ? wc_help_tip( $row['help'] ) : '&nbsp;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip() escapes the tip. ?></td>
						<td>
							<?php if ( $row['warning'] ) : ?>
								<mark class="error"><span class="dashicons dashicons-warning"></span> <?php echo esc_html( $row['value'] ); ?></mark>
							<?php else : ?>
								<?php echo esc_html( $row['value'] ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Add the built-in WooPayments Site Health debug information.
	 *
	 * @param mixed $info Debug information.
	 * @return mixed
	 */
	public function add_site_health_debug_info( $info ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}

		$info['woocommerce_woopayments'] = array(
			'label'  => __( 'Built-in WooPayments', 'woocommerce' ),
			'fields' => $this->get_status_fields(),
		);

		return $info;
	}

	/**
	 * Add the WooPayments tools to WooCommerce > Status > Tools, resolving the tools class only when the tools are listed.
	 *
	 * @param mixed $tools Debug tools.
	 * @return mixed
	 */
	public function add_debug_tools( $tools ) {
		return wc_get_container()->get( WooPaymentsDebugTools::class )->add_debug_tools( $tools );
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
	 * A location setting never saved reads as its form default, every method, as client 11.1.0 reads it
	 * (`includes/class-wc-payments-status.php:66-78`).
	 *
	 * @param string $method_id Method ID.
	 * @return string[]
	 */
	private function get_express_checkout_method_locations( string $method_id ): array {
		$locations = array();

		foreach ( array( 'product', 'cart', 'checkout' ) as $location ) {
			$setting = $this->get_account_service()->get_gateway_setting( 'express_checkout_' . $location . '_methods' );
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
			return $this->get_cutover_preflight_service()->get_failures();
		} catch ( Throwable $exception ) {
			return array( 'preflight_unavailable' );
		}
	}

	/**
	 * Tell whether core Multi-Currency runs on this store, the state client 11.1.0 reports for its own module
	 * (includes/class-wc-payments-status.php:600).
	 *
	 * @return bool
	 */
	private function is_multi_currency_running(): bool {
		return wc_get_container()->get( MultiCurrencyRuntimeArbiter::class )->should_core_register();
	}

	/**
	 * Tell whether the WooPayments rate provider is the one Multi-Currency holds, and is available.
	 *
	 * @return bool
	 */
	private function is_rate_provider_available(): bool {
		try {
			$rate_service = wc_get_container()->get( MultiCurrencyRateService::class );

			return WooPaymentsCurrencyRateProvider::PROVIDER_ID === $rate_service->get_automatic_rate_source_id() && $rate_service->has_available_provider();
		} catch ( Throwable $exception ) {
			return false;
		}
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
	 * Say who runs WooPayments, in words, for the Site Health check.
	 *
	 * @param string $runtime_owner Runtime owner value.
	 * @return string
	 */
	private function describe_runtime_owner( string $runtime_owner ): string {
		if ( WooPaymentsRuntimeArbiter::OWNER_EXTENSION === $runtime_owner ) {
			return __( 'the WooPayments extension', 'woocommerce' );
		}
		if ( WooPaymentsRuntimeArbiter::OWNER_BUILTIN === $runtime_owner ) {
			return __( 'the built-in WooPayments', 'woocommerce' );
		}

		return __( 'neither', 'woocommerce' );
	}

	/**
	 * Format a list.
	 *
	 * @param string[] $values Values.
	 * @return string
	 */
	private function format_list( array $values ): string {
		return empty( $values ) ? '-' : implode( ', ', $values );
	}

	/**
	 * Format express checkout state.
	 *
	 * @param bool     $enabled   Enabled state.
	 * @param string[] $locations Enabled locations.
	 * @return string
	 */
	private function format_express_checkout_status( bool $enabled, array $locations ): string {
		if ( ! $enabled ) {
			return __( 'Disabled', 'woocommerce' );
		}

		return empty( $locations )
			? __( 'Enabled (no locations enabled)', 'woocommerce' )
			: sprintf(
				/* translators: %s: comma-separated checkout locations. */
				__( 'Enabled (%s)', 'woocommerce' ),
				implode( ', ', $locations )
			);
	}

	/**
	 * Format an express checkout method the way the WooPayments extension's status section does.
	 *
	 * @param bool     $enabled   Whether the method is enabled.
	 * @param string[] $locations Enabled locations.
	 * @return string
	 */
	private function format_woopayments_express_checkout_status( bool $enabled, array $locations ): string {
		if ( ! $enabled ) {
			return __( 'Disabled', 'woocommerce' );
		}

		return __( 'Enabled', 'woocommerce' ) . ' (' . ( array() !== $locations ? implode( ',', $locations ) : 'no locations enabled' ) . ')';
	}

	/**
	 * Name the advanced fraud filters in a ruleset, as the WooPayments extension's status section does.
	 *
	 * Client 11.1.0 `includes/class-wc-payments-status.php:555-592`; a ruleset that failed to load names none.
	 *
	 * @param mixed $ruleset Advanced fraud protection settings.
	 * @return string[]
	 */
	private function get_enabled_fraud_filter_names( $ruleset ): array {
		$names = array(
			'avs_verification'         => 'AVS Verification',
			'international_ip_address' => 'International IP Address',
			'ip_address_mismatch'      => 'IP Address Mismatch',
			'address_mismatch'         => 'Address Mismatch',
			'purchase_price_threshold' => 'Purchase Price Threshold',
			'order_items_threshold'    => 'Order Items Threshold',
		);
		$list  = array();
		foreach ( is_array( $ruleset ) ? $ruleset : array() as $rule ) {
			$key = is_array( $rule ) && isset( $rule['key'] ) && is_string( $rule['key'] ) ? $rule['key'] : '';
			if ( isset( $names[ $key ] ) ) {
				$list[] = $names[ $key ];
			}
		}

		return $list;
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
	 * Get the cutover preflight service, resolving it on first use.
	 *
	 * @return WooPaymentsCutoverPreflightService
	 */
	private function get_cutover_preflight_service(): WooPaymentsCutoverPreflightService {
		if ( null === $this->cutover_preflight_service ) {
			$this->cutover_preflight_service = wc_get_container()->get( WooPaymentsCutoverPreflightService::class );
		}

		return $this->cutover_preflight_service;
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
