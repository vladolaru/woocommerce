<?php
/**
 * Stage or submit WooPayments dispute evidence.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\DisputeIdentifier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use WP_Error;

/**
 * Save dispute evidence as a draft, or submit it to the card network when `submit` is the boolean `true`.
 *
 * A submit cannot be changed afterwards, so the ability is annotated as destructive for the most dangerous mode. It goes
 * through the dispute update route the evidence screen uses, which also clears the dispute caches.
 *
 * @since 11.4.0
 */
final class SubmitDisputeEvidence extends AbstractAbility {
	/** Most metadata keys a dispute accepts. */
	private const MAX_METADATA_KEYS = 10;

	/** Longest metadata key, in bytes. */
	private const MAX_METADATA_KEY_LENGTH = 40;

	/** Longest metadata value, in bytes. */
	private const MAX_METADATA_VALUE_LENGTH = 500;

	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/submit-dispute-evidence';
	}

	/**
	 * Define the evidence inputs and write annotations advertised to clients.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		$evidence_text_fields = array(
			'product_description'      => __( 'Description of the product or service and how it was presented to the customer.', 'woocommerce' ),
			'customer_purchase_ip'     => __( 'IP address the customer used for the purchase.', 'woocommerce' ),
			'cancellation_rebuttal'    => __( 'Explanation of why the customer was not entitled to cancel.', 'woocommerce' ),
			'access_activity_log'      => __( 'Activity log showing the customer accessed or downloaded a digital product.', 'woocommerce' ),
			'uncategorized_text'       => __( 'Any additional explanation that does not fit another field.', 'woocommerce' ),
			'shipping_carrier'         => __( 'Delivery service that shipped the product (e.g. USPS, FedEx, UPS, DHL).', 'woocommerce' ),
			'shipping_date'            => __( 'Date the product was shipped.', 'woocommerce' ),
			'shipping_tracking_number' => __( 'Tracking number for a shipped physical product.', 'woocommerce' ),
			'shipping_address'         => __( 'Address the product was shipped to.', 'woocommerce' ),
		);

		$evidence_document_fields = array(
			'customer_communication'         => __( 'Correspondence with the customer relevant to the dispute (e.g. emails).', 'woocommerce' ),
			'customer_signature'             => __( 'Proof the customer signed for the product or agreed to terms.', 'woocommerce' ),
			'receipt'                        => __( 'Receipt or message sent to the customer for the charge.', 'woocommerce' ),
			'refund_policy'                  => __( 'Your refund policy as shown to the customer.', 'woocommerce' ),
			'duplicate_charge_documentation' => __( 'Documentation for the prior charge a duplicate dispute refers to.', 'woocommerce' ),
			'shipping_documentation'         => __( 'Proof the product was delivered (e.g. signed delivery confirmation).', 'woocommerce' ),
			'service_documentation'          => __( 'Proof the service was provided to the customer.', 'woocommerce' ),
			'cancellation_policy'            => __( 'Your cancellation policy as shown to the customer.', 'woocommerce' ),
			'uncategorized_file'             => __( 'Any additional supporting document.', 'woocommerce' ),
		);

		$evidence_properties = array();
		foreach ( $evidence_text_fields as $field => $field_description ) {
			$evidence_properties[ $field ] = array(
				'type'        => 'string',
				'description' => $field_description,
			);
		}
		foreach ( $evidence_document_fields as $field => $field_description ) {
			$evidence_properties[ $field ] = array(
				'type'        => 'string',
				'description' => sprintf(
					/* translators: %s: description of what the document should show. */
					__( '%s Provide the ID of a file uploaded via woocommerce-payments/upload-dispute-evidence-file, not raw file contents.', 'woocommerce' ),
					$field_description
				),
			);
		}

		return array(
			'label'         => __( 'Submit dispute evidence', 'woocommerce' ),
			'description'   => __( 'Stage or submit evidence for a dispute. With submit=false (default) the evidence is saved as a draft you can revise; with submit=true it is sent to the card network and cannot be changed.', 'woocommerce' ),
			'input_schema'  => array(
				'type'                 => 'object',
				'required'             => array( 'dispute_id' ),
				'properties'           => array(
					'dispute_id' => array(
						'type'        => 'string',
						'description' => __( 'Stripe dispute ID (typically `du_…` or legacy `dp_…`). Stripe ID prefixes are not contractually stable, so this field is not pattern-validated.', 'woocommerce' ),
					),
					'evidence'   => array(
						'type'                 => 'object',
						'description'          => __( 'Evidence fields. Text fields take free text; document fields take the ID of a file uploaded via woocommerce-payments/upload-dispute-evidence-file (not raw file contents).', 'woocommerce' ),
						'properties'           => $evidence_properties,
						'additionalProperties' => false,
					),
					'submit'     => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Whether to submit to the card network (irreversible). Default false stages a draft.', 'woocommerce' ),
					),
					'metadata'   => array(
						'type'                 => 'object',
						'description'          => __( 'Optional metadata to attach to the dispute. Maximum 10 keys; keys up to 40 characters; values up to 500 characters.', 'woocommerce' ),
						'additionalProperties' => array( 'type' => 'string' ),
						'maxProperties'        => self::MAX_METADATA_KEYS,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema' => array(),
			'meta'          => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'mcp'          => array( 'public' => false ),
			),
		);
	}

	/**
	 * Check the input, then send the evidence through the dispute update route.
	 *
	 * Only a `submit` that reaches this method as the boolean `true` submits. Through the REST run endpoint, WordPress 7.1
	 * and later turn `"true"`, `"1"` and `1` into `true`, and `"false"`, `"0"` and `0` into `false`, before this runs.
	 * From WordPress 7.2, the Abilities API converts `"true"`, `"1"` and `1` to boolean `true` before this check, so
	 * callers passing them submit. Every form meant as a draft saves a draft. Object-shaped `evidence` and `metadata`
	 * are kept, and the metadata limits apply to them.
	 *
	 * @since 11.4.0
	 *
	 * @param mixed $input Evidence parameters.
	 * @return array|WP_Error The updated dispute, or the reason it was not updated.
	 */
	public static function execute( $input = null ) {
		if ( ! is_array( $input ) || ! isset( $input['dispute_id'] ) || ! is_string( $input['dispute_id'] ) || '' === $input['dispute_id'] ) {
			return new WP_Error( 'wcpay_missing_dispute_id', __( 'A dispute_id is required to submit evidence.', 'woocommerce' ) );
		}

		$dispute_id = $input['dispute_id'];
		$evidence   = isset( $input['evidence'] ) ? rest_sanitize_object( $input['evidence'] ) : array();
		$submit     = true === ( $input['submit'] ?? false );
		$metadata   = isset( $input['metadata'] ) ? rest_sanitize_object( $input['metadata'] ) : array();

		if ( count( $metadata ) > self::MAX_METADATA_KEYS ) {
			return new WP_Error( 'wcpay_too_many_metadata_keys', __( 'Metadata may not contain more than 10 keys.', 'woocommerce' ) );
		}

		foreach ( $metadata as $key => $value ) {
			if ( ! is_string( $key ) || strlen( $key ) > self::MAX_METADATA_KEY_LENGTH ) {
				return new WP_Error( 'wcpay_invalid_metadata_key', __( 'Metadata keys must be strings of 40 characters or fewer.', 'woocommerce' ) );
			}
			if ( ! is_string( $value ) || strlen( $value ) > self::MAX_METADATA_VALUE_LENGTH ) {
				return new WP_Error( 'wcpay_invalid_metadata_value', __( 'Metadata values must be strings of 500 characters or fewer.', 'woocommerce' ) );
			}
		}

		if ( ! DisputeIdentifier::is_accepted( $dispute_id ) ) {
			return new WP_Error( 'wcpay_dispute_evidence_failed', __( 'Route param validation failed.', 'woocommerce' ) );
		}

		try {
			$response = self::delegate_to_rest_controller(
				'POST',
				'/wc/v3/payments/disputes/' . rawurlencode( $dispute_id ),
				array(
					'evidence' => $evidence,
					'submit'   => $submit,
					'metadata' => $metadata,
				)
			);
		} catch ( \Throwable $failure ) {
			self::log_failure( $failure instanceof ApiException ? $failure->get_error_code() : '' );
			return new WP_Error( 'wcpay_dispute_evidence_failed', $failure->getMessage(), array( 'exception' => $failure ) );
		}

		if ( is_wp_error( $response ) ) {
			$route_code = (string) $response->get_error_code();
			self::log_failure( $route_code );
			// No status in the data, so the REST run endpoint answers 500 as the extension's ability does.
			return new WP_Error( 'wcpay_dispute_evidence_failed', $response->get_error_message(), array( 'route_error_code' => $route_code ) );
		}

		return $response;
	}

	/**
	 * Log a failed update with a listed error code only.
	 *
	 * @param string $code Route or platform error code.
	 */
	private static function log_failure( string $code ): void {
		wc_get_logger()->error(
			sprintf( 'Dispute evidence submission failed (%s).', WooPaymentsLogger::get_loggable_error_code( $code ) ),
			array( 'source' => 'woopayments-abilities' )
		);
	}
}
