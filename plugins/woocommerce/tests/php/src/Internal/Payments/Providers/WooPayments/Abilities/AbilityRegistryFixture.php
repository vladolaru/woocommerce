<?php
/**
 * Isolate the WordPress abilities registry for one request.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities;

use Automattic\WooCommerce\Internal\Abilities\AbilitiesCategories;
use Automattic\WooCommerce\Internal\Abilities\AbilitiesLoader;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\AbilitiesRegistrar;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Proxies\LegacyProxy;

/** Isolate registry discovery and select the effective provider root. */
trait AbilityRegistryFixture {
	/**
	 * Registry slots and their original values.
	 *
	 * @var array
	 */
	private $registry_state = array();
	/**
	 * Action counts before this request.
	 *
	 * @var array
	 */
	private $actions;
	/**
	 * Caller before this request.
	 *
	 * @var int
	 */
	private $user_id;
	/**
	 * Whether to restore the previous locale.
	 *
	 * @var bool
	 */
	private $locale_switched;

	/** Start a fresh request after WordPress init and before registry discovery. */
	protected function set_up_ability_registry(): void {
		$this->actions         = $GLOBALS['wp_actions'];
		$this->user_id         = get_current_user_id();
		$this->locale_switched = switch_to_locale( 'en_US' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$GLOBALS['wp_actions']['init'] = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate registry access after init.

		foreach ( array(
			\WP_Abilities_Registry::class          => 'instance',
			\WP_Ability_Categories_Registry::class => 'instance',
			AbilitiesLoader::class                 => 'registered_core_abilities',
		) as $class => $property ) {
			$slot = new \ReflectionProperty( $class, $property );
			$slot->setAccessible( true );
			$this->registry_state[] = array( $slot, $slot->getValue() );
			$slot->setValue( null, 'instance' === $property ? null : array() );
		}
		AbilitiesCategories::init();
		add_action( 'wp_abilities_api_init', array( AbilitiesLoader::class, 'register_abilities' ) );
	}

	/** Restore the original registries, action counts, locale and caller. */
	protected function restore_ability_registry(): void {
		foreach ( $this->registry_state as list( $slot, $value ) ) {
			$slot->setValue( null, $value );
		}
		$GLOBALS['wp_actions'] = $this->actions; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the previous request.
		if ( $this->locale_switched ) {
			restore_previous_locale();
		}
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Install the ability root only if the effective tier selects it for this request.
	 *
	 * @param string $stored_tier      Stored setup tier.
	 * @param bool   $extension_active Whether the extension is active.
	 */
	protected function install_root( string $stored_tier = WooPaymentsSetupTier::CONNECTED, bool $extension_active = false ): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( WooPaymentsSetupTier::OPTION_NAME, $stored_tier );
		update_option( 'active_plugins', $extension_active ? array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) : array() );
		delete_site_option( 'active_sitewide_plugins' );
		$arbiter = new WooPaymentsRuntimeArbiter();
		$arbiter->init( wc_get_container()->get( LegacyProxy::class ) );
		$tier = new WooPaymentsSetupTier();
		$tier->init( $arbiter );
		foreach ( $tier->get_classes_for_request( 'cli' ) as $root ) {
			if ( AbilitiesRegistrar::class === $root ) {
				wc_get_container()->get( $root )->register();
			}
		}
	}
}
