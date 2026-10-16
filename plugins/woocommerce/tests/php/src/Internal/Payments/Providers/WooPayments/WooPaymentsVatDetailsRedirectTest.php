<?php
/**
 * WooPaymentsVatDetailsRedirect tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsVatDetailsRedirect;
use WC_Unit_Test_Case;

/**
 * Tests for the VAT details link redirect.
 */
class WooPaymentsVatDetailsRedirectTest extends WC_Unit_Test_Case {

	/**
	 * The system under test.
	 *
	 * @var WooPaymentsVatDetailsRedirect
	 */
	private WooPaymentsVatDetailsRedirect $sut;

	/**
	 * The intercepted redirect location.
	 *
	 * @var string
	 */
	private string $redirect = '';

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new WooPaymentsVatDetailsRedirect();
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		unset( $_GET['woopayments-vat-details-redirect'] );
		remove_action( 'template_redirect', array( $this->sut, 'handle_template_redirect' ), 1 );
		remove_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );
		parent::tearDown();
	}

	/**
	 * Stop the request at the redirect.
	 *
	 * @param string $location Redirect location.
	 * @throws \RuntimeException Always, so the redirect's exit is never reached.
	 */
	public function intercept_redirect( $location ): void {
		$this->redirect = (string) $location;
		throw new \RuntimeException( 'wp_redirect intercepted' );
	}

	/**
	 * @testdox Loads on front-end requests of a connected or active store, where template_redirect fires.
	 */
	public function test_loads_on_front_end_requests_of_a_connected_store(): void {
		$matrix = WooPaymentsProvider::get_classes_by_setup_tier();

		foreach ( array( WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ) as $state ) {
			$this->assertContains( WooPaymentsVatDetailsRedirect::class, $matrix[ $state ]['front'], $state );
		}
		$this->assertNotContains( WooPaymentsVatDetailsRedirect::class, $matrix[ WooPaymentsSetupTier::AVAILABLE ]['front'] ?? array(), 'A store without an account has no VAT details to show.' );
	}

	/**
	 * @testdox Sends a VAT details link to the WooPayments settings with the VAT details modal open.
	 */
	public function test_redirects_the_vat_details_link_to_the_settings_modal(): void {
		$this->sut->register();
		$this->assertSame( 1, has_action( 'template_redirect', array( $this->sut, 'handle_template_redirect' ) ) );
		$_GET['woopayments-vat-details-redirect'] = '1';

		try {
			do_action( 'template_redirect' );
			$this->fail( 'Expected a redirect.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'wp_redirect intercepted', $exception->getMessage() );
		}

		parse_str( (string) wp_parse_url( $this->redirect, PHP_URL_QUERY ), $query );
		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout', $this->redirect );
		$this->assertSame( '/woopayments/settings', $query['path'] );
		$this->assertSame( 'true', $query['woopayments-vat-details-modal'] );
	}

	/**
	 * @testdox Leaves a request alone when it lacks the query arg or is an AJAX request.
	 * @dataProvider provide_ignored_requests
	 *
	 * @param bool $has_query_arg Whether the request carries the VAT details query arg.
	 * @param bool $doing_ajax    Whether the request is an AJAX request.
	 */
	public function test_ignores_requests( bool $has_query_arg, bool $doing_ajax ): void {
		if ( $has_query_arg ) {
			$_GET['woopayments-vat-details-redirect'] = '1';
		}
		if ( $doing_ajax ) {
			add_filter( 'wp_doing_ajax', '__return_true' );
		}

		$this->sut->handle_template_redirect();

		remove_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertSame( '', $this->redirect );
	}

	/**
	 * Requests the redirect leaves alone.
	 *
	 * @return array<string,array{bool,bool}>
	 */
	public static function provide_ignored_requests(): array {
		return array(
			'without the query arg'           => array( false, false ),
			'AJAX request with the query arg' => array( true, true ),
		);
	}
}
