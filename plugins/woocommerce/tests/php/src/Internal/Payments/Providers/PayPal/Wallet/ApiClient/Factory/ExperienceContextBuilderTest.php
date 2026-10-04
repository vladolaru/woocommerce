<?php
/**
 * Tests for the experience context builder.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\ExperienceContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ExperienceContextBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Endpoint\ReturnUrlEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Shipping\ShippingCallbackUrlFactory;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WC_AJAX;

/**
 * Builds the experience context of a PayPal order one piece at a time: return URLs, locale, brand name, landing
 * page and the payment method and contact preferences. The URLs and the locale are the real ones of the test site.
 *
 * @group paypal-wallet
 */
class ExperienceContextBuilderTest extends WalletTestCase {

	/**
	 * The merchant settings, a mock.
	 *
	 * @var SettingsProvider
	 */
	private $settings;

	/**
	 * The factory of the shipping callback URL, a mock.
	 *
	 * @var ShippingCallbackUrlFactory
	 */
	private $shipping_callback_url_factory;

	/**
	 * The System Under Test.
	 *
	 * @var ExperienceContextBuilder
	 */
	private $sut;

	/**
	 * Set up the builder with mocked settings.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->settings                      = $this->mock( SettingsProvider::class );
		$this->shipping_callback_url_factory = $this->mock( ShippingCallbackUrlFactory::class );

		$this->sut = new ExperienceContextBuilder( $this->settings, $this->shipping_callback_url_factory );
	}

	/**
	 * Make the locale of the current user the given one.
	 *
	 * @param string $locale The WordPress locale, such as de_DE_formal.
	 */
	private function set_locale( string $locale ): void {
		wp_set_current_user( 0 );
		add_filter(
			'locale',
			static function () use ( $locale ) {
				return $locale;
			}
		);
	}

	/**
	 * @testdox Should return to the order received page and cancel to the same page with the cancelled flag.
	 */
	public function test_order_return_urls(): void {
		$wc_order = wc_create_order();
		$url      = $wc_order->get_checkout_order_received_url();

		$result = $this->sut
			->with_order_return_urls( $wc_order )
			->build();

		$this->assertEquals(
			array(
				'return_url' => $url,
				'cancel_url' => add_query_arg( 'cancelled', 'true', $url ),
			),
			$result->to_array()
		);
		// The order received URL already has the order key as its query, so the flag is appended to it.
		$this->assertSame( $url . '&cancelled=true', $result->to_array()['cancel_url'] );
	}

	/**
	 * @testdox Should return to the return URL endpoint and cancel to the checkout page.
	 */
	public function test_endpoint_return_urls(): void {
		$result = $this->sut
			->with_endpoint_return_urls()
			->build();

		$this->assertEquals(
			array(
				'return_url' => home_url( WC_AJAX::get_endpoint( ReturnUrlEndpoint::ENDPOINT ) ),
				'cancel_url' => wc_get_checkout_url(),
			),
			$result->to_array()
		);
		$this->assertStringContainsString( ReturnUrlEndpoint::ENDPOINT, $result->to_array()['return_url'] );
	}

	/**
	 * @testdox Should take the return and the cancel URL it is given.
	 */
	public function test_custom_return_and_cancel_urls(): void {
		$result = $this->sut
			->with_custom_return_url( 'https://example.com/return' )
			->with_custom_cancel_url( 'https://example.com/cancel' )
			->build();

		$this->assertEquals(
			array(
				'return_url' => 'https://example.com/return',
				'cancel_url' => 'https://example.com/cancel',
			),
			$result->to_array()
		);
	}

	/**
	 * @testdox Should turn the locale of the current user into the locale PayPal expects.
	 */
	public function test_current_locale(): void {
		$this->set_locale( 'de_DE_formal' );

		$result = $this->sut
			->with_current_locale()
			->build();

		$this->assertEquals( array( 'locale' => 'de-DE' ), $result->to_array() );
	}

	/**
	 * @testdox Should keep a language, a language with a region and a language with a script and a region, and fall back to English for the rest.
	 *
	 * @dataProvider data_locale
	 *
	 * @param string $wp_locale The WordPress locale.
	 * @param string $expected  The locale PayPal gets.
	 */
	public function test_current_locale_formats( string $wp_locale, string $expected ): void {
		$this->set_locale( $wp_locale );

		$result = $this->sut
			->with_current_locale()
			->build();

		$this->assertEquals( array( 'locale' => $expected ), $result->to_array() );
	}

	/**
	 * WordPress locales and what PayPal gets for each.
	 *
	 * @return array<string, array<string>>
	 */
	public function data_locale(): array {
		return array(
			'language and region'            => array( 'en_US', 'en-US' ),
			'language only'                  => array( 'fr', 'fr' ),
			'language, script and region'    => array( 'zh_Hant_TW', 'zh-Hant-TW' ),
			'variant is cut'                 => array( 'de_CH_informal', 'de-CH' ),
			'something that is not a locale' => array( 'a_b_c_d', 'en' ),
		);
	}

	/**
	 * @testdox Should use the brand name of the merchant and leave it out when there is none.
	 *
	 * @dataProvider data_brand_name
	 *
	 * @param string      $value    The brand name setting.
	 * @param string|null $expected The expected brand name, or null for none.
	 */
	public function test_current_brand_name( $value, $expected ): void {
		$this->settings
			->expects( 'brand_name' )
			->andReturn( $value );

		$result = $this->sut
			->with_current_brand_name()
			->build();

		if ( null === $expected ) {
			$this->assertEmpty( $result->to_array() );
			return;
		}

		$this->assertEquals( array( 'brand_name' => $expected ), $result->to_array() );
	}

	/**
	 * The brand name settings.
	 *
	 * @return array<string, array>
	 */
	public function data_brand_name(): array {
		return array(
			'no brand name' => array( '', null ),
			'a brand name'  => array( 'company', 'company' ),
		);
	}

	/**
	 * @testdox Should use the landing page of the merchant and no preference when there is none.
	 *
	 * @dataProvider data_landing_page
	 *
	 * @param string $value    The landing page setting.
	 * @param string $expected The expected landing page.
	 */
	public function test_current_landing_page( $value, $expected ): void {
		$this->settings
			->expects( 'landing_page_enum' )
			->andReturn( $value );

		$result = $this->sut
			->with_current_landing_page()
			->build();

		$this->assertEquals( array( 'landing_page' => $expected ), $result->to_array() );
	}

	/**
	 * The landing page settings.
	 *
	 * @return array<string, array>
	 */
	public function data_landing_page(): array {
		return array(
			'no landing page' => array( '', ExperienceContext::LANDING_PAGE_NO_PREFERENCE ),
			'login'           => array( ExperienceContext::LANDING_PAGE_LOGIN, ExperienceContext::LANDING_PAGE_LOGIN ),
		);
	}

	/**
	 * Given a builder with no landing page set, with_landing_page() with an explicit value gives a context with that
	 * value and ignores the merchant setting.
	 *
	 * @testdox Should use an explicit landing page and ignore the merchant setting.
	 */
	public function test_landing_page_sets_explicit_value(): void {
		$this->settings->shouldNotReceive( 'landing_page_enum' );

		$result = $this->sut
			->with_landing_page( ExperienceContext::LANDING_PAGE_GUEST_CHECKOUT )
			->build();

		$this->assertEquals( array( 'landing_page' => ExperienceContext::LANDING_PAGE_GUEST_CHECKOUT ), $result->to_array() );
	}

	/**
	 * Given a builder that already applied the merchant's landing page, with_landing_page() with an explicit value
	 * afterwards overrides the merchant setting.
	 *
	 * @testdox Should let an explicit landing page override the merchant setting.
	 */
	public function test_landing_page_overrides_current_landing_page(): void {
		$this->settings
			->expects( 'landing_page_enum' )
			->andReturn( ExperienceContext::LANDING_PAGE_LOGIN );

		$result = $this->sut
			->with_current_landing_page()
			->with_landing_page( ExperienceContext::LANDING_PAGE_GUEST_CHECKOUT )
			->build();

		$this->assertEquals( array( 'landing_page' => ExperienceContext::LANDING_PAGE_GUEST_CHECKOUT ), $result->to_array() );
	}

	/**
	 * @testdox Should ask for immediate payment only when the merchant takes instant payments only.
	 *
	 * @dataProvider data_payment_method_preference
	 *
	 * @param string $value    The instant payments only setting.
	 * @param string $expected The expected payment method preference.
	 */
	public function test_current_payment_method_preference( $value, $expected ): void {
		$this->settings
			->expects( 'instant_payments_only' )
			->andReturn( $value );

		$result = $this->sut
			->with_current_payment_method_preference()
			->build();

		$this->assertEquals( array( 'payment_method_preference' => $expected ), $result->to_array() );
	}

	/**
	 * The instant payments only settings.
	 *
	 * @return array<string, array>
	 */
	public function data_payment_method_preference(): array {
		return array(
			'off' => array( '', ExperienceContext::PAYMENT_METHOD_UNRESTRICTED ),
			'on'  => array( 'yes', ExperienceContext::PAYMENT_METHOD_IMMEDIATE_PAYMENT_REQUIRED ),
		);
	}

	/**
	 * @testdox Should hold the contact preference it is given.
	 *
	 * @dataProvider data_contact_preference
	 *
	 * @param string $preference The contact preference.
	 */
	public function test_contact_preference( $preference ): void {
		$result = $this->sut
			->with_contact_preference( $preference )
			->build();

		$this->assertEquals( array( 'contact_preference' => $preference ), $result->to_array() );
	}

	/**
	 * The contact preferences.
	 *
	 * @return array<string, array<string>>
	 */
	public function data_contact_preference(): array {
		return array(
			'no contact info'     => array( ExperienceContext::CONTACT_PREFERENCE_NO_CONTACT_INFO ),
			'update contact info' => array( ExperienceContext::CONTACT_PREFERENCE_UPDATE_CONTACT_INFO ),
		);
	}

	/**
	 * @testdox Should register the shipping callback URL for the shipping address and shipping options events.
	 */
	public function test_shipping_callback(): void {
		$this->shipping_callback_url_factory
			->expects( 'create' )
			->andReturn( 'https://example.com/callback?cart_token=abc' );

		$result = $this->sut
			->with_shipping_callback()
			->build();

		$this->assertEquals(
			array(
				'order_update_callback_config' => array(
					'callback_events' => array( 'SHIPPING_ADDRESS', 'SHIPPING_OPTIONS' ),
					'callback_url'    => 'https://example.com/callback?cart_token=abc',
				),
			),
			$result->to_array()
		);
	}

	/**
	 * @testdox Should combine the endpoint return URLs, the locale, the brand name, the landing page, the payment method preference, the shipping preference and the user action in the default PayPal config.
	 */
	public function test_with_default_paypal_config(): void {
		$this->set_locale( 'en_US' );
		$this->settings->expects( 'brand_name' )->andReturn( 'company' );
		$this->settings->expects( 'landing_page_enum' )->andReturn( ExperienceContext::LANDING_PAGE_LOGIN );
		$this->settings->expects( 'instant_payments_only' )->andReturn( 'yes' );

		$result = $this->sut
			->with_default_paypal_config()
			->build();

		$this->assertEquals(
			array(
				'return_url'                => home_url( WC_AJAX::get_endpoint( ReturnUrlEndpoint::ENDPOINT ) ),
				'cancel_url'                => wc_get_checkout_url(),
				'brand_name'                => 'company',
				'locale'                    => 'en-US',
				'landing_page'              => ExperienceContext::LANDING_PAGE_LOGIN,
				'shipping_preference'       => ExperienceContext::SHIPPING_PREFERENCE_NO_SHIPPING,
				'user_action'               => ExperienceContext::USER_ACTION_CONTINUE,
				'payment_method_preference' => ExperienceContext::PAYMENT_METHOD_IMMEDIATE_PAYMENT_REQUIRED,
			),
			$result->to_array()
		);
	}
}
