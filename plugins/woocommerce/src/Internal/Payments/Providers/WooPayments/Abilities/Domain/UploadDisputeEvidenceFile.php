<?php
/**
 * Upload a WooPayments dispute evidence file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use WP_Error;

/**
 * Upload a file for later attachment to a dispute.
 *
 * @since 11.4.0
 */
final class UploadDisputeEvidenceFile extends AbstractAbility {
	/** MIME types accepted by the dispute evidence screen. */
	private const ACCEPTED_FILE_TYPES = array( 'application/pdf', 'image/jpeg', 'image/png' );

	/** Maximum decoded size accepted for dispute evidence. */
	private const MAX_FILE_SIZE_BYTES = 4500000;

	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/upload-dispute-evidence-file';
	}

	/**
	 * Define the file inputs and write annotations advertised to clients.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		return array(
			'label'         => __( 'Upload dispute evidence file', 'woocommerce' ),
			'description'   => __( 'Upload a document (PDF, PNG, or JPEG) to use as dispute evidence. Returns a file object whose id can be passed to the document fields of woocommerce-payments/submit-dispute-evidence.', 'woocommerce' ),
			'input_schema'  => array(
				'type'                 => 'object',
				'required'             => array( 'file_name', 'file_type', 'file_contents' ),
				'properties'           => array(
					'file_name'     => array(
						'type'        => 'string',
						'description' => __( 'File name including extension (e.g. receipt.pdf).', 'woocommerce' ),
					),
					'file_type'     => array(
						'type'        => 'string',
						'enum'        => self::ACCEPTED_FILE_TYPES,
						'description' => __( 'File MIME type. One of application/pdf, image/jpeg, or image/png.', 'woocommerce' ),
					),
					'file_contents' => array(
						'type'        => 'string',
						'description' => __( 'Base64-encoded file contents. The decoded size must not exceed the dispute-evidence size limit.', 'woocommerce' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema' => array(),
			'meta'          => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'mcp'          => array( 'public' => false ),
			),
		);
	}

	/**
	 * Validate the evidence and return the provider's file object.
	 *
	 * @since 11.4.0
	 *
	 * @param mixed $input File parameters.
	 * @return array|WP_Error Uploaded file or the reason it could not be uploaded.
	 */
	public static function execute( $input = null ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$file_name     = ( isset( $input['file_name'] ) && is_string( $input['file_name'] ) ) ? sanitize_file_name( $input['file_name'] ) : '';
		$file_type     = ( isset( $input['file_type'] ) && is_string( $input['file_type'] ) ) ? $input['file_type'] : '';
		$file_contents = ( isset( $input['file_contents'] ) && is_string( $input['file_contents'] ) ) ? $input['file_contents'] : '';

		if ( '' === $file_name || '' === $file_type || '' === $file_contents ) {
			return new WP_Error(
				'wcpay_missing_file_input',
				__( 'file_name, file_type, and file_contents are all required to upload a file.', 'woocommerce' )
			);
		}

		if ( ! in_array( $file_type, self::ACCEPTED_FILE_TYPES, true ) ) {
			return new WP_Error(
				'wcpay_unsupported_file_type',
				__( 'file_type must be one of application/pdf, image/jpeg, or image/png.', 'woocommerce' )
			);
		}

		$decoded = base64_decode( $file_contents, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Check the file encoding and decoded length.
		if ( false === $decoded ) {
			return new WP_Error(
				'wcpay_invalid_file_contents',
				__( 'file_contents must be base64-encoded.', 'woocommerce' )
			);
		}

		if ( strlen( $decoded ) > self::MAX_FILE_SIZE_BYTES ) {
			return new WP_Error(
				'wcpay_file_too_large',
				sprintf(
					/* translators: %d: maximum file size in bytes. */
					__( 'The file exceeds the %d-byte dispute-evidence size limit.', 'woocommerce' ),
					self::MAX_FILE_SIZE_BYTES
				)
			);
		}

		try {
			return wc_get_container()->get( Client::class )->upload_file_contents( $file_contents, $file_name, $file_type, 'dispute_evidence', false );
		} catch ( \Throwable $failure ) {
			$code = $failure instanceof ApiException ? 'wcpay_evidence_file_upload_error' : 'wcpay_file_upload_failed';
			wc_get_logger()->error( sprintf( 'Dispute evidence file upload failed (%s).', $code ), array( 'source' => 'woopayments-abilities' ) );
			return new WP_Error( 'wcpay_file_upload_failed', $failure->getMessage(), array( 'exception' => $failure ) );
		}
	}
}
