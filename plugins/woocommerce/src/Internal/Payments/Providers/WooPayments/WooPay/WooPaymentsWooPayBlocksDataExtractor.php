<?php
/**
 * WooPaymentsWooPayBlocksDataExtractor class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry;
use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;
use Automattic\WooCommerce\StoreApi\StoreApi;
use Throwable;
use WP_Post;

/**
 * Extracts WooPay checkout block extension data.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsWooPayBlocksDataExtractor {

	private const SUPPORTED_BLOCK_INTEGRATIONS = array(
		'AutomateWoo\Blocks\Marketing_Optin_Block',
		'Mailchimp_Woocommerce_Newsletter_Blocks_Integration',
		'WCK\Blocks\CheckoutIntegration',
	);

	/**
	 * Temporary integration registry.
	 *
	 * @var IntegrationRegistry
	 */
	private IntegrationRegistry $integration_registry;

	/**
	 * Create the extractor instance.
	 */
	public function __construct() {
		$this->integration_registry = new IntegrationRegistry();
	}

	/**
	 * Get script data from supported checkout block integrations.
	 *
	 * @return array<string,mixed>
	 */
	public function get_data(): array {
		$blocks = $this->get_available_blocks();

		try {
			$this->register_blocks( $blocks );
			$blocks_data = $this->integration_registry->get_all_registered_script_data();

			if ( $this->can_get_mailpoet_data() ) {
				$blocks_data += array( 'mailpoet_data' => $this->get_mailpoet_data() );
			}

			return $blocks_data;
		} finally {
			$this->unregister_blocks( $blocks );
		}
	}

	/**
	 * Get Store API checkout schema extension namespaces.
	 *
	 * @return string[]
	 */
	public function get_checkout_schema_namespaces(): array {
		if (
			! class_exists( StoreApi::class ) ||
			! class_exists( ExtendSchema::class ) ||
			! class_exists( CheckoutSchema::class )
		) {
			return array();
		}

		try {
			$extend_schema = StoreApi::container()->get( ExtendSchema::class );
			if ( ! $extend_schema instanceof ExtendSchema ) {
				return array();
			}

			return array_keys( (array) $extend_schema->get_endpoint_schema( CheckoutSchema::IDENTIFIER ) );
		} catch ( Throwable $e ) {
			return array();
		}
	}

	/**
	 * Get WooPay status for checkout optional fields.
	 *
	 * @param string $custom_message WooPay custom checkout message.
	 * @return array{company:string,address_2:string,phone:string,terms_checkbox:bool,custom_terms:string}
	 */
	public function get_optional_fields_status( string $custom_message = '' ): array {
		$company                       = $this->get_checkout_field_status_option( 'woocommerce_checkout_company_field', 'optional' );
		$address_2                     = $this->get_checkout_field_status_option( 'woocommerce_checkout_address_2_field', 'optional' );
		$phone                         = $this->get_checkout_field_status_option( 'woocommerce_checkout_phone_field', 'required' );
		$has_terms_and_condition_page  = ! empty( get_option( 'woocommerce_terms_page_id', null ) );
		$terms_and_conditions          = wp_kses_post( wc_replace_policy_page_link_placeholders( wc_get_terms_and_conditions_checkbox_text() ) );
		$has_privacy_policy_page       = ! empty( get_option( 'wp_page_for_privacy_policy', null ) );
		$custom_terms                  = $this->format_custom_terms( $custom_message );
		$below_place_order_button_text = $custom_terms;
		$show_terms_checkbox           = false;

		if ( $has_terms_and_condition_page && '' !== $terms_and_conditions ) {
			$show_terms_checkbox = true;
			if ( '' === $below_place_order_button_text ) {
				$below_place_order_button_text = $terms_and_conditions;
			}
		}

		if ( '' === $below_place_order_button_text && $has_privacy_policy_page ) {
			$show_terms_checkbox           = false;
			$below_place_order_button_text = wp_kses_post( wc_replace_policy_page_link_placeholders( wc_get_privacy_policy_text( 'checkout' ) ) );
		}

		$checkout_block = $this->get_checkout_block();
		if ( null === $checkout_block ) {
			return array(
				'company'        => $company,
				'address_2'      => $address_2,
				'phone'          => $phone,
				'terms_checkbox' => $show_terms_checkbox,
				'custom_terms'   => $below_place_order_button_text,
			);
		}

		$company                       = 'optional';
		$address_2                     = 'optional';
		$phone                         = 'optional';
		$below_place_order_button_text = $custom_terms;
		$checkout_block_attrs          = $this->get_block_attrs( $checkout_block );

		if ( array() !== $checkout_block_attrs ) {
			if ( ! empty( $checkout_block_attrs['requireCompanyField'] ) ) {
				$company = 'required';
			}

			if ( ! empty( $checkout_block_attrs['requirePhoneField'] ) ) {
				$phone = 'required';
			}

			if ( empty( $checkout_block_attrs['showCompanyField'] ) ) {
				$company = 'hidden';
			}

			if ( isset( $checkout_block_attrs['showApartmentField'] ) && false === $checkout_block_attrs['showApartmentField'] ) {
				$address_2 = 'hidden';
			}

			if ( isset( $checkout_block_attrs['showPhoneField'] ) && false === $checkout_block_attrs['showPhoneField'] ) {
				$phone = 'hidden';
			}
		}

		$fields_block                  = $this->get_inner_block( $checkout_block, 'woocommerce/checkout-fields-block' );
		$terms_block                   = $this->get_inner_block( $fields_block, 'woocommerce/checkout-terms-block' );
		$show_terms_checkbox           = false;
		$below_place_order_button_text = '';

		if ( null !== $terms_block ) {
			$terms_block_attrs             = $this->get_block_attrs( $terms_block );
			$show_terms_checkbox           = ! empty( $terms_block_attrs['checkbox'] );
			$below_place_order_button_text = $this->get_blocks_terms_and_conditions_text( $terms_block, $show_terms_checkbox );
		}

		return array(
			'company'        => $company,
			'address_2'      => $address_2,
			'phone'          => $phone,
			'terms_checkbox' => $show_terms_checkbox,
			'custom_terms'   => $below_place_order_button_text,
		);
	}

	/**
	 * Get supported checkout block integrations available on the site.
	 *
	 * @return IntegrationInterface[]
	 */
	private function get_available_blocks(): array {
		$blocks = array();

		foreach ( self::SUPPORTED_BLOCK_INTEGRATIONS as $class_name ) {
			$block = $this->create_integration( $class_name );
			if ( $block instanceof IntegrationInterface ) {
				$blocks[] = $block;
			}
		}

		return $blocks;
	}

	/**
	 * Create an integration instance when the class is available.
	 *
	 * @param string $class_name Integration class name.
	 * @return IntegrationInterface|null
	 */
	private function create_integration( string $class_name ): ?IntegrationInterface {
		if ( ! class_exists( $class_name ) ) {
			return null;
		}

		/**
		 * Integration class name.
		 *
		 * @phpstan-var class-string $class_name
		 */
		$integration = new $class_name();

		return $integration instanceof IntegrationInterface ? $integration : null;
	}

	/**
	 * Register integrations in the temporary registry.
	 *
	 * @param IntegrationInterface[] $blocks Blocks to register.
	 */
	private function register_blocks( array $blocks ): void {
		foreach ( $blocks as $block ) {
			$this->integration_registry->register( $block );
		}
	}

	/**
	 * Unregister integrations from the temporary registry.
	 *
	 * @param IntegrationInterface[] $blocks Blocks to unregister.
	 */
	private function unregister_blocks( array $blocks ): void {
		foreach ( $blocks as $block ) {
			if ( $this->integration_registry->is_registered( $block->get_name() ) ) {
				$this->integration_registry->unregister( $block );
			}
		}
	}

	/**
	 * Tell whether MailPoet checkout data can be extracted.
	 *
	 * @return bool
	 */
	private function can_get_mailpoet_data(): bool {
		return class_exists( '\MailPoet\DI\ContainerWrapper' ) &&
			class_exists( '\MailPoet\WooCommerce\Subscription' ) &&
			class_exists( '\MailPoet\Settings\SettingsController' );
	}

	/**
	 * Get MailPoet checkout block data.
	 *
	 * @return array<string,mixed>
	 */
	private function get_mailpoet_data(): array {
		if ( ! $this->can_get_mailpoet_data() ) {
			return array();
		}

		$container_wrapper_class   = '\MailPoet\DI\ContainerWrapper';
		$subscription_class        = '\MailPoet\WooCommerce\Subscription';
		$settings_controller_class = '\MailPoet\Settings\SettingsController';
		$container_factory         = array( $container_wrapper_class, 'getInstance' );
		$settings_factory          = array( $settings_controller_class, 'getInstance' );

		if ( ! is_callable( $container_factory ) || ! is_callable( $settings_factory ) ) {
			return array();
		}

		$container           = call_user_func( $container_factory );
		$subscription        = is_object( $container ) && is_callable( array( $container, 'get' ) )
			? call_user_func( array( $container, 'get' ), $subscription_class )
			: null;
		$settings_controller = call_user_func( $settings_factory );
		$default_text        = is_object( $settings_controller ) && is_callable( array( $settings_controller, 'get' ) )
			? call_user_func( array( $settings_controller, 'get' ), 'woocommerce.optin_on_checkout.message', '' )
			: '';
		$optin_enabled       = is_object( $settings_controller ) && is_callable( array( $settings_controller, 'get' ) )
			? call_user_func( array( $settings_controller, 'get' ), 'woocommerce.optin_on_checkout.enabled', false )
			: false;
		$settings            = array(
			'defaultText'   => is_scalar( $default_text ) ? (string) $default_text : '',
			'optinEnabled'  => (bool) $optin_enabled,
			'defaultStatus' => false,
		);

		if (
			defined( 'MAILPOET_VERSION' ) &&
			version_compare( (string) constant( 'MAILPOET_VERSION' ), '4.18.0', '<=' ) &&
			is_object( $subscription ) &&
			is_callable( array( $subscription, 'isCurrentUserSubscribed' ) )
		) {
			$settings['defaultStatus'] = (bool) call_user_func( array( $subscription, 'isCurrentUserSubscribed' ) );
		}

		return $settings;
	}

	/**
	 * Get a checkout field status option as a string.
	 *
	 * @param string $option_name    Option name.
	 * @param string $default_status Default status.
	 * @return string
	 */
	private function get_checkout_field_status_option( string $option_name, string $default_status ): string {
		$status = get_option( $option_name, $default_status );

		return is_string( $status ) ? $status : $default_status;
	}

	/**
	 * Replace WooPay custom message terms/privacy placeholders.
	 *
	 * @param string $custom_message WooPay custom message.
	 * @return string
	 */
	private function format_custom_terms( string $custom_message ): string {
		$terms_value          = $this->get_policy_page_link_value( wc_terms_and_conditions_page_id(), __( 'Terms of Service', 'woocommerce' ) );
		$privacy_policy_value = $this->get_policy_page_link_value( wc_privacy_policy_page_id(), __( 'Privacy Policy', 'woocommerce' ) );
		$replacement_map      = array(
			'[terms_of_service_link]' => $terms_value,
			'[terms]'                 => $terms_value,
			'[privacy_policy_link]'   => $privacy_policy_value,
			'[privacy_policy]'        => $privacy_policy_value,
		);

		return str_replace( array_keys( $replacement_map ), array_values( $replacement_map ), $custom_message );
	}

	/**
	 * Get a linked policy page value when a page is configured.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $label   Link label.
	 * @return string
	 */
	private function get_policy_page_link_value( int $page_id, string $label ): string {
		if ( $page_id <= 0 ) {
			return $label;
		}

		$permalink = get_permalink( $page_id );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return $label;
		}

		return '<a href="' . esc_url( $permalink ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Get checkout block terms and conditions text.
	 *
	 * @param array<string,mixed> $terms_block         Checkout terms block.
	 * @param bool                $show_terms_checkbox Whether the terms checkbox is shown.
	 * @return string
	 */
	private function get_blocks_terms_and_conditions_text( array $terms_block, bool $show_terms_checkbox ): string {
		$terms_block_attrs = $this->get_block_attrs( $terms_block );
		if ( ! empty( $terms_block_attrs['text'] ) && is_scalar( $terms_block_attrs['text'] ) ) {
			return (string) $terms_block_attrs['text'];
		}

		$privacy_label     = __( 'Privacy Policy', 'woocommerce' );
		$privacy_page_link = get_privacy_policy_url();
		$privacy_page_link = $privacy_page_link
			? '<a href="' . esc_url( $privacy_page_link ) . '" target="_blank">' . esc_html( $privacy_label ) . '</a>'
			: $privacy_label;

		$terms_label     = __( 'Terms and Conditions', 'woocommerce' );
		$terms_page_id   = wc_terms_and_conditions_page_id();
		$terms_page_link = $terms_page_id ? get_permalink( $terms_page_id ) : '';
		$terms_page_link = is_string( $terms_page_link ) && '' !== $terms_page_link
			? '<a href="' . esc_url( $terms_page_link ) . '" target="_blank">' . esc_html( $terms_label ) . '</a>'
			: $terms_label;

		if ( $show_terms_checkbox ) {
			return sprintf(
				/* translators: %1$s terms page link, %2$s privacy page link. */
				__( 'You must accept our %1$s and %2$s to continue with your purchase.', 'woocommerce' ),
				$terms_page_link,
				$privacy_page_link
			);
		}

		return sprintf(
			/* translators: %1$s terms page link, %2$s privacy page link. */
			__( 'By proceeding with your purchase you agree to our %1$s and %2$s', 'woocommerce' ),
			$terms_page_link,
			$privacy_page_link
		);
	}

	/**
	 * Get the checkout block from the configured checkout page.
	 *
	 * @return array<string,mixed>|null
	 */
	private function get_checkout_block(): ?array {
		$checkout_page_id = absint( get_option( 'woocommerce_checkout_page_id' ) );
		if ( ! $checkout_page_id ) {
			return null;
		}

		$checkout_page = get_post( $checkout_page_id );
		if ( ! $checkout_page instanceof WP_Post ) {
			return null;
		}

		foreach ( parse_blocks( $checkout_page->post_content ) as $block ) {
			if ( is_array( $block ) && 'woocommerce/checkout' === ( $block['blockName'] ?? null ) ) {
				return $block;
			}
		}

		return null;
	}

	/**
	 * Get a child block by name.
	 *
	 * @param array<string,mixed>|null $current_block    Block to search.
	 * @param string                   $inner_block_name Child block name.
	 * @return array<string,mixed>|null
	 */
	private function get_inner_block( ?array $current_block, string $inner_block_name ): ?array {
		if ( null === $current_block || empty( $current_block['innerBlocks'] ) || ! is_array( $current_block['innerBlocks'] ) ) {
			return null;
		}

		foreach ( $current_block['innerBlocks'] as $inner_block ) {
			if ( is_array( $inner_block ) && ( $inner_block['blockName'] ?? null ) === $inner_block_name ) {
				return $inner_block;
			}
		}

		return null;
	}

	/**
	 * Get block attributes.
	 *
	 * @param array<string,mixed> $block Block data.
	 * @return array<string,mixed>
	 */
	private function get_block_attrs( array $block ): array {
		return is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
	}
}
