<?php
/**
 * The renderer.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Renderer
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Renderer;

use Automattic\WooCommerce\Internal\Admin\WCAdminAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Repository\RepositoryInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Endpoint\MuteMessageEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Entity\PersistentMessage;

/**
 * Class Renderer
 */
class Renderer implements RendererInterface {

	/**
	 * The message repository.
	 *
	 * @var RepositoryInterface
	 */
	private $repository;

	/**
	 * Used to enqueue assets.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Whether the current page contains at least one message that can be muted.
	 *
	 * @var bool
	 */
	private $can_mute_message = false;

	/**
	 * Renderer constructor.
	 *
	 * @param RepositoryInterface $repository The message repository.
	 * @param string              $version    The module version.
	 */
	public function __construct(
		RepositoryInterface $repository,
		string $version
	) {
		$this->repository = $repository;
		$this->version    = $version;
	}

	/**
	 * {@inheritDoc}
	 */
	public function render(): bool {
		$messages = $this->repository->current_message();

		foreach ( $messages as $message ) {
			$mute_message_id = '';

			if ( $message instanceof PersistentMessage ) {
				$this->can_mute_message = true;

				$mute_message_id = $message->id();
			}

			printf(
				'<div class="notice notice-%s %s" %s%s><p>%s</p></div>',
				esc_attr( $message->type() ),
				( $message->is_dismissible() ) ? 'is-dismissible' : '',
				( $message->wrapper() ? sprintf( 'data-ppcp-wrapper="%s"', esc_attr( $message->wrapper() ) ) : '' ),
				// Use `empty()` in condition, to avoid false phpcs warning.
				( empty( $mute_message_id ) ? '' : sprintf( 'data-ppcp-msg-id="%s"', esc_attr( $mute_message_id ) ) ),
				wp_kses_post( $message->message() )
			);
		}

		return (bool) count( $messages );
	}

	/**
	 * {@inheritDoc}
	 */
	public function enqueue_admin(): void {
		if ( ! $this->can_mute_message ) {
			return;
		}

		wp_register_style(
			'wc-ppcp-admin-notice',
			WCAdminAssets::get_url( 'paypal-wallet-notices/style', 'css' ),
			array(),
			$this->version
		);
		wp_style_add_data( 'wc-ppcp-admin-notice', 'rtl', 'replace' );
		wp_register_script(
			'wc-ppcp-admin-notice',
			WCAdminAssets::get_url( 'wp-admin-scripts/paypal-wallet-notices', 'js' ),
			array(),
			$this->version,
			true
		);

		wp_localize_script(
			'wc-ppcp-admin-notice',
			'wc_admin_notices',
			$this->script_data_for_admin()
		);

		wp_enqueue_style( 'wc-ppcp-admin-notice' );
		wp_enqueue_script( 'wc-ppcp-admin-notice' );
	}

	/**
	 * Data to inject into the current admin page, which is required by JS assets.
	 *
	 * @return array
	 */
	protected function script_data_for_admin(): array {
		$ajax_url = admin_url( 'admin-ajax.php' );

		return array(
			'ajax' => array(
				'mute_message' => array(
					'endpoint' => add_query_arg(
						array( 'action' => MuteMessageEndpoint::ENDPOINT ),
						$ajax_url
					),
					'nonce'    => wp_create_nonce( MuteMessageEndpoint::nonce() ),
				),
			),
		);
	}
}
