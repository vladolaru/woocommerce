<?php
/**
 * Tests for the settings REST endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsModel;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\SettingsRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\DataSanitizer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WP_REST_Request;

/**
 * The invoice prefix becomes part of the identifiers the wallet sends to PayPal (the purchase unit's invoice ID, the
 * refund fallback and the vault customer ID), so update_details() rejects unsafe characters before anything is stored.
 * This is a backstop for callers that bypass the settings UI, such as the REST API directly or a Blueprint import.
 *
 * The cases run over the real settings model and its stored option.
 *
 * @group paypal-wallet
 */
class SettingsRestEndpointTest extends WalletTestCase {

	private const OPTION = 'woocommerce-ppcp-data-settings';

	/**
	 * Start from a stored option that already holds a prefix, so a rejected request can be told from one that did not
	 * run.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->set_wallet_option( self::OPTION, array( 'invoice_prefix' => 'OLD-' ) );
	}

	/**
	 * Build the endpoint over the stored option as it is now.
	 *
	 * @return SettingsRestEndpoint
	 */
	private function create_endpoint(): SettingsRestEndpoint {
		return new SettingsRestEndpoint( new SettingsModel( new DataSanitizer(), 'wc-' ) );
	}

	/**
	 * Post the given parameters to the update callback.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @return array The response data.
	 */
	private function post( array $params ): array {
		$request = new WP_REST_Request( 'POST', '/wc/v3/wc_paypal/settings' );
		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}

		return $this->create_endpoint()->update_details( $request )->get_data();
	}

	/**
	 * The stored settings.
	 *
	 * @return array
	 */
	private function stored(): array {
		$stored = get_option( self::OPTION );
		$this->assertIsArray( $stored );

		return $stored;
	}

	/**
	 * Prefixes with a character the identifiers cannot carry.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function data_invalid_invoice_prefixes(): array {
		return array(
			'a trailing space is rejected'  => array( 'WC ' ),
			'an internal space is rejected' => array( 'WC Store' ),
			'an at-sign is rejected'        => array( 'WC@1' ),
			'a slash is rejected'           => array( 'WC/1' ),
		);
	}

	/**
	 * Given a request whose invoicePrefix contains a character outside letters, numbers, hyphens and underscores, the
	 * response reports the failure and nothing is stored.
	 *
	 * @testdox Should reject an invoice prefix with an unsafe character and store nothing.
	 *
	 * @dataProvider data_invalid_invoice_prefixes
	 *
	 * @param string $invalid_prefix The prefix.
	 */
	public function test_invalid_invoice_prefix_is_rejected_without_persisting( string $invalid_prefix ): void {
		$data = $this->post(
			array(
				'invoicePrefix' => $invalid_prefix,
				'brandName'     => 'Must not be stored',
			)
		);

		$this->assertFalse( $data['success'] );
		$this->assertSame( 'The invoice prefix may only contain letters, numbers, hyphens and underscores.', $data['message'] );
		$this->assertSame( array( 'invoice_prefix' => 'OLD-' ), $this->stored(), 'A rejected request must not touch the stored settings' );
	}

	/**
	 * Prefixes made of letters, numbers, hyphens and underscores.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function data_valid_invoice_prefixes(): array {
		return array(
			'a hyphen suffix is valid'         => array( 'WC-' ),
			'an underscore separator is valid' => array( 'Store_1' ),
			'plain alphanumerics are valid'    => array( 'abc123' ),
		);
	}

	/**
	 * Given a request whose invoicePrefix only has letters, numbers, hyphens or underscores, the response reports
	 * success, the prefix is stored and the response carries the stored settings.
	 *
	 * @testdox Should accept and store an invoice prefix of letters, numbers, hyphens and underscores.
	 *
	 * @dataProvider data_valid_invoice_prefixes
	 *
	 * @param string $valid_prefix The prefix.
	 */
	public function test_valid_invoice_prefix_is_accepted_and_saved( string $valid_prefix ): void {
		$data = $this->post( array( 'invoicePrefix' => $valid_prefix ) );

		$this->assertTrue( $data['success'] );
		$this->assertSame( $valid_prefix, $data['data']['invoicePrefix'] );
		$this->assertSame( $valid_prefix, $this->stored()['invoice_prefix'] );
	}

	/**
	 * Given an empty invoicePrefix, the update succeeds and stores the empty prefix, because the consumers fall back to
	 * a default one.
	 *
	 * @testdox Should accept and store an empty invoice prefix.
	 */
	public function test_empty_invoice_prefix_is_valid_and_saved(): void {
		$data = $this->post( array( 'invoicePrefix' => '' ) );

		$this->assertTrue( $data['success'] );
		$this->assertSame( '', $this->stored()['invoice_prefix'] );
	}

	/**
	 * Given a request without an invoicePrefix, the update runs as usual and leaves the stored prefix alone.
	 *
	 * @testdox Should save the other settings of a request that carries no invoice prefix and keep the stored prefix.
	 */
	public function test_missing_invoice_prefix_is_unaffected_and_saves_normally(): void {
		$data = $this->post( array( 'brandName' => 'Acme' ) );

		$this->assertTrue( $data['success'] );
		$this->assertSame( 'Acme', $this->stored()['brand_name'] );
		$this->assertSame( 'OLD-', $this->stored()['invoice_prefix'] );
		$this->assertSame( 'Acme', $data['data']['brandName'] );
	}

	/**
	 * @testdox Should convert the flag settings of a request to booleans and ignore keys it does not know.
	 */
	public function test_flags_are_converted_to_booleans_and_unknown_keys_are_ignored(): void {
		$this->post(
			array(
				'authorizeOnly'     => '1',
				'enableLogging'     => 1,
				'stayUpdated'       => 0,
				'notASettingOfOurs' => 'ignored',
			)
		);

		$stored = $this->stored();
		$this->assertTrue( $stored['authorize_only'] );
		$this->assertTrue( $stored['enable_logging'] );
		$this->assertFalse( $stored['stay_updated'] );
		$this->assertArrayNotHasKey( 'notASettingOfOurs', $stored );
	}

	/**
	 * @testdox Should return the stored settings under their JavaScript names.
	 */
	public function test_get_details_returns_the_settings_under_their_javascript_names(): void {
		$this->set_wallet_option(
			self::OPTION,
			array(
				'brand_name'     => 'Acme',
				'authorize_only' => true,
			)
		);

		$data = $this->create_endpoint()->get_details()->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 'Acme', $data['data']['brandName'] );
		$this->assertTrue( $data['data']['authorizeOnly'] );
		$this->assertArrayNotHasKey( 'brand_name', $data['data'] );
	}

	/**
	 * Roles and whether they may use the endpoints of the settings module, which all share one permission check.
	 *
	 * @return array<string, array{0: string|null, 1: bool}>
	 */
	public function data_roles(): array {
		return array(
			'an administrator may' => array( 'administrator', true ),
			'a shop manager may'   => array( 'shop_manager', true ),
			'a customer may not'   => array( 'customer', false ),
			'a visitor may not'    => array( null, false ),
		);
	}

	/**
	 * @testdox Should allow the settings endpoints only to users who can manage WooCommerce.
	 *
	 * @dataProvider data_roles
	 *
	 * @param string|null $role     The role of the current user, or null for a visitor.
	 * @param bool        $expected Whether the permission check passes.
	 */
	public function test_permission_requires_manage_woocommerce( ?string $role, bool $expected ): void {
		wp_set_current_user( null === $role ? 0 : self::factory()->user->create( array( 'role' => $role ) ) );

		$this->assertSame( $expected, $this->create_endpoint()->check_permission() );
	}
}
