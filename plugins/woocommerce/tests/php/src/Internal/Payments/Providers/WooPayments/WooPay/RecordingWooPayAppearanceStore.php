<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayAppearanceStore;

/**
 * Recording WooPay appearance store: it records writes on, and answers from, the recording session service's
 * appearance fields, so one double carries a test's WooPay state.
 */
class RecordingWooPayAppearanceStore extends WooPaymentsWooPayAppearanceStore {

	/**
	 * Recording session service whose appearance fields this store uses.
	 *
	 * @var RecordingWooPaySessionService
	 */
	public RecordingWooPaySessionService $flags;

	/**
	 * Use a recording session service's appearance fields.
	 *
	 * @param RecordingWooPaySessionService $flags Recording session service.
	 */
	public function __construct( RecordingWooPaySessionService $flags ) {
		$this->flags = $flags;
	}

	/**
	 * Save WooPay appearance data.
	 *
	 * @param array<string,mixed>             $appearance Appearance data.
	 * @param array<int,array<string,string>> $font_rules Font rules.
	 */
	public function save_woopay_appearance( array $appearance, array $font_rules = array() ): void {
		unset( $font_rules );
		++$this->flags->appearance_writes;
		$this->flags->last_appearance = $appearance;
	}

	/**
	 * Maybe save WooPay appearance data.
	 *
	 * @param array<string,mixed>             $appearance Appearance data.
	 * @param array<int,array<string,string>> $font_rules Font rules.
	 * @return bool
	 */
	public function maybe_save_woopay_appearance( array $appearance, array $font_rules = array() ): bool {
		unset( $font_rules );
		++$this->flags->appearance_writes;
		$this->flags->last_appearance = $appearance;

		return $this->flags->appearance_stored;
	}

	/**
	 * Get WooPay appearance data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_woopay_appearance(): array {
		return $this->flags->appearance;
	}

	/**
	 * Get WooPay font rules.
	 *
	 * @return array<int,array<string,string>>
	 */
	public function get_woopay_font_rules(): array {
		return $this->flags->font_rules;
	}

	/**
	 * Validate WooPay appearance data.
	 *
	 * @param array<string,mixed> $appearance Appearance data.
	 * @return bool
	 */
	public function validate_appearance_schema( array $appearance ): bool {
		return ! isset( $appearance['buttonTheme'] );
	}
}
