<?php
/**
 * The Settings module.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings;

use WC_Payment_Gateway;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\PartnerAttribution;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\OnboardingProfile;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\TodosModel;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\RestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Enum\InstallationPathEnum;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Handler\ConnectionListener;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\BrandedExperience\PathRepository;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\GatewayRedirectService;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\LoadingScreenService;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration\MigrationManager;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\PaymentMethodsEligibilityService;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\ScriptDataHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\SellerTypeResolver;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ExecutableModule;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ServiceModule;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\SettingsDataManager;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\MerchantDataResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\DTO\ConfigurationFlagsDTO;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\DTO\MerchantConnectionDTO;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Enum\ProductChoicesEnum;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Enum\SellerTypeEnum;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\PaymentSettings;

/**
 * Class SettingsModule
 */
class SettingsModule implements ServiceModule, ExecutableModule {
	use ModuleClassNameIdTrait;

	/**
	 * {@inheritDoc}
	 */
	public function services(): array {
		return require __DIR__ . '/services.php';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ContainerInterface $container The service container.
	 */
	public function run( ContainerInterface $container ): bool {
		// Suppress WooCommerce Settings UI elements via CSS to improve the loading experience.
		$loading_screen_service = $container->get( 'settings.services.loading-screen-service' );
		assert( $loading_screen_service instanceof LoadingScreenService );
		$loading_screen_service->register();

		add_action( 'init', fn() => $this->apply_branded_only_limitations( $container ), 1 );

		add_action(
			'woocommerce_paypal_payments_gateway_migrate',
			/**
			 * Auto-trigger settings migration to new UI when upgrading from a legacy (pre-4.0) version.
			 *
			 * Migration is skipped when:
			 * - No previous version exists (fresh install — nothing to migrate)
			 * - Previous version is 4.0 or newer (already on the new UI)
			 * - OPTION_NAME_MIGRATION_IS_DONE flag is already set (migration completed previously)
			 */
			static function ( $previous_version ) use ( $container ): void {
				if ( ! $previous_version || version_compare( (string) $previous_version, '4.0', '>=' ) ) {
					return;
				}

				if ( get_option( MigrationManager::OPTION_NAME_MIGRATION_IS_DONE ) === '1' ) {
					return;
				}

				self::pre_populate_credentials( $container );

				$run_migration = static function () use ( $container ): void {
					$migration_manager = $container->get( 'settings.service.data-migration' );
					assert( $migration_manager instanceof MigrationManager );

					$migration_manager->migrate();
				};

				// Timing is important - migration saves gateway options, which triggers WooCommerce
				// hooks that require WC to be fully initialized.
				if ( did_action( 'woocommerce_init' ) ) {
					$run_migration();
				} else {
					add_action( 'woocommerce_init', $run_migration );
				}
			}
		);

		add_action(
			'admin_init',
			function () use ( $container ): void {
				if ( get_option( MigrationManager::OPTION_NAME_MIGRATION_IS_DONE ) === '1' ) {
					return;
				}

				$legacy_settings = (array) get_option( 'woocommerce-ppcp-settings', array() );
				if ( empty( $legacy_settings['client_id'] ) ) {
					return;
				}

				self::pre_populate_credentials( $container );

				$migration_manager = $container->get( 'settings.service.data-migration' );
				assert( $migration_manager instanceof MigrationManager );
				$migration_manager->migrate();

				$migration_done = get_option( MigrationManager::OPTION_NAME_MIGRATION_IS_DONE );

				if ( '1' !== (string) $migration_done ) {
					add_action(
						'admin_notices',
						static function (): void {
							printf(
								'<div class="notice notice-warning"><p>%s</p></div>',
								esc_html__(
									'PayPal Payments: Settings migration could not be completed because the PayPal API is temporarily unavailable. It will retry automatically on the next page load.',
									'woocommerce'
								)
							);
						}
					);
				}
			}
		);

		add_action(
			'admin_enqueue_scripts',
			/**
			 * Param types removed to avoid third-party issues.
			 *
			 * @psalm-suppress MissingClosureParamType
			 */
			function ( $hook_suffix ) use ( $container ): void {
				if ( ! is_string( $hook_suffix ) ) {
					return;
				}

				// The settings app runs on its Payments settings route only.
				if ( ! $container->get( 'wcgateway.is-plugin-settings-page' ) ) {
					return;
				}

				$this->initialize_branded_only( $container );

				$script_data_handler = $container->get( 'settings.service.script-data-handler' );
				assert( $script_data_handler instanceof ScriptDataHandler );

				$script_data_handler->localize_scripts( $hook_suffix );
			}
		);

		add_action(
			'rest_api_init',
			static function () use ( $container ): void {
				$endpoints = array(
					'onboarding'             => $container->get( 'settings.rest.onboarding' ),
					'common'                 => $container->get( 'settings.rest.common' ),
					'connect_manual'         => $container->get( 'settings.rest.authentication' ),
					'login_link'             => $container->get( 'settings.rest.login_link' ),
					'webhooks'               => $container->get( 'settings.rest.webhooks' ),
					'refresh_feature_status' => $container->get( 'settings.rest.refresh_feature_status' ),
					'payment'                => $container->get( 'settings.rest.payment' ),
					'settings'               => $container->get( 'settings.rest.settings' ),
					'styling'                => $container->get( 'settings.rest.styling' ),
					'todos'                  => $container->get( 'settings.rest.todos' ),
					'pay_later_messaging'    => $container->get( 'settings.rest.pay_later_messaging' ),
					'features'               => $container->get( 'settings.rest.features' ),
				);

				foreach ( $endpoints as $endpoint ) {
					assert( $endpoint instanceof RestEndpoint );
					$endpoint->register_routes();
				}
			}
		);

		add_action(
			'admin_init',
			static function () use ( $container ): void {
				$connection_handler = $container->get( 'settings.handler.connection-listener' );
				assert( $connection_handler instanceof ConnectionListener );

				// @phpcs:ignore WordPress.Security.NonceVerification.Recommended -- no nonce; sanitation done by the handler
				$connection_handler->process( get_current_user_id(), $_GET );
			}
		);

		add_action(
			'woocommerce_paypal_payments_merchant_disconnected',
			static function () use ( $container ): void {
				$logger = $container->get( 'woocommerce.logger.woocommerce' );
				assert( $logger instanceof LoggerInterface );
				$logger->info( 'Merchant disconnected, reset onboarding' );

				// Reset onboarding profile.
				$onboarding_profile = $container->get( 'settings.data.onboarding' );
				assert( $onboarding_profile instanceof OnboardingProfile );

				$onboarding_profile->set_completed( false );
				$onboarding_profile->set_step( 0 );
				$onboarding_profile->set_gateways_synced( false );
				$onboarding_profile->set_gateways_refreshed( false );
				$onboarding_profile->save();

				// Reset dismissed and completed on click todos.
				$todos = $container->get( 'settings.data.todos' );
				assert( $todos instanceof TodosModel );
				$todos->reset_dismissed_todos();
				$todos->reset_completed_onclick_todos();
			}
		);

		add_action(
			'woocommerce_paypal_payments_authenticated_merchant',
			static function () use ( $container ): void {
				$logger = $container->get( 'woocommerce.logger.woocommerce' );
				assert( $logger instanceof LoggerInterface );
				$logger->info( 'Merchant connected, complete onboarding and set defaults.' );

				$general_settings = $container->get( 'settings.data.general' );
				assert( $general_settings instanceof GeneralSettings );

				// Resolve an unknown seller type once, at connect time.
				// Clear the stale seller-status cache and failure registry first:
				// fresh credentials warrant a fresh lookup. Only does work when
				// seller_type is 'unknown'.
				$partners_endpoint    = $container->get( 'api.endpoint.partners' );
				$seller_type_resolver = $container->get( 'settings.service.seller-type-resolver' );
				assert( $partners_endpoint instanceof PartnersEndpoint );
				assert( $seller_type_resolver instanceof SellerTypeResolver );

				/**
				 * Clears the APM eligibility flags from the default settings object.
				 *
				 * @since 11.3.0
				 */
				do_action( 'woocommerce_paypal_payments_clear_apm_product_status' );
				$seller_type_resolver->resolve_unknown_seller_type(
					$general_settings,
					$partners_endpoint,
					$logger
				);

				$country_resolver = $container->get( 'settings.service.merchant-data-resolver' );
				assert( $country_resolver instanceof MerchantDataResolver );
				$country_resolver->ensure_country_resolved();

				$onboarding_profile = $container->get( 'settings.data.onboarding' );
				assert( $onboarding_profile instanceof OnboardingProfile );

				$onboarding_profile->set_completed( true );
				$onboarding_profile->save();

				// Try to apply a default configuration for the current store.
				$data_manager = $container->get( 'settings.service.data-manager' );
				assert( $data_manager instanceof SettingsDataManager );

				$flags = new ConfigurationFlagsDTO();

				$flags->country_code       = $general_settings->get_merchant_country();
				$flags->is_business_seller = $general_settings->is_business_seller();
				$flags->use_card_payments  = $onboarding_profile->get_accept_card_payments();
				$flags->use_subscriptions  = in_array( ProductChoicesEnum::SUBSCRIPTIONS, $onboarding_profile->get_products(), true );

				$data_manager->set_defaults_for_new_merchant( $flags );
			}
		);

		add_filter(
			'woocommerce_paypal_payments_payment_methods',
			function ( array $payment_methods ) use ( $container ): array {
				$payment_methods_eligibility_service = $container->get( 'settings.service.payment_methods_eligibilities' );
				assert( $payment_methods_eligibility_service instanceof PaymentMethodsEligibilityService );

				foreach ( $payment_methods_eligibility_service->get_eligibility_checks() as $payment_method_id => $payment_method_check ) {
					if ( isset( $payment_methods[ $payment_method_id ] ) && call_user_func( $payment_method_check ) === false ) {
						unset( $payment_methods[ $payment_method_id ] );
					}
				}

				return $payment_methods;
			}
		);

		add_filter(
			'woocommerce_payment_gateways',
			/**
			 * Param types removed to avoid third-party issues.
			 *
			 * @psalm-suppress MissingClosureParamType
			 */
			function ( $methods ) use ( $container ): array {
				$is_onboarded = $container->get( 'api.merchant_id' ) !== '';
				if ( ! is_array( $methods ) || ! $is_onboarded ) {
					return $methods;
				}

				// Remove gateways where the merchant is not eligible.
				$eligibility_service = $container->get( 'settings.service.payment_methods_eligibilities' );
				$eligibility_checks  = $eligibility_service->get_eligibility_checks();
				$methods             = array_filter(
					$methods,
					static function ( $gateway ) use ( $eligibility_checks ) {
						$id = $gateway instanceof WC_Payment_Gateway ? $gateway->id : '';
						return ! isset( $eligibility_checks[ $id ] ) || $eligibility_checks[ $id ]();
					}
				);

				return $methods;
			},
			99
		);

		/**
		 * Filters the available payment gateways in the WooCommerce admin settings.
		 *
		 * Ensures that only enabled PayPal payment gateways are displayed.
		 *
		 * @hook     woocommerce_admin_field_payment_gateways
		 * @priority 5 Allows modifying the registered gateways before they are displayed.
		 */
		add_action(
			'woocommerce_admin_field_payment_gateways',
			function () use ( $container ): void {
				$all_gateway_ids  = $container->get( 'settings.config.all-gateway-ids' );
				$payment_gateways = WC()->payment_gateways()->payment_gateways;

				foreach ( $payment_gateways as $index => $payment_gateway ) {
					$payment_gateway_id = $payment_gateway->id;

					if (
						! in_array( $payment_gateway_id, $all_gateway_ids, true )
						|| PayPalGateway::ID === $payment_gateway_id
						|| $this->is_gateway_enabled( $payment_gateway_id )
					) {
						continue;
					}

					unset( WC()->payment_gateways()->payment_gateways[ $index ] );
				}
			},
			5
		);

		add_filter(
			'woocommerce_paypal_payments_gateway_title',
			function ( string $title, WC_Payment_Gateway $gateway ) {
				return $gateway->get_option( 'title', $title );
			},
			10,
			2
		);
		add_filter(
			'woocommerce_paypal_payments_gateway_description',
			function ( string $description, WC_Payment_Gateway $gateway ) {
				return $gateway->get_option( 'description', $description );
			},
			10,
			2
		);

		add_filter(
			'woocommerce_paypal_payments_paypal_gateway_icon',
			function ( string $icon_url ) use ( $container ) {
				$payment_settings = $container->get( 'settings.data.payment' );
				assert( $payment_settings instanceof PaymentSettings );

				// If "Show logo" is disabled, return an empty string to hide the icon.
				return $payment_settings->get_paypal_show_logo() ? $icon_url : '';
			}
		);

		// Toggle payment gateways after onboarding based on flags.
		add_action(
			'woocommerce_paypal_payments_sync_gateways',
			static function () use ( $container ) {
				$settings_data_manager = $container->get( 'settings.service.data-manager' );
				assert( $settings_data_manager instanceof SettingsDataManager );
				$settings_data_manager->sync_gateway_settings();
			}
		);

		// Redirect payment method links in the WC Payment Gateway to the new UI Payment Methods tab.
		$gateway_redirect_service = $container->get( 'settings.service.gateway-redirect' );
		assert( $gateway_redirect_service instanceof GatewayRedirectService );
		$gateway_redirect_service->register();

		// Migration code to update BN code of merchants that are on whitelabel mode (own_brand_only false) to use the whitelabel BN code (direct).
		add_action(
			'woocommerce_paypal_payments_gateway_migrate_on_update',
			static function () use ( $container ) {
				$general_settings = $container->get( 'settings.data.general' );
				assert( $general_settings instanceof GeneralSettings );

				$partner_attribution = $container->get( 'api.helper.partner-attribution' );
				assert( $partner_attribution instanceof PartnerAttribution );

				$own_brand_only    = $general_settings->own_brand_only();
				$installation_path = $general_settings->get_installation_path();

				if ( ! $own_brand_only && InstallationPathEnum::DIRECT !== $installation_path ) {
					$partner_attribution->initialize_bn_code( InstallationPathEnum::DIRECT, true );
				}
			}
		);

		// Runs the deferred merchant-country resolution retry (bounded, in-process).
		add_action(
			MerchantDataResolver::RETRY_HOOK,
			static function ( $attempt = 1 ) use ( $container ): void {
				$country_resolver = $container->get( 'settings.service.merchant-data-resolver' );
				assert( $country_resolver instanceof MerchantDataResolver );

				$country_resolver->handle_retry( (int) $attempt );
			}
		);

		/**
		 * Backfill the merchant country for merchants that onboarded before the
		 * resolution fix, whose merchant_country was left empty by the seller-status
		 * back-off. One-shot per upgrade; the resolver is naturally idempotent and
		 * bounded (no-op once the country is set or the merchant is disconnected).
		 */
		add_action(
			'woocommerce_paypal_payments_gateway_migrate_on_update',
			static function () use ( $container ): void {
				$country_resolver = $container->get( 'settings.service.merchant-data-resolver' );
				assert( $country_resolver instanceof MerchantDataResolver );

				$country_resolver->ensure_country_resolved();
			}
		);

		return true;
	}

	/**
	 * Pre-populates GeneralSettings with legacy credentials before migration
	 * DI services are resolved.
	 *
	 * DI services like api.key, api.secret, api.merchant_id are resolved from
	 * SettingsProvider → GeneralSettings → woocommerce-ppcp-data-common.
	 * This option does not exist before migration, so these DI services resolve
	 * to empty strings. Pre-populating ensures PartnersEndpoint has working
	 * credentials during migration so seller_status() API call succeeds.
	 *
	 * @param ContainerInterface $container The DI container.
	 */
	private static function pre_populate_credentials( ContainerInterface $container ): void {
		$general = $container->get( 'settings.data.general' );
		assert( $general instanceof GeneralSettings );

		if ( $general->is_merchant_connected() ) {
			return;
		}

		$legacy = (array) get_option( 'woocommerce-ppcp-settings', array() );
		if ( empty( $legacy['client_id'] ) || empty( $legacy['merchant_id'] ) ) {
			return;
		}

		$general->set_merchant_data(
			new MerchantConnectionDTO(
				! empty( $legacy['sandbox_on'] ),
				$legacy['client_id'],
				$legacy['client_secret'] ?? '',
				$legacy['merchant_id'],
				$legacy['merchant_email'] ?? '',
				'',
				SellerTypeEnum::UNKNOWN
			)
		);
	}

	/**
	 * Checks the branded-only state and applies relevant site-wide feature limitations, if needed.
	 *
	 * @param ContainerInterface $container The DI container provider.
	 *
	 * @return void
	 */
	protected function apply_branded_only_limitations( ContainerInterface $container ): void {
		$settings = $container->get( 'settings.data.general' );
		assert( $settings instanceof GeneralSettings );

		if ( ! $settings->own_brand_only() ) {
			return;
		}

		/**
		 * In branded-only mode, we completely disable all white label features.
		 */
		add_filter( 'woocommerce_paypal_payments_is_eligible_for_save_payment_methods', '__return_false' );
	}

	/**
	 * Initializes the branded-only flags if they are not set.
	 *
	 * This method can be called multiple times:
	 * The flags are only initialized once but does not change afterward.
	 *
	 * Also, this check has no impact on performance for two reasons:
	 * 1. The GeneralSettings class is already initialized and will short-circuit
	 *    the check if the settings are already initialized.
	 * 2. The settings UI is a React app, this method only runs when the React app
	 *    is injected to the DOM, and not while the UI is used.
	 *
	 * @param ContainerInterface $container The DI container provider.
	 *
	 * @return void
	 */
	protected function initialize_branded_only( ContainerInterface $container ): void {
		$path_repository = $container->get( 'settings.service.branded-experience.path-repository' );
		assert( $path_repository instanceof PathRepository );

		$partner_attribution = $container->get( 'api.helper.partner-attribution' );
		assert( $partner_attribution instanceof PartnerAttribution );

		$general_settings = $container->get( 'settings.data.general' );
		assert( $general_settings instanceof GeneralSettings );

		$path_repository->persist();

		$partner_attribution->initialize_bn_code( $general_settings->get_installation_path() );
	}

	/**
	 * Checks if the payment gateway with the given name is enabled.
	 *
	 * @param string $gateway_name The gateway name.
	 *
	 * @return bool True if the payment gateway with the given name is enabled, otherwise false.
	 */
	protected function is_gateway_enabled( string $gateway_name ): bool {
		$gateway_settings = get_option( "woocommerce_{$gateway_name}_settings", array() );
		$gateway_enabled  = $gateway_settings['enabled'] ?? false;

		return 'yes' === $gateway_enabled;
	}
}
