<?php
/**
 * Tests for the legacy PayPal Standard switch-off of the compatibility module.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\CompatModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Connecting PayPal switches PayPal Standard off, unless Subscriptions still bills renewals through it: then the count is
 * kept for an admin notice. All cases are wallet cases.
 *
 * @group paypal-wallet
 */
class DisableLegacyPaypalStandardTest extends WalletTestCase {

	private const STANDARD_OPTION = 'woocommerce_paypal_settings';
	private const RESTORE_OPTION  = 'woocommerce_restore_paypal_standard_settings';
	private const TRANSIENT       = 'ppcp_wps_standard_subs_notice';

	/**
	 * Anonymous subclass that fakes the Subscriptions count and exposes the protected methods.
	 *
	 * @var object
	 */
	private $sut;

	/**
	 * Start with no stored PayPal Standard settings and no pending notice.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( self::STANDARD_OPTION );
		delete_option( self::RESTORE_OPTION );
		delete_transient( self::TRANSIENT );

		$this->sut = new class() extends CompatModule {
			/**
			 * The faked count of active PayPal Standard subscriptions.
			 *
			 * @var int|null
			 */
			private $wcs_count = null;

			/**
			 * Fake the count.
			 *
			 * @param int|null $count The count, or null when Subscriptions is absent.
			 */
			public function set_wcs_count( ?int $count ): void {
				$this->wcs_count = $count;
			}

			/**
			 * The faked count.
			 *
			 * @return int|null
			 */
			protected function count_wps_active_subscriptions(): ?int {
				return $this->wcs_count;
			}

			/**
			 * Run the switch-off.
			 */
			public function call_disable(): void {
				$this->disable_legacy_paypal_standard_on_connect();
			}

			/**
			 * Run the notice and return what it printed.
			 *
			 * @return string
			 */
			public function call_notice(): string {
				ob_start();
				$this->maybe_show_wps_subscriptions_notice();
				return (string) ob_get_clean();
			}
		};
	}

	/**
	 * Delete the options and the transient the tests wrote.
	 */
	public function tearDown(): void {
		delete_option( self::STANDARD_OPTION );
		delete_option( self::RESTORE_OPTION );
		delete_transient( self::TRANSIENT );

		parent::tearDown();
	}

	/**
	 * Counts that allow the switch-off: Subscriptions absent, or no active subscription.
	 *
	 * @return array<string, array{int|null}>
	 */
	public function data_absent_or_empty_counts(): array {
		return array(
			'Subscriptions not installed' => array( null ),
			'Subscriptions has zero'      => array( 0 ),
		);
	}

	/**
	 * Counts that block the switch-off.
	 *
	 * @return array<string, array{int}>
	 */
	public function data_active_subscription_counts(): array {
		return array(
			'one subscription'       => array( 1 ),
			'multiple subscriptions' => array( 5 ),
		);
	}

	/**
	 * Count and the text the notice must contain.
	 *
	 * @return array<string, array{int, string}>
	 */
	public function data_notice_cases(): array {
		return array(
			'singular, 1 subscription' => array( 1, '1 subscription</a> is still billed' ),
			'plural, 5 subscriptions'  => array( 5, '5 subscriptions</a> are still billed' ),
		);
	}

	/**
	 * @testdox Should switch PayPal Standard off, and clear its load latch, when no active subscription depends on it.
	 * @dataProvider data_absent_or_empty_counts
	 *
	 * @param int|null $count The faked count.
	 */
	public function test_disables_standard_when_no_active_subscriptions( ?int $count ): void {
		$this->sut->set_wcs_count( $count );
		update_option(
			self::STANDARD_OPTION,
			array(
				'enabled'      => 'yes',
				'_should_load' => 'yes',
			)
		);

		$this->sut->call_disable();

		$this->assertSame(
			array(
				'enabled'      => 'no',
				'_should_load' => 'no',
			),
			get_option( self::STANDARD_OPTION )
		);
		$this->assertFalse( get_transient( self::TRANSIENT ), 'No notice is queued' );
	}

	/**
	 * @testdox Should keep the count for a notice and leave PayPal Standard on when active subscriptions exist.
	 * @dataProvider data_active_subscription_counts
	 *
	 * @param int $count The faked count.
	 */
	public function test_stores_transient_and_skips_disable_when_subscriptions_exist( int $count ): void {
		$this->sut->set_wcs_count( $count );
		$settings = array(
			'enabled'      => 'yes',
			'_should_load' => 'yes',
		);
		update_option( self::STANDARD_OPTION, $settings );

		$this->sut->call_disable();

		$this->assertSame( $count, (int) get_transient( self::TRANSIENT ) );
		$this->assertSame( $settings, get_option( self::STANDARD_OPTION ), 'PayPal Standard stays on' );
	}

	/**
	 * @testdox Should switch the restoration plugin's PayPal Standard off too when its settings exist.
	 */
	public function test_also_disables_restoration_plugin_when_option_present(): void {
		update_option(
			self::STANDARD_OPTION,
			array(
				'enabled'      => 'yes',
				'_should_load' => 'yes',
			)
		);
		update_option( self::RESTORE_OPTION, array( 'enabled' => 'yes' ) );

		$this->sut->call_disable();

		$this->assertSame( 'no', get_option( self::STANDARD_OPTION )['enabled'] );
		$this->assertSame( array( 'enabled' => 'no' ), get_option( self::RESTORE_OPTION ) );
	}

	/**
	 * @testdox Should print nothing when no notice is queued.
	 */
	public function test_no_output_when_transient_absent(): void {
		$this->assertSame( '', $this->sut->call_notice() );
	}

	/**
	 * @testdox Should print a warning notice with the subscription count in the right singular or plural form.
	 * @dataProvider data_notice_cases
	 *
	 * @param int    $count             The queued count.
	 * @param string $expected_fragment The text the notice must contain.
	 */
	public function test_renders_notice_with_correct_count( int $count, string $expected_fragment ): void {
		set_transient( self::TRANSIENT, $count, DAY_IN_SECONDS );

		$output = $this->sut->call_notice();

		$this->assertStringContainsString( 'notice notice-warning', $output );
		$this->assertStringContainsString( $expected_fragment, $output );
		$this->assertFalse( get_transient( self::TRANSIENT ), 'The notice is shown once' );
	}
}
