<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\CapabilityManifest;
use WC_Unit_Test_Case;

/**
 * Tests for the CapabilityManifest class.
 */
class CapabilityManifestTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Known capabilities include the WooPayments runtime surface categories.
	 */
	public function test_known_capabilities_include_woopayments_surface_categories(): void {
		$capabilities = CapabilityManifest::get_known_capabilities();

		$this->assertContains( CapabilityManifest::CAPABILITY_CARDS, $capabilities );
		$this->assertContains( CapabilityManifest::CAPABILITY_SAVED_TOKENS, $capabilities );
		$this->assertContains( CapabilityManifest::CAPABILITY_REFUNDS, $capabilities );
		$this->assertContains( CapabilityManifest::CAPABILITY_PARTIAL_REFUNDS, $capabilities );
		$this->assertContains( CapabilityManifest::CAPABILITY_DISPUTES, $capabilities );
		$this->assertContains( CapabilityManifest::CAPABILITY_SUBSCRIPTIONS, $capabilities );
		$this->assertContains( CapabilityManifest::CAPABILITY_MULTI_CURRENCY, $capabilities );
		$this->assertContains( CapabilityManifest::CAPABILITY_IN_PERSON, $capabilities );
	}

	/**
	 * @testdox Capability manifests fail closed for unknown or disabled capabilities.
	 */
	public function test_supports_fails_closed_for_unknown_or_disabled_capabilities(): void {
		$manifest = CapabilityManifest::from_array(
			array(
				CapabilityManifest::CAPABILITY_CARDS,
				CapabilityManifest::CAPABILITY_REFUNDS => true,
				CapabilityManifest::CAPABILITY_PARTIAL_REFUNDS => false,
			)
		);

		$this->assertTrue( $manifest->supports( CapabilityManifest::CAPABILITY_CARDS ) );
		$this->assertTrue( $manifest->supports( CapabilityManifest::CAPABILITY_REFUNDS ) );
		$this->assertFalse( $manifest->supports( CapabilityManifest::CAPABILITY_PARTIAL_REFUNDS ) );
		$this->assertFalse( $manifest->supports( 'made_up_capability' ) );
		$this->assertFalse( $manifest->all()['made_up_capability'] ?? false );
	}

	/**
	 * @testdox Extension-defined capabilities remain available through supports and all.
	 */
	public function test_unknown_capabilities_are_preserved(): void {
		$logger        = $this->createMock( \WC_Logger_Interface::class );
		$logger_filter = static fn() => $logger;
		add_filter( 'woocommerce_logging_class', $logger_filter );

		try {
			$enabled_manifest  = CapabilityManifest::from_array( array( 'vendor/enabled' ) );
			$disabled_manifest = new CapabilityManifest( array( 'vendor/disabled' => false ) );
		} finally {
			remove_filter( 'woocommerce_logging_class', $logger_filter );
		}

		$this->assertTrue( $enabled_manifest->supports( 'vendor/enabled' ) );
		$this->assertTrue( $enabled_manifest->all()['vendor/enabled'] );
		$this->assertArrayHasKey( 'vendor/disabled', $disabled_manifest->all() );
		$this->assertFalse( $disabled_manifest->supports( 'vendor/disabled' ) );
	}

	/**
	 * @testdox Unknown capabilities emit one warning per distinct key.
	 */
	public function test_unknown_capabilities_log_once_per_key(): void {
		$warnings = array();
		$logger   = $this->createMock( \WC_Logger_Interface::class );
		$logger->method( 'warning' )->willReturnCallback(
			static function ( $message, $context ) use ( &$warnings ): void {
				$warnings[] = array( $message, $context );
			}
		);
		$logger_filter = static fn() => $logger;
		add_filter( 'woocommerce_logging_class', $logger_filter );

		try {
			CapabilityManifest::from_array( array( 'vendor/logged-once' ) );
			CapabilityManifest::from_array( array( 'vendor/logged-once', 'vendor/also-logged' ) );
		} finally {
			remove_filter( 'woocommerce_logging_class', $logger_filter );
		}

		$this->assertSame(
			array(
				array(
					'Unknown native payments capability "vendor/logged-once" was registered.',
					array(
						'source'     => 'native-payments',
						'capability' => 'vendor/logged-once',
					),
				),
				array(
					'Unknown native payments capability "vendor/also-logged" was registered.',
					array(
						'source'     => 'native-payments',
						'capability' => 'vendor/also-logged',
					),
				),
			),
			$warnings
		);
	}
}
