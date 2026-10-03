class BaseAction {
	constructor( config ) {
		this.config = config;
	}

	get key() {
		return this.config.key;
	}

	register() {
		// To override.
	}

	run( _status ) {
		// To override.
	}
}

export default BaseAction;
