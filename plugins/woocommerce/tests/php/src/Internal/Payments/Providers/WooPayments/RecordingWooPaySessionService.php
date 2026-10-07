<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPaySessionService;
use WP_REST_Request;

/**
 * Recording WooPay session service for controller tests.
 */
class RecordingWooPaySessionService extends WooPaymentsWooPaySessionService {

	/**
	 * Whether WooPay is enabled.
	 *
	 * @var bool
	 */
	public bool $woopay_enabled = true;

	/**
	 * Whether the WooPay button should be shown.
	 *
	 * @var bool
	 */
	public bool $should_show_woopay_button = true;

	/**
	 * Whether WooPay save-user assets should be loaded.
	 *
	 * @var bool
	 */
	public bool $should_load_woopay_save_user_assets = true;

	/**
	 * Whether WooPay direct checkout is enabled.
	 *
	 * @var bool
	 */
	public bool $direct_checkout_enabled = false;

	/**
	 * Whether the shopper passes the WooPay guest rule.
	 *
	 * @var bool
	 */
	public bool $guest_rule_passes = true;

	/**
	 * Number of frontend config builds.
	 *
	 * @var int
	 */
	public int $frontend_config_calls = 0;

	/**
	 * Last session email.
	 *
	 * @var string
	 */
	public string $last_session_email = '';

	/**
	 * Last phone request.
	 *
	 * @var array<string,mixed>
	 */
	public array $last_phone_request = array();

	/**
	 * Last appearance.
	 *
	 * @var array<string,mixed>
	 */
	public array $last_appearance = array();

	/**
	 * WooPay appearance response.
	 *
	 * @var array<string,mixed>
	 */
	public array $appearance = array();

	/**
	 * WooPay font rules response.
	 *
	 * @var array<int,array<string,string>>
	 */
	public array $font_rules = array();

	/**
	 * Whether appearance should be stored.
	 *
	 * @var bool
	 */
	public bool $appearance_stored = true;

	/**
	 * Whether WooPay global theme support is enabled.
	 *
	 * @var bool
	 */
	public bool $global_theme_support_enabled = true;

	/**
	 * Number of appearance writes.
	 *
	 * @var int
	 */
	public int $appearance_writes = 0;

	/**
	 * Tell whether WooPay is enabled.
	 *
	 * @return bool
	 */
	public function is_woopay_enabled(): bool {
		return $this->woopay_enabled;
	}

	/**
	 * Tell whether the WooPay button should be shown.
	 *
	 * @param string $context Express checkout context.
	 * @return bool
	 */
	public function should_show_woopay_button( string $context = 'checkout' ): bool {
		unset( $context );

		return $this->should_show_woopay_button;
	}

	/**
	 * Tell whether WooPay save-user assets should load.
	 *
	 * @param string $context Express checkout context.
	 * @return bool
	 */
	public function should_load_woopay_save_user_assets( string $context = 'checkout' ): bool {
		unset( $context );

		return $this->should_load_woopay_save_user_assets;
	}

	/**
	 * Tell whether WooPay direct checkout is enabled.
	 *
	 * @return bool
	 */
	public function is_woopay_direct_checkout_enabled(): bool {
		return $this->direct_checkout_enabled;
	}

	/**
	 * Tell whether WooPay direct checkout runs for the current shopper.
	 *
	 * @return bool
	 */
	public function should_run_woopay_direct_checkout(): bool {
		return $this->direct_checkout_enabled && $this->guest_rule_passes;
	}

	/**
	 * Get WooPay frontend config.
	 *
	 * @param string $context Express checkout context.
	 * @return array<string,mixed>
	 */
	public function get_woopay_frontend_config( string $context = 'checkout' ): array {
		++$this->frontend_config_calls;

		return array(
			'isWooPayEnabled'               => $this->woopay_enabled,
			'shouldShowWooPayButton'        => $this->should_show_woopay_button,
			'isWooPayDirectCheckoutEnabled' => $this->direct_checkout_enabled,
			'forceNetworkSavedCards'        => true,
			'woopaySessionNonce'            => 'woopay-session-nonce',
			'woopayButton'                  => array(
				'type'    => 'default',
				'theme'   => 'dark',
				'height'  => '48',
				'radius'  => '',
				'size'    => 'medium',
				'context' => $context,
			),
		);
	}

	/**
	 * Number of light direct-checkout config builds.
	 *
	 * @var int
	 */
	public int $direct_checkout_config_calls = 0;

	/**
	 * Get the light direct-checkout config.
	 *
	 * @return array<string,mixed>
	 */
	public function get_woopay_direct_checkout_config(): array {
		++$this->direct_checkout_config_calls;

		return array(
			'woopayHost'                    => 'https://pay.woo.com',
			'isWooPayDirectCheckoutEnabled' => $this->direct_checkout_enabled,
			'woopaySessionNonce'            => 'woopay-session-nonce',
			'woopayMinimumSessionData'      => array( 'encrypted' => 'minimum' ),
		);
	}

	/**
	 * Get WooPay save-user checkout data.
	 *
	 * @return array<string,bool|string>
	 */
	public function get_save_user_checkout_data(): array {
		return array(
			'PRE_CHECK_SAVE_MY_INFO'         => true,
			'woopayPhoneValidationScriptUrl' => WooPaymentsFrontendAssets::get_phone_validation_script_url(),
		);
	}

	/**
	 * Get session data.
	 *
	 * @param string|null          $email          Shopper email.
	 * @param WP_REST_Request|null $woopay_request WooPay REST request.
	 * @return array<string,string>
	 */
	public function get_session_data( ?string $email = null, ?WP_REST_Request $woopay_request = null ): array {
		unset( $woopay_request );
		$this->last_session_email = (string) $email;

		return array( 'session' => 'native' );
	}

	/**
	 * Init WooPay.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,string>
	 */
	public function init_woopay_session( array $request ): array {
		return array( 'result' => 'success' );
	}

	/**
	 * Get encrypted session data.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,string>
	 */
	public function get_encrypted_session_data( array $request ): array {
		return array( 'encrypted' => 'session' );
	}

	/**
	 * Store WooPay phone data.
	 *
	 * @param array<string,mixed> $request Request data.
	 */
	public function set_woopay_phone_session_data( array $request ): void {
		$this->last_phone_request = $request;
	}

	/**
	 * Get the WooPay request signature.
	 *
	 * @return string
	 */
	public function get_woopay_request_signature(): string {
		return 'signed';
	}

	/**
	 * Get encrypted minimum session data.
	 *
	 * @return array<string,string>
	 */
	public function get_encrypted_minimum_session_data(): array {
		return array( 'encrypted' => 'minimum' );
	}

	/**
	 * Save WooPay appearance data.
	 *
	 * @param array<string,mixed>             $appearance Appearance data.
	 * @param array<int,array<string,string>> $font_rules Font rules.
	 */
	public function save_woopay_appearance( array $appearance, array $font_rules = array() ): void {
		unset( $font_rules );
		++$this->appearance_writes;
		$this->last_appearance = $appearance;
	}

	/**
	 * Maybe save WooPay appearance data.
	 *
	 * @param array<string,mixed>             $appearance Appearance data.
	 * @param array<int,array<string,string>> $font_rules Font rules.
	 * @return bool
	 */
	public function maybe_save_woopay_appearance( array $appearance, array $font_rules = array() ): bool {
		unset( $font_rules );
		++$this->appearance_writes;
		$this->last_appearance = $appearance;

		return $this->appearance_stored;
	}

	/**
	 * Tell whether WooPay global theme support is enabled.
	 *
	 * @return bool
	 */
	public function is_woopay_global_theme_support_enabled(): bool {
		return $this->global_theme_support_enabled;
	}

	/**
	 * Get WooPay appearance data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_woopay_appearance(): array {
		return $this->appearance;
	}

	/**
	 * Get WooPay font rules.
	 *
	 * @return array<int,array<string,string>>
	 */
	public function get_woopay_font_rules(): array {
		return $this->font_rules;
	}

	/**
	 * Validate WooPay appearance data.
	 *
	 * @param array<string,mixed> $appearance Appearance data.
	 * @return bool
	 */
	public function validate_appearance_schema( array $appearance ): bool {
		return ! isset( $appearance['buttonTheme'] );
	}
}
