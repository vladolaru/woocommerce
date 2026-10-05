class ErrorHandler {
	/**
	 * @param {string}  genericErrorText
	 * @param {Element} wrapper
	 */
	constructor( genericErrorText, wrapper ) {
		this.genericErrorText = genericErrorText;
		this.wrapper = wrapper;
	}

	genericError() {
		this.clear();
		this.message( this.genericErrorText );
	}

	appendPreparedErrorMessageElement( errorMessageElement ) {
		this.#getMessageContainer().replaceWith( errorMessageElement );
	}

	/**
	 * @param {string} text
	 */
	message( text ) {
		this.#addMessage( text );

		this.#scrollToMessages();
	}

	/**
	 * @param {Array} texts
	 */
	messages( texts ) {
		texts.forEach( ( t ) => this.#addMessage( t ) );

		this.#scrollToMessages();
	}

	/**
	 * @return {string} The HTML of the message container.
	 */
	currentHtml() {
		const messageContainer = this.#getMessageContainer();
		return messageContainer.outerHTML;
	}

	/**
	 * @private
	 * @param {string} text
	 */
	#addMessage( text ) {
		if ( text.length === 0 ) {
			throw new Error( 'A new message text must be a non-empty string.' );
		}

		const messageContainer = this.#getMessageContainer();

		const messageNode = this.#prepareMessageElement( text );
		messageContainer.appendChild( messageNode );
	}

	/**
	 * @private
	 */
	#scrollToMessages() {
		jQuery.scroll_to_notices( jQuery( '.woocommerce-error' ) );
	}

	/**
	 * @private
	 */
	#getMessageContainer() {
		let messageContainer = document.querySelector( 'ul.woocommerce-error' );
		if ( messageContainer === null ) {
			messageContainer = document.createElement( 'ul' );
			messageContainer.setAttribute( 'class', 'woocommerce-error' );
			messageContainer.setAttribute( 'role', 'alert' );
			jQuery( this.wrapper ).prepend( messageContainer );
		}
		return messageContainer;
	}

	/**
	 * @param {string} message
	 * @private
	 */
	#prepareMessageElement( message ) {
		const li = document.createElement( 'li' );
		li.innerHTML = message;

		return li;
	}

	clear() {
		jQuery( '.woocommerce-error, .woocommerce-message' ).remove();
	}
}

export default ErrorHandler;
