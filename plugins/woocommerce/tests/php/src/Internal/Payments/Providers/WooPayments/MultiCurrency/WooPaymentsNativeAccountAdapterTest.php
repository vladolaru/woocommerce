<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\MultiCurrency;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeAccountAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsNativeAccountAdapter class.
 */
class WooPaymentsNativeAccountAdapterTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should delegate account state to the native account service.
	 */
	public function test_delegates_account_state_to_native_account_service(): void {
		$account_data    = array(
			'account_id'          => 'acct_123',
			'customer_currencies' => array(
				'supported' => array( 'GBP', 'EUR' ),
			),
		);
		$account_service = new RecordingNativeAccountService( $account_data, true, true );
		$sut             = new WooPaymentsNativeAccountAdapter();
		$sut->init( $account_service );

		$this->assertTrue( $sut->is_provider_connected(), 'The adapter should use the native account presence signal.' );
		$this->assertTrue( $sut->is_account_rejected(), 'The adapter should delegate rejected-state checks.' );
		$this->assertSame( $account_data, $sut->get_cached_account_data( true ), 'The adapter should expose cached account data.' );
		$this->assertTrue( $account_service->forced_refresh, 'The adapter should forward forced refresh requests.' );
		$this->assertSame( array( 'GBP', 'EUR' ), $sut->get_account_customer_supported_currencies(), 'The adapter should read the preserved customer currency field.' );
		$supported_countries = $sut->get_supported_countries();
		$this->assertSame( 'United States (US)', $supported_countries['US'] );
		$this->assertSame( 'Poland', $supported_countries['PL'] );
		$this->assertSame( 'Romania', $supported_countries['RO'] );
		$this->assertArrayNotHasKey( 'BR', $supported_countries );
		$this->assertStringContainsString( '/woopayments/onboarding', rawurldecode( $sut->get_provider_onboarding_page_url() ) );
	}

	/**
	 * @testdox Should fail closed when the native account service throws.
	 */
	public function test_fails_closed_when_native_account_service_throws(): void {
		$sut = new WooPaymentsNativeAccountAdapter();
		$sut->init( new ThrowingNativeAccountService() );

		$this->assertTrue( $sut->is_provider_connected( true ), 'Provider connection checks should honor the supplied error fallback.' );
		$this->assertFalse( $sut->is_provider_connected(), 'Provider connection checks should default to false on errors.' );
		$this->assertFalse( $sut->is_account_rejected(), 'Rejected-state checks should fail closed.' );
		$this->assertFalse( $sut->get_cached_account_data(), 'Account data reads should fail closed.' );
		$this->assertSame( array(), $sut->get_account_customer_supported_currencies(), 'Customer currencies should fail closed.' );
		$this->assertSame( array(), $sut->get_supported_countries(), 'Supported countries should fail closed.' );
		$this->assertStringContainsString( '/woopayments/onboarding', rawurldecode( $sut->get_provider_onboarding_page_url() ) );
	}

	/**
	 * @testdox Should ignore malformed customer currency data.
	 */
	public function test_ignores_malformed_customer_currency_data(): void {
		$account_service = new RecordingNativeAccountService(
			array(
				'account_id'          => 'acct_123',
				'customer_currencies' => array(
					'supported' => 'GBP',
				),
			),
			true,
			false
		);
		$sut             = new WooPaymentsNativeAccountAdapter();
		$sut->init( $account_service );

		$this->assertSame( array(), $sut->get_account_customer_supported_currencies(), 'Malformed customer currency data should not leak through.' );
	}
}

	// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Classes.ClassFileName.NoMatch, SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName -- Test doubles live next to the tests they support.
	/**
	 * Recording native account-service test double.
	 */
class RecordingNativeAccountService extends WooPaymentsAccountService {

	/**
	 * Cached account data.
	 *
	 * @var array<string,mixed>
	 */
	private array $account_data;

	/**
	 * Whether the account is connected.
	 *
	 * @var bool
	 */
	private bool $has_account;

	/**
	 * Whether the account is rejected.
	 *
	 * @var bool
	 */
	private bool $is_rejected;

	/**
	 * Whether force refresh was requested.
	 *
	 * @var bool
	 */
	public bool $forced_refresh = false;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $account_data Cached account data.
	 * @param bool                $has_account  Whether the account is connected.
	 * @param bool                $is_rejected  Whether the account is rejected.
	 */
	public function __construct( array $account_data, bool $has_account, bool $is_rejected ) {
		$this->account_data = $account_data;
		$this->has_account  = $has_account;
		$this->is_rejected  = $is_rejected;
	}

	/**
	 * Get cached provider account data.
	 *
	 * @param bool $force_refresh Whether to force-refresh provider data.
	 * @return array<string,mixed>
	 */
	public function get_cached_account_data( bool $force_refresh = false ): array {
		$this->forced_refresh = $force_refresh;

		return $this->account_data;
	}

	/**
	 * Tell whether WooPayments has an account cache entry.
	 *
	 * @return bool
	 */
	public function has_account(): bool {
		return $this->has_account;
	}

	/**
	 * Tell whether the cached account is rejected.
	 *
	 * @return bool
	 */
	public function is_account_rejected(): bool {
		return $this->is_rejected;
	}
}

	/**
	 * Throwing native account-service test double.
	 */
class ThrowingNativeAccountService extends WooPaymentsAccountService {

	/**
	 * Throw when checking account presence.
	 *
	 * @throws \RuntimeException Always thrown.
	 */
	public function has_account(): bool {
		throw new \RuntimeException( 'Account failed' );
	}

	/**
	 * Throw when reading supported countries.
	 *
	 * @throws \RuntimeException Always thrown.
	 */
	public function get_supported_countries(): array {
		throw new \RuntimeException( 'Account failed' );
	}

	/**
	 * Throw when checking rejected state.
	 *
	 * @throws \RuntimeException Always thrown.
	 */
	public function is_account_rejected(): bool {
		throw new \RuntimeException( 'Account failed' );
	}

	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
	/**
	 * Throw when reading cached account data.
	 *
	 * @param bool $force_refresh Whether to force-refresh provider data.
	 * @return array<string,mixed>
	 * @throws \RuntimeException Always thrown.
	 */
	public function get_cached_account_data( bool $force_refresh = false ): array {
		unset( $force_refresh );

		throw new \RuntimeException( 'Account failed' );
	}
	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
}
	// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Classes.ClassFileName.NoMatch, SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName
