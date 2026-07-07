<?php
/**
 * WooPaymentsApplePayDomainService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Native WooPayments Apple Pay domain registration.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsApplePayDomainService implements RegisterHooksInterface {

	private const SETTINGS_OPTION = 'woocommerce_woocommerce_payments_settings';

	private const ERROR_OPTION = 'wcpay_apple_pay_domain_error';

	private const RETRY_ACTION = 'wcpay_register_apple_pay_domain';

	private const RETRY_DELAY_SECONDS = HOUR_IN_SECONDS;

	private const EXPRESS_METHOD_PAYMENT_REQUEST = 'payment_request';

	private const EXPRESS_LOCATION_SETTING_KEYS = array(
		'express_checkout_product_methods',
		'express_checkout_cart_methods',
		'express_checkout_checkout_methods',
	);

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Native WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Native WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Action Scheduler wrapper.
	 *
	 * @var WooPaymentsActionSchedulerService
	 */
	private WooPaymentsActionSchedulerService $scheduler;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter      $arbiter         Runtime owner arbiter.
	 * @param WooPaymentsApiClient              $api_client      Native WooPayments API client.
	 * @param WooPaymentsAccountService         $account_service Native WooPayments account service.
	 * @param WooPaymentsActionSchedulerService $scheduler       Action Scheduler wrapper.
	 */
	final public function init(
		NativePaymentsRuntimeArbiter $arbiter,
		WooPaymentsApiClient $api_client,
		WooPaymentsAccountService $account_service,
		WooPaymentsActionSchedulerService $scheduler
	): void {
		$this->arbiter         = $arbiter;
		$this->api_client      = $api_client;
		$this->account_service = $account_service;
		$this->scheduler       = $scheduler;
	}

	/**
	 * Register Apple Pay domain verification hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		add_action( 'admin_init', array( $this, 'verify_domain_on_domain_name_change' ) );
		add_action( 'admin_notices', array( $this, 'display_error_notice' ) );
		add_action( 'woocommerce_woocommerce_payments_admin_notices', array( $this, 'display_error_notice' ) );
		add_action( 'add_option_' . self::SETTINGS_OPTION, array( $this, 'verify_domain_on_new_gateway_settings' ), 10, 2 );
		add_action( 'update_option_' . self::SETTINGS_OPTION, array( $this, 'verify_domain_on_updated_gateway_settings' ), 10, 2 );
		add_action( 'update_option_home', array( $this, 'verify_domain_on_site_url_change' ), 10, 2 );
		add_action( 'update_option_siteurl', array( $this, 'verify_domain_on_site_url_change' ), 10, 2 );
		add_action( self::RETRY_ACTION, array( $this, 'handle_domain_registration_retry' ) );
	}

	/**
	 * Verify the Apple Pay domain after native gateway settings are first stored.
	 *
	 * @param string              $_option  Option name.
	 * @param array<string,mixed> $settings New settings.
	 */
	public function verify_domain_on_new_gateway_settings( string $_option, $settings ): void {
		if ( is_array( $settings ) ) {
			$this->verify_domain_if_configured( $settings );
		}
	}

	/**
	 * Verify the Apple Pay domain after native gateway settings become Apple Pay capable.
	 *
	 * @param array<string,mixed> $previous_settings Previous settings.
	 * @param array<string,mixed> $settings          New settings.
	 */
	public function verify_domain_on_updated_gateway_settings( $previous_settings, $settings ): void {
		$previous_settings = is_array( $previous_settings ) ? $previous_settings : array();
		$settings          = is_array( $settings ) ? $settings : array();

		if ( ! $this->is_apple_pay_configured( $previous_settings ) && $this->is_apple_pay_configured( $settings ) ) {
			$this->verify_domain_if_configured( $settings );
		}
	}

	/**
	 * Verify the Apple Pay domain after the site's URL host changes.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 */
	public function verify_domain_on_site_url_change( $old_value, $new_value ): void {
		$old_domain = is_scalar( $old_value ) ? wp_parse_url( (string) $old_value, PHP_URL_HOST ) : null;
		$new_domain = is_scalar( $new_value ) ? wp_parse_url( (string) $new_value, PHP_URL_HOST ) : null;

		if ( $old_domain === $new_domain ) {
			return;
		}

		$this->verify_domain_if_configured();
	}

	/**
	 * Verify the Apple Pay domain when the current host differs from the last verified host.
	 */
	public function verify_domain_on_domain_name_change(): void {
		$domain = $this->get_domain_name();
		if ( '' === $domain ) {
			return;
		}

		if ( $domain !== (string) $this->get_gateway_setting( 'apple_pay_verified_domain', '' ) ) {
			$this->verify_domain_if_configured();
		}
	}

	/**
	 * Retry Apple Pay domain registration from Action Scheduler.
	 */
	public function handle_domain_registration_retry(): void {
		$this->verify_domain_if_configured();
	}

	/**
	 * Register the current site domain with Apple Pay.
	 *
	 * @return bool True when the domain was verified.
	 */
	public function register_domain(): bool {
		$domain = $this->get_domain_name();
		if ( '' === $domain ) {
			return false;
		}

		$error = '';

		try {
			$response = $this->api_client->register_apple_pay_domain( $domain );
			if ( $this->is_successful_registration_response( $response ) ) {
				$this->update_gateway_settings(
					array(
						'apple_pay_verified_domain' => $domain,
						'apple_pay_domain_set'      => 'yes',
					)
				);
				delete_option( self::ERROR_OPTION );
				$this->log( __( 'Your domain has been verified with Apple Pay!', 'woocommerce' ) );

				return true;
			}

			$error = $this->get_registration_error_message( $response );
		} catch ( WooPaymentsApiException $exception ) {
			$error = $exception->getMessage();
		}

		if ( '' === $error ) {
			$error = __( 'Apple Pay domain verification failed.', 'woocommerce' );
		}

		$this->update_gateway_settings(
			array(
				'apple_pay_verified_domain' => $domain,
				'apple_pay_domain_set'      => 'no',
			)
		);
		update_option( self::ERROR_OPTION, $error );
		$this->schedule_retry();
		$this->log( 'Error registering domain with Apple: ' . $error, 'error' );

		return false;
	}

	/**
	 * Process Apple Pay domain registration when settings are configured.
	 *
	 * @param array<string,mixed>|null $settings Optional settings snapshot.
	 */
	public function verify_domain_if_configured( ?array $settings = null ): void {
		$settings = $settings ?? $this->get_gateway_settings();

		if ( ! $this->is_apple_pay_configured( $settings ) ) {
			return;
		}

		$this->register_domain();
	}

	/**
	 * Display Apple Pay registration errors.
	 */
	public function display_error_notice(): void {
		if ( ! $this->is_apple_pay_configured( $this->get_gateway_settings() ) || ! $this->account_service->has_live_account() ) {
			return;
		}

		$domain_set   = (string) $this->get_gateway_setting( 'apple_pay_domain_set', '' );
		$error_notice = (string) get_option( self::ERROR_OPTION, '' );
		$empty_notice = '' === $error_notice;

		if ( $empty_notice && 'no' !== $domain_set ) {
			return;
		}

		if ( ! $empty_notice ) {
			delete_option( self::ERROR_OPTION );
		}

		$allowed_error_html = array(
			'a' => array(
				'href'  => array(),
				'title' => array(),
			),
		);

		$verification_failed = $empty_notice
			? __( 'Apple Pay domain verification failed.', 'woocommerce' )
			: __( 'Apple Pay domain verification failed with the following error:', 'woocommerce' );
		$learn_more_text     = sprintf(
			wp_kses(
				/* translators: %s: URL to Apple Pay domain registration documentation. */
				__( '<a href="%s" target="_blank" rel="noopener noreferrer">Learn more</a>.', 'woocommerce' ),
				array(
					'a' => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
			),
			esc_url( 'https://woocommerce.com/document/woopayments/payment-methods/apple-pay/#domain-registration' )
		);
		$check_log_text = sprintf(
			wp_kses(
				/* translators: %s: URL to WooCommerce logs page. */
				__( 'Please check the <a href="%s">logs</a> for more details on this issue. Debug log must be enabled under <strong>Advanced settings</strong> to see recorded logs.', 'woocommerce' ),
				array(
					'a'      => array(
						'href' => array(),
					),
					'strong' => array(),
				)
			),
			esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) )
		);

		?>
		<div class="notice notice-error apple-pay-message">
			<p>
				<strong><?php esc_html_e( 'Express checkouts:', 'woocommerce' ); ?></strong>
				<?php echo esc_html( $verification_failed ); ?>
				<?php echo $learn_more_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</p>
			<?php if ( ! $empty_notice ) : ?>
				<p><i><?php echo wp_kses( make_clickable( esc_html( $error_notice ) ), $allowed_error_html ); ?></i></p>
			<?php endif; ?>
			<p><?php echo $check_log_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
		</div>
		<?php
	}

	/**
	 * Tell whether Apple Pay domain verification should be active.
	 *
	 * @param array<string,mixed> $settings Gateway settings.
	 * @return bool
	 */
	private function is_apple_pay_configured( array $settings ): bool {
		return $this->is_truthy( $settings['enabled'] ?? 'no' ) && $this->is_payment_request_enabled( $settings );
	}

	/**
	 * Tell whether payment-request express checkout is enabled in settings.
	 *
	 * @param array<string,mixed> $settings Gateway settings.
	 * @return bool
	 */
	private function is_payment_request_enabled( array $settings ): bool {
		$has_location_settings = false;

		foreach ( self::EXPRESS_LOCATION_SETTING_KEYS as $key ) {
			if ( ! array_key_exists( $key, $settings ) || ! is_array( $settings[ $key ] ) ) {
				continue;
			}

			$has_location_settings = true;
			if ( in_array( self::EXPRESS_METHOD_PAYMENT_REQUEST, $this->normalize_method_list( $settings[ $key ] ), true ) ) {
				return true;
			}
		}

		if ( $has_location_settings ) {
			return false;
		}

		return $this->is_truthy( $settings[ self::EXPRESS_METHOD_PAYMENT_REQUEST ] ?? 'no' );
	}

	/**
	 * Normalize express checkout method IDs.
	 *
	 * @param array<int,mixed> $methods Method IDs.
	 * @return array<int,string>
	 */
	private function normalize_method_list( array $methods ): array {
		$normalized = array();

		foreach ( $methods as $method ) {
			if ( ! is_scalar( $method ) ) {
				continue;
			}

			$method = sanitize_key( (string) $method );
			if ( '' !== $method ) {
				$normalized[] = $method;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Tell whether a setting value is truthy by WooPayments settings conventions.
	 *
	 * @param mixed $value Setting value.
	 * @return bool
	 */
	private function is_truthy( $value ): bool {
		return true === $value || 1 === $value || '1' === $value || 'yes' === $value || 'true' === $value;
	}

	/**
	 * Tell whether the API response indicates an active Apple Pay domain.
	 *
	 * @param array<string,mixed> $response API response.
	 * @return bool
	 */
	private function is_successful_registration_response( array $response ): bool {
		return isset( $response['id'] )
			&& isset( $response['apple_pay'] )
			&& is_array( $response['apple_pay'] )
			&& 'active' === ( $response['apple_pay']['status'] ?? null );
	}

	/**
	 * Extract the platform Apple Pay domain registration error from a response.
	 *
	 * @param array<string,mixed> $response API response.
	 * @return string
	 */
	private function get_registration_error_message( array $response ): string {
		$apple_pay      = is_array( $response['apple_pay'] ?? null ) ? $response['apple_pay'] : array();
		$status_details = is_array( $apple_pay['status_details'] ?? null ) ? $apple_pay['status_details'] : array();
		$error_message  = $status_details['error_message'] ?? '';

		return is_scalar( $error_message ) ? (string) $error_message : '';
	}

	/**
	 * Schedule a domain-registration retry.
	 */
	private function schedule_retry(): void {
		$this->scheduler->schedule_job( self::RETRY_ACTION, array(), time() + self::RETRY_DELAY_SECONDS );
	}

	/**
	 * Get native WooPayments gateway settings.
	 *
	 * @return array<string,mixed>
	 */
	private function get_gateway_settings(): array {
		$settings = get_option( self::SETTINGS_OPTION, array() );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Get a gateway setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	private function get_gateway_setting( string $key, $fallback = null ) {
		$settings = $this->get_gateway_settings();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
	}

	/**
	 * Update gateway settings while preserving unrelated settings.
	 *
	 * @param array<string,mixed> $updates Settings to update.
	 */
	private function update_gateway_settings( array $updates ): void {
		update_option( self::SETTINGS_OPTION, array_merge( $this->get_gateway_settings(), $updates ) );
	}

	/**
	 * Get the current site domain name.
	 *
	 * @return string
	 */
	private function get_domain_name(): string {
		$domain = wp_parse_url( get_site_url(), PHP_URL_HOST );

		return is_scalar( $domain ) ? (string) $domain : '';
	}

	/**
	 * Log a domain-registration message.
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level.
	 */
	private function log( string $message, string $level = 'info' ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->log( $level, $message, array( 'source' => 'woocommerce-payments' ) );
	}
}
