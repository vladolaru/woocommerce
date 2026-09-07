import { execFileSync } from 'node:child_process';

import { request, type FullConfig } from '@playwright/test';

import { admin } from '../../test-data/data';
import { ADMIN_STATE_PATH } from '../../playwright.config';

const READONLY_PROJECT = 'woopayments-native-readonly';

export function selectedReadonlyProject( config: FullConfig ): boolean {
	return config.projects.some(
		( project ) => project.name === READONLY_PROJECT
	);
}

export default async function readonlyGlobalSetup(
	config: FullConfig
): Promise< void > {
	if ( ! selectedReadonlyProject( config ) ) {
		return;
	}

	execFileSync( 'bash', [ `${ __dirname }/seed-readonly.sh` ], {
		cwd: process.env.E2E_WOOPAYMENTS_NATIVE_STORE_DIR ?? process.cwd(),
		stdio: 'inherit',
	} );

	const baseURL = config.projects.find(
		( project ) => project.name === READONLY_PROJECT
	)?.use.baseURL as string | undefined;
	if ( ! baseURL ) {
		throw new Error(
			'Readonly global setup requires a Playwright baseURL.'
		);
	}

	const adminSession = await request.newContext( { baseURL } );
	try {
		const login = await adminSession.post( './wp-login.php', {
			form: { log: admin.username, pwd: admin.password },
		} );
		if ( ! login.ok() ) {
			throw new Error(
				`Readonly admin warm-up failed: HTTP ${ login.status() }`
			);
		}
		const warm = await adminSession.get( './wp-admin/' );
		if ( ! warm.ok() || ! warm.url().includes( '/wp-admin/' ) ) {
			throw new Error(
				`Readonly admin session did not reach wp-admin: HTTP ${ warm.status() } ${ warm.url() }`
			);
		}
		await adminSession.storageState( { path: ADMIN_STATE_PATH } );
	} finally {
		await adminSession.dispose();
	}
}
