<?php
/**
 * Manages the SDK v6 frontend assets.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Assets;

use WC_Product;
use WP_Post;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\UpdateShippingEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\ApproveOrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\ChangeCartEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\CreateOrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\FrontendLogEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint\GetOrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\Context;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\DisabledFundingSources;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayLaterBlock\PayLaterBlockModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Endpoint\CreatePaymentToken;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Endpoint\CreatePaymentTokenForGuest;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Endpoint\CreateSetupToken;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Endpoint\ClientTokenEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Endpoint\SimulateCartEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper\ButtonStyleMapper;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper\MessagesEligibility;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper\MessageStyleMapper;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\Cancellation\CancelController;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\Cancellation\CancelView;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\SettingsStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\FreeTrialHandlerTrait;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\FreeTrialSubscriptionHelper;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;

/**
 * Decides where the SDK v6 assets load and enqueues them with their data.
 */
class SdkV6Manager {
	use FreeTrialHandlerTrait;

	public const WRAPPER_ID           = 'ppc-button-ppcp-gateway-v6';
	public const MINI_CART_WRAPPER_ID = 'ppc-button-minicart-v6';

	/**
	 * The height every payment button on the page renders at.
	 *
	 * One value for all of them: the buttons are meant to match, and the styling
	 * DTOs carry no height of their own (see ButtonStyleMapper).
	 */
	public const PAYMENT_BUTTON_HEIGHT = '48px';

	/**
	 * The button height in the mini cart, which is narrower than a page column
	 * and so carries a shorter button. Matches the v5 default for the location
	 * (see SmartButton::script_data(), which sends 35 there and 48 for the
	 * block contexts).
	 */
	public const MINI_CART_BUTTON_HEIGHT = '35px';

	// The pay-for-order page has no pre-payment hook, so the message renders
	// after the submit button and is relocated by SdkV6Module.
	public const PAY_ORDER_MESSAGE_HOOK = 'woocommerce_pay_order_before_submit';

	/**
	 * The asset getter.
	 *
	 * @var AssetGetter
	 */
	private AssetGetter $asset_getter;
	/**
	 * The version.
	 *
	 * @var string
	 */
	private string $version;
	/**
	 * The environment.
	 *
	 * @var Environment
	 */
	private Environment $environment;
	/**
	 * The style mapper.
	 *
	 * @var ButtonStyleMapper
	 */
	private ButtonStyleMapper $style_mapper;
	/**
	 * The settings status.
	 *
	 * @var SettingsStatus
	 */
	private SettingsStatus $settings_status;
	/**
	 * The context.
	 *
	 * @var Context
	 */
	private Context $context;
	/**
	 * The session handler.
	 *
	 * @var SessionHandler
	 */
	private SessionHandler $session_handler;
	/**
	 * The cancel view.
	 *
	 * @var CancelView
	 */
	private CancelView $cancel_view;
	/**
	 * Whether the final review is enabled.
	 *
	 * @var bool
	 */
	private bool $final_review_enabled;
	/**
	 * Whether vaulting is enabled.
	 *
	 * @var bool
	 */
	private bool $vaulting_enabled;
	/**
	 * The subscription helper.
	 *
	 * @var SubscriptionHelper
	 */
	private SubscriptionHelper $subscription_helper;
	/**
	 * The free trial helper.
	 *
	 * @var FreeTrialSubscriptionHelper
	 */
	private FreeTrialSubscriptionHelper $free_trial_helper;

	/**
	 * The message style mapper.
	 *
	 * @var MessageStyleMapper
	 */
	private MessageStyleMapper $message_style_mapper;
	/**
	 * The messages eligibility.
	 *
	 * @var MessagesEligibility
	 */
	private MessagesEligibility $messages_eligibility;
	/**
	 * The v5 disabled-funding helper, whose settings rule decides where Venmo is offered.
	 *
	 * @var DisabledFundingSources
	 */
	private DisabledFundingSources $disabled_funding_sources;
	/**
	 * Whether the merchant is connected and the PayPal gateway is on.
	 *
	 * @var bool
	 */
	private bool $buttons_available;

	/**
	 * Memoizes should_load_on_current_page(), asked by every surface that
	 * stands down for v6.
	 *
	 * @var bool|null
	 */
	private ?bool $should_load = null;

	/**
	 * Memoizes has_paylater_block(), which the messaging gates ask repeatedly.
	 *
	 * @var bool|null
	 */
	private ?bool $has_paylater_block = null;

	/**
	 * SdkV6Manager constructor.
	 *
	 * @param AssetGetter                 $asset_getter         The asset getter.
	 * @param string                      $version              The version.
	 * @param Environment                 $environment          The environment.
	 * @param ButtonStyleMapper           $style_mapper         The style mapper.
	 * @param SettingsStatus              $settings_status      The settings status.
	 * @param Context                     $context              The context.
	 * @param SessionHandler              $session_handler      The session handler.
	 * @param CancelView                  $cancel_view          The cancel view.
	 * @param bool                        $final_review_enabled Whether the final review is enabled.
	 * @param bool                        $vaulting_enabled     Whether vaulting is enabled.
	 * @param SubscriptionHelper          $subscription_helper  The subscription helper.
	 * @param FreeTrialSubscriptionHelper $free_trial_helper    The free trial helper.
	 * @param MessageStyleMapper          $message_style_mapper The message style mapper.
	 * @param MessagesEligibility         $messages_eligibility The messages eligibility.
	 * @param DisabledFundingSources      $disabled_funding_sources The v5 disabled-funding helper.
	 * @param bool                        $buttons_available    Whether the merchant is connected and the PayPal gateway is on.
	 */
	public function __construct(
		AssetGetter $asset_getter,
		string $version,
		Environment $environment,
		ButtonStyleMapper $style_mapper,
		SettingsStatus $settings_status,
		Context $context,
		SessionHandler $session_handler,
		CancelView $cancel_view,
		bool $final_review_enabled,
		bool $vaulting_enabled,
		SubscriptionHelper $subscription_helper,
		FreeTrialSubscriptionHelper $free_trial_helper,
		MessageStyleMapper $message_style_mapper,
		MessagesEligibility $messages_eligibility,
		DisabledFundingSources $disabled_funding_sources,
		bool $buttons_available
	) {
		$this->asset_getter             = $asset_getter;
		$this->version                  = $version;
		$this->environment              = $environment;
		$this->style_mapper             = $style_mapper;
		$this->settings_status          = $settings_status;
		$this->context                  = $context;
		$this->session_handler          = $session_handler;
		$this->cancel_view              = $cancel_view;
		$this->final_review_enabled     = $final_review_enabled;
		$this->vaulting_enabled         = $vaulting_enabled;
		$this->subscription_helper      = $subscription_helper;
		$this->free_trial_helper        = $free_trial_helper;
		$this->message_style_mapper     = $message_style_mapper;
		$this->messages_eligibility     = $messages_eligibility;
		$this->disabled_funding_sources = $disabled_funding_sources;
		$this->buttons_available        = $buttons_available;
	}

	/**
	 * Registers, localizes and enqueues the classic bootstrap script.
	 */
	public function enqueue(): void {
		// The classic bootstrap renders into PHP-printed wrappers that do not
		// exist on block pages, which the block payment method script serves.
		if ( ! $this->should_load_on_current_page() || $this->is_block_context() ) {
			return;
		}

		$script_url = $this->asset_getter->get_asset_url( 'boot.js' );
		if ( ! $script_url ) {
			return;
		}

		$asset = $this->asset_getter->get_asset_data( 'boot.js', $this->version );

		wp_register_script(
			'wc-ppcp-sdk-v6-boot',
			$script_url,
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script(
			'wc-ppcp-sdk-v6-boot',
			'wc_ppcp_sdk_v6',
			$this->script_data()
		);

		wp_enqueue_script( 'wc-ppcp-sdk-v6-boot' );

		// Lays out the express buttons inside the wrappers render_wrapper()
		// prints. Classic pages only; block pages return at the top of this
		// method and style their containers from the block bundle.
		wp_enqueue_style(
			'wc-ppcp-sdk-v6-gateway',
			$this->asset_getter->get_asset_url( 'gateway.css' ),
			array(),
			$this->asset_getter->get_asset_data( 'gateway.css', $this->version )['version']
		);
	}

	/**
	 * Determines which button locations should render on the current page.
	 *
	 * Expects Context::init_context() to have run, so that is_cart() and
	 * is_checkout() resolve on classic-shortcode block pages.
	 *
	 * @return array<string, bool> Location => enabled (product, cart, checkout, pay-now, mini-cart).
	 */
	public function determine_render_places(): array {
		// Subscription pages that cannot vault defer to v5 (see should_load_on_current_page);
		// print no v6 wrappers so the classic page hands off cleanly. These render
		// hooks key on the smart-button locations rather than that method, so they
		// need this guard explicitly. Every location is returned false (rather than
		// an empty array) to keep the array shape callers index into. The same
		// holds when the gateway is off or the merchant is not connected.
		if ( ! $this->buttons_available || $this->is_subscription_page_without_vaulting() ) {
			return array(
				'product'   => false,
				'cart'      => false,
				'checkout'  => false,
				'pay-now'   => false,
				'mini-cart' => false,
			);
		}

		$needs_payment = $this->cart_needs_payment();

		// A free-trial ($0) subscription cart needs no one-time payment, so
		// $needs_payment is false, but the checkout still needs the PayPal
		// save-without-purchase button (boot.js renders it for the checkout /
		// pay-now contexts only). Confined to checkout: the cart and mini-cart
		// have no form to submit the vaulted token with.
		$free_trial_checkout = $this->free_trial_helper->is_free_trial_cart();

		// The cart does not hold the product being viewed, so a free trial only
		// turns the total zero once the button adds it - too late for the client.
		$product_enabled = $this->settings_status->is_smart_button_enabled_for_location( 'product' );

		// pay-now is driven by the existing WC order rather than the cart, so
		// the zero-total guard does not apply to it.
		return array(
			'product'   => $product_enabled && ! $this->is_free_trial_product(),
			'cart'      => $needs_payment && $this->settings_status->is_smart_button_enabled_for_location( 'cart' ),
			'checkout'  => ( $needs_payment || $free_trial_checkout ) && $this->settings_status->is_smart_button_enabled_for_location( 'checkout' ),
			'pay-now'   => $this->settings_status->is_smart_button_enabled_for_location( 'pay-now' ),
			'mini-cart' => $needs_payment && $this->settings_status->is_smart_button_enabled_for_location( 'mini-cart' ),
		);
	}

	/**
	 * Whether the current cart still needs payment.
	 *
	 * Keeps buttons off $0 orders (a full-value coupon), where no payment method
	 * should be offered at all. A free-trial subscription cart is $0 too but is
	 * handled separately in determine_render_places(), since it still needs the
	 * PayPal save-without-purchase button on the checkout.
	 */
	private function cart_needs_payment(): bool {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return true;
		}

		return $cart->needs_payment();
	}

	/**
	 * Renders the shared express-button wrapper.
	 */
	public function render_wrapper(): void {
		echo '<div class="ppc-button-wrapper"><div id="' . esc_attr( self::WRAPPER_ID ) . '"></div></div>';
	}

	/**
	 * Renders the mini-cart button wrapper.
	 */
	public function render_mini_cart_wrapper(): void {
		echo '<p class="woocommerce-mini-cart__buttons buttons">';
		echo '<span id="' . esc_attr( self::MINI_CART_WRAPPER_ID ) . '"></span>';
		echo '</p>';
	}

	/**
	 * Whether the v6 SDK loads on the current page.
	 *
	 * Also scopes the v5 suppression, since both SDKs claim window.paypal: v5 is
	 * disabled on exactly the pages this returns true for. See extensions.php.
	 *
	 * Each surface gets its own OR'd condition rather than folding into the
	 * location check. Pay Later messaging is one such condition: it can claim a
	 * classic page where nothing else here would.
	 */
	public function should_load_on_current_page(): bool {
		if ( null !== $this->should_load ) {
			return $this->should_load;
		}

		$should_load = $this->resolve_should_load();

		// Memoized only after Context::init_context() ran on `wp`; before that
		// is_cart()/is_checkout() have not resolved.
		if ( did_action( 'wp' ) ) {
			$this->should_load = $should_load;
		}

		return $should_load;
	}

	/**
	 * The uncached answer for should_load_on_current_page().
	 */
	private function resolve_should_load(): bool {
		// The v5 button factory checks the same two things before it renders
		// buttons or messages; turning PayPal Wallet off must take it off every page.
		if ( ! $this->buttons_available ) {
			return false;
		}

		// A subscription page with vaulting off and manual renewals off has no v6
		// path: v6 can only carry a subscription by vaulting. Hand the whole page
		// back to the v5 stack. Checked before every other gate so it also
		// overrides the sitewide mini-cart fallback below.
		if ( $this->is_subscription_page_without_vaulting() ) {
			return false;
		}

		$page_location = $this->get_page_context();
		if ( $page_location && $this->settings_status->is_smart_button_enabled_for_location( $page_location ) ) {
			return true;
		}

		if ( $this->should_load_messages() ) {
			return true;
		}

		// Home and shop, where v5 places a Pay Later message and v6 has no
		// message hook of its own yet. Whether v5 rendered there came down to the
		// unrelated mini-cart setting: with the mini-cart on, the fallback below
		// claimed the page and v5 went dark; with it off, v5 stayed and drew the
		// banner. Claim the page either way, so one stack owns messaging
		// everywhere rather than v6 and v5 splitting it per page. The message is
		// withheld until v6 can draw it itself, at which point should_load_messages()
		// above claims the page first and this branch stops being reached.
		//
		// Scoped to the empty page context so the block and page-context
		// locations keep deciding without consulting messaging, as
		// testShouldLoadOnCurrentPageInBlockContextsIsUnaffectedByMessagingEligibility
		// pins.
		$message_location = $page_location ? '' : $this->context->location();
		if ( $message_location && $this->settings_status->is_pay_later_messaging_enabled_for_location( $message_location ) ) {
			return true;
		}

		// Sitewide, because the mini-cart can appear on any page as either the
		// classic "Cart" widget or the block Mini-Cart, and is_active_widget()
		// only detects the classic one. boot.js renders into the mini-cart
		// wrapper only where that wrapper exists.
		return $this->settings_status->is_smart_button_enabled_for_location( 'mini-cart' );
	}

	/**
	 * Whether the current page holds a subscription that PayPal cannot take.
	 *
	 * That is a subscription in the current context while "Save PayPal and Venmo"
	 * and manual renewals are both off. v6 can only carry a subscription by
	 * vaulting, so it defers the page to v5. The
	 * `woocommerce_paypal_payments_subscription_mode_disabled` filter opts out.
	 */
	private function is_subscription_page_without_vaulting(): bool {
		if ( $this->vaulting_enabled || $this->subscription_helper->accept_manual_renewals() ) {
			return false;
		}

		$has_subscription = $this->subscription_helper->current_product_is_subscription()
			|| $this->subscription_helper->cart_contains_subscription()
			|| $this->subscription_helper->order_pay_contains_subscription();

		if ( ! $has_subscription ) {
			return false;
		}

		/**
		 * Allows disabling the subscription mode.
		 *
		 * @since 11.3.0
		 *
		 * @see \Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper::resolve_subscription_mode()
		 *
		 * @param bool $subscription_mode_disabled True to disable the subscription mode. Default false.
		 */
		return ! apply_filters( 'woocommerce_paypal_payments_subscription_mode_disabled', false );
	}

	/**
	 * Whether Pay Later messaging alone should pull the v6 SDK onto this page.
	 *
	 * Two exclusions:
	 *
	 * - Block contexts, where claiming the page for messaging alone may
	 *   leave nothing rendering it.
	 * - Pages this module will not actually paint. messages_render_hook()
	 *   returns null for shop, home and the mini-cart fallback context, which
	 *   stay with v5.
	 */
	private function should_load_messages(): bool {
		if ( is_admin() || $this->is_block_context() ) {
			return false;
		}

		if ( ! $this->messages_enabled() ) {
			return false;
		}

		// The Pay Later block prints its own `.ppcp-messages` placeholder, so it
		// needs no hook.
		return null !== $this->messages_render_hook() || $this->has_paylater_block();
	}

	/**
	 * Whether Pay Later messaging is enabled and eligible on this page.
	 */
	public function messages_enabled(): bool {
		if ( is_admin() ) {
			return false;
		}

		return $this->messages_eligibility->is_enabled_for_location(
			$this->messages_settings_location()
		);
	}

	/**
	 * Maps the current page onto a Pay Later messaging settings location.
	 *
	 * The single normalization point for both the style and the eligibility
	 * lookup. SettingsStatus::normalize_location() cannot be relied on here:
	 * it is button-oriented and maps 'checkout-block' to
	 * 'checkout-block-express', a location the messaging settings never
	 * contain, so messaging would silently never enable on the block
	 * checkout.
	 *
	 * Falls back to 'custom_placement' where a Pay Later block sits. Empty for
	 * shop, home and mini-cart, which this module does not serve.
	 */
	private function messages_settings_location(): string {
		switch ( $this->context->location() ) {
			case 'product':
				return 'product';
			case 'cart':
			case 'cart-block':
				return 'cart';
			case 'checkout':
			case 'checkout-block':
			case 'pay-now':
				return 'checkout';
			default:
				return $this->has_paylater_block() ? 'custom_placement' : '';
		}
	}

	/**
	 * Whether an enabled Pay Later block sits on the page being rendered.
	 *
	 * Mirrors the v5 SmartButton's `$has_paylater_block`, which is what carries
	 * messaging onto pages that are not themselves messaging locations.
	 */
	private function has_paylater_block(): bool {
		if ( null !== $this->has_paylater_block ) {
			return $this->has_paylater_block;
		}

		// has_block() reads $post, which only a queried singular request sets —
		// not archives, REST, cron or webhooks, and before `wp` it holds whatever
		// was left over. Left unmemoized: "not resolved yet" is not "no block".
		if ( ! did_action( 'wp' ) || ! ( $GLOBALS['post'] ?? null ) instanceof WP_Post ) {
			return false;
		}

		$this->has_paylater_block = PayLaterBlockModule::is_block_enabled( $this->settings_status )
			&& has_block( 'woocommerce-paypal-payments/paylater-messages' );

		return $this->has_paylater_block;
	}

	/**
	 * The v6 page-type attribute value for the current page.
	 */
	private function messages_page_type(): string {
		switch ( $this->messages_settings_location() ) {
			case 'product':
				return 'product-details';
			case 'cart':
				return 'cart';
			case 'custom_placement':
				// Unclassifiable page, so the neutral 'home' type. A block naming
				// its own placement overrides this per placeholder (pageTypeFor()).
				return 'home';
			default:
				return 'checkout';
		}
	}

	/**
	 * Whether a product page's message re-prices through the cart-simulation endpoint.
	 */
	private function messages_use_cart_simulation(): bool {
		/**
		 * Filters whether Pay Later messaging prices a product page through the
		 * cart-simulation endpoint rather than from the product form.
		 *
		 * @since 11.3.0
		 *
		 * @param bool $use_cart_simulation Whether to use the endpoint.
		 */
		return (bool) apply_filters(
			'woocommerce_paypal_payments_sdk_v6_messages_use_cart_simulation',
			false
		);
	}

	/**
	 * The amount the Pay Later message prices.
	 *
	 * Product-first, matching v5's message_values(): on a product page the
	 * message must price the product the buyer is looking at, even when the
	 * cart already holds other items. This is why transaction_amount() (which
	 * is cart-first, because it feeds button eligibility) cannot be reused.
	 */
	private function messages_amount(): string {
		if ( 'pay-now' === $this->get_page_context() ) {
			$order = $this->order_pay();

			return $order ? number_format( (float) $order->get_total(), 2, '.', '' ) : '';
		}

		// Scoped to the product location rather than probing wc_get_product()
		// unconditionally the way v5 does: on a cart or checkout page a stray
		// global $product, left behind by a theme loop or another plugin,
		// would otherwise make the message price that product instead of the
		// cart. The location check also covers the [product_page] shortcut,
		// where is_product() is false but the context is still 'product'.
		if ( 'product' === $this->messages_settings_location() ) {
			$product = wc_get_product();
			if ( $product instanceof \WC_Product ) {
				return number_format( (float) wc_get_price_including_tax( $product ), 2, '.', '' );
			}
		}

		$cart = WC()->cart;
		if ( $cart ) {
			return number_format( (float) $cart->get_total( 'edit' ), 2, '.', '' );
		}

		return '';
	}

	/**
	 * Adds the price the Pay Later message should use to each variation.
	 *
	 * WooCommerce reports `display_price`, which follows the shop's tax display
	 * setting, while the message's opening amount always carries tax. Left
	 * mixed, picking a variation would change the tax basis rather than only
	 * the figure, and on a shop displaying prices excluding tax the message
	 * would price less than the shopper pays. Tax-inclusive throughout keeps
	 * the amount the cart-simulation endpoint used to report.
	 *
	 * Param types are omitted because any callback on this filter may have
	 * replaced the value first.
	 *
	 * @param mixed $data      The variation data assembled so far.
	 * @param mixed $product   The parent variable product.
	 * @param mixed $variation The variation being described.
	 * @return mixed The variation data, carrying ppcp_message_amount.
	 *
	 * @psalm-suppress MissingParamType
	 */
	public function add_variation_message_amount( $data, $product = null, $variation = null ) {
		if ( ! is_array( $data )
			|| ! $variation instanceof WC_Product
			|| $this->messages_use_cart_simulation() ) {
			return $data;
		}

		$data['ppcp_message_amount'] = number_format(
			(float) wc_get_price_including_tax( $variation ),
			2,
			'.',
			''
		);

		return $data;
	}

	/**
	 * The action hook the message wrapper renders on, or null when this
	 * module does not place messages on the current page.
	 *
	 * Reuses the v5 filter names so a merchant override relocates both
	 * stacks.
	 *
	 * @return array{name: string, priority: int}|null
	 */
	public function messages_render_hook(): ?array {
		switch ( $this->context->location() ) {
			case 'checkout':
				return $this->message_hook( 'checkout', 'woocommerce_review_order_before_payment', 10 );
			case 'cart':
				/**
				 * The action name that the PayPal buttons use for rendering next to the cart's Proceed to Checkout button.
				 *
				 * @since 11.3.0
				 *
				 * @param string $hook The action name; woocommerce_proceed_to_checkout by default.
				 */
				$cart_hook = (string) apply_filters(
					'woocommerce_paypal_payments_proceed_to_checkout_button_renderer_hook',
					'woocommerce_proceed_to_checkout'
				);

				return $this->message_hook( 'cart', $cart_hook, 19 );
			case 'product':
				/**
				 * The action name that the PayPal buttons use for rendering on the single product page.
				 *
				 * @since 11.3.0
				 *
				 * @param string $hook The action name; woocommerce_single_product_summary by default.
				 */
				$product_hook = (string) apply_filters(
					'woocommerce_paypal_payments_single_product_renderer_hook',
					'woocommerce_single_product_summary'
				);

				return $this->message_hook( 'product', $product_hook, 30 );
			case 'pay-now':
				return $this->message_hook( 'pay-now', self::PAY_ORDER_MESSAGE_HOOK, 10 );
			default:
				return null;
		}
	}

	/**
	 * Applies the per-location message hook and priority filters.
	 *
	 * @param string $location         The page location.
	 * @param string $default_hook     The default action name.
	 * @param int    $default_priority The default priority.
	 * @return array{name: string, priority: int}
	 */
	private function message_hook( string $location, string $default_hook, int $default_priority ): array {
		$location_hook = 'pay-now' === $location ? 'pay_order' : $location;

		/**
		 * The filter returning the action name that will be used for rendering Pay Later messages.
		 *
		 * @since 11.3.0
		 *
		 * @param string $hook The default action name for the location.
		 */
		$name = (string) apply_filters(
			"woocommerce_paypal_payments_{$location_hook}_messages_renderer_hook",
			$default_hook
		);

		/**
		 * The filter returning the action priority that will be used for rendering Pay Later messages.
		 *
		 * @since 11.3.0
		 *
		 * @param int $priority The default action priority for the location.
		 */
		$priority = (int) apply_filters(
			"woocommerce_paypal_payments_{$location_hook}_messages_renderer_priority",
			$default_priority
		);

		return array(
			'name'     => $name,
			'priority' => $priority,
		);
	}

	/**
	 * Prints the Pay Later message placeholder.
	 *
	 * Emits the same .ppcp-messages wrapper v5 emits rather than the
	 * <paypal-message> element itself, so one JS mount path serves both these
	 * wrappers and the Pay Later message blocks, and so theme and plugin CSS
	 * keyed on the class keeps working.
	 */
	public function render_message_wrapper(): void {
		$location      = $this->context->location();
		$location_hook = 'pay-now' === $location ? 'pay_order' : $location;

		/**
		 * A hook executed before rendering of the PCP Pay Later messages wrapper.
		 *
		 * @since 11.3.0
		 */
		do_action( "ppcp_before_{$location_hook}_message_wrapper" );

		echo '<div class="ppcp-messages"></div>';

		/**
		 * A hook executed after rendering of the PCP Pay Later messages wrapper.
		 *
		 * @since 11.3.0
		 */
		do_action( "ppcp_after_{$location_hook}_message_wrapper" );
	}

	/**
	 * The button styles for one context, carrying the height every button in
	 * that context's express stack shares.
	 *
	 * @param string $context The page context.
	 * @return array{colorClass: string, borderRadius: string, height: string}
	 */
	private function button_styles( string $context ): array {
		return array_merge(
			$this->style_mapper->styles_for_context( $context ),
			array( 'height' => $this->button_height( $context ) )
		);
	}

	/**
	 * The button height for a context.
	 *
	 * @param string $context The context.
	 */
	private function button_height( string $context ): string {
		return 'mini-cart' === $context
			? self::MINI_CART_BUTTON_HEIGHT
			: self::PAYMENT_BUTTON_HEIGHT;
	}

	/**
	 * Whether the Venmo button belongs in a location.
	 *
	 * Uses the v5 settings rule (DisabledFundingSources), which v5 applied
	 * through the SDK URL's disable-funding: the Venmo switch, the location's
	 * styling choice and the disabled-funding filter.
	 *
	 * @param string $location The button location.
	 * @return bool Whether the button may render.
	 */
	private function is_venmo_button_enabled( string $location ): bool {
		return ! in_array( 'venmo', $this->disabled_funding_sources->get_sources_from_settings( $location ), true );
	}

	/**
	 * Whether the Pay Later button belongs in a location.
	 *
	 * Mirrors SmartButton::is_pay_later_button_enabled_for_location(). v5 hid
	 * the button through the SDK URL's disable-funding, which has no v6
	 * equivalent, so the decision travels to the renderer instead.
	 *
	 * @param string $location The button location.
	 * @return bool Whether the button may render.
	 */
	private function is_pay_later_button_enabled( string $location ): bool {
		return $this->is_pay_later_filter_enabled( $location )
			&& $this->settings_status->is_pay_later_button_enabled_for_location( $location );
	}

	/**
	 * Whether the filters v5 fires allow Pay Later in a location.
	 *
	 * @param string $location The button location.
	 * @return bool Whether the filters allow the button.
	 */
	private function is_pay_later_filter_enabled( string $location ): bool {
		if ( 'product' === $location ) {
			/**
			 * Allows to decide if the button should be disabled for a given product.
			 *
			 * @since 11.3.0
			 *
			 * @param bool  $disabled Whether the Pay Later button or message is disabled for the product; false by default.
			 * @param array $context  The product context data.
			 */
			return ! apply_filters(
				'woocommerce_paypal_payments_product_buttons_paylater_disabled',
				false,
				$this->pay_later_product_context()
			);
		}

		/**
		 * Allows to decide if the button should be disabled on a given context.
		 *
		 * @since 11.3.0
		 *
		 * @param bool   $disabled Whether the Pay Later button or message is disabled in the location; false by default.
		 * @param string $context  The location, such as cart or checkout.
		 */
		return ! apply_filters(
			'woocommerce_paypal_payments_buttons_paylater_disabled',
			false,
			$location
		);
	}

	/**
	 * The context data the product filter receives, as v5 assembles it.
	 *
	 * @return array{product?: \WC_Product, order_total?: float}
	 */
	private function pay_later_product_context(): array {
		$product = wc_get_product();
		if ( ! $product ) {
			return array();
		}

		return array(
			'product'     => $product,
			'order_total' => (float) $product->get_price( 'raw' ),
		);
	}

	/**
	 * The configuration data for the SDK v6 bootstrap script.
	 *
	 * Also consumed by the block payment method (V6PaymentMethod), which
	 * exposes it under `wcSettings.paymentMethodData['ppcp-sdk-v6']` for the
	 * React entry.
	 */
	public function script_data(): array {
		$base_url = $this->environment->is_sandbox()
			? 'https://www.sandbox.paypal.com'
			: 'https://www.paypal.com';

		$buyer_country = WC()->customer ? WC()->customer->get_billing_country() : '';
		if ( ! $buyer_country ) {
			$buyer_country = wc_get_base_location()['country'] ?? '';
		}

		$page_context = $this->get_page_context();

		$shipping_contexts = $this->shipping_contexts( $page_context );

		$store_api_base = rtrim( rest_url( 'wc/store/v1/cart' ), '/' );

		$button_styles    = array();
		$pay_later_button = array();
		$venmo_button     = array();
		if ( $page_context ) {
			$button_styles[ $page_context ]    = $this->button_styles( $page_context );
			$pay_later_button[ $page_context ] = $this->is_pay_later_button_enabled( $page_context );
			$venmo_button[ $page_context ]     = $this->is_venmo_button_enabled( $page_context );
		}
		if ( $this->settings_status->is_smart_button_enabled_for_location( 'mini-cart' ) ) {
			$button_styles['mini-cart']    = $this->button_styles( 'mini-cart' );
			$pay_later_button['mini-cart'] = $this->is_pay_later_button_enabled( 'mini-cart' );
			$venmo_button['mini-cart']     = $this->is_venmo_button_enabled( 'mini-cart' );
		}

		$messages_settings_location = $this->messages_settings_location();

		/*
		 * - final_review: drives the post-approval fork; see V6ExpressComponent.approve().
		 * - is_free_trial_cart, cart_needs_vaulting: a zero-total subscription
		 *   cart is vaulted instead of purchased; see utils/freeTrial.js.
		 */
		$data = array(
			'sdk_url'             => $base_url . '/web-sdk/v6/core',
			'page_context'        => $page_context,
			// Whether the page's own button location is on. The SDK can load for
			// another surface (the mini-cart, messaging) where it is off.
			'buttons_enabled'     => '' !== $page_context && $this->settings_status->is_smart_button_enabled_for_location( $page_context ),
			'currency'            => get_woocommerce_currency(),
			'amount'              => $this->transaction_amount(),
			'buyer_country'       => $buyer_country,
			'locale'              => str_replace( '_', '-', get_locale() ),
			'vaulting_enabled'    => $this->vaulting_enabled,
			'final_review'        => $this->final_review_enabled,
			'is_free_trial_cart'  => $this->free_trial_helper->is_free_trial_cart(),
			'cart_needs_vaulting' => $this->free_trial_helper->cart_requires_vaulting(),
			'has_subscriptions'   => $this->subscription_helper->cart_contains_subscription(),
			'user'                => array(
				'is_logged' => is_user_logged_in(),
			),
			'ajax'                => array(
				'client_token'                   => array(
					'endpoint' => \WC_AJAX::get_endpoint( ClientTokenEndpoint::ENDPOINT ),
					'nonce'    => wp_create_nonce( ClientTokenEndpoint::nonce() ),
				),
				'change_cart'                    => array(
					'endpoint' => \WC_AJAX::get_endpoint( ChangeCartEndpoint::ENDPOINT ),
					'nonce'    => wp_create_nonce( ChangeCartEndpoint::nonce() ),
				),
				'simulate_cart'                  => array(
					'endpoint' => \WC_AJAX::get_endpoint( SimulateCartEndpoint::ENDPOINT ),
					'nonce'    => wp_create_nonce( SimulateCartEndpoint::nonce() ),
				),
				'create_order'                   => array(
					'endpoint' => \WC_AJAX::get_endpoint( CreateOrderEndpoint::ENDPOINT ),
					'nonce'    => wp_create_nonce( CreateOrderEndpoint::nonce() ),
				),
				'approve_order'                  => array(
					'endpoint' => \WC_AJAX::get_endpoint( ApproveOrderEndpoint::ENDPOINT ),
					'nonce'    => wp_create_nonce( ApproveOrderEndpoint::nonce() ),
				),
				'get_order'                      => array(
					'endpoint' => \WC_AJAX::get_endpoint( GetOrderEndpoint::ENDPOINT ),
					'nonce'    => wp_create_nonce( GetOrderEndpoint::nonce() ),
				),
				'update_shipping'                => array(
					'endpoint' => \WC_AJAX::get_endpoint( UpdateShippingEndpoint::ENDPOINT ),
					'nonce'    => wp_create_nonce( UpdateShippingEndpoint::nonce() ),
				),
				// Vault v3 "save without purchase" endpoints, used by the
				// free-trial checkout flow (see is_free_trial_cart). Registered as
				// wc_ajax actions by ppcp-save-payment-methods when vaulting is on.
				'create_setup_token'             => array(
					'endpoint' => \WC_AJAX::get_endpoint( CreateSetupToken::ENDPOINT ),
					'nonce'    => wp_create_nonce( CreateSetupToken::nonce() ),
				),
				'create_payment_token'           => array(
					'endpoint' => \WC_AJAX::get_endpoint( CreatePaymentToken::ENDPOINT ),
					'nonce'    => wp_create_nonce( CreatePaymentToken::nonce() ),
				),
				'create_payment_token_for_guest' => array(
					'endpoint' => \WC_AJAX::get_endpoint( CreatePaymentTokenForGuest::ENDPOINT ),
					'nonce'    => wp_create_nonce( CreatePaymentTokenForGuest::nonce() ),
				),
				'frontend_log'                   => array(
					'endpoint' => \WC_AJAX::get_endpoint( FrontendLogEndpoint::ENDPOINT ),
					'nonce'    => wp_create_nonce( FrontendLogEndpoint::nonce() ),
				),
				'wc_store_api'                   => array(
					'cart'                 => $store_api_base,
					'select_shipping_rate' => $store_api_base . '/select-shipping-rate',
					'update_customer'      => $store_api_base . '/update-customer',
					'nonce'                => wp_create_nonce( 'wc_store_api' ),
				),
			),
			'urls'                => array(
				'checkout' => wc_get_checkout_url(),
			),
			'labels'              => array(
				'generic_error' => __(
					'Something went wrong. Please try again or choose another payment source.',
					'woocommerce'
				),
			),
			'shipping'            => array(
				'in_context' => $shipping_contexts,
			),
			'button_styles'       => $button_styles,
			'pay_later_button'    => $pay_later_button,
			'venmo_button'        => $venmo_button,
			'wrapper'             => '#' . self::WRAPPER_ID,
			'mini_cart_wrapper'   => '#' . self::MINI_CART_WRAPPER_ID,
			'messages'            => array(
				'enabled'             => $this->messages_enabled(),
				'wrapper'             => '.ppcp-messages',
				'is_hidden'           => $this->messages_eligibility->is_hidden( $page_context ),
				'amount'              => $this->messages_amount(),
				'page_type'           => $this->messages_page_type(),
				'style'               => $this->message_style_mapper->styles_for_location( $messages_settings_location ),
				'use_cart_simulation' => $this->messages_use_cart_simulation(),
			),
		);

		// The pay-for-order page builds the PayPal order from an existing WC
		// order rather than the cart, so its identifiers must reach the
		// create-order endpoint's from_wc_order branch.
		if ( 'pay-now' === $page_context ) {
			$data['pay_now'] = array(
				'order_id'  => $this->order_pay_id(),
				'order_key' => $this->order_pay_key(),
			);
		}

		$continuation = $this->continuation_data();
		if ( $continuation ) {
			$data['continuation'] = $continuation;
		}

		return $data;
	}

	/**
	 * The WC order ID on the pay-for-order (order-pay) page, or 0.
	 */
	private function order_pay_id(): int {
		global $wp;

		if ( ! isset( $wp->query_vars['order-pay'] ) ) {
			return 0;
		}

		return absint( $wp->query_vars['order-pay'] );
	}

	/**
	 * The order key from the pay-for-order page URL, or empty string.
	 */
	private function order_pay_key(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';

		return is_string( $key ) ? $key : '';
	}

	/**
	 * The WC order for the current pay-for-order page, validated against the
	 * URL order key, or null.
	 */
	private function order_pay(): ?\WC_Order {
		$order_id = $this->order_pay_id();
		if ( ! $order_id ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		// Stops a crafted URL from reading another customer's order total.
		if ( ! hash_equals( (string) $order->get_order_key(), $this->order_pay_key() ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * Whether the buyer is returning from an approved PayPal order and should
	 * see the order review instead of the express buttons.
	 */
	public function is_continuation(): bool {
		return $this->context->is_paypal_continuation();
	}

	/**
	 * The continuation payload, or null when there is no approved order.
	 *
	 * The cancel link is load-bearing: while an approved order sits in the
	 * session the express buttons are suppressed everywhere, so it is the
	 * buyer's only way out.
	 */
	private function continuation_data(): ?array {
		if ( ! $this->is_continuation() ) {
			return null;
		}

		$order = $this->session_handler->order();
		if ( ! $order ) {
			return null;
		}

		$cancel_url = add_query_arg(
			array( CancelController::NONCE => wp_create_nonce( CancelController::NONCE ) ),
			wc_get_checkout_url()
		);

		return array(
			'order_id'       => $order->id(),
			'order'          => $order->to_array(),
			// Carried in the payload rather than a window global, so the gateway
			// is told which source approved the order.
			'funding_source' => $this->session_handler->funding_source(),
			'cancel'         => array(
				'html' => $this->cancel_view->render_session_cancellation(
					$cancel_url,
					$this->session_handler->funding_source()
				),
			),
		);
	}

	/**
	 * Whether shipping details are collected, per context.
	 *
	 * One decision per context, shared by every surface that asks it. A map rather
	 * than a single flag because the mini-cart renders on any page, so two
	 * contexts can be live at once and answer differently.
	 *
	 * @param string $page_context The context of the current page.
	 * @return array<string, bool> Keyed by context.
	 */
	private function shipping_contexts( string $page_context ): array {
		$contexts = array();

		if ( $page_context ) {
			$contexts[ $page_context ] = $this->shipping_for_context( $page_context );
		}

		$contexts['mini-cart'] = $this->shipping_for_context( 'mini-cart' );

		return $contexts;
	}

	/**
	 * Whether the given context collects a shipping address and shipping options.
	 *
	 * Requires the "Pay Now" experience, which builds the WC order from the approved
	 * PayPal order and the address collected during payment. Continuation mode ends
	 * on a final review page instead, and that page collects shipping itself.
	 *
	 * The product page judges the product rather than the cart, because the product
	 * is what gets bought there: it is added to the cart on click, so the cart's
	 * current contents describe a basket that is about to be replaced.
	 *
	 * @param string $context The context to judge.
	 * @return bool
	 */
	private function shipping_for_context( string $context ): bool {
		if ( $this->final_review_enabled ) {
			return false;
		}

		// Both pages already own the address and the total the order will use, so
		// the PayPal popup only authorizes what the page shows. This prevents
		// conflicting addresses/details between checkout form and popup.
		if ( in_array( $context, array( 'checkout', 'pay-now' ), true ) ) {
			return false;
		}

		// Block surfaces read needsShipping live from the React cart and combine it
		// with this value themselves, so answering with the cart as it stood when the
		// page was built would gate them twice, on a snapshot that goes stale the
		// moment the buyer edits the cart.
		if ( in_array( $context, array( 'cart-block', 'checkout-block' ), true ) ) {
			return true;
		}

		if ( 'product' === $context ) {
			$product = wc_get_product();

			return $product instanceof WC_Product
				&& ! $product->is_virtual()
				&& ! $product->is_downloadable();
		}

		$cart = WC()->cart;

		return $cart && $cart->needs_shipping();
	}

	/**
	 * The amount eligibility is checked against, which moves Pay Later
	 * thresholds: the cart total, or the product price while the cart is empty.
	 *
	 * @return string A decimal string, or empty when unknown.
	 */
	private function transaction_amount(): string {
		// The pay-for-order page has no cart; its order carries the total.
		if ( 'pay-now' === $this->get_page_context() ) {
			$order = $this->order_pay();
			if ( $order ) {
				return number_format( (float) $order->get_total(), 2, '.', '' );
			}

			return '';
		}

		$cart = WC()->cart;
		if ( $cart && ! $cart->is_empty() ) {
			return number_format( (float) $cart->get_total( 'edit' ), 2, '.', '' );
		}

		if ( is_product() ) {
			$product = wc_get_product( get_the_ID() );
			if ( $product ) {
				$price = (float) wc_get_price_including_tax( $product );
				if ( $price ) {
					return number_format( $price, 2, '.', '' );
				}
			}
		}

		return '';
	}

	/**
	 * The page context for the current WC page, or empty off a supported page.
	 *
	 * Resolves through the shared Context helper, which handles
	 * classic-shortcode block pages, then narrows to the contexts this module
	 * supports. The block editor stays out of scope.
	 */
	private function get_page_context(): string {
		$context = $this->context->context();

		if ( in_array(
			$context,
			array( 'product', 'cart', 'checkout', 'pay-now', 'cart-block', 'checkout-block' ),
			true
		) ) {
			return $context;
		}

		return '';
	}

	/**
	 * Whether the current page is a WooCommerce Blocks (React) page.
	 */
	public function is_block_context(): bool {
		return in_array(
			$this->get_page_context(),
			array( 'cart-block', 'checkout-block' ),
			true
		);
	}
}
