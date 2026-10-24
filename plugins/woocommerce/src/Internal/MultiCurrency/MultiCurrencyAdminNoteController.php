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
	 * Admin request resolver.
	 *
	 * @var callable|null
	 */
	private $admin_request_resolver = null;

	/**
	 * Ajax request resolver.
	 *
	 * @var callable|null
	 */
	private $ajax_request_resolver = null;

	/**
	 * Provider connected resolver.
	 *
	 * @var callable|null
	 */
	private $provider_connected_resolver = null;

	/**
	 * Note can-be-added resolver.
	 *
	 * @var callable|null
	 */
	private $note_can_be_added_resolver = null;

	/**
	 * Note saver.
	 *
	 * @var callable|null
	 */
	private $note_saver = null;

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
	 * Set the admin request resolver.
	 *
	 * @internal Used by tests.
	 *
	 * @param callable $admin_request_resolver Resolver returning whether the request is admin.
	 */
	public function set_admin_request_resolver( callable $admin_request_resolver ): void {
		$this->admin_request_resolver = $admin_request_resolver;
	}

	/**
	 * Set the Ajax request resolver.
	 *
	 * @internal Used by tests.
	 *
	 * @param callable $ajax_request_resolver Resolver returning whether the request is Ajax.
	 */
	public function set_ajax_request_resolver( callable $ajax_request_resolver ): void {
		$this->ajax_request_resolver = $ajax_request_resolver;
	}

	/**
	 * Set the provider connected resolver.
	 *
	 * @internal Used by tests.
	 *
	 * @param callable $provider_connected_resolver Resolver returning whether the provider account is connected.
	 */
	public function set_provider_connected_resolver( callable $provider_connected_resolver ): void {
		$this->provider_connected_resolver = $provider_connected_resolver;
	}

	/**
	 * Set the note can-be-added resolver.
	 *
	 * @internal Used by tests.
	 *
	 * @param callable $note_can_be_added_resolver Resolver returning whether the note can be added.
	 */
	public function set_note_can_be_added_resolver( callable $note_can_be_added_resolver ): void {
		$this->note_can_be_added_resolver = $note_can_be_added_resolver;
	}

	/**
	 * Set the note saver.
	 *
	 * @internal Used by tests.
	 *
	 * @param callable $note_saver Note saver accepting the built note.
	 */
	public function set_note_saver( callable $note_saver ): void {
		$this->note_saver = $note_saver;
	}

	/**
	 * Register admin note hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_core_register() || ! $this->is_admin_request() ) {
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
		if ( $this->is_ajax_request() || ! $this->is_provider_connected() || ! $this->can_note_be_added() ) {
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

		if ( null !== $this->note_saver ) {
			call_user_func( $this->note_saver, $note );
			return;
		}

		$note->save();
	}

	/**
	 * Tell whether the request is an admin request.
	 *
	 * @return bool
	 */
	private function is_admin_request(): bool {
		if ( null !== $this->admin_request_resolver ) {
			return (bool) call_user_func( $this->admin_request_resolver );
		}

		return is_admin();
	}

	/**
	 * Tell whether the request is an Ajax request.
	 *
	 * @return bool
	 */
	private function is_ajax_request(): bool {
		if ( null !== $this->ajax_request_resolver ) {
			return (bool) call_user_func( $this->ajax_request_resolver );
		}

		return wp_doing_ajax();
	}

	/**
	 * Tell whether the provider account is connected.
	 *
	 * @return bool
	 */
	private function is_provider_connected(): bool {
		if ( null !== $this->provider_connected_resolver ) {
			return (bool) call_user_func( $this->provider_connected_resolver );
		}

		return $this->rate_service->is_provider_connected();
	}

	/**
	 * Tell whether the note can be added.
	 *
	 * @return bool
	 */
	private function can_note_be_added(): bool {
		if ( null !== $this->note_can_be_added_resolver ) {
			return (bool) call_user_func( $this->note_can_be_added_resolver );
		}

		return false === Notes::get_note_by_name( self::NOTE_NAME );
	}
}
