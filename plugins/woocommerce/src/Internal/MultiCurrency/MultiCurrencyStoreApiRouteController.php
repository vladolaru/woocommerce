<?php
/**
 * MultiCurrencyStoreApiRouteController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\RequestContext;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Treats a request as a Store API request once WordPress has matched it, or a request batched or dispatched inside it, to a
 * Store API route its URL did not name: a mixed-case route, a `rest_route` sent in the body, a `batch/v1` sub-request or a
 * nested dispatch.
 *
 * Store API routes load and save the shopper's cart, so from the match on the request runs in the shopper's currency, as
 * client 11.3.0 `includes/multi-currency/MultiCurrency.php:396-506` does: the store-currency REST overrides are removed, the
 * storefront price and currency hooks are registered, the shopper's currency is selected, and the cart's saved totals are
 * checked when it loads. Only the request that matched clears the match when its callbacks have run; the hooks and the
 * removed overrides stay for the rest of the request, so later batch sub-requests also run in the shopper's currency.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencyStoreApiRouteController implements RegisterHooksInterface {

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Request context, which holds the match.
	 *
	 * @var RequestContext
	 */
	private RequestContext $request_context;

	/**
	 * Store-currency REST overrides.
	 *
	 * @var MultiCurrencyRestRequestOverrideController
	 */
	private MultiCurrencyRestRequestOverrideController $rest_request_overrides;

	/**
	 * Storefront price hooks.
	 *
	 * @var MultiCurrencyFrontendPricesController
	 */
	private MultiCurrencyFrontendPricesController $frontend_prices;

	/**
	 * Storefront currency hooks.
	 *
	 * @var MultiCurrencyFrontendCurrenciesController
	 */
	private MultiCurrencyFrontendCurrenciesController $frontend_currencies;

	/**
	 * Async price renderer.
	 *
	 * @var MultiCurrencyAsyncPriceRendererController
	 */
	private MultiCurrencyAsyncPriceRendererController $async_price_renderer;

	/**
	 * Selected currency hooks.
	 *
	 * @var MultiCurrencySelectedCurrencyController
	 */
	private MultiCurrencySelectedCurrencyController $selected_currency;

	/**
	 * The request that matched a Store API route, until its callbacks have run.
	 *
	 * @var \WP_REST_Request<array<string,mixed>>|null
	 */
	private ?\WP_REST_Request $matched_request = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter                $arbiter                Runtime owner arbiter.
	 * @param RequestContext                             $request_context        Request context.
	 * @param MultiCurrencyRestRequestOverrideController $rest_request_overrides Store-currency REST overrides.
	 * @param MultiCurrencyFrontendPricesController      $frontend_prices        Storefront price hooks.
	 * @param MultiCurrencyFrontendCurrenciesController  $frontend_currencies    Storefront currency hooks.
	 * @param MultiCurrencyAsyncPriceRendererController  $async_price_renderer   Async price renderer.
	 * @param MultiCurrencySelectedCurrencyController    $selected_currency      Selected currency hooks.
	 */
	final public function init(
		MultiCurrencyRuntimeArbiter $arbiter,
		RequestContext $request_context,
		MultiCurrencyRestRequestOverrideController $rest_request_overrides,
		MultiCurrencyFrontendPricesController $frontend_prices,
		MultiCurrencyFrontendCurrenciesController $frontend_currencies,
		MultiCurrencyAsyncPriceRendererController $async_price_renderer,
		MultiCurrencySelectedCurrencyController $selected_currency
	): void {
		$this->arbiter                = $arbiter;
		$this->request_context        = $request_context;
		$this->rest_request_overrides = $rest_request_overrides;
		$this->frontend_prices        = $frontend_prices;
		$this->frontend_currencies    = $frontend_currencies;
		$this->async_price_renderer   = $async_price_renderer;
		$this->selected_currency      = $selected_currency;
	}

	/**
	 * Watch the routes a REST request matches, including a request a front-end URL carries in a `rest_route` body field.
	 */
	public function register() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only request classification, as client 11.3.0 `MultiCurrency.php:396`.
		if ( ! $this->arbiter->is_builtin_owner() || ( ! $this->request_context->is_wc_rest_api_request() && empty( $_POST['rest_route'] ) ) ) {
			return;
		}

		// Priority 9: before other callbacks on the hook read the currency.
		add_filter( 'rest_request_before_callbacks', array( $this, 'handle_rest_request_before_callbacks' ), 9, 3 );
	}

	/**
	 * Run the rest of the request in the shopper's currency once a Store API route its URL did not name is matched.
	 *
	 * A request already treated as Store API, by its URL or an earlier match still running, matches nothing more, so a
	 * Store API request nested in a matched one leaves the match to the outer one.
	 *
	 * @internal
	 *
	 * @param mixed $response Result to send to the client.
	 * @param mixed $handler  Route handler used for the request.
	 * @param mixed $request  Request used to generate the response.
	 * @return mixed The response, unchanged.
	 */
	public function handle_rest_request_before_callbacks( $response, $handler, $request ) {
		unset( $handler );

		if ( ! $request instanceof \WP_REST_Request || $this->request_context->is_store_api_request() || ! RequestContext::is_store_api_route( $request->get_route() ) ) {
			return $response;
		}

		// Set first: the registrations below read it.
		$this->request_context->set_matched_store_api_route( true );
		$this->matched_request = $request;
		add_filter( 'rest_request_after_callbacks', array( $this, 'handle_rest_request_after_callbacks' ), 10, 3 );

		$this->rest_request_overrides->remove_overrides();
		$this->async_price_renderer->remove_hooks();
		$this->frontend_prices->register();
		$this->frontend_currencies->register();
		$this->selected_currency->start_matched_store_api_route();

		return $response;
	}

	/**
	 * Clear the match once the callbacks of the request that matched have run; a nested request ending inside it leaves it.
	 *
	 * @internal
	 *
	 * @param mixed $response Result to send to the client.
	 * @param mixed $handler  Route handler used for the request.
	 * @param mixed $request  Request whose callbacks have run.
	 * @return mixed The response, unchanged.
	 */
	public function handle_rest_request_after_callbacks( $response, $handler = null, $request = null ) {
		unset( $handler );

		if ( null !== $request && $request !== $this->matched_request ) {
			return $response;
		}

		$this->request_context->set_matched_store_api_route( false );
		$this->matched_request = null;
		$this->selected_currency->end_matched_store_api_route();
		remove_filter( 'rest_request_after_callbacks', array( $this, 'handle_rest_request_after_callbacks' ), 10 );

		return $response;
	}
}
