<?php
/**
 * WooPaymentsWooPayExtensionSync class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;

/**
 * Keeps the store's WooPay compatibility data in step with the platform: once a day it stores which extensions WooPay
 * cannot run with, which it has adapted and which countries it serves, and a plugin's activation or deactivation
 * updates the incompatibility warning.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsWooPayExtensionSync implements RegisterHooksInterface {

	/**
	 * Action Scheduler hook of the daily compatibility sync.
	 *
	 * @var string
	 */
	public const VALIDATE_COMPATIBILITY_ACTION = 'validate_woopay_compatibility';

	/**
	 * A retired WooPay validation hook whose leftover schedules are cleared.
	 *
	 * @var string
	 */
	private const RETIRED_INCOMPATIBLE_EXTENSIONS_ACTION = 'validate_incompatible_extensions';

	/**
	 * Option set while an active plugin is one WooPay cannot run with; it turns on the warning.
	 *
	 * @var string
	 */
	private const INVALID_EXTENSIONS_FOUND_OPTION_NAME = 'woopay_invalid_extension_found';

	/**
	 * Extensions WooPay cannot run with, from the platform.
	 *
	 * @var string
	 */
	private const INCOMPATIBLE_EXTENSIONS_LIST_OPTION_NAME = 'woopay_incompatible_extensions';

	/**
	 * Active extensions WooPay has adapted.
	 *
	 * @var string
	 */
	private const ENABLED_ADAPTED_EXTENSIONS_OPTION_NAME = 'woopay_enabled_adapted_extensions';

	/**
	 * Extensions WooPay has adapted, from the platform.
	 *
	 * @var string
	 */
	private const ADAPTED_EXTENSIONS_LIST_OPTION_NAME = 'woopay_adapted_extensions';

	/**
	 * Countries WooPay is available in, from the platform.
	 *
	 * @var string
	 */
	private const AVAILABLE_COUNTRIES_OPTION_NAME = 'woocommerce_woocommerce_payments_woopay_available_countries';

	/**
	 * WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * WooPayments Action Scheduler service.
	 *
	 * @var WooPaymentsActionSchedulerService
	 */
	private WooPaymentsActionSchedulerService $action_scheduler;

	/**
	 * WooPayments logger.
	 *
	 * @var WooPaymentsLogger
	 */
	private WooPaymentsLogger $logger;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsApiClient              $api_client       WooPayments API client.
	 * @param WooPaymentsActionSchedulerService $action_scheduler WooPayments Action Scheduler service.
	 * @param WooPaymentsLogger                 $logger           WooPayments logger.
	 */
	final public function init( WooPaymentsApiClient $api_client, WooPaymentsActionSchedulerService $action_scheduler, WooPaymentsLogger $logger ): void {
		$this->api_client       = $api_client;
		$this->action_scheduler = $action_scheduler;
		$this->logger           = $logger;
	}

	/**
	 * Register WooPay extension compatibility sync hooks.
	 */
	public function register() {
		add_action( 'action_scheduler_ensure_recurring_actions', array( $this, 'schedule' ) );

		add_action( self::VALIDATE_COMPATIBILITY_ACTION, array( $this, 'update_compatibility_and_maybe_show_incompatibility_warning' ) );

		add_action( 'activated_plugin', array( $this, 'show_warning_when_incompatible_extension_is_enabled' ) );

		add_action( 'deactivated_plugin', array( $this, 'hide_warning_when_incompatible_extension_is_disabled' ) );
	}

	/**
	 * Schedule recurring WooPay compatibility validation.
	 */
	public function schedule(): void {
		$this->action_scheduler->schedule_recurring_job( self::VALIDATE_COMPATIBILITY_ACTION, time(), DAY_IN_SECONDS );
	}

	/**
	 * Remove the retired WooPay incompatible-extension validation schedules.
	 */
	public function remove_retired_validation_schedule(): void {
		if ( wp_next_scheduled( self::RETIRED_INCOMPATIBLE_EXTENSIONS_ACTION ) ) {
			wp_clear_scheduled_hook( self::RETIRED_INCOMPATIBLE_EXTENSIONS_ACTION );
		}

		as_unschedule_all_actions( self::RETIRED_INCOMPATIBLE_EXTENSIONS_ACTION, array(), WooPaymentsActionSchedulerService::GROUP_ID );
	}

	/**
	 * Update WooPay compatibility options and warning state.
	 */
	public function update_compatibility_and_maybe_show_incompatibility_warning(): void {
		try {
			$compatibility           = $this->api_client->get_woopay_compatibility();
			$incompatible_extensions = $this->get_array_value( $compatibility, 'incompatible_extensions' );
			$adapted_extensions      = $this->get_array_value( $compatibility, 'adapted_extensions' );
			$available_countries     = $this->get_array_value( $compatibility, 'available_countries' );
			$active_plugins          = get_option( 'active_plugins', array() );
			$active_plugins          = is_array( $active_plugins ) ? $active_plugins : array();

			update_option( self::INCOMPATIBLE_EXTENSIONS_LIST_OPTION_NAME, $incompatible_extensions );
			delete_option( self::INVALID_EXTENSIONS_FOUND_OPTION_NAME );

			update_option( self::ADAPTED_EXTENSIONS_LIST_OPTION_NAME, $adapted_extensions );
			delete_option( self::ENABLED_ADAPTED_EXTENSIONS_OPTION_NAME );

			if ( count( $this->get_extensions_in_list( $active_plugins, $incompatible_extensions ) ) > 0 ) {
				update_option( self::INVALID_EXTENSIONS_FOUND_OPTION_NAME, true );
			}

			$this->update_enabled_adapted_extensions( $active_plugins, $adapted_extensions );
			$this->update_available_countries( $available_countries );
		} catch ( Throwable $e ) {
			// The platform's message is free text, so the line carries only the failure context; written whatever the logging
			// setting.
			$this->logger->log_throwable_always( 'Failed to update WooPay compatibility data.', $e );
		}
	}

	/**
	 * Update the enabled adapted extensions option.
	 *
	 * @param array<int|string,mixed> $active_plugins     Active plugin paths.
	 * @param array<int|string,mixed> $adapted_extensions Adapted extension slugs.
	 */
	public function update_enabled_adapted_extensions( array $active_plugins, array $adapted_extensions ): void {
		update_option(
			self::ENABLED_ADAPTED_EXTENSIONS_OPTION_NAME,
			$this->get_extensions_in_list( $active_plugins, $adapted_extensions )
		);
	}

	/**
	 * Update the available countries option.
	 *
	 * @param array<int|string,mixed> $available_countries Available country codes.
	 */
	public function update_available_countries( array $available_countries ): void {
		update_option( self::AVAILABLE_COUNTRIES_OPTION_NAME, wp_json_encode( array_values( $available_countries ) ) );
	}

	/**
	 * Show the WooPay incompatibility warning when an incompatible plugin is enabled.
	 *
	 * @param mixed $plugin Plugin basename being activated.
	 */
	public function show_warning_when_incompatible_extension_is_enabled( $plugin ): void {
		if ( ! is_string( $plugin ) ) {
			return;
		}

		$incompatible_extensions = get_option( self::INCOMPATIBLE_EXTENSIONS_LIST_OPTION_NAME, array() );
		$adapted_extensions      = get_option( self::ADAPTED_EXTENSIONS_LIST_OPTION_NAME, array() );
		$active_plugins          = get_option( 'active_plugins', array() );
		$incompatible_extensions = is_array( $incompatible_extensions ) ? $incompatible_extensions : array();
		$adapted_extensions      = is_array( $adapted_extensions ) ? $adapted_extensions : array();
		$active_plugins          = is_array( $active_plugins ) ? $active_plugins : array();
		$plugin                  = $this->format_extension_name( $plugin );

		if ( count( $this->get_extensions_in_list( array( $plugin ), $incompatible_extensions ) ) > 0 ) {
			update_option( self::INVALID_EXTENSIONS_FOUND_OPTION_NAME, true );
		}

		$this->update_enabled_adapted_extensions( $active_plugins, $adapted_extensions );
	}

	/**
	 * Hide the WooPay incompatibility warning when the last incompatible plugin is disabled.
	 *
	 * @param mixed $plugin_being_deactivated Plugin basename being deactivated.
	 */
	public function hide_warning_when_incompatible_extension_is_disabled( $plugin_being_deactivated ): void {
		if ( ! is_string( $plugin_being_deactivated ) ) {
			return;
		}

		$incompatible_extensions = get_option( self::INCOMPATIBLE_EXTENSIONS_LIST_OPTION_NAME, array() );
		$adapted_extensions      = get_option( self::ADAPTED_EXTENSIONS_LIST_OPTION_NAME, array() );
		$active_plugins          = get_option( 'active_plugins', array() );
		$incompatible_extensions = is_array( $incompatible_extensions ) ? $incompatible_extensions : array();
		$adapted_extensions      = is_array( $adapted_extensions ) ? $adapted_extensions : array();
		$active_plugins          = is_array( $active_plugins ) ? $active_plugins : array();
		$active_plugins          = array_values( array_diff( $active_plugins, array( $plugin_being_deactivated ) ) );

		if ( 0 === count( $this->get_extensions_in_list( $active_plugins, $incompatible_extensions ) ) ) {
			delete_option( self::INVALID_EXTENSIONS_FOUND_OPTION_NAME );
		}

		$this->update_enabled_adapted_extensions( $active_plugins, $adapted_extensions );
	}

	/**
	 * Get the active extensions present in a target extension list.
	 *
	 * @param array<int|string,mixed> $active_plugins Active plugin paths.
	 * @param array<int|string,mixed> $extensions     Extension slugs.
	 * @return string[]
	 */
	public function get_extensions_in_list( array $active_plugins, array $extensions ): array {
		$plugins_in_list = array();

		foreach ( $active_plugins as $plugin ) {
			if ( ! is_scalar( $plugin ) ) {
				continue;
			}

			$plugin = $this->format_extension_name( (string) $plugin );

			if ( in_array( $plugin, $extensions, true ) ) {
				$plugins_in_list[] = $plugin;
			}
		}

		return $plugins_in_list;
	}

	/**
	 * Get an array response field.
	 *
	 * @param array<string,mixed> $source Source array.
	 * @param string              $key    Field key.
	 * @return array<int|string,mixed>
	 */
	private function get_array_value( array $source, string $key ): array {
		return isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : array();
	}

	/**
	 * Remove folder and file extension from a plugin basename.
	 *
	 * @param string $plugin Plugin basename or slug.
	 * @return string
	 */
	private function format_extension_name( string $plugin ): string {
		$plugin_parts = explode( '/', $plugin );
		$plugin       = (string) end( $plugin_parts );

		return str_replace( '.php', '', $plugin );
	}
}
