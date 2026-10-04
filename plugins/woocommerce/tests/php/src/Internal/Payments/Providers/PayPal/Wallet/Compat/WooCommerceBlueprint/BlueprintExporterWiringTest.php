<?php
/**
 * Tests for the way the Blueprint exporters are wired in the compatibility module.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat\WooCommerceBlueprint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat\WooCommerceBlueprint;

use Automattic\WooCommerce\Blueprint\Exporters\StepExporter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\CompatModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\WooCommerceBlueprint\PayPalSettingsExporter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\WooCommerceBlueprint\PayPalSettingsImporter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\DataSanitizer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * The Blueprint exporters as the compatibility module wires them, not as a test builds them by hand.
 *
 * The exporter tests construct `new PayPalSettingsExporter( $sanitizer, $flag )` themselves, so they keep passing when
 * both container entries are registered with the same flag. That mistake either stops the opt-in export from carrying
 * the credentials, or makes every export carry them, and nothing else would notice.
 *
 * @group paypal-wallet
 */
class BlueprintExporterWiringTest extends WalletTestCase {

	private const CREDENTIAL_KEYS = array(
		'client_id',
		'client_secret',
		'merchant_id',
		'merchant_email',
	);

	/**
	 * Skip the whole class when the Blueprint package is not on this branch, and store a connected merchant.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! interface_exists( StepExporter::class ) ) {
			$this->markTestSkipped( 'The Blueprint class ' . StepExporter::class . ' is not available.' );
		}

		$this->set_wallet_option(
			'woocommerce-ppcp-data-common',
			array(
				'client_id'      => 'client-id-value',
				'client_secret'  => 'client-secret-value',
				'merchant_id'    => 'MERCHANT123',
				'merchant_email' => 'merchant@example.com',
			)
		);
	}

	/**
	 * Resolve a service through the module's own service definitions, so the closures under test are the production ones.
	 *
	 * @param string $id The service ID.
	 * @return mixed
	 */
	private function resolve( string $id ) {
		$services  = ( new CompatModule() )->services();
		$container = $this->mock( ContainerInterface::class );
		$container->shouldReceive( 'get' )->andReturnUsing(
			static function ( string $requested ) use ( $services, $container ) {
				if ( 'settings.service.sanitizer' === $requested ) {
					return new DataSanitizer();
				}

				return $services[ $requested ]( $container );
			}
		);

		$this->assertArrayHasKey( $id, $services );

		return $services[ $id ]( $container );
	}

	/**
	 * The stored common option as an exporter exports it.
	 *
	 * @param PayPalSettingsExporter $exporter The exporter.
	 * @return array<string, mixed>
	 */
	private function exported_common( PayPalSettingsExporter $exporter ): array {
		return $exporter->export()->prepare_json_array()['options']['woocommerce-ppcp-data-common'];
	}

	/**
	 * @testdox Should strip the connection credentials in the default exporter service.
	 */
	public function test_the_default_service_strips_credentials(): void {
		$common = $this->exported_common( $this->resolve( 'compat.blueprint.paypal_settings_exporter' ) );

		foreach ( self::CREDENTIAL_KEYS as $key ) {
			$this->assertSame( '', $common[ $key ], "$key should be stripped by the default service" );
		}
	}

	/**
	 * @testdox Should keep the connection credentials in the opt-in exporter service.
	 */
	public function test_the_opt_in_service_keeps_credentials(): void {
		$common = $this->exported_common( $this->resolve( 'compat.blueprint.paypal_settings_exporter_with_connection' ) );

		foreach ( self::CREDENTIAL_KEYS as $key ) {
			$this->assertNotSame( '', $common[ $key ], "$key should survive the opt-in service" );
		}
	}

	/**
	 * The assertion that fails when both container entries are given the same flag.
	 *
	 * @testdox Should wire the two exporter services with opposite flags.
	 */
	public function test_the_two_services_are_wired_with_opposite_flags(): void {
		$default = $this->resolve( 'compat.blueprint.paypal_settings_exporter' );
		$opt_in  = $this->resolve( 'compat.blueprint.paypal_settings_exporter_with_connection' );

		$this->assertSame( PayPalSettingsExporter::ALIAS, $default->get_alias() );
		$this->assertSame( PayPalSettingsExporter::ALIAS_WITH_CONNECTION, $opt_in->get_alias() );
		$this->assertNotEquals(
			$this->exported_common( $default ),
			$this->exported_common( $opt_in ),
			'Both services produced the same payload, so they are wired with the same flag.'
		);
	}

	/**
	 * @testdox Should register both exporters through the bootstrap.
	 */
	public function test_the_bootstrap_registers_both_exporters(): void {
		$bootstrap = $this->resolve( 'compat.blueprint.bootstrap' );

		$exporters = $bootstrap->register_exporters( array() );

		$aliases = array_map(
			static function ( $exporter ): string {
				return $exporter->get_alias();
			},
			$exporters
		);
		$this->assertContains( PayPalSettingsExporter::ALIAS, $aliases );
		$this->assertContains( PayPalSettingsExporter::ALIAS_WITH_CONNECTION, $aliases );
		$this->assertCount( 2, $aliases );
	}

	/**
	 * @testdox Should keep the exporters other plugins registered.
	 */
	public function test_the_bootstrap_preserves_exporters_from_other_plugins(): void {
		$bootstrap = $this->resolve( 'compat.blueprint.bootstrap' );
		$foreign   = $this->mock( StepExporter::class );

		$exporters = $bootstrap->register_exporters( array( $foreign ) );

		$this->assertContains( $foreign, $exporters );
		$this->assertCount( 3, $exporters );
	}

	/**
	 * Blueprint itself and other plugins use the same filters, so the case counts what init() adds.
	 *
	 * @testdox Should add the two exporters and the importer to the Blueprint filters on init.
	 */
	public function test_the_bootstrap_hooks_the_blueprint_filters_on_init(): void {
		$bootstrap = $this->resolve( 'compat.blueprint.bootstrap' );
		// phpcs:disable WooCommerce.Commenting.CommentHooks.MissingHookComment
		$exporters_before = count( apply_filters( 'wooblueprint_exporters', array() ) );
		$importers_before = count( apply_filters( 'wooblueprint_importers', array() ) );

		$bootstrap->init();

		$exporters = apply_filters( 'wooblueprint_exporters', array() );
		$importers = apply_filters( 'wooblueprint_importers', array() );
		// phpcs:enable WooCommerce.Commenting.CommentHooks.MissingHookComment
		$this->assertCount( $exporters_before + 2, $exporters );
		$this->assertCount( $importers_before + 1, $importers );
		$this->assertInstanceOf( PayPalSettingsExporter::class, $exporters[ $exporters_before ] );
		$this->assertInstanceOf( PayPalSettingsExporter::class, $exporters[ $exporters_before + 1 ] );
		$this->assertInstanceOf( PayPalSettingsImporter::class, $importers[ $importers_before ] );
	}
}
