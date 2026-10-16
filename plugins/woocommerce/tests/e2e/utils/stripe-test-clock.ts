/**
 * Stripe test clocks for the local-only Stripe Billing renewal cases.
 *
 * A renewal billed by Stripe Billing only happens when Stripe's clock passes
 * the period end, so the renewal case binds its customer to a Stripe test
 * clock and advances it. Stripe only lets the platform account create clocks,
 * so this helper uses the local platform's Stripe test secret key, read at run
 * time from the local WPCOM env's secrets file. The key stays in this
 * process: it is never written to the checkout, a fixture, a log or a report,
 * and errors carry Stripe's message only, never the request.
 *
 * It refuses any key that is not a test key, and it only ever acts on the
 * connected account of the store under test, read from the store after the
 * test-mode guard passes.
 */

/**
 * External dependencies
 */
import { readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

/**
 * Internal dependencies
 */
import { requireTestModeAccount } from './woopayments';

/** Environment variable that overrides where the secrets file is read from. */
const SECRETS_FILE_ENV = 'WOOPAYMENTS_STRIPE_TEST_SECRETS_FILE';
const SECRETS_KEY = 'stripe_test_secret_key';
const STRIPE_API = 'https://api.stripe.com/v1';
const ACCOUNTS_ROUTE = 'wc/v3/payments/accounts';
const CLOCK_READY_BUDGET_MS = 120_000;
const CLOCK_POLL_MS = 2_000;

type StripeObject = Record< string, unknown >;

function secretsFile(): string {
	return (
		process.env[ SECRETS_FILE_ENV ] ||
		join( homedir(), '.wpcom-local', 'secrets', 'transact.json' )
	);
}

/**
 * Read the Stripe test secret key, or null when the secrets file or key is
 * absent, so the caller can skip.
 *
 * @throws When the key is present but is not a Stripe test key.
 */
function readTestKey(): string | null {
	let secrets: Record< string, unknown >;
	try {
		secrets = JSON.parse( readFileSync( secretsFile(), 'utf8' ) );
	} catch {
		return null;
	}

	const key = secrets[ SECRETS_KEY ];
	if ( typeof key !== 'string' || key === '' ) {
		return null;
	}
	if ( ! key.startsWith( 'sk_test_' ) ) {
		throw new Error(
			`The ${ SECRETS_KEY } in the local WPCOM secrets file is not a Stripe test key; refusing to use it.`
		);
	}

	return key;
}

/** Tell whether the Stripe test key is available, for `test.skip()`. */
export function hasStripeTestKey(): boolean {
	return readTestKey() !== null;
}

function encodeForm(
	params: Record< string, string | number >,
	prefix = ''
): string {
	return Object.entries( params )
		.map(
			( [ name, value ] ) =>
				`${ encodeURIComponent(
					prefix ? `${ prefix }[${ name }]` : name
				) }=${ encodeURIComponent( String( value ) ) }`
		)
		.join( '&' );
}

/**
 * Stripe test-clock calls on the connected account of the store under test.
 */
export class StripeTestClock {
	private constructor(
		private readonly key: string,
		readonly accountId: string
	) {}

	/**
	 * Bind to the store's connected account, after the store's test-mode guard
	 * passes.
	 *
	 * @throws When the key is absent or not a test key, or the store is not in test mode.
	 */
	static async forStore( restApi: ApiClient ): Promise< StripeTestClock > {
		const key = readTestKey();
		if ( key === null ) {
			throw new Error(
				`No Stripe test key at ${ secretsFile() }; skip the case with hasStripeTestKey().`
			);
		}
		await requireTestModeAccount( restApi );
		const account = ( await restApi.get( ACCOUNTS_ROUTE ) ).data as {
			account_id?: unknown;
		};
		if (
			typeof account.account_id !== 'string' ||
			! account.account_id.startsWith( 'acct_' )
		) {
			throw new Error(
				'The store reports no connected account id; refusing to call Stripe.'
			);
		}

		return new StripeTestClock( key, account.account_id );
	}

	private async request(
		method: 'GET' | 'POST' | 'DELETE',
		path: string,
		params: Record< string, string | number > = {}
	): Promise< StripeObject > {
		const body = encodeForm( params );
		const response = await fetch(
			`${ STRIPE_API }/${ path }${
				method === 'GET' && body ? `?${ body }` : ''
			}`,
			{
				method,
				headers: {
					Authorization: `Bearer ${ this.key }`,
					'Stripe-Account': this.accountId,
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: method === 'POST' ? body : undefined,
			}
		);
		const data = ( await response.json() ) as StripeObject & {
			error?: { message?: string };
		};
		if ( ! response.ok ) {
			throw new Error(
				`Stripe ${ method } ${ path } failed (${ response.status }): ${
					data.error?.message ?? 'no message'
				}`
			);
		}

		return data;
	}

	/** Create a test clock frozen at the given Unix time. */
	createClock( frozenTime: number, name: string ): Promise< StripeObject > {
		return this.request( 'POST', 'test_helpers/test_clocks', {
			frozen_time: frozenTime,
			name,
		} );
	}

	/** Create a customer bound to a clock, with a test payment method as its default. */
	async createClockCustomer(
		clockId: string,
		testPaymentMethod: string
	): Promise< StripeObject > {
		const customer = await this.request( 'POST', 'customers', {
			test_clock: clockId,
		} );
		await this.setDefaultPaymentMethod(
			String( customer.id ),
			testPaymentMethod
		);

		return customer;
	}

	/**
	 * Attach a Stripe test payment method (such as `pm_card_visa` or
	 * `pm_card_chargeCustomerFail`) and make it the customer's default.
	 */
	async setDefaultPaymentMethod(
		customerId: string,
		testPaymentMethod: string
	): Promise< StripeObject > {
		const paymentMethod = await this.request(
			'POST',
			`payment_methods/${ encodeURIComponent(
				testPaymentMethod
			) }/attach`,
			{ customer: customerId }
		);
		await this.request(
			'POST',
			`customers/${ encodeURIComponent( customerId ) }`,
			{
				'invoice_settings[default_payment_method]': String(
					paymentMethod.id
				),
			}
		);

		return paymentMethod;
	}

	/** Advance a clock, then wait until Stripe reports it ready. */
	async advanceClock(
		clockId: string,
		frozenTime: number
	): Promise< StripeObject > {
		const path = `test_helpers/test_clocks/${ encodeURIComponent(
			clockId
		) }`;
		await this.request( 'POST', `${ path }/advance`, {
			frozen_time: frozenTime,
		} );
		const deadline = Date.now() + CLOCK_READY_BUDGET_MS;
		for (;;) {
			const clock = await this.request( 'GET', path );
			if ( clock.status === 'ready' ) {
				return clock;
			}
			if ( Date.now() > deadline ) {
				throw new Error(
					`Stripe test clock ${ clockId } is not ready after ${ CLOCK_READY_BUDGET_MS } ms.`
				);
			}
			await new Promise( ( resolve ) =>
				setTimeout( resolve, CLOCK_POLL_MS )
			);
		}
	}

	/** Read a Stripe object, such as `subscriptions/sub_...` or `invoices/in_...`. */
	get( path: string ): Promise< StripeObject > {
		return this.request( 'GET', path );
	}

	/** Delete a clock; Stripe deletes its customers and subscriptions with it. */
	deleteClock( clockId: string ): Promise< StripeObject > {
		return this.request(
			'DELETE',
			`test_helpers/test_clocks/${ encodeURIComponent( clockId ) }`
		);
	}
}
