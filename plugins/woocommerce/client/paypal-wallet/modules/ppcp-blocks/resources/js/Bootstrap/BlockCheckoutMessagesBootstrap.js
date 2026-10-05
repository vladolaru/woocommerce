import MessagesBootstrap from '../../../../ppcp-button/resources/js/modules/ContextBootstrap/MessagesBootstrap';
import { debounce } from '../Helper/debounce';

class BlockCheckoutMessagesBootstrap {
	constructor( scriptData ) {
		this.messagesBootstrap = new MessagesBootstrap( scriptData, null );
		this.lastCartTotal = null;
	}

	init() {
		this.messagesBootstrap.init();

		this.#updateCartTotal();

		if ( wp.data?.subscribe ) {
			wp.data.subscribe(
				debounce( () => {
					this.#updateCartTotal();
				}, 300 )
			);
		}
	}

	/**
	 * @private
	 */
	#getCartTotal() {
		if ( ! wp.data.select ) {
			return null;
		}

		const cart = wp.data.select( 'wc/store/cart' );
		if ( ! cart ) {
			return null;
		}

		const totals = cart.getCartTotals();
		return (
			parseInt( totals.total_price, 10 ) /
			10 ** totals.currency_minor_unit
		);
	}

	/**
	 * @private
	 */
	#updateCartTotal() {
		const currentTotal = this.#getCartTotal();
		if ( currentTotal === null ) {
			return;
		}

		if ( currentTotal !== this.lastCartTotal ) {
			this.lastCartTotal = currentTotal;
			jQuery( document.body ).trigger( 'ppcp_block_cart_total_updated', [
				currentTotal,
			] );
		}
	}
}

export default BlockCheckoutMessagesBootstrap;
