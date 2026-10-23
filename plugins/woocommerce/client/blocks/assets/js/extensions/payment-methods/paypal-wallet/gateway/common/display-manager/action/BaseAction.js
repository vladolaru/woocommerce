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

	run() {
		// To override. Receives the rule's status.
	}
}

export default BaseAction;
