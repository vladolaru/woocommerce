<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling;

use WC_Unit_Test_Case;

/**
 * Keeps the Stripe Billing module separable: outside its folder, only the named seam files may reference it.
 */
class StripeBillingPlacementTest extends WC_Unit_Test_Case {

	private const MODULE_DIRECTORY = 'src/Internal/Payments/Providers/WooPayments/StripeBilling/';

	/**
	 * Files outside the module that may reference it, one line each (the seams in `StripeBilling/README.md`).
	 */
	private const SEAM_FILES = array(
		'includes/legacy/woopayments-compat/class-wc-payments-features.php',
		'src/Internal/Payments/Providers/WooPayments/WooPaymentsProvider.php',
		'src/Internal/Payments/Providers/WooPayments/WooPaymentsGateway.php',
		'src/Internal/Payments/Providers/WooPayments/Subscriptions/SubscriptionsController.php',
		'src/Internal/Payments/Providers/WooPayments/Webhooks/EventIngestor.php',
		'src/Internal/Payments/Providers/WooPayments/WooPaymentsStoreSetupReportController.php',
		'src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php',
		'src/Internal/Payments/Providers/WooPayments/Admin/MerchantRestController.php',
		'src/Internal/Payments/Providers/WooPayments/Cutover/BundledSubscriptionsGuard.php',
		'src/Internal/Payments/Providers/WooPayments/WooPaymentsFeeDetailsNoteController.php',
	);

	/**
	 * @testdox The scanner permits only the module class in seam files and ignores comments.
	 */
	public function test_scanner_recognizes_allowed_references(): void {
		$referencing = <<<'PHP'
<?php
namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingModule;
PHP;
		$relative    = <<<'PHP'
<?php
namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;
$module = wc_get_container()->get( StripeBilling\StripeBillingModule::class );
PHP;
		$commented   = <<<'PHP'
<?php
// See StripeBilling\StripeBillingModule for the boundary.
/** Mirrors Providers\WooPayments\StripeBilling\StripeBillingApi. */
$module = WooPaymentsStripeBillingModuleFactory::class;
PHP;

		$this->assertTrue( $this->has_forbidden_reference( $referencing, false ) );
		$this->assertFalse( $this->has_forbidden_reference( $referencing, true ) );
		$this->assertTrue( $this->has_forbidden_reference( $relative, false ) );
		$this->assertFalse( $this->has_forbidden_reference( $relative, true ) );
		$this->assertTrue( $this->has_forbidden_reference( str_replace( 'StripeBillingModule', 'StripeBillingSubscriptionService', $referencing ), true ) );
		$this->assertFalse( $this->has_forbidden_reference( $commented, false ) );
	}

	/**
	 * @testdox Outside the module folder, only the seam files may name StripeBillingModule and none may name its services.
	 */
	public function test_only_seam_files_reference_the_module(): void {
		$plugin_directory = WC()->plugin_path() . '/';
		$violations       = array();

		foreach ( array( 'src', 'includes' ) as $source_directory ) {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $plugin_directory . $source_directory ) );
			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo || ! $file->isFile() || 'php' !== $file->getExtension() ) {
					continue;
				}

				$path = substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $plugin_directory ) );
				if ( 0 === strpos( $path, self::MODULE_DIRECTORY ) ) {
					continue;
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local production source for a placement assertion.
				$source = (string) file_get_contents( $file->getPathname() );
				// The scanner's pattern needs the literal module name, so a file without it is skipped before tokenizing.
				if ( false !== stripos( $source, 'StripeBilling' ) && $this->has_forbidden_reference( $source, in_array( $path, self::SEAM_FILES, true ) ) ) {
					$violations[] = $path;
				}
			}
		}

		$this->assertSame( array(), $violations, 'Only StripeBillingModule may be named outside its folder, and only in the seam files listed in StripeBilling/README.md.' );
	}

	/**
	 * @testdox The module never reaches into the compatibility layer.
	 */
	public function test_module_does_not_reference_compat(): void {
		$violations = array();
		$iterator   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( WC()->plugin_path() . '/' . self::MODULE_DIRECTORY ) );
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local production source for a placement assertion.
			if ( preg_match( '/\\\\Compat\\\\/', $this->code_without_comments( (string) file_get_contents( $file->getPathname() ) ) ) ) {
				$violations[] = $file->getFilename();
			}
		}

		$this->assertSame( array(), $violations, 'The Stripe Billing module must not use the compatibility layer.' );
	}

	/**
	 * Check whether code names the Stripe Billing namespace beyond the class allowed at a seam.
	 *
	 * @param string $source       PHP source.
	 * @param bool   $is_seam_file Whether this file may name StripeBillingModule.
	 * @return bool
	 */
	private function has_forbidden_reference( string $source, bool $is_seam_file ): bool {
		$pattern = $is_seam_file ? '/\bStripeBilling\b(?!\\\\StripeBillingModule\b(?!\\\\))/i' : '/\bStripeBilling\b/i';

		return 1 === preg_match( $pattern, $this->code_without_comments( $source ) );
	}

	/**
	 * Strip comments from PHP source.
	 *
	 * @param string $source PHP source.
	 * @return string
	 */
	private function code_without_comments( string $source ): string {
		$code = '';
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$code .= is_array( $token ) ? $token[1] : $token;
		}

		return $code;
	}
}
