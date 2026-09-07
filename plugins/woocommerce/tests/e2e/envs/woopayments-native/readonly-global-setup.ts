import { execFileSync } from 'node:child_process';

import {
	request,
	type APIRequestContext,
	type FullConfig,
} from '@playwright/test';

import { admin } from '../../test-data/data';
import { ensureReadonlyCatalog } from '../../utils/woopayments-native/readonly-catalog';
import { ADMIN_STATE_PATH } from '../../playwright.config';

const READONLY_PROJECT = 'woopayments-native-readonly';

export type ReadonlySetupRequestContext = Pick<
	APIRequestContext,
	'get' | 'post' | 'storageState' | 'dispose'
>;

export interface ReadonlyGlobalSetupDependencies {
	runtime: string | undefined;
	seedNative: () => void;
	newRequestContext: ( options: {
		baseURL: string;
	} ) => Promise< ReadonlySetupRequestContext >;
	seedCatalog: (
		api: ReadonlySetupRequestContext,
		nonce: string
	) => Promise< void >;
}

interface AdminWarmResponse {
	ok: () => boolean;
	status: () => number;
	url: () => string;
	text: () => Promise< string >;
}

export async function authenticatedAdminNonce(
	response: AdminWarmResponse,
	baseURL: string
): Promise< string > {
	const expected = new URL( baseURL );
	const actual = new URL( response.url() );
	const adminPath = `${ expected.pathname.replace( /\/+$/, '' ) }/wp-admin/`;
	const body = await response.text();
	const authenticated =
		response.ok() &&
		actual.origin === expected.origin &&
		actual.pathname.startsWith( adminPath ) &&
		! actual.searchParams.has( 'action' ) &&
		! /id=["']loginform["']/.test( body ) &&
		/id=["']wpadminbar["']/.test( body );
	if ( ! authenticated ) {
		throw new Error(
			`Readonly admin session did not reach authenticated wp-admin: HTTP ${ response.status() } ${ response.url() }`
		);
	}

	const settingsMatch = body.match( /var wpApiSettings = (\{.*?\});/s );
	if ( ! settingsMatch ) {
		throw new Error(
			'Readonly authenticated wp-admin did not expose wpApiSettings.'
		);
	}
	const settings = JSON.parse( settingsMatch[ 1 ] ) as {
		nonce?: unknown;
	};
	if ( typeof settings.nonce !== 'string' || settings.nonce === '' ) {
		throw new Error(
			'Readonly authenticated wp-admin did not expose a REST nonce.'
		);
	}
	return settings.nonce;
}

export function selectedReadonlyProject( config: FullConfig ): boolean {
	return config.projects.some(
		( project ) => project.metadata.woopaymentsReadonlySetup === true
	);
}

export async function runReadonlyGlobalSetup(
	config: FullConfig,
	dependencies: ReadonlyGlobalSetupDependencies
): Promise< void > {
	if ( ! selectedReadonlyProject( config ) ) {
		return;
	}

	const runtime = dependencies.runtime;
	if ( runtime === 'native' ) {
		dependencies.seedNative();
	} else if ( runtime !== 'client' ) {
		throw new Error(
			'Readonly global setup requires WCPAY_RUNTIME=native|client.'
		);
	}

	const baseURL = config.projects.find(
		( project ) => project.name === READONLY_PROJECT
	)?.use.baseURL as string | undefined;
	if ( ! baseURL ) {
		throw new Error(
			'Readonly global setup requires a Playwright baseURL.'
		);
	}

	const adminSession = await dependencies.newRequestContext( { baseURL } );
	try {
		const loginForm = await adminSession.get( './wp-login.php' );
		if ( ! loginForm.ok() ) {
			throw new Error(
				`Readonly admin login form failed: HTTP ${ loginForm.status() }`
			);
		}
		const login = await adminSession.post( './wp-login.php', {
			form: {
				log: admin.username,
				pwd: admin.password,
				'wp-submit': 'Log In',
				redirect_to: new URL( './wp-admin/', baseURL ).toString(),
				testcookie: '1',
			},
		} );
		if ( ! login.ok() ) {
			throw new Error(
				`Readonly admin warm-up failed: HTTP ${ login.status() }`
			);
		}
		const warm = await adminSession.get( './wp-admin/' );
		const nonce = await authenticatedAdminNonce( warm, baseURL );
		if ( runtime === 'native' ) {
			await dependencies.seedCatalog( adminSession, nonce );
		}
		await adminSession.storageState( { path: ADMIN_STATE_PATH } );
	} finally {
		await adminSession.dispose();
	}
}

export default async function readonlyGlobalSetup(
	config: FullConfig
): Promise< void > {
	return runReadonlyGlobalSetup( config, {
		runtime: process.env.WCPAY_RUNTIME,
		seedNative: () => {
			execFileSync( 'bash', [ `${ __dirname }/seed-readonly.sh` ], {
				cwd:
					process.env.E2E_WOOPAYMENTS_NATIVE_STORE_DIR ??
					process.cwd(),
				stdio: 'inherit',
			} );
		},
		newRequestContext: ( options ) => request.newContext( options ),
		seedCatalog: ( api, nonce ) => ensureReadonlyCatalog( api, nonce ),
	} );
}
