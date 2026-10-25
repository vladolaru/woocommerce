<?php
/**
 * MultiCurrencyAdminNoteController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRateService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Registers native multi-currency WC Admin notes when core owns multi-currency.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyAdminNoteController implements RegisterHooksInterface {

	private const ADMIN_INIT_HOOK = 'admin_init';
	private const NOTE_NAME       = 'wc-payments-notes-multi-currency-available';
	private const NOTE_SOURCE     = 'woocommerce-payments';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Rate service holding the automatic-rate provider.
	 *
	 * @var MultiCurrencyRateService
	 */
	private MultiCurrencyRateService $rate_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter $arbiter      Runtime owner arbiter.
	 * @param MultiCurrencyRateService    $rate_service Rate service holding the automatic-rate provider.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter, MultiCurrencyRateService $rate_service ): void {
		$this->arbiter      = $arbiter;
		$this->rate_service = $rate_service;
	}

	/**
	 * Register admin note hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() || ! is_admin() ) {
			return;
		}

		add_action( self::ADMIN_INIT_HOOK, array( $this, 'handle_admin_init' ) );
	}

	/**
	 * Handle WC Admin note creation.
	 *
	 * @internal
	 */
	public function handle_admin_init(): void {
		// Like the client, look up the stored note only for a connected provider (NoteMultiCurrencyAvailable.php:64-72).
		if ( wp_doing_ajax() || ! $this->rate_service->is_provider_connected() || false !== Notes::get_note_by_name( self::NOTE_NAME ) ) {
			return;
		}

		$note = new Note();
		$note->set_name( self::NOTE_NAME );
		$note->set_title( __( 'Sell worldwide in multiple currencies', 'woocommerce' ) );
		$note->set_content( __( 'Boost your international sales by allowing your customers to shop and pay in their local currency.', 'woocommerce' ) );
		$note->set_content_data( (object) array() );
		$note->set_type( Note::E_WC_ADMIN_NOTE_INFORMATIONAL );
		$note->set_source( self::NOTE_SOURCE );
		$note->add_action( self::NOTE_NAME, __( 'Set up now', 'woocommerce' ), MultiCurrencySettingsController::get_settings_page_url(), Note::E_WC_ADMIN_NOTE_UNACTIONED, true );

		$note->save();
	}
}
