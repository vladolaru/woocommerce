import { spawn, type ChildProcessWithoutNullStreams } from 'node:child_process';
import { createHash } from 'node:crypto';
import { stripVTControlCharacters } from 'node:util';

import type { BrowserContext, Page } from '@playwright/test';

import type { ProviderWriteSession } from '../../../fixtures/woopayments-native';
import {
	ResourceQuarantineRequiredError,
	type JsonValue,
} from '../resource-locks';

const ACCOUNT_OPTION = 'wcpay_account_data';
const FORCE_OPTION = 'wcpaydev_force_card_testing_protection_on';
const CLASSIC_CHECKOUT_SLUG = 'classic-checkout';
const SESSION_COOKIE_PREFIX = 'wp_woocommerce_session_';
const TOKEN_LENGTH = 16;
const SHA256_PATTERN = /^[a-f0-9]{64}$/;
const CUSTOMER_ID_PATTERN = /^t_[a-f0-9]{30}$/;
const MARKER_PATTERN = /^woopayments-e2e-[a-f0-9]{16}$/;
const MAX_RUNNER_OUTPUT_BYTES = 1024 * 1024;
const WP_CLI_STARTUP_TIMEOUT_MS = 30_000;
const WP_CLI_INNER_TIMEOUT_SECONDS = 45;
const WP_CLI_OPERATION_TIMEOUT_MS = 50_000;
const WP_CLI_MUTATION_SAFETY_TIMEOUT_MS = 46_000;
const WP_CLI_TERMINATION_GRACE_MS = 2_000;
const WP_CLI_CLOSE_FALLBACK_MS = 5_000;
export const NATIVE_STORE_READY_SENTINEL = '__WCPAY_E2E_WP_CLI_READY__';
export const NATIVE_STORE_DONE_SENTINEL = '__WCPAY_E2E_WP_CLI_DONE__';

type RawOptionRow = Readonly< {
	exists: boolean;
	valueBase64: string | null;
	autoload: string | null;
} >;

interface ProtectionSnapshot {
	accountOption: RawOptionRow;
	forceOption: RawOptionRow;
	classicCheckoutSlug: typeof CLASSIC_CHECKOUT_SLUG;
	runMarker: string;
	originalEffectiveProtection: boolean;
}

type CardTestingProtectionOperation =
	| 'capture-state'
	| 'mutate-state'
	| 'verify-mutated-state'
	| 'read-guest-session'
	| 'delete-guest-session'
	| 'verify-session-absent'
	| 'restore-state'
	| 'verify-restored-state';

export interface CardTestingProtectionRunnerRequest {
	operation: CardTestingProtectionOperation;
	input: Record< string, unknown >;
}

export interface CardTestingProtectionRunner {
	run( request: CardTestingProtectionRunnerRequest ): Promise< unknown >;
}

export interface CardTestingTokenDigest {
	length: 16;
	sha256: string;
}

export interface CardTestingProtectionScope {
	readonly classicCheckout: Readonly< {
		pageId: number;
		slug: typeof CLASSIC_CHECKOUT_SLUG;
		path: 'classic-checkout/';
	} >;
	registerFreshContext( source: Page | BrowserContext ): Promise< void >;
	captureGuestSessionToken(
		source: Page | BrowserContext
	): Promise< CardTestingTokenDigest >;
}

interface ControllerOptions {
	runner?: CardTestingProtectionRunner;
}

interface TrackedGuestSession {
	customerId: string;
	verified: boolean;
}

interface TrackedSessionCookie {
	name: string;
	value: string;
	domain: string;
	path: string;
}

function exactKeys( value: object, keys: readonly string[] ): boolean {
	return (
		JSON.stringify( Object.keys( value ).sort() ) ===
		JSON.stringify( [ ...keys ].sort() )
	);
}

function isPlainObject( value: unknown ): value is Record< string, unknown > {
	return (
		typeof value === 'object' &&
		value !== null &&
		! Array.isArray( value ) &&
		( Object.getPrototypeOf( value ) === Object.prototype ||
			Object.getPrototypeOf( value ) === null )
	);
}

function invalid( message: string ): never {
	throw new Error( message );
}

function assertBase64( value: string ): void {
	if (
		! /^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/.test(
			value
		) ||
		Buffer.from( value, 'base64' ).toString( 'base64' ) !== value
	) {
		invalid( 'Protection snapshot contains invalid raw option bytes.' );
	}
}

function assertRawOptionRow( value: unknown ): RawOptionRow {
	if (
		! isPlainObject( value ) ||
		! exactKeys( value, [ 'autoload', 'exists', 'valueBase64' ] ) ||
		typeof value.exists !== 'boolean'
	) {
		invalid( 'Protection snapshot contains an invalid option row.' );
	}
	if ( value.exists ) {
		if (
			typeof value.valueBase64 !== 'string' ||
			typeof value.autoload !== 'string'
		) {
			invalid( 'Protection snapshot contains an incomplete option row.' );
		}
		assertBase64( value.valueBase64 );
	} else if ( value.valueBase64 !== null || value.autoload !== null ) {
		invalid(
			'Protection snapshot contains an ambiguous absent option row.'
		);
	}
	return {
		exists: value.exists,
		valueBase64: value.valueBase64,
		autoload: value.autoload,
	};
}

function assertSnapshot( value: unknown ): ProtectionSnapshot {
	if (
		! isPlainObject( value ) ||
		! exactKeys( value, [
			'accountOption',
			'classicCheckoutSlug',
			'forceOption',
			'originalEffectiveProtection',
			'runMarker',
		] ) ||
		value.classicCheckoutSlug !== CLASSIC_CHECKOUT_SLUG ||
		typeof value.runMarker !== 'string' ||
		! MARKER_PATTERN.test( value.runMarker ) ||
		typeof value.originalEffectiveProtection !== 'boolean'
	) {
		invalid( 'Invalid card-testing protection restoration snapshot.' );
	}
	return {
		accountOption: assertRawOptionRow( value.accountOption ),
		forceOption: assertRawOptionRow( value.forceOption ),
		classicCheckoutSlug: CLASSIC_CHECKOUT_SLUG,
		runMarker: value.runMarker,
		originalEffectiveProtection: value.originalEffectiveProtection,
	};
}

function assertEnvelope( value: unknown, baseURL: string ): unknown {
	if (
		! isPlainObject( value ) ||
		! exactKeys( value, [ 'home', 'payload', 'site' ] ) ||
		value.home !== baseURL ||
		value.site !== baseURL
	) {
		invalid(
			'Native-store WP-CLI URL proof did not match the selected store.'
		);
	}
	return value.payload;
}

function assertPayload(
	value: unknown,
	keys: readonly string[]
): Record< string, unknown > {
	if ( ! isPlainObject( value ) || ! exactKeys( value, keys ) ) {
		invalid( 'Native-store WP-CLI returned an invalid operation result.' );
	}
	return value;
}

function deterministicMarker( runId: string ): string {
	return `woopayments-e2e-${ createHash( 'sha256' )
		.update( runId )
		.digest( 'hex' )
		.slice( 0, 16 ) }`;
}

function assertSafeNativeStoreBaseUrl( baseURL: string ): void {
	let parsed: URL;
	try {
		parsed = new URL( baseURL );
	} catch {
		invalid( 'Card-testing protection requires a valid native store URL.' );
	}
	if ( parsed.port === '8082' ) {
		invalid(
			'Card-testing protection refuses the standing native store on port 8082.'
		);
	}
}

function asContext( source: Page | BrowserContext ): BrowserContext {
	if (
		typeof ( source as Page ).context === 'function' &&
		typeof ( source as BrowserContext ).cookies !== 'function'
	) {
		return ( source as Page ).context();
	}
	return source as BrowserContext;
}

function parseGuestCustomerId( cookieValue: string ): string {
	const parts = cookieValue.includes( '||' )
		? cookieValue.split( '||' )
		: cookieValue.split( '|' );
	if ( parts.length !== 4 || ! CUSTOMER_ID_PATTERN.test( parts[ 0 ] ) ) {
		invalid( 'WooCommerce guest session cookie has an invalid structure.' );
	}
	return parts[ 0 ];
}

function quarantine(
	message: string,
	reasonCode: ConstructorParameters<
		typeof ResourceQuarantineRequiredError
	>[ 1 ],
	primaryError?: unknown
): ResourceQuarantineRequiredError {
	return new ResourceQuarantineRequiredError(
		message,
		reasonCode,
		primaryError
	);
}

function toError( value: unknown ): Error {
	return value instanceof Error ? value : new Error( String( value ) );
}

async function runOperation(
	runner: CardTestingProtectionRunner,
	operation: CardTestingProtectionOperation,
	input: Record< string, unknown >,
	baseURL: string
): Promise< unknown > {
	return assertEnvelope( await runner.run( { operation, input } ), baseURL );
}

async function assertWriteAuthorized(
	session: ProviderWriteSession
): Promise< void > {
	try {
		await session.assertCanWrite();
	} catch ( error ) {
		throw quarantine(
			'Card-testing protection write authorization was lost.',
			'lock-ownership-lost',
			error
		);
	}
}

function assertCaptureResult( value: unknown ): {
	accountOption: RawOptionRow;
	forceOption: RawOptionRow;
	effectiveProtection: boolean;
} {
	const payload = assertPayload( value, [
		'accountConnected',
		'accountOption',
		'classicPageExists',
		'effectiveProtection',
		'forceOption',
	] );
	if (
		payload.accountConnected !== true ||
		typeof payload.effectiveProtection !== 'boolean' ||
		typeof payload.classicPageExists !== 'boolean' ||
		payload.classicPageExists
	) {
		invalid( 'Native account or Classic checkout allocation is unsafe.' );
	}
	return {
		accountOption: assertRawOptionRow( payload.accountOption ),
		forceOption: assertRawOptionRow( payload.forceOption ),
		effectiveProtection: payload.effectiveProtection,
	};
}

function assertMutationResult( value: unknown ): number {
	const payload = assertPayload( value, [ 'pageId' ] );
	if (
		typeof payload.pageId !== 'number' ||
		! Number.isSafeInteger( payload.pageId ) ||
		payload.pageId <= 0
	) {
		invalid( 'Classic checkout page allocation was ambiguous.' );
	}
	return payload.pageId;
}

function assertMutationProof( value: unknown ): void {
	const payload = assertPayload( value, [
		'accountConnected',
		'accountProtection',
		'cacheUsable',
		'forceProtection',
		'pageMatches',
	] );
	if (
		payload.accountConnected !== true ||
		payload.accountProtection !== true ||
		payload.cacheUsable !== true ||
		payload.forceProtection !== true ||
		payload.pageMatches !== true
	) {
		invalid( 'Cold card-testing protection proof did not match mutation.' );
	}
}

function assertRestoreResult( value: unknown ): void {
	const payload = assertPayload( value, [ 'pageAbsent', 'restored' ] );
	if ( payload.restored !== true || payload.pageAbsent !== true ) {
		invalid( 'Raw protection-state restoration was incomplete.' );
	}
}

function assertRestorationProof(
	value: unknown,
	originalEffectiveProtection: boolean
): void {
	const payload = assertPayload( value, [
		'effectiveProtection',
		'pageAbsent',
		'rowsMatch',
		'sessionAbsent',
	] );
	if (
		payload.rowsMatch !== true ||
		payload.effectiveProtection !== originalEffectiveProtection ||
		payload.pageAbsent !== true ||
		payload.sessionAbsent !== true
	) {
		invalid(
			'Cold protection-state restoration proof did not match snapshot.'
		);
	}
}

function assertSessionIdentity( value: unknown ): unknown {
	const payload = assertPayload( value, [
		'cookieValid',
		'sessionExists',
		'token',
	] );
	if ( payload.cookieValid !== true || payload.sessionExists !== true ) {
		invalid(
			'WooCommerce guest session identity is missing or malformed.'
		);
	}
	return payload.token;
}

function assertTokenDigest( value: unknown ): CardTestingTokenDigest {
	if (
		! isPlainObject( value ) ||
		! exactKeys( value, [ 'length', 'sha256' ] ) ||
		value.length !== TOKEN_LENGTH ||
		typeof value.sha256 !== 'string' ||
		! SHA256_PATTERN.test( value.sha256 )
	) {
		invalid( 'WooCommerce session token proof is missing or malformed.' );
	}
	return { length: TOKEN_LENGTH, sha256: value.sha256 };
}

function assertDeleteResult( value: unknown ): void {
	const payload = assertPayload( value, [ 'deleted' ] );
	if ( payload.deleted !== true ) {
		invalid( 'Exact WooCommerce guest session deletion failed.' );
	}
}

function assertSessionAbsent( value: unknown ): void {
	const payload = assertPayload( value, [ 'cacheAbsent', 'rawAbsent' ] );
	if ( payload.rawAbsent !== true || payload.cacheAbsent !== true ) {
		invalid( 'WooCommerce guest session remained after deletion.' );
	}
}

const NATIVE_STORE_PHP = String.raw`<?php
function wcpay_e2e_fail() { throw new RuntimeException( 'WooPayments E2E state operation failed.' ); }
function wcpay_e2e_exact_keys( $value, $keys ) { if ( ! is_array( $value ) ) { wcpay_e2e_fail(); } $actual = array_keys( $value ); sort( $actual ); sort( $keys ); if ( $actual !== $keys ) { wcpay_e2e_fail(); } }
function wcpay_e2e_row( $name ) { global $wpdb; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_value, autoload FROM %i WHERE option_name = %s', $wpdb->options, $name ), ARRAY_A ); if ( 1 < count( $rows ) ) { wcpay_e2e_fail(); } if ( ! $rows ) { return array( 'exists' => false, 'valueBase64' => null, 'autoload' => null ); } return array( 'exists' => true, 'valueBase64' => base64_encode( (string) $rows[0]['option_value'] ), 'autoload' => (string) $rows[0]['autoload'] ); }
function wcpay_e2e_account_wrapper( $row ) { if ( true !== $row['exists'] || ! is_string( $row['valueBase64'] ) ) { wcpay_e2e_fail(); } $raw = base64_decode( $row['valueBase64'], true ); if ( false === $raw || ! is_serialized( $raw, true ) ) { wcpay_e2e_fail(); } $wrapper = @unserialize( $raw, array( 'allowed_classes' => false ) ); if ( ! is_array( $wrapper ) || ! array_key_exists( 'data', $wrapper ) || ! is_array( $wrapper['data'] ) || ! array_key_exists( 'fetched', $wrapper ) || ! is_int( $wrapper['fetched'] ) || ! array_key_exists( 'errored', $wrapper ) || ! is_bool( $wrapper['errored'] ) || ! array_key_exists( 'consecutive_errors', $wrapper ) || ! is_int( $wrapper['consecutive_errors'] ) || 0 > $wrapper['consecutive_errors'] ) { wcpay_e2e_fail(); } if ( ! isset( $wrapper['data']['account_id'] ) || ! is_string( $wrapper['data']['account_id'] ) || '' === $wrapper['data']['account_id'] ) { wcpay_e2e_fail(); } if ( ! array_key_exists( 'card_testing_protection_eligible', $wrapper['data'] ) || ! is_bool( $wrapper['data']['card_testing_protection_eligible'] ) ) { wcpay_e2e_fail(); } return $wrapper; }
function wcpay_e2e_invalidate_option( $name ) { wp_cache_delete( $name, 'options' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); }
function wcpay_e2e_pages( $slug ) { global $wpdb; return $wpdb->get_results( $wpdb->prepare( 'SELECT ID, post_name, post_title, post_content, post_status, post_type FROM %i WHERE post_type = %s AND post_name = %s', $wpdb->posts, 'page', $slug ), ARRAY_A ); }
function wcpay_e2e_page_matches( $row, $slug, $title, $content ) { return is_array( $row ) && $slug === (string) $row['post_name'] && $title === (string) $row['post_title'] && $content === (string) $row['post_content'] && 'publish' === (string) $row['post_status'] && 'page' === (string) $row['post_type']; }
function wcpay_e2e_restore_row( $name, $snapshot ) { global $wpdb; wcpay_e2e_exact_keys( $snapshot, array( 'autoload', 'exists', 'valueBase64' ) ); $current = wcpay_e2e_row( $name ); if ( true === $snapshot['exists'] ) { if ( ! is_string( $snapshot['valueBase64'] ) || ! is_string( $snapshot['autoload'] ) ) { wcpay_e2e_fail(); } $raw = base64_decode( $snapshot['valueBase64'], true ); if ( false === $raw ) { wcpay_e2e_fail(); } if ( $current['exists'] ) { $result = $wpdb->update( $wpdb->options, array( 'option_value' => $raw, 'autoload' => $snapshot['autoload'] ), array( 'option_name' => $name ), array( '%s', '%s' ), array( '%s' ) ); } else { $result = $wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $raw, 'autoload' => $snapshot['autoload'] ), array( '%s', '%s', '%s' ) ); } if ( false === $result ) { wcpay_e2e_fail(); } } else { if ( null !== $snapshot['valueBase64'] || null !== $snapshot['autoload'] ) { wcpay_e2e_fail(); } if ( $current['exists'] && false === $wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) ) ) { wcpay_e2e_fail(); } } wcpay_e2e_invalidate_option( $name ); }
function wcpay_e2e_row_equal( $left, $right ) { return $left['exists'] === $right['exists'] && $left['valueBase64'] === $right['valueBase64'] && $left['autoload'] === $right['autoload']; }
function wcpay_e2e_emit( $payload ) { echo wp_json_encode( array( 'home' => get_home_url(), 'site' => get_site_url(), 'payload' => $payload ), JSON_UNESCAPED_SLASHES ) . "\n"; }
try {
	$input_json = base64_decode( $wcpay_e2e_input_base64, true ); $operation = base64_decode( $wcpay_e2e_operation_base64, true );
	if ( false === $input_json || false === $operation ) { wcpay_e2e_fail(); }
	$input = json_decode( $input_json, true, 32, JSON_THROW_ON_ERROR );
	if ( ! is_array( $input ) || ! isset( $input['baseURL'] ) || ! is_string( $input['baseURL'] ) || get_home_url() !== $input['baseURL'] || get_site_url() !== $input['baseURL'] ) { wcpay_e2e_fail(); }
	$account_name = '${ ACCOUNT_OPTION }';
	$force_name = '${ FORCE_OPTION }';
	if ( 'capture-state' === $operation ) {
		wcpay_e2e_exact_keys( $input, array( 'baseURL', 'marker', 'slug' ) );
		$account_row = wcpay_e2e_row( $account_name ); $wrapper = wcpay_e2e_account_wrapper( $account_row );
		wcpay_e2e_emit( array( 'accountOption' => $account_row, 'forceOption' => wcpay_e2e_row( $force_name ), 'accountConnected' => true, 'effectiveProtection' => $wrapper['data']['card_testing_protection_eligible'], 'classicPageExists' => 0 !== count( wcpay_e2e_pages( $input['slug'] ) ) ) );
	} elseif ( 'mutate-state' === $operation ) {
		wcpay_e2e_exact_keys( $input, array( 'baseURL', 'content', 'marker', 'slug', 'title' ) );
		if ( wcpay_e2e_pages( $input['slug'] ) ) { wcpay_e2e_fail(); }
		$account_row = wcpay_e2e_row( $account_name ); $wrapper = wcpay_e2e_account_wrapper( $account_row );
		$wrapper['data']['card_testing_protection_eligible'] = true; $wrapper['fetched'] = time(); $wrapper['errored'] = false; $wrapper['consecutive_errors'] = 0;
		global $wpdb; if ( false === $wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $wrapper ) ), array( 'option_name' => $account_name ), array( '%s' ), array( '%s' ) ) ) { wcpay_e2e_fail(); }
		$force_row = wcpay_e2e_row( $force_name ); if ( $force_row['exists'] ) { $force_result = $wpdb->update( $wpdb->options, array( 'option_value' => '1' ), array( 'option_name' => $force_name ), array( '%s' ), array( '%s' ) ); } else { $force_result = $wpdb->insert( $wpdb->options, array( 'option_name' => $force_name, 'option_value' => '1', 'autoload' => 'on' ), array( '%s', '%s', '%s' ) ); } if ( false === $force_result ) { wcpay_e2e_fail(); }
		wcpay_e2e_invalidate_option( $account_name ); wcpay_e2e_invalidate_option( $force_name );
		$page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $input['slug'], 'post_title' => $input['title'], 'post_content' => $input['content'] ), true );
		if ( is_wp_error( $page_id ) || ! is_int( $page_id ) || 0 >= $page_id ) { wcpay_e2e_fail(); }
		$page = get_post( $page_id, ARRAY_A ); if ( ! wcpay_e2e_page_matches( $page, $input['slug'], $input['title'], $input['content'] ) || 1 !== count( wcpay_e2e_pages( $input['slug'] ) ) ) { wcpay_e2e_fail(); }
		wcpay_e2e_emit( array( 'pageId' => $page_id ) );
	} elseif ( 'verify-mutated-state' === $operation ) {
		wcpay_e2e_exact_keys( $input, array( 'baseURL', 'content', 'marker', 'pageId', 'slug', 'title' ) );
		$wrapper = wcpay_e2e_account_wrapper( wcpay_e2e_row( $account_name ) ); $force_row = wcpay_e2e_row( $force_name ); $force_raw = $force_row['exists'] ? base64_decode( $force_row['valueBase64'], true ) : false; $page = get_post( $input['pageId'], ARRAY_A );
		wcpay_e2e_emit( array( 'accountConnected' => true, 'accountProtection' => true === $wrapper['data']['card_testing_protection_eligible'], 'cacheUsable' => 0 < $wrapper['fetched'] && false === $wrapper['errored'] && 0 === $wrapper['consecutive_errors'], 'forceProtection' => true === $force_row['exists'] && '1' === $force_raw, 'pageMatches' => wcpay_e2e_page_matches( $page, $input['slug'], $input['title'], $input['content'] ) && 1 === count( wcpay_e2e_pages( $input['slug'] ) ) ) );
	} elseif ( 'read-guest-session' === $operation ) {
		wcpay_e2e_exact_keys( $input, array( 'baseURL' ) ); $cookie_name = base64_decode( $wcpay_e2e_cookie_name_base64, true ); $cookie_value = base64_decode( $wcpay_e2e_cookie_value_base64, true ); $customer_id = base64_decode( $wcpay_e2e_customer_id_base64, true ); if ( false === $cookie_name || false === $cookie_value || false === $customer_id || 0 !== strpos( $cookie_name, '${ SESSION_COOKIE_PREFIX }' ) || ! preg_match( '/^t_[a-f0-9]{30}$/D', $customer_id ) ) { wcpay_e2e_fail(); }
		$_COOKIE[$cookie_name] = $cookie_value; $handler = new WC_Session_Handler(); $parsed = $handler->get_session_cookie(); if ( ! is_array( $parsed ) || 4 !== count( $parsed ) || ! hash_equals( $customer_id, (string) $parsed[0] ) ) { wcpay_e2e_emit( array( 'cookieValid' => false, 'sessionExists' => false, 'token' => null ) ); return; }
		global $wpdb; $raw_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE session_key = %s', $wpdb->prefix . 'woocommerce_sessions', $customer_id ) ); $data = $handler->get_session( $customer_id, null ); if ( 1 !== $raw_count || ! is_array( $data ) ) { wcpay_e2e_emit( array( 'cookieValid' => true, 'sessionExists' => false, 'token' => null ) ); return; }
		$token = $data['wcpay-fraud-prevention-token'] ?? null; if ( ! is_string( $token ) || ${ TOKEN_LENGTH } !== strlen( $token ) ) { wcpay_e2e_emit( array( 'cookieValid' => true, 'sessionExists' => true, 'token' => null ) ); return; }
		wcpay_e2e_emit( array( 'cookieValid' => true, 'sessionExists' => true, 'token' => array( 'length' => ${ TOKEN_LENGTH }, 'sha256' => hash( 'sha256', $token ) ) ) );
	} elseif ( 'delete-guest-session' === $operation ) {
		wcpay_e2e_exact_keys( $input, array( 'baseURL' ) ); $customer_id = base64_decode( $wcpay_e2e_customer_id_base64, true ); if ( false === $customer_id || ! preg_match( '/^t_[a-f0-9]{30}$/D', $customer_id ) ) { wcpay_e2e_fail(); } $handler = new WC_Session_Handler(); $handler->delete_session( $customer_id ); wcpay_e2e_emit( array( 'deleted' => true ) );
	} elseif ( 'verify-session-absent' === $operation ) {
		wcpay_e2e_exact_keys( $input, array( 'baseURL' ) ); $customer_id = base64_decode( $wcpay_e2e_customer_id_base64, true ); if ( false === $customer_id || ! preg_match( '/^t_[a-f0-9]{30}$/D', $customer_id ) ) { wcpay_e2e_fail(); } global $wpdb; $raw_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE session_key = %s', $wpdb->prefix . 'woocommerce_sessions', $customer_id ) ); $handler = new WC_Session_Handler(); $cached = $handler->get_session( $customer_id, null ); wcpay_e2e_emit( array( 'rawAbsent' => 0 === $raw_count, 'cacheAbsent' => null === $cached ) );
	} elseif ( 'restore-state' === $operation ) {
		wcpay_e2e_exact_keys( $input, array( 'baseURL', 'content', 'pageId', 'snapshot', 'title' ) ); $snapshot = $input['snapshot']; wcpay_e2e_exact_keys( $snapshot, array( 'accountOption', 'classicCheckoutSlug', 'forceOption', 'originalEffectiveProtection', 'runMarker' ) ); wcpay_e2e_restore_row( $account_name, $snapshot['accountOption'] ); wcpay_e2e_restore_row( $force_name, $snapshot['forceOption'] );
		$pages = wcpay_e2e_pages( $snapshot['classicCheckoutSlug'] ); $owned = array_values( array_filter( $pages, function ( $row ) use ( $snapshot, $input ) { return wcpay_e2e_page_matches( $row, $snapshot['classicCheckoutSlug'], $input['title'], $input['content'] ); } ) ); $page_id = $input['pageId'];
		if ( null !== $page_id ) { $page = get_post( $page_id, ARRAY_A ); if ( ! $page || ! wcpay_e2e_page_matches( $page, $snapshot['classicCheckoutSlug'], $input['title'], $input['content'] ) ) { wcpay_e2e_fail(); } if ( false === wp_delete_post( $page_id, true ) ) { wcpay_e2e_fail(); } } else { if ( 1 < count( $owned ) || count( $owned ) !== count( $pages ) ) { wcpay_e2e_fail(); } if ( 1 === count( $owned ) && false === wp_delete_post( (int) $owned[0]['ID'], true ) ) { wcpay_e2e_fail(); } }
		wcpay_e2e_emit( array( 'restored' => true, 'pageAbsent' => 0 === count( wcpay_e2e_pages( $snapshot['classicCheckoutSlug'] ) ) ) );
	} elseif ( 'verify-restored-state' === $operation ) {
		wcpay_e2e_exact_keys( $input, array( 'baseURL', 'content', 'pageId', 'snapshot', 'title' ) ); $snapshot = $input['snapshot']; $account_row = wcpay_e2e_row( $account_name ); $force_row = wcpay_e2e_row( $force_name ); $wrapper = wcpay_e2e_account_wrapper( $account_row ); $marker = $snapshot['runMarker']; global $wpdb; $marker_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_type = %s AND ( post_title LIKE %s OR post_content LIKE %s )', $wpdb->posts, 'page', '%' . $wpdb->esc_like( $marker ) . '%', '%' . $wpdb->esc_like( $marker ) . '%' ) ); $customer_id = base64_decode( $wcpay_e2e_customer_id_base64, true ); if ( false === $customer_id ) { wcpay_e2e_fail(); } $session_absent = true; if ( '' !== $customer_id ) { $raw_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE session_key = %s', $wpdb->prefix . 'woocommerce_sessions', $customer_id ) ); $handler = new WC_Session_Handler(); $session_absent = 0 === $raw_count && null === $handler->get_session( $customer_id, null ); }
		wcpay_e2e_emit( array( 'rowsMatch' => wcpay_e2e_row_equal( $account_row, $snapshot['accountOption'] ) && wcpay_e2e_row_equal( $force_row, $snapshot['forceOption'] ), 'effectiveProtection' => $wrapper['data']['card_testing_protection_eligible'], 'pageAbsent' => 0 === count( wcpay_e2e_pages( $snapshot['classicCheckoutSlug'] ) ) && 0 === $marker_count, 'sessionAbsent' => $session_absent ) );
	} else { wcpay_e2e_fail(); }
} catch ( Throwable $error ) { fwrite( STDERR, "WooPayments E2E state operation failed.\n" ); exit( 1 ); }
`;

function nativeStorePhp( request: CardTestingProtectionRunnerRequest ): string {
	const { cookieName, cookieValue, customerId, ...publicInput } =
		request.input;
	const encode = ( value: string ): string =>
		Buffer.from( value, 'utf8' ).toString( 'base64' );
	const transport = [
		`$wcpay_e2e_operation_base64 = '${ encode( request.operation ) }';`,
		`$wcpay_e2e_input_base64 = '${ encode(
			JSON.stringify( publicInput )
		) }';`,
		`$wcpay_e2e_cookie_name_base64 = '${ encode(
			typeof cookieName === 'string' ? cookieName : ''
		) }';`,
		`$wcpay_e2e_cookie_value_base64 = '${ encode(
			typeof cookieValue === 'string' ? cookieValue : ''
		) }';`,
		`$wcpay_e2e_customer_id_base64 = '${ encode(
			typeof customerId === 'string' ? customerId : ''
		) }';`,
	].join( '\n' );

	return NATIVE_STORE_PHP.replace( '<?php', `<?php\n${ transport }` );
}

type NativeStoreSpawn = (
	command: string,
	args: string[],
	options: {
		cwd: string;
		env: NodeJS.ProcessEnv;
		stdio: [ 'pipe', 'pipe', 'pipe' ];
		detached: true;
	}
) => ChildProcessWithoutNullStreams;

type KillProcess = ( pid: number, signal: NodeJS.Signals ) => boolean;

export interface NativeStoreWpCliRunnerOptions {
	spawnProcess?: NativeStoreSpawn;
	killProcess?: KillProcess;
	storeDirectory?: string;
	startupTimeoutMs?: number;
	innerTimeoutSeconds?: number;
	operationTimeoutMs?: number;
	mutationSafetyTimeoutMs?: number;
	terminationGraceMs?: number;
	closeFallbackMs?: number;
	maxOutputBytes?: number;
}

function isSafeProcessGroupPid( pid: number | undefined ): pid is number {
	return (
		typeof pid === 'number' &&
		Number.isSafeInteger( pid ) &&
		pid > 1 &&
		pid !== process.pid
	);
}

export class NativeStoreWpCliRunner implements CardTestingProtectionRunner {
	private readonly options: Required< NativeStoreWpCliRunnerOptions >;

	public constructor( options: NativeStoreWpCliRunnerOptions = {} ) {
		this.options = {
			spawnProcess: options.spawnProcess ?? ( spawn as NativeStoreSpawn ),
			killProcess: options.killProcess ?? process.kill.bind( process ),
			storeDirectory:
				options.storeDirectory ??
				process.env.E2E_WOOPAYMENTS_NATIVE_STORE_DIR ??
				'',
			startupTimeoutMs:
				options.startupTimeoutMs ?? WP_CLI_STARTUP_TIMEOUT_MS,
			innerTimeoutSeconds:
				options.innerTimeoutSeconds ?? WP_CLI_INNER_TIMEOUT_SECONDS,
			operationTimeoutMs:
				options.operationTimeoutMs ?? WP_CLI_OPERATION_TIMEOUT_MS,
			mutationSafetyTimeoutMs:
				options.mutationSafetyTimeoutMs ??
				WP_CLI_MUTATION_SAFETY_TIMEOUT_MS,
			terminationGraceMs:
				options.terminationGraceMs ?? WP_CLI_TERMINATION_GRACE_MS,
			closeFallbackMs:
				options.closeFallbackMs ?? WP_CLI_CLOSE_FALLBACK_MS,
			maxOutputBytes: options.maxOutputBytes ?? MAX_RUNNER_OUTPUT_BYTES,
		};
		if (
			! Number.isInteger( this.options.innerTimeoutSeconds ) ||
			this.options.innerTimeoutSeconds < 1 ||
			this.options.innerTimeoutSeconds * 1000 >=
				this.options.operationTimeoutMs ||
			this.options.mutationSafetyTimeoutMs <=
				this.options.innerTimeoutSeconds * 1000
		) {
			throw new Error(
				'Native-store WP-CLI timeout configuration is invalid.'
			);
		}
	}

	public async run(
		request: CardTestingProtectionRunnerRequest
	): Promise< unknown > {
		const {
			spawnProcess,
			killProcess,
			storeDirectory,
			startupTimeoutMs,
			innerTimeoutSeconds,
			operationTimeoutMs,
			mutationSafetyTimeoutMs,
			terminationGraceMs,
			closeFallbackMs,
			maxOutputBytes,
		} = this.options;
		if ( ! storeDirectory ) {
			throw new Error(
				'E2E_WOOPAYMENTS_NATIVE_STORE_DIR is required for native-store WP-CLI operations.'
			);
		}
		const child = spawnProcess(
			'pnpm',
			[
				'exec',
				'wp-env',
				'run',
				'cli',
				'timeout',
				'--signal=KILL',
				`${ innerTimeoutSeconds }s`,
				'sh',
				'-c',
				"printf '%s%s\\n' '__WCPAY_E2E_' 'WP_CLI_READY__'; wp --user=1 eval-file -; wcpay_status=$?; printf '%s%s\\n' '__WCPAY_E2E_' 'WP_CLI_DONE__'; exit \"$wcpay_status\"",
			],
			{
				cwd: storeDirectory,
				env: process.env,
				stdio: [ 'pipe', 'pipe', 'pipe' ],
				detached: true,
			}
		);

		return new Promise< unknown >( ( resolve, reject ) => {
			let stdout = '';
			let stdoutBytes = 0;
			let stderrBytes = 0;
			let settled = false;
			let readyAt: number | undefined;
			let doneObserved = false;
			let terminationRequested = false;
			let closeObserved = false;
			let deadline: ReturnType< typeof setTimeout > | undefined;
			let forceKillTimer: ReturnType< typeof setTimeout > | undefined;
			let closeFallbackTimer: ReturnType< typeof setTimeout > | undefined;
			let safeRejectionTimer: ReturnType< typeof setTimeout > | undefined;

			const clearTimers = () => {
				for ( const timer of [
					deadline,
					forceKillTimer,
					closeFallbackTimer,
					safeRejectionTimer,
				] ) {
					if ( timer ) {
						clearTimeout( timer );
					}
				}
			};
			const rejectGeneric = () => {
				if ( settled ) {
					return;
				}
				settled = true;
				clearTimers();
				reject(
					new Error(
						`Native-store WP-CLI ${ request.operation } operation failed.`
					)
				);
			};
			const rejectWhenMutationCannotStillRun = () => {
				if ( doneObserved ) {
					rejectGeneric();
					return;
				}
				const safeAt = readyAt
					? readyAt + mutationSafetyTimeoutMs
					: Date.now();
				const delay = Math.max( 0, safeAt - Date.now() );
				if ( delay === 0 ) {
					rejectGeneric();
					return;
				}
				safeRejectionTimer = setTimeout( rejectGeneric, delay );
			};
			const killGroup = ( signal: NodeJS.Signals ) => {
				if ( ! isSafeProcessGroupPid( child.pid ) ) {
					return;
				}
				try {
					killProcess( -child.pid, signal );
				} catch {
					// The exact child group may already have exited.
				}
			};
			const requestTermination = () => {
				if ( settled || terminationRequested ) {
					return;
				}
				terminationRequested = true;
				if ( deadline ) {
					clearTimeout( deadline );
				}
				killGroup( 'SIGTERM' );
				forceKillTimer = setTimeout( () => {
					killGroup( 'SIGKILL' );
					closeFallbackTimer = setTimeout( () => {
						if ( ! closeObserved ) {
							rejectWhenMutationCannotStillRun();
						}
					}, closeFallbackMs );
				}, terminationGraceMs );
			};
			const startDeadline = ( milliseconds: number ) => {
				if ( deadline ) {
					clearTimeout( deadline );
				}
				deadline = setTimeout( requestTermination, milliseconds );
			};

			startDeadline( startupTimeoutMs );
			child.once( 'error', requestTermination );
			child.stdin.once( 'error', requestTermination );
			child.stdout.on( 'data', ( chunk: Buffer ) => {
				if ( terminationRequested ) {
					return;
				}
				stdoutBytes += chunk.length;
				if ( stdoutBytes > maxOutputBytes ) {
					requestTermination();
					return;
				}
				stdout += chunk.toString( 'utf8' );
				const normalizedLines = stdout
					.split( /\r?\n/ )
					.map( ( line ) => stripVTControlCharacters( line ).trim() );
				if (
					readyAt === undefined &&
					normalizedLines.includes( NATIVE_STORE_READY_SENTINEL )
				) {
					readyAt = Date.now();
					startDeadline( operationTimeoutMs );
					try {
						child.stdin.end( nativeStorePhp( request ) );
					} catch {
						requestTermination();
					}
				}
				if (
					readyAt !== undefined &&
					normalizedLines.includes( NATIVE_STORE_DONE_SENTINEL )
				) {
					doneObserved = true;
				}
			} );
			child.stderr.on( 'data', ( chunk: Buffer ) => {
				if ( terminationRequested ) {
					return;
				}
				stderrBytes += chunk.length;
				if ( stderrBytes > maxOutputBytes ) {
					requestTermination();
				}
			} );
			child.once( 'close', ( code ) => {
				if ( settled ) {
					return;
				}
				closeObserved = true;
				if ( terminationRequested ) {
					if ( forceKillTimer ) {
						clearTimeout( forceKillTimer );
					}
					if ( closeFallbackTimer ) {
						clearTimeout( closeFallbackTimer );
					}
					rejectWhenMutationCannotStillRun();
					return;
				}
				if ( deadline ) {
					clearTimeout( deadline );
				}
				if ( readyAt !== undefined && ! doneObserved ) {
					rejectWhenMutationCannotStillRun();
					return;
				}
				if ( code !== 0 || readyAt === undefined ) {
					rejectGeneric();
					return;
				}
				const jsonLine = stdout
					.split( /\r?\n/ )
					.toReversed()
					.find( ( line ) => line.trim().startsWith( '{' ) );
				if ( ! jsonLine ) {
					rejectGeneric();
					return;
				}
				try {
					const parsed: unknown = JSON.parse( jsonLine );
					settled = true;
					clearTimers();
					resolve( parsed );
				} catch {
					rejectGeneric();
				}
			} );
		} );
	}
}

export async function withCapturedCardTestingProtectionState< Result >(
	session: ProviderWriteSession,
	runId: string,
	callback: ( scope: CardTestingProtectionScope ) => Promise< Result >,
	options: ControllerOptions = {}
): Promise< Result > {
	if ( session.runtime !== 'native' ) {
		throw new Error(
			'Card-testing protection state capture requires the native runtime.'
		);
	}
	if ( ! runId || runId !== session.runId ) {
		throw new Error(
			'Card-testing protection state capture requires the active run ID.'
		);
	}
	assertSafeNativeStoreBaseUrl( session.baseURL );
	const runner = options.runner ?? new NativeStoreWpCliRunner();
	const marker = deterministicMarker( runId );
	const title = `WooPayments E2E Classic Checkout ${ marker }`;
	const content = `<!-- ${ marker } -->\n[woocommerce_checkout]`;

	return session.withProviderWriteLocks(
		{
			featureSetting: 'card-testing-protection',
			recordEvent: 'shopper-card-protection',
		},
		async () => {
			session.requireApprovedProviderFixture(
				'card-testing-protection-setting'
			);
			session.requireApprovedProviderFixture( 'classic-checkout-page' );
			const settingLock = session.getActiveFeatureSettingLock();
			if ( ! settingLock ) {
				throw quarantine(
					'Card-testing protection requires an owned feature-setting lock.',
					'lock-ownership-lost'
				);
			}

			const restoreAndProve = async (
				snapshot: ProtectionSnapshot,
				pageId: number | null,
				customerId?: string
			): Promise< void > => {
				await assertWriteAuthorized( session );
				const restoredTitle = `WooPayments E2E Classic Checkout ${ snapshot.runMarker }`;
				const restoredContent = `<!-- ${ snapshot.runMarker } -->\n[woocommerce_checkout]`;
				assertRestoreResult(
					await runOperation(
						runner,
						'restore-state',
						{
							baseURL: session.baseURL,
							content: restoredContent,
							pageId,
							snapshot,
							title: restoredTitle,
						},
						session.baseURL
					)
				);
				assertRestorationProof(
					await runOperation(
						runner,
						'verify-restored-state',
						{
							baseURL: session.baseURL,
							content: restoredContent,
							customerId,
							pageId,
							snapshot,
							title: restoredTitle,
						},
						session.baseURL
					),
					snapshot.originalEffectiveProtection
				);
			};

			try {
				if ( ! ( await settingLock.isOwned() ) ) {
					throw quarantine(
						'Card-testing protection feature-setting lock ownership was lost.',
						'lock-ownership-lost'
					);
				}
				await settingLock.restoreFromJournalIfOwned(
					async ( recoveredValue ) => {
						await restoreAndProve(
							assertSnapshot( recoveredValue ),
							null
						);
					}
				);
				if ( ! ( await settingLock.isOwned() ) ) {
					throw quarantine(
						'Card-testing protection feature-setting lock ownership was lost.',
						'lock-ownership-lost'
					);
				}
			} catch ( error ) {
				if ( error instanceof ResourceQuarantineRequiredError ) {
					throw error;
				}
				throw quarantine(
					'Card-testing protection stale-journal recovery failed.',
					'restoration-failed',
					error
				);
			}

			let captured;
			try {
				captured = assertCaptureResult(
					await runOperation(
						runner,
						'capture-state',
						{
							baseURL: session.baseURL,
							marker,
							slug: CLASSIC_CHECKOUT_SLUG,
						},
						session.baseURL
					)
				);
			} catch ( error ) {
				throw quarantine(
					'Card-testing protection state capture failed.',
					'uncertain-provider-write',
					error
				);
			}
			const snapshot: ProtectionSnapshot = {
				accountOption: captured.accountOption,
				forceOption: captured.forceOption,
				classicCheckoutSlug: CLASSIC_CHECKOUT_SLUG,
				runMarker: marker,
				originalEffectiveProtection: captured.effectiveProtection,
			};
			try {
				await settingLock.writeRestorationJournal(
					snapshot as unknown as JsonValue
				);
			} catch ( error ) {
				throw quarantine(
					'Card-testing protection restoration journal write failed.',
					'restoration-failed',
					error
				);
			}

			let pageId: number | null = null;
			let trackedSession: TrackedGuestSession | undefined;
			let registeredContext: BrowserContext | undefined;
			let registeredCookie: TrackedSessionCookie | undefined;
			let contextRegistrationAttempted = false;
			let freshContextVerified = false;
			let sessionCaptureAttempted = false;
			let tokenEvidenceCaptured = false;
			let scopeViolated = false;
			let result: Result | undefined;
			let primaryError: unknown;
			let callbackStarted = false;
			const cleanupErrors: Error[] = [];
			const restorationErrors: Error[] = [];

			try {
				await assertWriteAuthorized( session );
				pageId = assertMutationResult(
					await runOperation(
						runner,
						'mutate-state',
						{
							baseURL: session.baseURL,
							content,
							marker,
							slug: CLASSIC_CHECKOUT_SLUG,
							title,
						},
						session.baseURL
					)
				);
				assertMutationProof(
					await runOperation(
						runner,
						'verify-mutated-state',
						{
							baseURL: session.baseURL,
							content,
							marker,
							pageId,
							slug: CLASSIC_CHECKOUT_SLUG,
							title,
						},
						session.baseURL
					)
				);

				const scope: CardTestingProtectionScope = {
					classicCheckout: {
						pageId,
						slug: CLASSIC_CHECKOUT_SLUG,
						path: 'classic-checkout/',
					},
					registerFreshContext: async ( source ) => {
						const context = asContext( source );
						if ( contextRegistrationAttempted ) {
							scopeViolated = true;
							if ( context !== registeredContext ) {
								try {
									await context.close();
								} catch {
									throw quarantine(
										'WooCommerce guest context registration failed.',
										'uncertain-provider-write'
									);
								}
							}
							throw quarantine(
								'WooCommerce guest context registration may run only once.',
								'uncertain-provider-write'
							);
						}
						contextRegistrationAttempted = true;
						registeredContext = context;
						try {
							const cookies = await context.cookies( [
								session.baseURL,
							] );
							if (
								cookies.some( ( cookie ) =>
									cookie.name.startsWith(
										SESSION_COOKIE_PREFIX
									)
								)
							) {
								invalid(
									'Fresh guest context already contains a WooCommerce session cookie.'
								);
							}
							freshContextVerified = true;
						} catch ( error ) {
							throw error instanceof
								ResourceQuarantineRequiredError
								? error
								: quarantine(
										'WooCommerce guest context registration failed.',
										'uncertain-provider-write',
										error
								  );
						}
					},
					captureGuestSessionToken: async ( source ) => {
						if ( sessionCaptureAttempted ) {
							scopeViolated = true;
							throw quarantine(
								'WooCommerce guest session capture may run only once.',
								'uncertain-provider-write'
							);
						}
						sessionCaptureAttempted = true;
						const context = asContext( source );
						if (
							! freshContextVerified ||
							context !== registeredContext
						) {
							scopeViolated = true;
							throw quarantine(
								'WooCommerce session capture requires the registered fresh context.',
								'uncertain-provider-write'
							);
						}
						try {
							const cookies = await context.cookies( [
								session.baseURL,
							] );
							const sessionCookies = cookies.filter( ( cookie ) =>
								cookie.name.startsWith( SESSION_COOKIE_PREFIX )
							);
							if ( sessionCookies.length !== 1 ) {
								invalid(
									'Expected exactly one WooCommerce guest session cookie.'
								);
							}
							const cookie = sessionCookies[ 0 ];
							const trackedCookie = {
								name: cookie.name,
								value: cookie.value,
								domain: cookie.domain,
								path: cookie.path,
							};
							registeredCookie = trackedCookie;
							const customerId = parseGuestCustomerId(
								cookie.value
							);
							trackedSession = {
								customerId,
								verified: false,
							};
							const token = assertSessionIdentity(
								await runOperation(
									runner,
									'read-guest-session',
									{
										baseURL: session.baseURL,
										cookieName: cookie.name,
										cookieValue: cookie.value,
										customerId,
									},
									session.baseURL
								)
							);
							trackedSession.verified = true;
							const digest = assertTokenDigest( token );
							tokenEvidenceCaptured = true;
							return digest;
						} catch ( error ) {
							throw error instanceof
								ResourceQuarantineRequiredError
								? error
								: quarantine(
										'WooCommerce guest session evidence is missing or malformed.',
										'uncertain-provider-write',
										error
								  );
						}
					},
				};
				callbackStarted = true;
				result = await callback( scope );
				if (
					! freshContextVerified ||
					! trackedSession?.verified ||
					! tokenEvidenceCaptured ||
					scopeViolated
				) {
					throw quarantine(
						'Card-testing protection callback completed without run-owned guest session evidence.',
						'uncertain-provider-write'
					);
				}
			} catch ( error ) {
				if ( error instanceof ResourceQuarantineRequiredError ) {
					primaryError = error;
				} else if ( ! callbackStarted ) {
					primaryError = quarantine(
						'Card-testing protection mutation or cold proof failed.',
						'uncertain-provider-write',
						error
					);
				} else {
					primaryError = error;
				}
			}

			if (
				registeredContext &&
				freshContextVerified &&
				! trackedSession?.verified
			) {
				try {
					const cookies = await registeredContext.cookies( [
						session.baseURL,
					] );
					const sessionCookies = cookies.filter( ( cookie ) =>
						cookie.name.startsWith( SESSION_COOKIE_PREFIX )
					);
					if ( sessionCookies.length !== 1 ) {
						invalid(
							'Expected exactly one WooCommerce guest session cookie during cleanup.'
						);
					}
					const cookie = sessionCookies[ 0 ];
					registeredCookie = {
						name: cookie.name,
						value: cookie.value,
						domain: cookie.domain,
						path: cookie.path,
					};
					const candidateSession: TrackedGuestSession = {
						customerId: parseGuestCustomerId( cookie.value ),
						verified: false,
					};
					trackedSession = candidateSession;
					assertSessionIdentity(
						await runOperation(
							runner,
							'read-guest-session',
							{
								baseURL: session.baseURL,
								cookieName: cookie.name,
								cookieValue: cookie.value,
								customerId: candidateSession.customerId,
							},
							session.baseURL
						)
					);
					candidateSession.verified = true;
				} catch {
					cleanupErrors.push(
						new Error(
							'Run-owned WooCommerce guest session cleanup identity could not be proven.'
						)
					);
				}
			}

			if ( registeredContext ) {
				if ( trackedSession?.verified ) {
					try {
						await assertWriteAuthorized( session );
						assertDeleteResult(
							await runOperation(
								runner,
								'delete-guest-session',
								{
									baseURL: session.baseURL,
									customerId: trackedSession.customerId,
								},
								session.baseURL
							)
						);
					} catch ( error ) {
						cleanupErrors.push( toError( error ) );
					}
				}
				if ( registeredCookie ) {
					try {
						await registeredContext.clearCookies( {
							name: registeredCookie.name,
							domain: registeredCookie.domain,
							path: registeredCookie.path,
						} );
					} catch ( error ) {
						cleanupErrors.push( toError( error ) );
					}
				}
				try {
					await registeredContext.close();
				} catch ( error ) {
					cleanupErrors.push( toError( error ) );
				}
				if ( trackedSession?.verified ) {
					try {
						assertSessionAbsent(
							await runOperation(
								runner,
								'verify-session-absent',
								{
									baseURL: session.baseURL,
									customerId: trackedSession.customerId,
								},
								session.baseURL
							)
						);
					} catch ( error ) {
						cleanupErrors.push( toError( error ) );
					}
				}
			}

			try {
				const restored = await settingLock.restoreFromJournalIfOwned(
					async ( originalValue ) => {
						await restoreAndProve(
							assertSnapshot( originalValue ),
							pageId,
							trackedSession?.verified
								? trackedSession.customerId
								: undefined
						);
					}
				);
				if ( ! restored ) {
					restorationErrors.push(
						new Error(
							'Protection state was not restored because journal or lock ownership was lost.'
						)
					);
				}
			} catch ( error ) {
				restorationErrors.push( toError( error ) );
			}
			const teardownErrors = [ ...cleanupErrors, ...restorationErrors ];
			const teardownReason =
				restorationErrors.length > 0
					? 'restoration-failed'
					: 'cleanup-failed';

			if ( primaryError !== undefined ) {
				if ( teardownErrors.length > 0 ) {
					throw quarantine(
						'Card-testing protection teardown failed after the primary scenario failure.',
						teardownReason,
						primaryError
					);
				}
				throw primaryError;
			}
			if ( teardownErrors.length > 0 ) {
				throw quarantine(
					'Card-testing protection teardown failed.',
					teardownReason
				);
			}
			return result as Result;
		}
	);
}
