<?php
/**
 * ExtensionBoundRenewalPlugins tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\ExtensionBoundRenewalPlugins;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the plugins that charge renewals through the WooPayments extension.
 */
class ExtensionBoundRenewalPluginsTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var ExtensionBoundRenewalPlugins
	 */
	private ExtensionBoundRenewalPlugins $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new ExtensionBoundRenewalPlugins();
		$this->sut->init( wc_get_container()->get( LegacyProxy::class ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		try {
			$this->reset_legacy_proxy_mocks();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Each listed plugin charges renewals or scheduled payments through the extension's own classes (YITH 4.26.3
	 * class-ywsbs-wc-payments.php extends WC_Payment_Gateway_WCPay; Order Subscriptions 1.2.15, Ultimate Payment Plans 4.5.0
	 * and Deposit and Partial Payment Plan 1.0.5 build WCPay\Payment_Information), which the built-in WooPayments does not
	 * declare. The free YITH WooCommerce Subscription has no WooPayments integration.
	 *
	 * @testdox The active listed plugins are found by their folder and named by their header: $_dataName.
	 * @dataProvider provide_site_active_plugins
	 *
	 * @param array<int,mixed> $active_plugins Active plugin entries of the site.
	 * @param string[]         $files          The listed plugin files found.
	 * @param string[]         $names          Their names.
	 */
	public function test_finds_and_names_the_active_listed_plugins( array $active_plugins, array $files, array $names ): void {
		$this->fake_active_plugins( $active_plugins, array(), false );

		$this->assertSame( $files, $this->sut->get_active_plugin_files() );
		$this->assertSame( $names, $this->sut->get_active_plugin_names() );
	}

	/**
	 * @return array<string,array{array<int,mixed>,string[],string[]}>
	 */
	public function provide_site_active_plugins(): array {
		return array(
			'YITH WooCommerce Subscription Premium'        => array( array( 'woocommerce/woocommerce.php', 'yith-woocommerce-subscription-premium/init.php' ), array( 'yith-woocommerce-subscription-premium/init.php' ), array( 'YITH WooCommerce Subscription Premium' ) ),
			'Order Subscriptions for WooCommerce'          => array( array( 'order-subscriptions/order-subscriptions.php' ), array( 'order-subscriptions/order-subscriptions.php' ), array( 'Order Subscriptions for WooCommerce' ) ),
			'Ultimate Payment Plans'                       => array( array( 'ultimate-payment-plans/ultimate-payment-plans.php' ), array( 'ultimate-payment-plans/ultimate-payment-plans.php' ), array( 'Ultimate Payment Plans' ) ),
			'Deposit and Partial Payment Plan'             => array( array( 'deposit-and-partial-payment-plan/deposit-and-partial-payment-plan.php' ), array( 'deposit-and-partial-payment-plan/deposit-and-partial-payment-plan.php' ), array( 'Deposit and Partial Payment Plan' ) ),
			'two of them'                                  => array( array( 'yith-woocommerce-subscription-premium/init.php', 'ultimate-payment-plans/ultimate-payment-plans.php' ), array( 'yith-woocommerce-subscription-premium/init.php', 'ultimate-payment-plans/ultimate-payment-plans.php' ), array( 'YITH WooCommerce Subscription Premium', 'Ultimate Payment Plans' ) ),
			'none of them'                                 => array( array( 'woocommerce/woocommerce.php' ), array(), array() ),
			'the free YITH WooCommerce Subscription'       => array( array( 'yith-woocommerce-subscription/init.php' ), array(), array() ),
			'a folder that only starts with a listed slug' => array( array( 'yith-woocommerce-subscription-premium-addon/addon.php' ), array(), array() ),
			'a listed plugin as a single file'             => array( array( 'ultimate-payment-plans.php' ), array( 'ultimate-payment-plans.php' ), array( 'ultimate-payment-plans' ) ),
			'an entry that is not a plugin file'           => array( array( 42, 'order-subscriptions/order-subscriptions.php' ), array( 'order-subscriptions/order-subscriptions.php' ), array( 'Order Subscriptions for WooCommerce' ) ),
		);
	}

	/**
	 * @testdox On a multisite, a listed plugin active for this site only is found.
	 */
	public function test_site_active_listed_plugin_is_found_on_a_multisite(): void {
		// An unrelated network-active plugin: the site's own list must still count beside a nonempty network list.
		$this->fake_active_plugins( array( 'order-subscriptions/order-subscriptions.php' ), array( 'akismet/akismet.php' ), true );

		$this->assertSame( array( 'order-subscriptions/order-subscriptions.php' ), $this->sut->get_active_plugin_files() );
		$this->assertSame( array( 'Order Subscriptions for WooCommerce' ), $this->sut->get_active_plugin_names() );
	}

	/**
	 * @testdox A listed plugin active for the whole network is found on a multisite, and a single site ignores its network plugin list.
	 */
	public function test_network_active_listed_plugin_is_found_on_a_multisite_only(): void {
		$this->fake_active_plugins( array(), array( 'yith-woocommerce-subscription-premium/init.php' ), true );

		$this->assertSame( array( 'yith-woocommerce-subscription-premium/init.php' ), $this->sut->get_active_plugin_files(), 'On a multisite the network plugin list counts.' );
		$this->assertSame( array( 'YITH WooCommerce Subscription Premium' ), $this->sut->get_active_plugin_names() );

		$this->fake_active_plugins( array(), array( 'yith-woocommerce-subscription-premium/init.php' ), false );

		$this->assertSame( array(), $this->sut->get_active_plugin_files(), 'A single site has no network plugin list.' );
	}

	/**
	 * @testdox A listed plugin in both the site and the network plugin lists is found once.
	 */
	public function test_listed_plugin_in_both_lists_is_found_once(): void {
		$this->fake_active_plugins( array( 'ultimate-payment-plans/ultimate-payment-plans.php' ), array( 'ultimate-payment-plans/ultimate-payment-plans.php' ), true );

		$this->assertSame( array( 'ultimate-payment-plans/ultimate-payment-plans.php' ), $this->sut->get_active_plugin_files() );
		$this->assertSame( array( 'Ultimate Payment Plans' ), $this->sut->get_active_plugin_names() );
	}

	/**
	 * @testdox A listed plugin whose header cannot be read is named by its slug.
	 */
	public function test_listed_plugin_without_a_readable_header_is_named_by_its_slug(): void {
		$this->fake_active_plugins( array( 'order-subscriptions/order-subscriptions.php' ), array(), false, array() );

		$this->assertSame( array( 'order-subscriptions' ), $this->sut->get_active_plugin_names() );
	}

	/**
	 * Control the site and network active plugin lists and the plugin headers WordPress reads.
	 *
	 * WordPress stores active_sitewide_plugins as a plugin-file => activation-timestamp map (wp-admin/includes/plugin.php:712-715),
	 * and get_plugins() in the same file returns a plugin-file => header map whose Name is each plugin's "Plugin Name" header; the
	 * four names are those headers in the plugins' current main files.
	 *
	 * @param array<int,mixed>                        $site_plugins    Active plugin entries of the site.
	 * @param string[]                                $network_plugins Plugin files active for the whole network.
	 * @param bool                                    $is_multisite    Whether the install is a multisite.
	 * @param array<string,array<string,string>>|null $headers         Plugin headers by file, or null for the listed plugins' headers.
	 */
	private function fake_active_plugins( array $site_plugins, array $network_plugins, bool $is_multisite, ?array $headers = null ): void {
		$headers = $headers ?? array(
			'yith-woocommerce-subscription-premium/init.php' => array( 'Name' => 'YITH WooCommerce Subscription Premium' ),
			'order-subscriptions/order-subscriptions.php' => array( 'Name' => 'Order Subscriptions for WooCommerce' ),
			'ultimate-payment-plans/ultimate-payment-plans.php' => array( 'Name' => 'Ultimate Payment Plans' ),
			'deposit-and-partial-payment-plan/deposit-and-partial-payment-plan.php' => array( 'Name' => 'Deposit and Partial Payment Plan' ),
		);
		$this->register_legacy_proxy_function_mocks(
			array(
				'get_option'      => fn( $name, $default_value = false ) => 'active_plugins' === $name ? $site_plugins : get_option( $name, $default_value ),
				'get_site_option' => fn( $name, $default_value = false ) => 'active_sitewide_plugins' === $name ? array_fill_keys( $network_plugins, 1 ) : get_site_option( $name, $default_value ),
				'is_multisite'    => fn() => $is_multisite,
				'get_plugins'     => fn() => $headers,
			)
		);
	}
}
