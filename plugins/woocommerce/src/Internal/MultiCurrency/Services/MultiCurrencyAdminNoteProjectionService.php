<?php
/**
 * MultiCurrencyAdminNoteProjectionService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

/**
 * Projects multi-currency WC Admin notes without saving or deleting notes.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyAdminNoteProjectionService {

	private const NOTE_NAME      = 'wc-payments-notes-multi-currency-available';
	private const NOTE_SOURCE    = 'woocommerce-payments';
	private const NOTE_TYPE_INFO = 'info';
	private const ACTION_STATUS  = 'unactioned';

	/**
	 * Project the multi-currency availability note.
	 *
	 * @return array<string,mixed>
	 *
	 * @since 11.0.0
	 */
	public static function get_note_manifest(): array {
		return array(
			'name'         => self::NOTE_NAME,
			'title'        => __( 'Sell worldwide in multiple currencies', 'woocommerce' ),
			'content'      => __( 'Boost your international sales by allowing your customers to shop and pay in their local currency.', 'woocommerce' ),
			'content_data' => array(),
			'type'         => self::NOTE_TYPE_INFO,
			'source'       => self::NOTE_SOURCE,
			'actions'      => array(
				array(
					'name'    => self::NOTE_NAME,
					'label'   => __( 'Set up now', 'woocommerce' ),
					'query'   => MultiCurrencySettingsProjectionService::get_settings_page_url(),
					'status'  => self::ACTION_STATUS,
					'primary' => true,
				),
			),
		);
	}

	/**
	 * Project add-note eligibility and note metadata.
	 *
	 * @param bool $is_ajax            Whether the current request is Ajax.
	 * @param bool $provider_connected Whether the provider account is connected.
	 * @param bool $can_be_added       Whether note traits say the note can be added.
	 * @return array{should_add: bool, note: array<string,mixed>|null, blockers: array<int,string>}
	 *
	 * @since 11.0.0
	 */
	public static function get_add_note_manifest(
		bool $is_ajax,
		bool $provider_connected,
		bool $can_be_added
	): array {
		$blockers = array();

		if ( $is_ajax ) {
			$blockers[] = 'ajax_request';
		}

		if ( ! $provider_connected ) {
			$blockers[] = 'provider_not_connected';
		}

		if ( ! $can_be_added ) {
			$blockers[] = 'note_cannot_be_added';
		}

		if ( ! empty( $blockers ) ) {
			return array(
				'should_add' => false,
				'note'       => null,
				'blockers'   => $blockers,
			);
		}

		return array(
			'should_add' => true,
			'note'       => self::get_note_manifest(),
			'blockers'   => array(),
		);
	}
}
