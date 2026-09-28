<?php
/**
 * WooPaymentsCutoverStateStore tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\WooPaymentsCutoverState;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverStateStore;
use InvalidArgumentException;
use WC_Unit_Test_Case;

/**
 * Tests for WooPaymentsCutoverStateStore.
 */
class WooPaymentsCutoverStateStoreTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsCutoverStateStore|null
	 */
	private ?WooPaymentsCutoverStateStore $sut = null;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( class_exists( WooPaymentsCutoverStateStore::class ) ) {
			$this->sut = new WooPaymentsCutoverStateStore();
			$this->cleanup_options();
		}
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( $this->sut instanceof WooPaymentsCutoverStateStore ) {
			$this->cleanup_options();
		}

		parent::tearDown();
	}

	/**
	 * @testdox Persists only complete valid records in the site-local autoloaded option.
	 */
	public function test_save_record_persists_a_valid_autoloaded_record(): void {
		$sut    = $this->require_sut();
		$record = $this->valid_record();

		$this->assertTrue( $sut->save_record( $record ), 'A valid cutover record should persist.' );

		$this->assertSame( $record, $sut->get_record(), 'The validated record should round-trip without changing its fields.' );
		$this->assertArrayHasKey( WooPaymentsCutoverStateStore::OPTION_NAME, wp_load_alloptions( true ), 'The cutover state should be available through the per-site autoloaded option set.' );
	}

	/**
	 * @testdox The install-seeded absent marker reads as no record without a query, and the first save replaces it.
	 */
	public function test_first_save_replaces_the_absent_marker(): void {
		global $wpdb;
		$sut    = $this->require_sut();
		$record = $this->valid_record();
		add_option( WooPaymentsCutoverStateStore::OPTION_NAME, 'none', '', true ); // What WC_Install::create_options() seeds.
		wp_load_alloptions( true );

		$queries = $wpdb->num_queries;
		$this->assertNull( $sut->get_record() );
		$this->assertSame( 0, $wpdb->num_queries - $queries, 'A never-cutover store must read the record from the autoloaded options.' );
		$this->assertSame( 1, $sut->get_next_generation() );

		$this->assertTrue( $sut->save_record( $record ), 'The first record should replace the absent marker.' );
		$this->assertSame( $record, $sut->get_record() );
		$this->assertArrayHasKey( WooPaymentsCutoverStateStore::OPTION_NAME, wp_load_alloptions( true ) );
	}

	/**
	 * @testdox A save that loses the race for the absent marker fails and keeps the winner's record.
	 */
	public function test_first_save_over_the_absent_marker_fails_when_another_writer_wins(): void {
		global $wpdb;
		$sut                 = $this->require_sut();
		$winner              = $this->valid_record();
		$loser               = $winner;
		$loser['generation'] = $winner['generation'] + 1;
		add_option( WooPaymentsCutoverStateStore::OPTION_NAME, 'none', '', true ); // What WC_Install::create_options() seeds.
		$race = function ( $query ) use ( &$race, $wpdb, $winner ) {
			if ( 0 === stripos( ltrim( $query ), 'UPDATE' ) && false !== strpos( $query, WooPaymentsCutoverStateStore::OPTION_NAME ) ) {
				remove_filter( 'query', $race );
				$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $winner ) ), array( 'option_name' => WooPaymentsCutoverStateStore::OPTION_NAME ) );
			}
			return $query;
		};
		add_filter( 'query', $race );

		try {
			$this->assertFalse( $sut->save_record( $loser ), 'The losing writer must see a conflict.' );
		} finally {
			remove_filter( 'query', $race );
		}

		$this->assertSame( $winner, $sut->get_record(), 'The winning record must stay in place.' );
	}

	/**
	 * @testdox WC_Install::create_options() seeds the cutover marker in a sub-site's own options table and keeps a record written there after a missed read.
	 * @group multisite
	 */
	public function test_create_options_seeds_the_cutover_marker_per_site(): void {
		global $wpdb;
		$this->skipWithoutMultisite();
		$name    = WooPaymentsCutoverStateStore::OPTION_NAME;
		$record  = maybe_serialize( array( 'state' => 'pending' ) );
		$blog_id = self::factory()->blog->create();
		update_option( $name, 'none', true );

		try {
			switch_to_blog( $blog_id );
			// An upgrading sub-site, so create_options() skips the new-install setup the bare test blog lacks tables for.
			update_option( 'woocommerce_version', WC()->version );
			update_option( 'woocommerce_coming_soon', 'no' );
			$this->assertFalse( get_option( $name ) );
			$wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => $name,
					'option_value' => $record,
					'autoload'     => 'on',
				)
			);

			$this->invoke_install_create_options();

			$this->assertSame( $record, $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ) );
			$this->assertSame( array( 'state' => 'pending' ), get_option( $name ) );
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );

			$this->invoke_install_create_options();

			$this->assertSame( 'none', get_option( $name ), 'A sub-site without a record should get its own marker.' );
			restore_current_blog();
			$this->assertSame( 'none', get_option( $name ), 'The main site keeps its own marker.' );
		} finally {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
			delete_option( $name );
			wpmu_delete_blog( $blog_id, true );
		}
	}

	/**
	 * Invoke the install-only WC_Install::create_options() seam.
	 */
	private function invoke_install_create_options(): void {
		$create_options = function (): void {
			static::create_options();
		};
		$create_options->call( new \WC_Install() );
	}

	/**
	 * @testdox Rejects records whose state is outside the persisted cutover vocabulary.
	 */
	public function test_save_record_rejects_an_invalid_state(): void {
		$sut             = $this->require_sut();
		$record          = $this->valid_record();
		$record['state'] = 'unknown';

		$this->expectException( InvalidArgumentException::class );

		$sut->save_record( $record );
	}

	/**
	 * @testdox Accepts every durable cutover state.
	 * @testWith ["pending"]
	 *           ["running"]
	 *           ["deferred"]
	 *           ["done"]
	 *           ["excluded"]
	 *
	 * @param string $state Persisted cutover state.
	 */
	public function test_save_record_accepts_each_durable_state( string $state ): void {
		$sut             = $this->require_sut();
		$record          = $this->valid_record();
		$record['state'] = $state;
		if ( WooPaymentsCutoverState::RUNNING === $state ) {
			$record['lease_token']      = 'running-worker-token';
			$record['lease_expires_at'] = $record['updated_at'] + WooPaymentsCutoverStateStore::LEASE_TTL;
		}

		$this->assertTrue( $sut->save_record( $record ) );
		$this->assertSame( $state, $sut->get_record()['state'] ?? null );
	}

	/**
	 * @testdox Treats incomplete or malformed persisted data as unavailable state.
	 */
	public function test_get_record_rejects_malformed_persisted_data(): void {
		$sut = $this->require_sut();
		add_option(
			WooPaymentsCutoverStateStore::OPTION_NAME,
			array(
				'schema_version' => WooPaymentsCutoverStateStore::SCHEMA_VERSION,
				'state'          => WooPaymentsCutoverState::PENDING,
			),
			'',
			true
		);

		$this->assertNull( $sut->get_record(), 'A partial state record must not drive reconciliation.' );
	}

	/**
	 * @testdox A live lease excludes another worker and an expired lease can be recovered.
	 */
	public function test_lease_excludes_concurrent_workers_and_recovers_after_expiry(): void {
		$sut         = $this->require_sut();
		$now         = 1_700_000_000;
		$first_token = $sut->acquire_lease( $now );

		$this->assertIsString( $first_token, 'The first worker should acquire the site-local lease.' );
		$this->assertNotSame( '', $first_token, 'The lease token should identify its owner.' );
		$this->assertNull( $sut->acquire_lease( $now + 1 ), 'A live lease should exclude a concurrent worker.' );

		$recovered_token = $sut->acquire_lease( $now + WooPaymentsCutoverStateStore::LEASE_TTL + 1 );

		$this->assertIsString( $recovered_token, 'A worker should recover an expired lease.' );
		$this->assertNotSame( $first_token, $recovered_token, 'Recovery should create a new lease owner token.' );
	}

	/**
	 * @testdox Releasing a stale token cannot remove the current worker's lease.
	 */
	public function test_release_lease_ignores_a_stale_owner_token(): void {
		$sut         = $this->require_sut();
		$now         = 1_700_000_000;
		$first_token = $sut->acquire_lease( $now );
		$this->assertIsString( $first_token );
		$second_token = $sut->acquire_lease( $now + WooPaymentsCutoverStateStore::LEASE_TTL + 1 );
		$this->assertIsString( $second_token );

		$sut->release_lease( $first_token );

		$this->assertNull( $sut->acquire_lease( $now + WooPaymentsCutoverStateStore::LEASE_TTL + 2 ), 'A stale release must leave the current lease intact.' );
		$sut->release_lease( $second_token );
		$this->assertIsString( $sut->acquire_lease( $now + WooPaymentsCutoverStateStore::LEASE_TTL + 2 ), 'The current owner should be able to release its lease.' );
	}

	/**
	 * @testdox An external fenced terminal transition prevents a stale worker from completing its claim.
	 */
	public function test_compare_and_set_rejects_a_stale_post_claim_write(): void {
		$sut     = $this->require_sut();
		$pending = $this->valid_record();
		$this->assertTrue( $sut->save_record( $pending ) );

		$claimed                     = $pending;
		$claimed['revision']         = $pending['revision'] + 1;
		$claimed['state']            = WooPaymentsCutoverState::RUNNING;
		$claimed['attempt']          = $pending['attempt'] + 1;
		$claimed['lease_token']      = 'worker-claim-token';
		$claimed['lease_expires_at'] = $pending['updated_at'] + WooPaymentsCutoverStateStore::LEASE_TTL;
		$this->assertTrue( $sut->compare_and_set_record( $pending, $claimed ), 'The worker should atomically claim the expected pending revision.' );

		$excluded                     = $claimed;
		$excluded['revision']         = $claimed['revision'] + 1;
		$excluded['state']            = WooPaymentsCutoverState::EXCLUDED;
		$excluded['attempt']          = $claimed['attempt'] + 1;
		$excluded['lease_token']      = null;
		$excluded['lease_expires_at'] = null;
		$this->assertTrue( $sut->compare_and_set_record( $claimed, $excluded ), 'The coordinator should revoke the claim with a newer terminal revision.' );

		$stale_completion                     = $claimed;
		$stale_completion['revision']         = $claimed['revision'] + 1;
		$stale_completion['state']            = WooPaymentsCutoverState::DONE;
		$stale_completion['lease_token']      = null;
		$stale_completion['lease_expires_at'] = null;
		$this->assertFalse( $sut->compare_and_set_record( $claimed, $stale_completion ), 'The stale worker must not overwrite the fenced terminal state.' );
		$this->assertSame( $excluded, $sut->get_record() );
	}

	/**
	 * @testdox Compare-and-set requires the exact serialized option bytes.
	 */
	public function test_compare_and_set_rejects_a_case_only_mismatch(): void {
		$sut                            = $this->require_sut();
		$stored                         = $this->valid_record();
		$stored['request_origin_token'] = 'Opaque-Token';
		$this->assertTrue( $sut->save_record( $stored ) );

		$incorrect                         = $stored;
		$incorrect['request_origin_token'] = 'opaque-token';
		$replacement                       = $incorrect;
		$replacement['revision']           = $incorrect['revision'] + 1;

		$this->assertFalse( $sut->compare_and_set_record( $incorrect, $replacement ) );
		$this->assertSame( $stored, $sut->get_record() );
	}

	/**
	 * @testdox Compare-and-set requires exactly the next monotonic revision.
	 */
	public function test_compare_and_set_rejects_a_non_monotonic_revision(): void {
		$sut         = $this->require_sut();
		$current     = $this->valid_record();
		$replacement = $current;
		$this->assertTrue( $sut->save_record( $current ) );

		$this->assertFalse( $sut->compare_and_set_record( $current, $replacement ) );
		$this->assertSame( $current, $sut->get_record() );
	}

	/**
	 * Require the state store after the initial class-existence red assertion.
	 *
	 * @return WooPaymentsCutoverStateStore
	 */
	private function require_sut(): WooPaymentsCutoverStateStore {
		$this->assertTrue( class_exists( WooPaymentsCutoverStateStore::class ), 'The cutover state store has not been implemented yet.' );
		$this->assertInstanceOf( WooPaymentsCutoverStateStore::class, $this->sut, 'The cutover state store has not been implemented yet.' );

		return $this->sut;
	}

	/**
	 * Build a complete valid state record.
	 *
	 * @return array<string,mixed>
	 */
	private function valid_record(): array {
		return array(
			'schema_version'         => 1,
			'generation'             => 2,
			'revision'               => 1,
			'state'                  => WooPaymentsCutoverState::DEFERRED,
			'started_at'             => 1_700_000_000,
			'updated_at'             => 1_700_000_100,
			'attempt'                => 3,
			'action_id'              => 42,
			'current_step'           => 'deferred',
			'step_log'               => array(
				array(
					'step' => 'deferred',
					'at'   => 1_700_000_100,
				),
			),
			'deferred_codes'         => array( 'native_transport_unavailable' ),
			'informational_outcomes' => array(),
			'next_attempt_at'        => 1_700_001_000,
			'lease_token'            => null,
			'lease_expires_at'       => null,
		);
	}

	/**
	 * Delete the store's site-local options.
	 */
	private function cleanup_options(): void {
		delete_option( WooPaymentsCutoverStateStore::OPTION_NAME );
		delete_option( WooPaymentsCutoverStateStore::LEASE_OPTION_NAME );
	}
}
