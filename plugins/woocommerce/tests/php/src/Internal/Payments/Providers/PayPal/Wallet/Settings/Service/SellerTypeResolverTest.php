<?php
/**
 * Tests for the seller type resolver.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\SellerStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\SellerStatusCapability;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\SellerStatusProduct;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\SellerStatusFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\Cache;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\FailureRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Enum\SellerTypeEnum;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\SellerTypeResolver;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery\MockInterface;

/**
 * Whether the merchant is a business: derived from the capabilities PayPal reports, and looked up again for a connected
 * merchant whose seller type is still unknown, over a stubbed PayPal and the real shared settings option.
 *
 * @group paypal-wallet
 */
class SellerTypeResolverTest extends WalletTestCase {

	private const COMMON_OPTION     = 'woocommerce-ppcp-data-common';
	private const CLEAR_STATUS_HOOK = 'woocommerce_paypal_payments_clear_apm_product_status';
	private const STATUS_TRANSIENT  = 'ppcp-test-seller-' . PartnersEndpoint::SELLER_STATUS_CACHE_KEY;
	private const FAILURE_TRANSIENT = 'ppcp-test-failure-' . FailureRegistry::CACHE_KEY . '_' . FailureRegistry::SELLER_STATUS_KEY;

	/**
	 * The resolver under test.
	 *
	 * @var SellerTypeResolver
	 */
	private $sut;

	/**
	 * The logger passed to the resolver.
	 *
	 * @var LoggerInterface|MockInterface
	 */
	private $logger;

	/**
	 * Build the resolver and have the test base delete the transients the partners endpoint writes.
	 */
	public function setUp(): void {
		parent::setUp();

		foreach ( array( self::STATUS_TRANSIENT, self::FAILURE_TRANSIENT ) as $name ) {
			$this->set_wallet_transient( $name, '' );
			delete_transient( $name );
		}

		$this->sut    = new SellerTypeResolver();
		$this->logger = $this->mock( LoggerInterface::class );
	}

	/**
	 * Store the shared settings of a connected merchant with the given seller type, and load the model over them.
	 *
	 * @param string $seller_type The stored seller type.
	 * @return GeneralSettings
	 */
	private function connected_settings( string $seller_type ): GeneralSettings {
		$this->set_wallet_option(
			self::COMMON_OPTION,
			array(
				'merchant_id'    => 'MID',
				'merchant_email' => 'merchant@example.com',
				'client_id'      => 'cid',
				'client_secret'  => 'secret',
				'seller_type'    => $seller_type,
			)
		);

		return new GeneralSettings( 'US', 'USD', false );
	}

	/**
	 * A real partners endpoint over the stubbed PayPal.
	 *
	 * @return PartnersEndpoint
	 */
	private function make_partners_endpoint(): PartnersEndpoint {
		return new PartnersEndpoint(
			'https://api.sandbox.paypal.com/',
			$this->make_bearer( false ),
			$this->mock( LoggerInterface::class )->shouldIgnoreMissing(),
			new SellerStatusFactory(),
			'partner-abc',
			'MID',
			new FailureRegistry( new Cache( 'ppcp-test-failure-' ) ),
			new Cache( 'ppcp-test-seller-' )
		);
	}

	/**
	 * Answer the seller status request with the given capabilities and country.
	 *
	 * @param string[] $active_capabilities The names of the capabilities PayPal reports as active.
	 * @param string   $country             The country PayPal reports.
	 */
	private function stub_seller_status( array $active_capabilities, string $country = 'DE' ): void {
		$capabilities = array();
		foreach ( $active_capabilities as $name ) {
			$capabilities[] = array(
				'name'   => $name,
				'status' => 'ACTIVE',
			);
		}

		$this->stub_http(
			$this->http_response(
				200,
				(string) wp_json_encode(
					array(
						'country'      => $country,
						'products'     => array(),
						'capabilities' => $capabilities,
					)
				)
			)
		);
	}

	/**
	 * The stored seller type.
	 *
	 * @return string
	 */
	private function stored_seller_type(): string {
		$stored = get_option( self::COMMON_OPTION );
		$this->assertIsArray( $stored );

		return (string) $stored['seller_type'];
	}

	/**
	 * Given a merchant whose seller type is already known, nothing is asked of PayPal and nothing is stored.
	 *
	 * @testdox Should do nothing when the seller type is already known.
	 */
	public function test_does_nothing_when_seller_type_is_already_known(): void {
		$settings = $this->connected_settings( SellerTypeEnum::BUSINESS );
		$cleared  = $this->spy_filter( self::CLEAR_STATUS_HOOK );
		$updates  = $this->spy_filter( 'pre_update_option_' . self::COMMON_OPTION );

		$this->sut->resolve_unknown_seller_type( $settings, $this->make_partners_endpoint(), $this->logger );

		$this->assertCount( 0, $this->http_requests );
		$this->assertCount( 0, $updates );
		$this->assertCount( 0, $cleared );
		$this->assertFalse( $this->sut->needs_seller_type_resolution( $settings ) );
	}

	/**
	 * @testdox Should do nothing when the merchant is not connected.
	 */
	public function test_does_nothing_when_merchant_is_not_connected(): void {
		$this->set_wallet_option( self::COMMON_OPTION, array( 'seller_type' => SellerTypeEnum::UNKNOWN ) );
		$settings = new GeneralSettings( 'US', 'USD', false );
		$updates  = $this->spy_filter( 'pre_update_option_' . self::COMMON_OPTION );

		$this->sut->resolve_unknown_seller_type( $settings, $this->make_partners_endpoint(), $this->logger );

		$this->assertCount( 0, $this->http_requests );
		$this->assertCount( 0, $updates );
		$this->assertFalse( $this->sut->needs_seller_type_resolution( $settings ) );
	}

	/**
	 * Given a connected merchant with an unknown seller type, when PayPal reports the commercial entity capability, the
	 * business type is stored, the empty merchant country is filled from the status, and the cached product statuses
	 * are cleared.
	 *
	 * @testdox Should resolve a business seller from the seller status and store it.
	 */
	public function test_resolves_business_and_persists_it(): void {
		$settings = $this->connected_settings( SellerTypeEnum::UNKNOWN );
		$this->assertTrue( $this->sut->needs_seller_type_resolution( $settings ) );
		$this->stub_seller_status( array( 'COMMERCIAL_ENTITY' ) );
		$cleared = $this->spy_filter( self::CLEAR_STATUS_HOOK );

		$this->sut->resolve_unknown_seller_type( $settings, $this->make_partners_endpoint(), $this->logger );

		$this->assertCount( 1, $this->http_requests );
		$this->assertSame( SellerTypeEnum::BUSINESS, $this->stored_seller_type() );
		$this->assertSame( 'DE', get_option( self::COMMON_OPTION )['merchant_country'] );
		$this->assertCount( 1, $cleared );
		$this->assertFalse( $this->sut->needs_seller_type_resolution( new GeneralSettings( 'US', 'USD', false ) ) );
	}

	/**
	 * Given a seller status call that fails, the failure is logged for later, nothing is stored and the seller type
	 * stays unknown.
	 *
	 * @testdox Should log and store nothing when the seller status call fails.
	 */
	public function test_does_not_persist_when_api_call_throws(): void {
		$settings = $this->connected_settings( SellerTypeEnum::UNKNOWN );
		$this->stub_http( $this->http_response( 403, '{"name":"NOT_AUTHORIZED"}' ) );
		$this->logger->shouldReceive( 'debug' )->once()->with( 'Seller type resolution deferred.', \Mockery::type( 'array' ) );
		$updates = $this->spy_filter( 'pre_update_option_' . self::COMMON_OPTION );

		$this->sut->resolve_unknown_seller_type( $settings, $this->make_partners_endpoint(), $this->logger );

		$this->assertSame( SellerTypeEnum::UNKNOWN, $this->stored_seller_type() );
		$this->assertCount( 0, $updates );
	}

	/**
	 * Given a seller status that succeeds without any business capability, nothing is stored and the type stays unknown.
	 *
	 * @testdox Should store nothing when the seller status has no business capability.
	 */
	public function test_does_not_persist_when_no_business_capability(): void {
		$settings = $this->connected_settings( SellerTypeEnum::UNKNOWN );
		$this->stub_seller_status( array() );
		$cleared = $this->spy_filter( self::CLEAR_STATUS_HOOK );
		$updates = $this->spy_filter( 'pre_update_option_' . self::COMMON_OPTION );

		$this->sut->resolve_unknown_seller_type( $settings, $this->make_partners_endpoint(), $this->logger );

		$this->assertCount( 1, $this->http_requests );
		$this->assertSame( SellerTypeEnum::UNKNOWN, $this->stored_seller_type() );
		$this->assertCount( 0, $updates );
		$this->assertCount( 0, $cleared );
	}

	/**
	 * Capabilities that make a seller a business when they are active.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function data_business_capabilities(): array {
		return array(
			'commercial entity'                => array( 'COMMERCIAL_ENTITY' ),
			'custom card processing'           => array( 'CUSTOM_CARD_PROCESSING' ),
			'card processing virtual terminal' => array( 'CARD_PROCESSING_VIRTUAL_TERMINAL' ),
			'fraud tool access'                => array( 'FRAUD_TOOL_ACCESS' ),
			'pay upon invoice'                 => array( 'PAY_UPON_INVOICE' ),
			'send invoice'                     => array( 'SEND_INVOICE' ),
		);
	}

	/**
	 * @testdox Should resolve a business seller from an active business capability.
	 *
	 * @dataProvider data_business_capabilities
	 *
	 * @param string $capability The capability.
	 */
	public function test_resolve_returns_business_for_an_active_business_capability( string $capability ): void {
		$status = new SellerStatus( array(), array( new SellerStatusCapability( $capability, SellerStatusCapability::STATUS_ACTIVE ) ), 'DE' );

		$this->assertSame( SellerTypeEnum::BUSINESS, $this->sut->resolve( $status ) );
	}

	/**
	 * @testdox Should not resolve a business seller from an inactive capability, or from a capability that is no sign of a business.
	 */
	public function test_resolve_ignores_inactive_and_unrelated_capabilities(): void {
		$status = new SellerStatus(
			array(),
			array(
				new SellerStatusCapability( 'COMMERCIAL_ENTITY', 'DENIED' ),
				new SellerStatusCapability( 'PAYPAL_WALLET_VAULTING_ADVANCED', SellerStatusCapability::STATUS_ACTIVE ),
			),
			'DE'
		);

		$this->assertSame( SellerTypeEnum::UNKNOWN, $this->sut->resolve( $status ) );
	}

	/**
	 * @testdox Should resolve a business seller from a subscribed custom card product, and not from one that is not subscribed.
	 */
	public function test_resolve_reads_the_custom_product_vetting_status(): void {
		$subscribed = new SellerStatus( array( new SellerStatusProduct( 'PPCP_CUSTOM', 'SUBSCRIBED', array() ) ), array(), 'DE' );
		$pending    = new SellerStatus( array( new SellerStatusProduct( 'PPCP_CUSTOM', 'IN_REVIEW', array() ) ), array(), 'DE' );

		$this->assertSame( SellerTypeEnum::BUSINESS, $this->sut->resolve( $subscribed ) );
		$this->assertSame( SellerTypeEnum::UNKNOWN, $this->sut->resolve( $pending ) );
	}
}
