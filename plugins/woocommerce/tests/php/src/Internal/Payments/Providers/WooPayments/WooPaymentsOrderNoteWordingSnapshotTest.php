<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsHtmlUtils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteWordingSnapshot;
use MO;
use PO;
use WC_Unit_Test_Case;
use WP_Locale_Switcher;

/**
 * Tests for the WooPaymentsOrderNoteWordingSnapshot class.
 *
 * Language packs are built from `Fixtures/woocommerce-payments-note-wordings-de.po` into a temporary directory, which the
 * `lang_dir_for_domain` and `pre_get_language_files_from_path` filters put where WordPress looks for the extension's packs.
 */
class WooPaymentsOrderNoteWordingSnapshotTest extends WC_Unit_Test_Case {

	/**
	 * The payment-success wording's English source.
	 */
	private const CHARGED = 'A payment of %1$s was <strong>successfully charged</strong> using %2$s (<a>%3$s</a>).';

	/**
	 * Its German translation in the fixture catalog.
	 */
	private const CHARGED_DE = 'Eine Zahlung von %1$s wurde mit %2$s <strong>erfolgreich belastet</strong> (<a>%3$s</a>).';

	/**
	 * The authorization-expiry wording's English source.
	 */
	private const EXPIRED = 'Payment authorization has <strong>expired</strong> (<a>%1$s</a>).';

	/**
	 * Its German translation in the fixture catalog.
	 */
	private const EXPIRED_DE = 'Die Zahlungsautorisierung ist <strong>abgelaufen</strong> (<a>%1$s</a>).';

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsOrderNoteWordingSnapshot
	 */
	private $sut;

	/**
	 * Temporary directory holding the built language packs.
	 *
	 * @var string
	 */
	private string $language_dir = '';

	/**
	 * The locale switcher in place before the test.
	 *
	 * @var WP_Locale_Switcher
	 */
	private $original_locale_switcher;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_locale_switcher = $GLOBALS['wp_locale_switcher'];
		$this->language_dir             = trailingslashit( get_temp_dir() ) . 'wc-note-wordings-' . wp_generate_password( 8, false ) . '/';
		wp_mkdir_p( $this->language_dir );
		add_filter( 'lang_dir_for_domain', array( $this, 'filter_extension_language_dir' ), 10, 3 );
		add_filter( 'pre_get_language_files_from_path', array( $this, 'filter_language_files' ), 10, 2 );
		unload_textdomain( 'woocommerce-payments', true );
		$this->sut = new WooPaymentsOrderNoteWordingSnapshot();
	}

	/**
	 * Remove the language packs and put the original locale switcher back.
	 */
	public function tearDown(): void {
		try {
			$GLOBALS['wp_locale_switcher'] = $this->original_locale_switcher;
			unload_textdomain( 'woocommerce-payments', true );
			foreach ( (array) glob( $this->language_dir . '*.mo' ) as $file ) {
				wp_delete_file( (string) $file );
			}
			rmdir( $this->language_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Point the extension's text domain at the built language packs.
	 *
	 * @param mixed  $path   Languages directory WordPress found.
	 * @param string $domain Text domain.
	 * @param string $locale Locale.
	 * @return mixed
	 */
	public function filter_extension_language_dir( $path, $domain, $locale ) {
		if ( 'woocommerce-payments' !== $domain ) {
			return $path;
		}

		return file_exists( $this->language_dir . 'woocommerce-payments-' . $locale . '.mo' ) ? $this->language_dir : false;
	}

	/**
	 * List the built language packs as the plugin language packs WordPress has.
	 *
	 * @param mixed  $files Files found so far.
	 * @param string $path  Directory being listed.
	 * @return mixed
	 */
	public function filter_language_files( $files, $path ) {
		return trailingslashit( WP_LANG_DIR ) . 'plugins/' === $path ? (array) glob( $this->language_dir . '*.mo' ) : $files;
	}

	/**
	 * Client 11.1.0 renders its notes through the `woocommerce-payments` text domain, which WordPress loads from the
	 * extension's language pack for the site's locale and, when a note is written while a store manager's locale is in
	 * place, for that locale. Fails when the snapshot stores filled notes or misses the pack of either locale.
	 *
	 * @testdox Should store the extension's translated wordings, placeholders intact, for the site locale and a store manager's locale.
	 */
	public function test_captures_the_site_and_a_store_manager_locale_from_the_language_pack(): void {
		add_filter( 'locale', static fn() => 'de_DE' );
		$this->install_language_pack( 'de_DE' );
		$this->install_language_pack( 'de_CH' );
		$this->make_locales_available( array( 'de_DE', 'de_CH' ) );
		$this->create_store_manager( 'manager_ch', 'de_CH' );

		$this->sut->capture();

		$locales = get_option( WooPaymentsOrderNoteWordingSnapshot::OPTION )['locales'];
		$this->assertSame( array( 'de_DE', 'de_CH' ), array_keys( $locales ) );
		foreach ( $locales as $locale => $entry ) {
			$this->assertSame( 'rendered', $entry['status'], $locale );
			$this->assertSame( self::CHARGED_DE, $entry['strings'][ self::CHARGED ], $locale );
			$this->assertSame( self::EXPIRED_DE, $entry['strings'][ self::EXPIRED ], $locale );
			$this->assertSame( 'Other', $entry['strings']['Other'], 'A wording the pack does not translate is stored in English.' );
		}
		$this->assertSame( 'de_DE', get_locale(), 'The capture restores the locale it switched from.' );
	}

	/**
	 * A locale WordPress has but the extension has no pack for got the extension's English wordings; a locale WordPress
	 * does not have is read from the extension's pack directly, and without one nothing can be rendered. Fails when the
	 * statuses or the stored strings of any of the three cases change.
	 *
	 * @testdox Should record each locale as rendered, English fallback or unavailable, by what the store has for it.
	 */
	public function test_records_english_fallback_and_unavailable_locales(): void {
		$this->install_language_pack( 'nl_NL' );
		$this->make_locales_available( array( 'es_ES' ) );
		$this->create_store_manager( 'manager_es', 'es_ES' );
		$this->create_store_manager( 'manager_nl', 'nl_NL' );
		$this->create_store_manager( 'manager_xx', 'xx_XX' );

		$this->sut->capture();

		$locales = get_option( WooPaymentsOrderNoteWordingSnapshot::OPTION )['locales'];
		$this->assertSame(
			array(
				'en_US' => 'english_fallback',
				'es_ES' => 'english_fallback',
				'nl_NL' => 'rendered',
				'xx_XX' => 'unavailable',
			),
			array_map( static fn( array $entry ): string => $entry['status'], $locales )
		);
		$this->assertSame( self::CHARGED, $locales['es_ES']['strings'][ self::CHARGED ] );
		$this->assertSame( self::CHARGED_DE, $locales['nl_NL']['strings'][ self::CHARGED ] );
		$this->assertSame( array(), $locales['xx_XX']['strings'] );
	}

	/**
	 * Fails when a snapshot over the ceiling is stored whole, drops other than the last locales, or drops the site's.
	 *
	 * @testdox Should drop locales from the end, never the site's, until the snapshot fits the size ceiling: $_dataName.
	 *
	 * @testWith ["one locale too many", -1, ["en_US", "es_ES"], ["it_IT"]]
	 *           ["the site locale alone is too large", 1, ["en_US"], ["es_ES", "it_IT"]]
	 *
	 * @param string   $name            Case label.
	 * @param int      $ceiling         Ceiling in bytes; -1 means one byte under the full snapshot.
	 * @param string[] $expected_kept    Locales kept.
	 * @param string[] $expected_trimmed Locales listed as dropped.
	 */
	public function test_drops_locales_beyond_the_size_ceiling( string $name, int $ceiling, array $expected_kept, array $expected_trimmed ): void {
		unset( $name );
		$this->make_locales_available( array( 'es_ES', 'it_IT' ) );
		$this->create_store_manager( 'manager_es', 'es_ES' );
		$this->create_store_manager( 'manager_it', 'it_IT' );
		$this->sut->capture();
		$full_size = strlen( serialize( get_option( WooPaymentsOrderNoteWordingSnapshot::OPTION ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		delete_option( WooPaymentsOrderNoteWordingSnapshot::OPTION );

		$this->create_snapshot_with_size_ceiling( -1 === $ceiling ? $full_size - 1 : $ceiling )->capture();

		$stored = get_option( WooPaymentsOrderNoteWordingSnapshot::OPTION );
		$this->assertSame( $expected_kept, array_keys( $stored['locales'] ) );
		$this->assertSame( $expected_trimmed, $stored['trimmed'] );
	}

	/**
	 * The write path of the cutover job's step: the attempt is stored before rendering, the snapshot after it, and each
	 * write is retried once. Fails when a write retries more than once, a capture logs more than once, throws, or stops
	 * counting attempts.
	 *
	 * @testdox Should retry each failed write once, log one warning with the size and return normally when every write fails.
	 */
	public function test_failed_write_is_retried_once_and_logged(): void {
		$writes = array();
		add_filter(
			'pre_update_option_' . WooPaymentsOrderNoteWordingSnapshot::OPTION,
			static function ( $value, $old_value ) use ( &$writes ) {
				$writes[] = $value;

				return $old_value;
			},
			10,
			2
		);
		$logger = RecordingWcLogger::install();

		$this->sut->capture();

		$warnings = array_values( array_intersect_key( $logger->contexts, array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] ) ) );
		$this->assertCount( 4, $writes, 'The attempt and the snapshot, each written, then retried once.' );
		$this->assertSame( 1, $writes[0]['attempts']['count'] );
		$this->assertFalse( $writes[0]['attempts']['complete'], 'The attempt is stored before rendering.' );
		$this->assertCount( 1, $warnings );
		$this->assertGreaterThan( 0, $warnings[0]['bytes'] );
		$this->assertFalse( get_option( WooPaymentsOrderNoteWordingSnapshot::OPTION ) );
	}

	/**
	 * A persistent object cache holding an entry for a missing row makes update_option() fail on every write until the
	 * entry is flushed (WordPress Trac #38931). Fails when the flush before the retry is dropped.
	 *
	 * @testdox Should store the snapshot when a stale object-cache entry fails the first write.
	 */
	public function test_a_stale_object_cache_entry_is_flushed_and_the_retry_stores_the_snapshot(): void {
		wp_cache_set( WooPaymentsOrderNoteWordingSnapshot::OPTION, 'stale', 'options' );

		$this->sut->capture();

		$stored = get_option( WooPaymentsOrderNoteWordingSnapshot::OPTION );
		$this->assertIsArray( $stored );
		$this->assertTrue( $stored['attempts']['complete'] );
		$this->assertSame( 'english_fallback', $stored['locales']['en_US']['status'] );
	}

	/**
	 * A capture checks its 20-second budget before each locale; once spent, the remaining locales are left for the next
	 * capture. The clock is the test's. Fails when the budget check is dropped or a capture past it reads as complete.
	 *
	 * @testdox Should leave the locales after an exhausted time budget unavailable and the capture incomplete, then resume them.
	 */
	public function test_an_exhausted_time_budget_leaves_the_rest_for_the_next_capture(): void {
		$this->make_locales_available( array( 'es_ES' ) );
		$this->create_store_manager( 'manager_es', 'es_ES' );

		$this->create_snapshot_with_clock( array( 0.0, 0.0, 21.0 ) )->capture();

		$stored = get_option( WooPaymentsOrderNoteWordingSnapshot::OPTION );
		$this->assertSame( 'english_fallback', $stored['locales']['en_US']['status'] );
		$this->assertSame( 'unavailable', $stored['locales']['es_ES']['status'] );
		$this->assertFalse( $stored['attempts']['complete'] );

		$this->sut->capture();

		$stored = get_option( WooPaymentsOrderNoteWordingSnapshot::OPTION );
		$this->assertSame( 'english_fallback', $stored['locales']['es_ES']['status'] );
		$this->assertSame( 2, $stored['attempts']['count'] );
		$this->assertTrue( $stored['attempts']['complete'] );
	}

	/**
	 * A capture the request ends, here an Error from another plugin's change_locale callback, must still count, so the
	 * switch stops capturing after three attempts, and must not leave the request in the locale it was switching to.
	 * Fails when the attempt is stored only after rendering or the locale is not restored.
	 *
	 * @testdox Should count a capture a change_locale callback ends, restore the site locale, and stop after three attempts.
	 */
	public function test_a_capture_a_locale_callback_ends_counts_and_restores_the_locale(): void {
		$this->make_locales_available( array( 'es_ES' ) );
		$this->create_store_manager( 'manager_es', 'es_ES' );
		$switches = 0;
		add_action(
			'change_locale',
			static function ( $locale ) use ( &$switches ) {
				if ( 'es_ES' === $locale ) {
					++$switches;
					throw new \Error( 'A change_locale callback failed.' );
				}
			}
		);

		foreach ( array( 1, 2, 3 ) as $attempt ) {
			$ended = false;
			try {
				$this->sut->capture();
			} catch ( \Error $error ) {
				$ended = 'A change_locale callback failed.' === $error->getMessage();
			}

			$this->assertTrue( $ended, "Attempt $attempt ends in the callback." );
			$this->assertFalse( is_locale_switched(), "Attempt $attempt leaves no locale switched." );
			$this->assertSame( 'en_US', determine_locale() );
			$this->assertSame( $attempt, get_option( WooPaymentsOrderNoteWordingSnapshot::OPTION )['attempts']['count'] );
		}

		$this->sut->capture();

		$this->assertSame( 3, $switches, 'A fourth capture renders nothing.' );
	}

	/**
	 * Fails when a later capture overwrites a rendered locale or skips one the earlier capture left unavailable.
	 *
	 * @testdox Should resume a capture that ran out of time with the locales not yet rendered, keeping the rendered ones.
	 */
	public function test_resumes_only_the_locales_not_yet_rendered(): void {
		$this->install_language_pack( 'de_DE' );
		$this->make_locales_available( array( 'de_DE', 'es_ES' ) );
		$this->create_store_manager( 'manager_de', 'de_DE' );
		$this->create_store_manager( 'manager_es', 'es_ES' );
		update_option(
			WooPaymentsOrderNoteWordingSnapshot::OPTION,
			array(
				'schema'   => 1,
				'locales'  => array(
					'de_DE' => array(
						'status'  => 'rendered',
						'strings' => array( self::CHARGED => 'kept' ),
					),
					'es_ES' => array(
						'status'  => 'unavailable',
						'strings' => array(),
					),
				),
				'attempts' => array(
					'count'    => 1,
					'last'     => 1,
					'complete' => false,
				),
			),
			false
		);

		$this->sut->capture();

		$stored = get_option( WooPaymentsOrderNoteWordingSnapshot::OPTION );
		$this->assertSame( array( self::CHARGED => 'kept' ), $stored['locales']['de_DE']['strings'] );
		$this->assertSame( 'english_fallback', $stored['locales']['es_ES']['status'] );
		$this->assertSame( 'english_fallback', $stored['locales']['en_US']['status'] );
		$this->assertSame( 2, $stored['attempts']['count'] );
		$this->assertTrue( $stored['attempts']['complete'] );
	}

	/**
	 * Fails when a capture runs after a complete one or a third attempt.
	 *
	 * @testdox Should not capture again after $_dataName.
	 *
	 * @testWith ["a capture that finished", 1, true]
	 *           ["three attempts", 3, false]
	 *
	 * @param string $name     Case label.
	 * @param int    $count    Stored attempts.
	 * @param bool   $complete Whether the last attempt finished.
	 */
	public function test_does_not_capture_again( string $name, int $count, bool $complete ): void {
		unset( $name );
		$stored = array(
			'schema'   => 1,
			'locales'  => array(),
			'attempts' => array(
				'count'    => $count,
				'last'     => 1,
				'complete' => $complete,
			),
		);
		update_option( WooPaymentsOrderNoteWordingSnapshot::OPTION, $stored, false );

		$this->sut->capture();

		$this->assertSame( $stored, get_option( WooPaymentsOrderNoteWordingSnapshot::OPTION ) );
	}

	/**
	 * Client 11.1.0 writes the authorization-expiry note through its text domain with the transaction link
	 * (includes/class-wc-payments-order-service.php:2293-2304); on a German store whose pack is gone the live rendering is
	 * English. Fails when the matcher does not read the snapshot, reads a malformed one, or stops stamping the identity.
	 *
	 * @testdox Should recognise a German note the extension wrote, with no language pack loaded, only through the snapshot: $_dataName.
	 *
	 * @testWith ["German wordings captured", "german", 1]
	 *           ["no snapshot", "none", 2]
	 *           ["a snapshot that is not an array", "malformed", 2]
	 *
	 * @param string $name           Case label.
	 * @param string $snapshot       Stored snapshot: german, none or malformed.
	 * @param int    $expected_notes Expiry notes on the order after two writes.
	 */
	public function test_matcher_recognises_extension_notes_through_the_snapshot( string $name, string $snapshot, int $expected_notes ): void {
		unset( $name );
		if ( 'german' === $snapshot ) {
			update_option(
				WooPaymentsOrderNoteWordingSnapshot::OPTION,
				array(
					'schema'  => 1,
					'locales' => array(
						'de_DE' => array(
							'status'  => 'rendered',
							'strings' => array( self::EXPIRED => self::EXPIRED_DE ),
						),
					),
				),
				false
			);
		} elseif ( 'malformed' === $snapshot ) {
			update_option( WooPaymentsOrderNoteWordingSnapshot::OPTION, 'not a snapshot', false );
		}
		wc_get_container()->replace( WooPaymentsOrderNoteWordingSnapshot::class, new WooPaymentsOrderNoteWordingSnapshot() );
		$notes             = wc_get_container()->get( WooPaymentsOrderNoteService::class );
		$order             = wc_create_order();
		$extension_note    = sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				self::EXPIRED_DE,
				array(
					'strong' => '<strong>',
					'a'      => '<a href="%2$s" target="_blank" rel="noopener noreferrer">',
				)
			),
			'pi_123',
			$notes->transaction_url( 'pi_123', '' )
		);
		$extension_note_id = $order->add_order_note( $extension_note );

		try {
			foreach ( array( 1, 2 ) as $write ) {
				$candidates = $notes->format_capture_expired_note_candidates( 'pi_123', '' );
				$notes->add_note_once( $order, $candidates[0], 'capture_expired:pi_123', $candidates );
			}
		} finally {
			wc_get_container()->reset_all_replacements();
		}

		$expiry_notes = array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), static fn( $note ): bool => str_contains( $note->content, 'pi_123' ) );
		$this->assertCount( $expected_notes, $expiry_notes );
		if ( 1 === $expected_notes ) {
			$this->assertNotSame( '', get_comment_meta( $extension_note_id, '_wc_woopayments_note_identity', true ), 'A note found by its text gets the identity.' );
		}
	}

	/**
	 * Build the fixture catalog into the extension's language pack for a locale.
	 *
	 * @param string $locale Locale.
	 */
	private function install_language_pack( string $locale ): void {
		require_once ABSPATH . WPINC . '/pomo/po.php';
		$po = new PO();
		$po->import_from_file( __DIR__ . '/Fixtures/woocommerce-payments-note-wordings-de.po' );
		$mo = new MO();
		$mo->set_headers( $po->headers );
		foreach ( $po->entries as $entry ) {
			$mo->add_entry( $entry );
		}
		$mo->export_to_file( $this->language_dir . 'woocommerce-payments-' . $locale . '.mo' );
	}

	/**
	 * Make WordPress treat locales as installed, as get_available_languages() reports them to its locale switcher.
	 *
	 * @param string[] $locales Locales.
	 */
	private function make_locales_available( array $locales ): void {
		add_filter( 'get_available_languages', static fn( $languages ) => array_merge( (array) $languages, $locales ) );
		$switcher = new WP_Locale_Switcher();
		$switcher->init();
		$GLOBALS['wp_locale_switcher'] = $switcher;
	}

	/**
	 * Create a store manager with a locale.
	 *
	 * @param string $login  User login.
	 * @param string $locale User locale.
	 */
	private function create_store_manager( string $login, string $locale ): void {
		$this->factory->user->create(
			array(
				'user_login' => $login,
				'role'       => 'shop_manager',
				'locale'     => $locale,
			)
		);
	}

	/**
	 * Create a snapshot with another size ceiling.
	 *
	 * @param int $ceiling Ceiling in bytes.
	 * @return WooPaymentsOrderNoteWordingSnapshot
	 */
	private function create_snapshot_with_size_ceiling( int $ceiling ): WooPaymentsOrderNoteWordingSnapshot {
		return new class( $ceiling ) extends WooPaymentsOrderNoteWordingSnapshot {
			/**
			 * Ceiling in bytes.
			 *
			 * @var int
			 */
			private int $ceiling;

			/**
			 * Constructor.
			 *
			 * @param int $ceiling Ceiling in bytes.
			 */
			public function __construct( int $ceiling ) {
				$this->ceiling = $ceiling;
			}

			/**
			 * Get the ceiling.
			 *
			 * @return int
			 */
			protected function get_size_ceiling(): int {
				return $this->ceiling;
			}
		};
	}

	/**
	 * Create a snapshot whose clock returns the given times, then keeps the last one.
	 *
	 * @param float[] $times Times in seconds, in call order.
	 * @return WooPaymentsOrderNoteWordingSnapshot
	 */
	private function create_snapshot_with_clock( array $times ): WooPaymentsOrderNoteWordingSnapshot {
		return new class( $times ) extends WooPaymentsOrderNoteWordingSnapshot {
			/**
			 * Times still to return.
			 *
			 * @var float[]
			 */
			private array $times;

			/**
			 * Constructor.
			 *
			 * @param float[] $times Times in seconds.
			 */
			public function __construct( array $times ) {
				$this->times = $times;
			}

			/**
			 * Get the next time.
			 *
			 * @return float
			 */
			protected function now(): float {
				return count( $this->times ) > 1 ? array_shift( $this->times ) : $this->times[0];
			}
		};
	}
}
