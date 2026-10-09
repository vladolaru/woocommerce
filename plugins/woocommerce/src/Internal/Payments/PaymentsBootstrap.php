<?php
/**
 * PaymentsBootstrap class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use Automattic\WooCommerce\Container;
use Automattic\WooCommerce\Internal\DependencyManagement\RuntimeContainer;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyBootstrap;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Registers what WooCommerce's built-in payment provider needs on the current request, and starts Multi-Currency.
 * Payment extensions add their gateways through `woocommerce_payment_gateways`; this bootstrap loads only the payment
 * provider built into WooCommerce.
 *
 * A listed class that is a payment gateway provider is handed to ProviderGatewaysController, which resolves it only when
 * WooCommerce builds its gateway list; it is register()ed as well only when it also registers hooks. Every other listed
 * class is resolved and register()ed.
 *
 * @since 11.2.0
 * @internal
 */
final class PaymentsBootstrap {

	/** Filter that decides whether WooCommerce loads its built-in payment provider on this request. */
	public const FILTER_LOAD_PAYMENT_PROVIDERS = 'woocommerce_load_payment_providers';

	/**
	 * Lists the classes a request type registers for the built-in payment provider.
	 *
	 * @var callable
	 * @phpstan-var callable(Container|RuntimeContainer, string): array<int,class-string>
	 */
	private $classes_for_request;

	/**
	 * Tells whether the built-in payment provider's gateways belong in the gateway list now.
	 *
	 * @var callable
	 * @phpstan-var callable(Container|RuntimeContainer): bool
	 */
	private $should_register_gateways;

	/**
	 * Lists the Multi-Currency classes the built-in payment provider contributes.
	 *
	 * @var callable
	 * @phpstan-var callable(): array<int,class-string>
	 */
	private $multi_currency_classes;

	/**
	 * Container for the cron classes this request registers when Action Scheduler first runs an action.
	 *
	 * @var Container|RuntimeContainer|null
	 */
	private $on_demand_container = null;

	/**
	 * Cron classes this request lacks, registered when Action Scheduler first runs an action.
	 *
	 * @var array<int,class-string>
	 */
	private array $on_demand_cron_classes = array();

	/**
	 * Create the bootstrap for WooCommerce's built-in payment provider.
	 *
	 * WooCommerce::init_hooks() builds these inputs as closures, so the bootstrap names no provider, and building it loads
	 * no provider class and makes no WC() call while WooCommerce is still being constructed. They give the classes each
	 * request type registers, in order and empty when nothing is set up; whether the gateways belong in the gateway list,
	 * which the bootstrap only hands to ProviderGatewaysController; and the Multi-Currency classes the provider contributes.
	 *
	 * @param callable $classes_for_request      Lists the classes a request type registers.
	 * @param callable $should_register_gateways Tells whether the provider's gateways belong in the gateway list now.
	 * @param callable $multi_currency_classes   Lists the provider's Multi-Currency classes.
	 * @phpstan-param callable(Container|RuntimeContainer, string): array<int,class-string> $classes_for_request
	 * @phpstan-param callable(Container|RuntimeContainer): bool $should_register_gateways
	 * @phpstan-param callable(): array<int,class-string> $multi_currency_classes
	 */
	public function __construct( callable $classes_for_request, callable $should_register_gateways, callable $multi_currency_classes ) {
		$this->classes_for_request      = $classes_for_request;
		$this->should_register_gateways = $should_register_gateways;
		$this->multi_currency_classes   = $multi_currency_classes;
	}

	/**
	 * Register the integrations needed for the current request.
	 *
	 * WooCommerce passes its Container; the RuntimeContainer type is for tests, which extend it because Container is final.
	 *
	 * @since 11.2.0
	 *
	 * @param Container|RuntimeContainer $container           Runtime dependency container.
	 * @param callable                   $is_rest_api_request Whether the current request is a REST request.
	 */
	public function register( $container, callable $is_rest_api_request ): void {
		/**
		 * Whether WooCommerce loads its built-in payment provider and the Multi-Currency integration it starts on this request.
		 * It is applied while WooCommerce loads, so the callback must be added before WooCommerce loads, from a mu-plugin or a
		 * plugin that loads earlier; returning false loads neither the built-in payment provider nor the Multi-Currency it starts.
		 *
		 * @since 11.2.0
		 *
		 * @param bool $load Whether to load the built-in payment provider and Multi-Currency.
		 */
		if ( ! apply_filters( self::FILTER_LOAD_PAYMENT_PROVIDERS, true ) ) {
			return;
		}

		( new MultiCurrencyBootstrap( $this->multi_currency_classes ) )->register( $container, $is_rest_api_request );

		$request_type = MultiCurrencyBootstrap::classify_request( $is_rest_api_request );
		$classes      = ( $this->classes_for_request )( $container, $request_type );

		$this->register_classes( $container, $classes );
		$this->register_cron_classes_on_demand( $container, $request_type, $classes );
	}

	/**
	 * Classes load per request type, so a front or admin request that runs a scheduled action registers the cron classes
	 * it lacks just before the action runs.
	 *
	 * ALTERNATE_WP_CRON and the Tools > Scheduled Actions "Run" link run actions inside a front or admin request.
	 * WP-CLI requests list the cron classes themselves, so they need no listener.
	 * The client attaches its scheduled-action handlers on every request (client 11.1.0 `includes/class-wc-payments.php:603,657`).
	 *
	 * @param Container|RuntimeContainer $container    Runtime dependency container.
	 * @param string                     $request_type Request type.
	 * @param array<int,class-string>    $registered   Classes already registered for this request.
	 */
	private function register_cron_classes_on_demand( $container, string $request_type, array $registered ): void {
		if ( 'cron' === $request_type || 'cli' === $request_type ) {
			return;
		}

		$missing = self::classes_missing_from( ( $this->classes_for_request )( $container, 'cron' ), $registered );
		if ( empty( $missing ) ) {
			return;
		}

		$this->on_demand_container    = $container;
		$this->on_demand_cron_classes = $missing;
		// Action Scheduler fires this before it checks the action has callbacks and runs it (`ActionScheduler_Abstract_QueueRunner::process_action()`).
		add_action( 'action_scheduler_before_execute', array( $this, 'handle_action_scheduler_before_execute' ), 0 );
	}

	/**
	 * Register the missing cron classes before Action Scheduler runs the first action of this request.
	 *
	 * @internal
	 */
	public function handle_action_scheduler_before_execute(): void {
		remove_action( 'action_scheduler_before_execute', array( $this, 'handle_action_scheduler_before_execute' ), 0 );
		$classes                      = $this->on_demand_cron_classes;
		$this->on_demand_cron_classes = array();
		if ( empty( $classes ) || null === $this->on_demand_container ) {
			return;
		}

		$this->register_classes( $this->on_demand_container, $classes );
	}

	/**
	 * Get the classes not yet registered.
	 *
	 * @param array<int,class-string> $classes    Class names in registration order.
	 * @param array<int,class-string> $registered Classes already registered.
	 * @return array<int,class-string> Missing classes in registration order.
	 */
	private static function classes_missing_from( array $classes, array $registered ): array {
		return array_values( array_diff( $classes, $registered ) );
	}

	/**
	 * Resolve and register the listed classes once, handing payment gateway providers to ProviderGatewaysController.
	 *
	 * @param Container|RuntimeContainer $container Runtime dependency container.
	 * @param array<int,class-string>    $classes   Class names.
	 */
	private function register_classes( $container, array $classes ): void {
		foreach ( $classes as $class_name ) {
			if ( is_a( $class_name, PaymentGatewayProviderInterface::class, true ) ) {
				$this->add_gateway_provider( $container, $class_name );
				if ( ! is_a( $class_name, RegisterHooksInterface::class, true ) ) {
					continue;
				}
			}

			$this->register_class( $container, $class_name );
		}
	}

	/**
	 * Hand a payment gateway provider to ProviderGatewaysController, resolved only when WooCommerce builds its gateway list.
	 *
	 * @param Container|RuntimeContainer $container      Runtime dependency container.
	 * @param class-string               $provider_class Payment gateway provider class name.
	 */
	private function add_gateway_provider( $container, string $provider_class ): void {
		/**
		 * Provider gateways controller.
		 *
		 * @var ProviderGatewaysController $gateways_controller
		 */
		$gateways_controller = $container->get( ProviderGatewaysController::class );
		$gateways_controller->set_provider(
			static function () use ( $container, $provider_class ) {
				/**
				 * Payment gateway provider.
				 *
				 * @var PaymentGatewayProviderInterface $provider
				 */
				$provider = $container->get( $provider_class );
				return $provider;
			},
			fn(): bool => ( $this->should_register_gateways )( $container )
		);
		$gateways_controller->register();
	}

	/**
	 * Resolve and register one listed class.
	 *
	 * @param Container|RuntimeContainer $container  Runtime dependency container.
	 * @param class-string               $class_name Class name.
	 */
	private function register_class( $container, string $class_name ): void {
		/**
		 * Registrar.
		 *
		 * @var RegisterHooksInterface $registrar
		 */
		$registrar = $container->get( $class_name );
		$registrar->register();
	}
}
