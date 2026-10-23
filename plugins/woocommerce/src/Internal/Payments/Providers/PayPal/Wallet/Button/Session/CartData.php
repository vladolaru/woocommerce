<?php

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Session;

/**
 * Contains a snapshot of the WC cart data, e.g. for saving it and creating an order later.
 */
class CartData {
	/**
	 * The key.
	 *
	 * @var string|null
	 */
	protected ?string $key = null;

	/**
	 * The cart items, keyed by cart item key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	protected array $items;

	/**
	 * The applied coupon codes.
	 *
	 * @var string[]
	 */
	protected array $coupons;

	/**
	 * The cart fees, each normalized to an array as in CartDataFactory.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	protected array $fees = array();

	/**
	 * Whether the cart needs shipping.
	 *
	 * @var bool
	 */
	protected bool $needs_shipping;

	/**
	 * The customer user ID.
	 *
	 * @var int
	 */
	protected int $user_id;

	/**
	 * The cart hash.
	 *
	 * @var string
	 */
	protected string $cart_hash;

	/**
	 * The PayPal order ID.
	 *
	 * @var string|null
	 */
	protected ?string $paypal_order_id = null;

	/**
	 * The session customer ID.
	 *
	 * @var string|null
	 */
	protected ?string $session_customer_id = null;

	/**
	 * CartData constructor.
	 *
	 * @param array<string, array<string, mixed>> $items The cart items like in $cart->get_cart_for_session() or $cart->get_cart().
	 * @param string[]                            $coupons Applied coupon codes.
	 * @param bool                                $needs_shipping Whether the cart needs shipping.
	 * @param int                                 $user_id The customer user ID.
	 * @param string                              $cart_hash The cart hash.
	 * @param array<string, array<string, mixed>> $fees Optional.
	 */
	public function __construct(
		array $items,
		array $coupons,
		bool $needs_shipping,
		int $user_id,
		string $cart_hash,
		array $fees = array()
	) {
		$this->items          = $items;
		$this->coupons        = $coupons;
		$this->needs_shipping = $needs_shipping;
		$this->user_id        = $user_id;
		$this->cart_hash      = $cart_hash;
		$this->fees           = $fees;
	}

	/**
	 * Generates a new random key.
	 */
	public function generate_key(): void {
		$this->key = uniqid( '', true );
	}

	/**
	 * Returns the key that can be used for identifying the instance in storage.
	 */
	public function key(): ?string {
		return $this->key;
	}

	/**
	 * The cart items like in $cart->get_cart_for_session() or $cart->get_cart().
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * Returns the applied coupon codes.
	 *
	 * @return string[]
	 */
	public function coupons(): array {
		return $this->coupons;
	}

	/**
	 * The cart fees, each normalized to an array as in CartDataFactory.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fees(): array {
		return $this->fees;
	}

	/**
	 * Returns whether the cart needs shipping.
	 */
	public function needs_shipping(): bool {
		return $this->needs_shipping;
	}

	/**
	 * Returns the customer user ID.
	 */
	public function user_id(): int {
		return $this->user_id;
	}

	/**
	 * Returns the cart hash.
	 */
	public function cart_hash(): string {
		return $this->cart_hash;
	}

	/**
	 * Sets the PayPal order ID.
	 *
	 * @param string|null $paypal_order_id The paypal order id.
	 */
	public function set_paypal_order_id( ?string $paypal_order_id ): void {
		$this->paypal_order_id = $paypal_order_id;
	}

	/**
	 * Returns the PayPal order ID.
	 */
	public function paypal_order_id(): ?string {
		return $this->paypal_order_id;
	}

	/**
	 * Sets the session customer ID.
	 *
	 * @param string|null $session_customer_id The session customer id.
	 */
	public function set_session_customer_id( ?string $session_customer_id ): void {
		$this->session_customer_id = $session_customer_id;
	}

	/**
	 * Returns the session customer ID.
	 */
	public function session_customer_id(): ?string {
		return $this->session_customer_id;
	}

	/**
	 * Returns the cart data as an array.
	 */
	public function to_array(): array {
		return array(
			'items'               => $this->items,
			'coupons'             => $this->coupons,
			'fees'                => $this->fees,
			'needs_shipping'      => $this->needs_shipping,
			'user_id'             => $this->user_id,
			'cart_hash'           => $this->cart_hash,
			'paypal_order_id'     => $this->paypal_order_id,
			'session_customer_id' => $this->session_customer_id,
		);
	}

	/**
	 * Creates the cart data from an array.
	 *
	 * @param array       $data The data.
	 * @param string|null $key  The key.
	 */
	public static function from_array( array $data, ?string $key = null ): CartData {
		$cart_data                      = new CartData(
			$data['items'] ?? array(),
			$data['coupons'] ?? array(),
			(bool) ( $data['needs_shipping'] ?? false ),
			(int) ( $data['user_id'] ?? 0 ),
			$data['cart_hash'] ?? '',
			$data['fees'] ?? array()
		);
		$cart_data->paypal_order_id     = $data['paypal_order_id'] ?? null;
		$cart_data->session_customer_id = $data['session_customer_id'] ?? null;
		$cart_data->key                 = $key;
		return $cart_data;
	}
}
