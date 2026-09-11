#!/usr/bin/env python3
"""Produce context-bound SC-04 saved-card Playwright and order-state evidence."""

from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
import re
import secrets
import signal
import subprocess
import sys
from pathlib import Path
from typing import Any
from urllib.parse import urlsplit, urlunsplit


SELF_DIR = Path(__file__).resolve().parent
REPO_ROOT = SELF_DIR.parents[1]
CRITICAL_FLOWS_DIR = REPO_ROOT / "tools" / "woopayments-critical-flows"
EXIT_CLEANUP = 70
sys.path.insert(0, str(CRITICAL_FLOWS_DIR))

from evidence_context import (  # noqa: E402
    EvidenceContextError,
    capture_store_state,
    source_snapshot,
    validate_context,
    validate_local_wp_command,
)


SURFACES = ("classic", "blocks", "sca_classic", "sca_blocks")
FIXTURE_PROBE_PHP = r"""
// SC04_FIXTURE_PROBE
$subscription_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
$role            = isset( $args[1] ) ? sanitize_key( (string) $args[1] ) : '';
$errors          = array();
$subscription    = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription_id ) : false;

if ( ! $subscription ) {
	$errors[] = 'subscription fixture is unavailable';
}

$customer_id = $subscription ? (int) $subscription->get_customer_id() : 0;
$user        = $customer_id ? get_userdata( $customer_id ) : false;
if ( ! $user ) {
	$errors[] = 'subscription customer is unavailable';
}

$normal_token = false;
$token_hint   = $subscription ? absint( $subscription->get_meta( '_payment_method_token', true ) ) : 0;
$provider_pm  = $subscription ? (string) $subscription->get_meta( '_payment_method_id', true ) : '';
if ( $token_hint ) {
	$candidate = WC_Payment_Tokens::get( $token_hint );
	if ( $candidate && (int) $candidate->get_user_id() === $customer_id && 'woocommerce_payments' === $candidate->get_gateway_id() ) {
		$normal_token = $candidate;
	}
}
if ( ! $normal_token ) {
	foreach ( WC_Payment_Tokens::get_customer_tokens( $customer_id, 'woocommerce_payments' ) as $candidate ) {
		if ( '' !== $provider_pm && $provider_pm === (string) $candidate->get_token() ) {
			$normal_token = $candidate;
			break;
		}
		if ( ! $normal_token ) {
			$normal_token = $candidate;
		}
	}
}
if ( ! $normal_token ) {
	$errors[] = 'subscription customer has no WooPayments saved card token';
}

$product_id = function_exists( 'wc_get_product_id_by_sku' ) ? (int) wc_get_product_id_by_sku( 'cf-simple' ) : 0;
if ( ! $product_id ) {
	$errors[] = 'cf-simple product fixture is unavailable';
}

$classic_url = '';
$blocks_url  = '';
foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1 ) ) as $page ) {
	if ( '' === $classic_url && has_shortcode( $page->post_content, 'woocommerce_checkout' ) ) {
		$classic_url = (string) get_permalink( $page );
	}
	if ( '' === $blocks_url && has_block( 'woocommerce/checkout', $page ) ) {
		$blocks_url = (string) get_permalink( $page );
	}
}
if ( '' === $classic_url ) {
	$errors[] = 'classic checkout page is unavailable';
}
if ( '' === $blocks_url ) {
	$errors[] = 'Blocks checkout page is unavailable';
}

$baseline_token_ids   = array();
$existing_sca_token_id = 0;
if ( $customer_id ) {
	foreach ( WC_Payment_Tokens::get_customer_tokens( $customer_id, 'woocommerce_payments' ) as $candidate ) {
		$baseline_token_ids[] = (int) $candidate->get_id();
		if ( ! $existing_sca_token_id && method_exists( $candidate, 'get_last4' ) && '3155' === (string) $candidate->get_last4() ) {
			$existing_sca_token_id = (int) $candidate->get_id();
		}
	}
}

WP_CLI::line(
	wp_json_encode(
		array(
			'success'                  => empty( $errors ),
			'store'                    => $role,
			'subscription_id'          => $subscription_id,
				'customer_id'              => $customer_id,
				'normal_token_id'          => $normal_token ? (int) $normal_token->get_id() : 0,
				'normal_payment_method_id' => $normal_token ? (string) $normal_token->get_token() : '',
				'product_id'               => $product_id,
				'classic_url'              => $classic_url,
				'blocks_url'               => $blocks_url,
				'baseline_token_ids'       => $baseline_token_ids,
				'existing_sca_token_id'    => $existing_sca_token_id,
				'errors'                   => $errors,
		),
		JSON_UNESCAPED_SLASHES
	)
);
"""

AUTH_SESSION_CREATE_PHP = r"""
// SC04_AUTH_SESSION_CREATE
$customer_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
$token       = isset( $args[1] ) ? (string) base64_decode( $args[1], true ) : '';
$errors      = array();
$auth_cookie = '';
$user        = $customer_id ? get_userdata( $customer_id ) : false;

if ( ! $user ) {
	$errors[] = 'subscription customer is unavailable';
}
if ( '' === $token ) {
	$errors[] = 'caller-known session token is unavailable';
}
if ( empty( $errors ) ) {
	$expiration = time() + HOUR_IN_SECONDS;
	$session    = apply_filters( 'attach_session_information', array(), $customer_id );
	$session['expiration'] = $expiration;
	$session['login']      = time();
	WP_Session_Tokens::get_instance( $customer_id )->update( $token, $session );
	$auth_cookie = wp_generate_auth_cookie( $customer_id, $expiration, 'logged_in', $token );
	if ( '' === $auth_cookie ) {
		$errors[] = 'could not create a short-lived customer auth cookie';
	}
}

WP_CLI::line(
	wp_json_encode(
		array(
			'success'     => empty( $errors ),
			'auth_cookie' => array(
				'name'  => defined( 'LOGGED_IN_COOKIE' ) ? LOGGED_IN_COOKIE : '',
				'value' => $auth_cookie,
			),
			'errors'      => $errors,
		),
		JSON_UNESCAPED_SLASHES
	)
);
"""

SESSION_DESTROY_PHP = r"""
// SC04_SESSION_DESTROY
$customer_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
$token       = isset( $args[1] ) ? (string) base64_decode( $args[1], true ) : '';
$success     = false;
if ( $customer_id && '' !== $token ) {
	WP_Session_Tokens::get_instance( $customer_id )->destroy( $token );
	$success = true;
}
WP_CLI::line( wp_json_encode( array( 'success' => $success ) ) );
"""

TOKEN_CLEANUP_PHP = r"""
// SC04_TOKEN_CLEANUP
$customer_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
$encoded     = isset( $args[1] ) ? (string) $args[1] : '';
$baseline    = json_decode( base64_decode( $encoded, true ), true );
$errors      = array();
$deleted     = array();

if ( ! $customer_id || ! is_array( $baseline ) ) {
	$errors[] = 'token cleanup inputs are invalid';
} else {
	$baseline = array_map( 'absint', $baseline );
	foreach ( WC_Payment_Tokens::get_customer_tokens( $customer_id, 'woocommerce_payments' ) as $token ) {
		$token_id = (int) $token->get_id();
		$is_sca_fixture = method_exists( $token, 'get_last4' ) && '3155' === (string) $token->get_last4();
		if ( in_array( $token_id, $baseline, true ) || ! $is_sca_fixture ) {
			continue;
		}
		WC_Payment_Tokens::delete( $token_id );
		if ( WC_Payment_Tokens::get( $token_id ) ) {
			$errors[] = 'could not remove run-created token ' . $token_id;
		} else {
			$deleted[] = $token_id;
		}
	}
}

WP_CLI::line(
	wp_json_encode(
		array(
			'success'           => empty( $errors ),
			'deleted_token_ids' => $deleted,
			'errors'            => $errors,
		)
	)
);
"""

STATE_PROBE_PHP = r"""
// SC04_STATE_PROBE
$encoded = isset( $args[0] ) ? (string) $args[0] : '';
$browser = json_decode( base64_decode( $encoded, true ), true );
$errors  = array();
if ( ! is_array( $browser ) ) {
	$browser = array();
	$errors[] = 'browser evidence payload is invalid';
}

$customer_id = absint( $browser['customer_id'] ?? 0 );
$token_ids   = array(
	'token'     => absint( $browser['token_id'] ?? 0 ),
	'sca_token' => absint( $browser['sca_token_id'] ?? 0 ),
);
$tokens = array();
foreach ( $token_ids as $key => $token_id ) {
	$token = $token_id ? WC_Payment_Tokens::get( $token_id ) : false;
	$tokens[ $key ] = array(
		'id'                => $token ? (int) $token->get_id() : 0,
		'user_id'           => $token ? (int) $token->get_user_id() : 0,
		'gateway_id'        => $token ? (string) $token->get_gateway_id() : '',
		'payment_method_id' => $token ? (string) $token->get_token() : '',
	);
	if ( ! $token ) {
		$errors[] = $key . ' fixture is unavailable';
	}
}

$orders = array();
foreach ( (array) ( $browser['surfaces'] ?? array() ) as $surface => $surface_evidence ) {
	$order_id = absint( $surface_evidence['order_id'] ?? 0 );
	$order    = $order_id ? wc_get_order( $order_id ) : false;
	$order_errors = array();
	if ( ! $order ) {
		$order_errors[] = 'order is unavailable';
	}
	$orders[ sanitize_key( (string) $surface ) ] = array(
		'success'           => $order && empty( $order_errors ),
		'order_id'          => $order_id,
		'customer_id'       => $order ? (int) $order->get_customer_id() : 0,
		'status'            => $order ? (string) $order->get_status() : '',
		'payment_method'    => $order ? (string) $order->get_payment_method() : '',
		'payment_method_id' => $order ? (string) $order->get_meta( '_payment_method_id', true ) : '',
		'amount'            => $order ? wc_format_decimal( $order->get_total(), wc_get_price_decimals() ) : '',
		'currency'          => $order ? (string) $order->get_currency() : '',
		'meta_presence'     => array(
			'_intent_id' => $order ? '' !== (string) $order->get_meta( '_intent_id', true ) : false,
			'_charge_id' => $order ? '' !== (string) $order->get_meta( '_charge_id', true ) : false,
		),
		'errors'            => $order_errors,
	);
}

WP_CLI::line(
	wp_json_encode(
		array(
			'success'     => empty( $errors ),
			'customer_id' => $customer_id,
			'token'       => $tokens['token'] ?? array(),
			'sca_token'   => $tokens['sca_token'] ?? array(),
			'orders'      => $orders,
			'errors'      => $errors,
		),
		JSON_UNESCAPED_SLASHES
	)
);
"""


class GateBlocked(RuntimeError):
    """The local evidence prerequisites are incomplete or stale."""


class GateFailure(RuntimeError):
    """The exercised browser or state behavior failed."""


class GateCleanupError(RuntimeError):
    """The gate could not verify exact restoration of harness-owned state."""

    def __init__(self, cleanup_errors: list[str], primary_error: BaseException | None = None):
        self.cleanup_errors = cleanup_errors
        self.primary_error = primary_error
        primary_detail = f"primary error: {primary_error}; " if primary_error is not None else ""
        super().__init__(f"{primary_detail}cleanup blockers: {'; '.join(cleanup_errors)}")


class GateSignal(BaseException):
    """A process signal requested an abort after armed cleanup runs."""

    def __init__(self, signum: int):
        super().__init__(f"received signal {signum}")
        self.signum = signum
        self.cleanup_errors: list[str] = []


def load_json(path: Path) -> dict[str, Any]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(payload, dict):
        raise GateBlocked(f"expected a JSON object in {path}")
    return payload


def write_json(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def parse_last_json(output: str) -> dict[str, Any]:
    for line in reversed(output.splitlines()):
        try:
            value = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(value, dict):
            return value

    decoder = json.JSONDecoder()
    candidates = []
    for index, character in enumerate(output):
        if character != "{":
            continue
        try:
            value, _ = decoder.raw_decode(output[index:])
        except json.JSONDecodeError:
            continue
        if isinstance(value, dict):
            candidates.append(value)
    if not candidates:
        raise GateBlocked("WP-CLI probe did not emit JSON")
    for candidate in candidates:
        if "success" in candidate or ("ready" in candidate and "runtime_owner" in candidate):
            return candidate
    return candidates[0]


def wp_parts(command: str, role: str) -> list[str]:
    parts = validate_local_wp_command(command, role)
    if "--allow-root" not in parts:
        parts.append("--allow-root")
    if not any(part == "--user" or part.startswith("--user=") for part in parts):
        parts.append("--user=1")
    return parts


def run_wp_eval(command: str, role: str, code: str, *args: str) -> dict[str, Any]:
    php_file = code if code.lstrip().startswith("<?php") else f"<?php\n{code}"
    completed = subprocess.run(
        [*wp_parts(command, role), "eval-file", "-", *args],
        input=php_file,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        timeout=180,
    )
    if completed.returncode != 0:
        detail = completed.stderr.strip() or f"exit {completed.returncode}"
        raise GateBlocked(f"{role} WP-CLI probe failed: {detail}")
    return parse_last_json(completed.stdout)


def external_url(internal_url: str, base_url: str) -> str:
    internal = urlsplit(internal_url)
    base = urlsplit(base_url)
    return urlunsplit((base.scheme, base.netloc, internal.path or "/", internal.query, internal.fragment))


def external_logged_in_cookie_name(internal_name: str, base_url: str) -> str:
    match = re.fullmatch(r"(.+_)[0-9a-fA-F]{32}", internal_name)
    if not match:
        return internal_name
    external_site_url = base_url.rstrip("/")
    cookie_hash = hashlib.md5(external_site_url.encode(), usedforsecurity=False).hexdigest()
    return f"{match.group(1)}{cookie_hash}"


def context_binding(context: dict[str, Any]) -> dict[str, str]:
    return {
        "aggregate_run_id": str(context["aggregate_run_id"]),
        "context_sha256": str(context["context_sha256"]),
    }


def browser_passed(payload: dict[str, Any]) -> bool:
    if payload.get("errors") or payload.get("fatal_console_errors") or payload.get("fatal_response_errors"):
        return False
    surfaces = payload.get("surfaces")
    return isinstance(surfaces, dict) and set(surfaces) == set(SURFACES) and all(
        isinstance(surfaces[surface], dict) and surfaces[surface].get("success") is True
        for surface in SURFACES
    )


def state_assertion_errors(payload: dict[str, Any]) -> list[str]:
    errors = [str(error) for error in payload.get("errors", []) if str(error)]
    if payload.get("success") is not True:
        errors.append("state probe did not report success")

    orders = payload.get("orders")
    if not isinstance(orders, dict) or set(orders) != set(SURFACES):
        errors.append("state probe did not return the exact four SC-04 order surfaces")
        return errors

    for surface in SURFACES:
        order = orders.get(surface)
        if not isinstance(order, dict):
            errors.append(f"{surface} order state is missing")
            continue
        nested_errors = order.get("errors") if isinstance(order.get("errors"), list) else []
        if order.get("success") is not True or nested_errors:
            detail = ", ".join(str(error) for error in nested_errors if str(error)) or "state assertion failed"
            errors.append(f"{surface} order state: {detail}")
    return errors


def capture_state(
    *,
    repo: Path,
    context: dict[str, Any],
    role: str,
    wp: str,
    browser_path: Path,
    state_path: Path,
) -> dict[str, Any]:
    browser = load_json(browser_path)
    encoded = base64.b64encode(json.dumps(browser, separators=(",", ":")).encode()).decode()
    probed = run_wp_eval(wp, role, STATE_PROBE_PHP, encoded)
    owner = "plugin" if role == "ref" else "native"
    current_store = capture_store_state(wp, role, owner)
    if current_store != context["stores"][role]:
        raise GateBlocked(f"{role} store/account identity changed after browser capture")
    if source_snapshot(repo) != context["source"]:
        raise GateBlocked("source snapshot changed after the critical-flow context was created")

    payload = {
        "schema": "woopayments_sc04_state_evidence.v1",
        "context_binding": context_binding(context),
        "store": role,
        "home_url": str(browser.get("home_url") or ""),
        "source": context["source"],
        "store_context": current_store,
        "success": probed.get("success") is True,
        "customer_id": probed.get("customer_id"),
        "token": probed.get("token"),
        "sca_token": probed.get("sca_token"),
        "orders": probed.get("orders"),
        "errors": probed.get("errors") if isinstance(probed.get("errors"), list) else [],
    }
    write_json(state_path, payload)
    return payload


def run_browser(
    *,
    config: dict[str, Any],
    browser_runner: Path,
    browser_driver: Path,
    log_path: Path,
) -> int:
    env = os.environ.copy()
    env["PLAYWRIGHT_RUNNER_STATE_JSON"] = json.dumps({"sc04SavedCardConfig": config}, separators=(",", ":"))
    completed = subprocess.run(
        [str(browser_runner), str(browser_driver), "--timeout", "900000"],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        env=env,
        check=False,
        timeout=930,
    )
    log_path.write_text(completed.stdout, encoding="utf-8")
    return completed.returncode


def run_store(
    *,
    repo: Path,
    context: dict[str, Any],
    role: str,
    wp: str,
    base_url: str,
    subscription_id: str,
    out_dir: Path,
    browser_runner: Path,
    browser_driver: Path,
) -> dict[str, Any]:
    label = "reference" if role == "ref" else "target"
    browser_path = out_dir / f"{label}-browser.json"
    state_path = out_dir / f"{label}-state.json"
    log_path = out_dir / f"{label}-browser.log"
    fixture = run_wp_eval(wp, role, FIXTURE_PROBE_PHP, subscription_id, role)
    if fixture.get("success") is not True:
        raise GateBlocked(f"{role} SC-04 fixture is not ready: {fixture.get('errors')}")
    if str(fixture.get("subscription_id")) != str(context["fixtures"][role]["subscription_id"]):
        raise GateBlocked(f"{role} subscription fixture does not match the aggregate context")

    customer_id = int(fixture.get("customer_id") or 0)
    if customer_id <= 0:
        raise GateBlocked(f"{role} SC-04 fixture has no customer")
    raw_baseline_token_ids = fixture.get("baseline_token_ids")
    if not isinstance(raw_baseline_token_ids, list):
        raise GateBlocked(f"{role} SC-04 fixture has no token baseline")
    try:
        baseline_token_ids = sorted({int(token_id) for token_id in raw_baseline_token_ids if int(token_id) > 0})
    except (TypeError, ValueError) as exc:
        raise GateBlocked(f"{role} SC-04 fixture has an invalid token baseline") from exc
    if int(fixture.get("normal_token_id") or 0) not in baseline_token_ids:
        raise GateBlocked(f"{role} normal saved-card token is absent from the cleanup baseline")

    session_token = secrets.token_urlsafe(32)
    encoded_session_token = base64.b64encode(session_token.encode()).decode()
    encoded_token_baseline = base64.b64encode(json.dumps(baseline_token_ids).encode()).decode()
    result: dict[str, Any] | None = None
    primary_error: BaseException | None = None

    try:
        auth_session = run_wp_eval(
            wp,
            role,
            AUTH_SESSION_CREATE_PHP,
            str(customer_id),
            encoded_session_token,
        )
        if auth_session.get("success") is not True:
            raise GateBlocked(f"{role} short-lived customer auth session is unavailable: {auth_session.get('errors')}")
        auth_cookie = auth_session.get("auth_cookie")
        if not isinstance(auth_cookie, dict) or not auth_cookie.get("name") or not auth_cookie.get("value"):
            raise GateBlocked(f"{role} short-lived customer auth cookie is missing")

        config = {
            "store": role,
            "baseUrl": base_url.rstrip("/"),
            "classicCheckoutUrl": external_url(str(fixture["classic_url"]), base_url),
            "blocksCheckoutUrl": external_url(str(fixture["blocks_url"]), base_url),
            "productId": int(fixture["product_id"]),
            "customerId": customer_id,
            "normalTokenId": int(fixture["normal_token_id"]),
            "existingScaTokenId": int(fixture.get("existing_sca_token_id") or 0),
            "authCookie": {
                "name": external_logged_in_cookie_name(str(auth_cookie["name"]), base_url),
                "value": str(auth_cookie["value"]),
            },
            "contextBinding": context_binding(context),
            "evidencePath": str(browser_path),
        }
        browser_rc = run_browser(
            config=config,
            browser_runner=browser_runner,
            browser_driver=browser_driver,
            log_path=log_path,
        )
        if not browser_path.is_file():
            raise GateFailure(f"{role} browser runner exited {browser_rc} without evidence; see {log_path}")
        browser = load_json(browser_path)
        if browser.get("context_binding") != context_binding(context) or browser.get("store") != role:
            raise GateBlocked(f"{role} browser evidence is not bound to the active aggregate context")
        if browser_rc != 0 or not browser_passed(browser):
            raise GateFailure(f"{role} SC-04 browser flow failed; see {browser_path} and {log_path}")
        state = capture_state(
            repo=repo,
            context=context,
            role=role,
            wp=wp,
            browser_path=browser_path,
            state_path=state_path,
        )
        state_errors = state_assertion_errors(state)
        if state_errors:
            raise GateFailure(f"{role} SC-04 order/token state assertions failed: {state_errors}")
        result = {
            "store": role,
            "status": "pass",
            "browser": str(browser_path),
            "state": str(state_path),
            "log": str(log_path),
            "errors": [],
        }
    except GateSignal as exc:
        primary_error = exc
    except Exception as exc:
        primary_error = exc
    finally:
        cleanup_errors = []
        try:
            token_cleanup = run_wp_eval(
                wp,
                role,
                TOKEN_CLEANUP_PHP,
                str(customer_id),
                encoded_token_baseline,
            )
            if token_cleanup.get("success") is not True:
                cleanup_errors.append(f"could not restore {role} saved-card token baseline")
        except GateSignal as exc:
            if not isinstance(primary_error, GateSignal):
                primary_error = exc
            cleanup_errors.append(
                f"token cleanup for {role} was interrupted by signal {exc.signum}"
            )
        except Exception as exc:
            cleanup_errors.append(f"could not restore {role} saved-card token baseline: {exc}")

        try:
            session_cleanup = run_wp_eval(
                wp,
                role,
                SESSION_DESTROY_PHP,
                str(customer_id),
                encoded_session_token,
            )
            if session_cleanup.get("success") is not True:
                cleanup_errors.append(f"could not destroy {role} short-lived customer auth session")
        except GateSignal as exc:
            if not isinstance(primary_error, GateSignal):
                primary_error = exc
            cleanup_errors.append(
                f"session cleanup for {role} was interrupted by signal {exc.signum}"
            )
        except Exception as exc:
            cleanup_errors.append(f"could not destroy {role} short-lived customer auth session: {exc}")

        if cleanup_errors:
            if isinstance(primary_error, GateSignal):
                primary_error.cleanup_errors.extend(cleanup_errors)
            else:
                primary_error = GateCleanupError(cleanup_errors, primary_error)

    if primary_error is not None:
        raise primary_error
    if result is None:
        raise GateBlocked(f"{role} SC-04 producer completed without a result")
    return result


def local_url(value: str, label: str) -> str:
    parsed = urlsplit(value)
    if parsed.scheme not in {"http", "https"}:
        raise GateBlocked(f"{label} must be a local HTTP(S) URL")
    host = parsed.hostname or ""
    if host not in {"localhost", "127.0.0.1"} and not host.endswith(".localhost"):
        raise GateBlocked(f"{label} must stay local")
    return value.rstrip("/")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", required=True)
    parser.add_argument("--context-file", required=True)
    parser.add_argument("--ref-wp", required=True)
    parser.add_argument("--target-wp", required=True)
    parser.add_argument("--ref-url", required=True)
    parser.add_argument("--target-url", required=True)
    parser.add_argument("--ref-subscription-id", required=True)
    parser.add_argument("--target-subscription-id", required=True)
    parser.add_argument("--out-dir", required=True)
    parser.add_argument("--browser-runner", default="playwright", choices=("playwright",))
    parser.add_argument("--print-plan", action="store_true")
    return parser.parse_args()


def print_plan(args: argparse.Namespace) -> None:
    print(
        json.dumps(
            {
                "schema": "woopayments_sc04_saved_card_gate_plan.v1",
                "browser_runner": args.browser_runner,
                "browser_driver": str(SELF_DIR / "sc04-saved-card.playwright.mjs"),
                "context_file": args.context_file,
                "stores": {
                    "ref": {"url": args.ref_url, "subscription_id": args.ref_subscription_id},
                    "target": {"url": args.target_url, "subscription_id": args.target_subscription_id},
                },
                "surfaces": list(SURFACES),
            },
            sort_keys=True,
        )
    )


def run_gate_main() -> int:
    args = parse_args()
    if args.print_plan:
        print_plan(args)
        return 0

    repo = Path(args.repo).resolve()
    context_path = Path(args.context_file)
    out_dir = Path(args.out_dir)
    browser_driver = SELF_DIR / "sc04-saved-card.playwright.mjs"
    browser_runner = Path(os.environ.get("PLAYWRIGHT_SCRIPT_RUNNER_BIN", SELF_DIR / "playwright-script-runner.mjs"))
    if not context_path.is_file():
        raise GateBlocked(f"critical-flow context is missing: {context_path}")
    if not browser_driver.is_file():
        raise GateBlocked(f"SC-04 browser driver is missing: {browser_driver}")
    if not browser_runner.is_file():
        raise GateBlocked(f"Playwright script runner is missing: {browser_runner}")
    if out_dir.exists() and any(out_dir.iterdir()):
        raise GateBlocked(f"SC-04 evidence directory must be empty: {out_dir}")
    out_dir.mkdir(parents=True, exist_ok=True)

    context = load_json(context_path)
    validate_context(context)
    if source_snapshot(repo) != context["source"]:
        raise GateBlocked("source snapshot changed after the critical-flow context was created")
    ref_url = local_url(args.ref_url, "reference URL")
    target_url = local_url(args.target_url, "target URL")
    if ref_url == target_url:
        raise GateBlocked("reference and target URLs must be distinct")

    results = []
    failures = []
    blockers = []
    cleanup_failures = []
    for role, wp, url, subscription_id in (
        ("ref", args.ref_wp, ref_url, args.ref_subscription_id),
        ("target", args.target_wp, target_url, args.target_subscription_id),
    ):
        try:
            results.append(
                run_store(
                    repo=repo,
                    context=context,
                    role=role,
                    wp=wp,
                    base_url=url,
                    subscription_id=subscription_id,
                    out_dir=out_dir,
                    browser_runner=browser_runner,
                    browser_driver=browser_driver,
                )
            )
        except GateFailure as exc:
            failures.append(f"{role}: {exc}")
            results.append({"store": role, "status": "fail", "errors": [str(exc)]})
        except GateCleanupError as exc:
            role_cleanup_failures = [f"{role}: {error}" for error in exc.cleanup_errors]
            role_primary_error = f"{role}: {exc.primary_error}" if exc.primary_error is not None else ""
            if isinstance(exc.primary_error, GateFailure):
                failures.append(role_primary_error)
            elif exc.primary_error is not None:
                blockers.append(role_primary_error)
            cleanup_failures.extend(role_cleanup_failures)
            results.append(
                {
                    "store": role,
                    "status": "fail",
                    "errors": [*([role_primary_error] if role_primary_error else []), *role_cleanup_failures],
                }
            )
            break
        except (GateBlocked, EvidenceContextError, OSError, subprocess.SubprocessError) as exc:
            blockers.append(f"{role}: {exc}")
            results.append({"store": role, "status": "blocked", "errors": [str(exc)]})

    rollup = {
        "schema": "woopayments_sc04_saved_card_gate.v1",
        "status": "fail" if failures or cleanup_failures else "blocked" if blockers else "pass",
        "context_binding": context_binding(context),
        "results": results,
        "errors": [*failures, *blockers, *cleanup_failures],
        "failures": failures,
        "blockers": blockers,
        "cleanup_failures": cleanup_failures,
    }
    write_json(out_dir / "sc04-saved-card-gate.json", rollup)
    if cleanup_failures:
        for error in failures:
            print(f"FAIL: {error}", file=sys.stderr)
        for error in blockers:
            print(f"BLOCKED: {error}", file=sys.stderr)
        for error in cleanup_failures:
            print(f"CLEANUP FAILED: {error}", file=sys.stderr)
        return EXIT_CLEANUP
    if failures:
        for error in failures:
            print(f"FAIL: {error}", file=sys.stderr)
        return 1
    if blockers:
        for error in blockers:
            print(f"BLOCKED: {error}", file=sys.stderr)
        return 3
    print(f"SC-04 gate: wrote passing evidence to {out_dir}")
    return 0


def main() -> int:
    handled_signals = (signal.SIGHUP, signal.SIGINT, signal.SIGTERM)
    previous_handlers = {signum: signal.getsignal(signum) for signum in handled_signals}

    def handle_signal(signum, _frame) -> None:
        for handled_signal in handled_signals:
            signal.signal(handled_signal, signal.SIG_IGN)
        raise GateSignal(signum)

    for signum in handled_signals:
        signal.signal(signum, handle_signal)

    try:
        return run_gate_main()
    except GateCleanupError as exc:
        print(f"CLEANUP FAILED: {exc}", file=sys.stderr)
        return EXIT_CLEANUP
    except GateSignal as exc:
        if exc.cleanup_errors:
            print(
                f"INTERRUPTED: {exc}; cleanup blockers: {'; '.join(exc.cleanup_errors)}",
                file=sys.stderr,
            )
            return EXIT_CLEANUP
        else:
            print(f"INTERRUPTED: {exc}; armed cleanup completed before exit.", file=sys.stderr)
        return 128 + exc.signum
    finally:
        for signum, previous_handler in previous_handlers.items():
            signal.signal(signum, previous_handler)


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (GateBlocked, EvidenceContextError, OSError, subprocess.SubprocessError) as exc:
        print(f"BLOCKED: {exc}", file=sys.stderr)
        raise SystemExit(3)
