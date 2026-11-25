<?php
/**
 * MultiCurrencyAdminNoteController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Admin\Notes\DataStore;
use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Admin\Notes\NotesUnavailableException;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RateService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Adds the "Sell worldwide in multiple currencies" admin note when it is not stored yet, while WooCommerce runs
 * Multi-Currency and an automatic-rate provider is connected.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencyAdminNoteController implements RegisterHooksInterface {

	/**
	 * The stored name of the note, the WooPayments extension's.
	 */
	public const NOTE_NAME = 'wc-payments-notes-multi-currency-available';

	private const ADMIN_INIT_HOOK = 'admin_init';
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
	 * @var RateService
	 */
	private RateService $rate_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter $arbiter      Runtime owner arbiter.
	 * @param RateService                 $rate_service Rate service holding the automatic-rate provider.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter, RateService $rate_service ): void {
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
	 * Remove WooCommerce's copies of the shared note. The extension uses the same name but links to its setup wizard.
	 *
	 * @since 11.4.0
	 *
	 * @param callable|null $claim On takeover, claim the per-site transition after capturing the outgoing notes.
	 * @phpstan-param (callable(): bool)|null $claim
	 */
	public static function remove_builtin_notes( ?callable $claim = null ): void {
		// Bootstrap runs before WC Admin registers its data store. Even on a new site the tables exist by wp_loaded, before admin_init.
		if ( ! did_action( 'wp_loaded' ) ) {
			$blog_id = get_current_blog_id();
			add_action(
				'wp_loaded',
				static function () use ( $claim, $blog_id ) {
					if ( get_current_blog_id() === $blog_id ) {
						self::remove_builtin_notes( $claim );
						return;
					}
					switch_to_blog( $blog_id );
					try {
						self::remove_builtin_notes( $claim );
					} finally {
						restore_current_blog();
					}
				}
			);
			return;
		}

		$notes = self::get_builtin_notes();
		if ( null !== $claim && ! $claim() ) {
			return;
		}
		foreach ( $notes as $note ) {
			$note->delete();
		}
	}

	/**
	 * Find outgoing copies by their settings button, including copies saved before a change of site URL.
	 *
	 * @return Note[]
	 */
	private static function get_builtin_notes(): array {
		try {
			/**
			 * Admin-note data store behind the WC_Data_Store proxy.
			 *
			 * @var DataStore $data_store
			 */
			$data_store = Notes::load_data_store();
		} catch ( NotesUnavailableException $e ) {
			// An unavailable inbox must not prevent the payments owner from handing Multi-Currency over.
			return array();
		}

		$notes       = array();
		$setup_query = 'page=wc-settings&tab=' . MultiCurrencySettingsController::TAB_ID;
		foreach ( $data_store->get_notes_with_name( self::NOTE_NAME ) as $id ) {
			$note = Notes::get_note( $id );
			if ( ! $note instanceof Note ) {
				continue;
			}
			$url = $note->get_action( self::NOTE_NAME, 'edit' )->query ?? '';
			if ( wp_parse_url( $url, PHP_URL_QUERY ) === $setup_query && 'admin.php' === wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) {
				$notes[] = $note;
			}
		}
		return $notes;
	}

	/**
	 * Handle WC Admin note creation.
	 *
	 * @internal
	 */
	public function handle_admin_init(): void {
		// Like the client, look up the stored note only for a connected provider (NoteMultiCurrencyAvailable.php:64-72).
		if ( wp_doing_ajax() || ! $this->arbiter->is_builtin_owner() || ! $this->rate_service->is_provider_connected() || false !== Notes::get_note_by_name( self::NOTE_NAME ) ) {
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
