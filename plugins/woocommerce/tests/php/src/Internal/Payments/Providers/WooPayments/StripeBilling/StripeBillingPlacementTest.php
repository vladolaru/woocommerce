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
		'src/Internal/Payments/Providers/WooPayments/WooPaymentsProvider.php',
		'src/Internal/Payments/Providers/WooPayments/NativeWooPaymentsGateway.php',
		'src/Internal/Payments/Providers/WooPayments/Subscriptions/WooPaymentsSubscriptionsController.php',
		'src/Internal/Payments/Providers/WooPayments/Webhooks/WooPaymentsEventIngestor.php',
		'src/Internal/Payments/Providers/WooPayments/WooPaymentsOperationalQueueService.php',
		'src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php',
		'src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsMerchantRestController.php',
		'src/Internal/Payments/Providers/WooPayments/Subscriptions/WooPaymentsLegacySubscriptionsGuard.php',
		'src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverReconciliationJob.php',
	);

	/**
	 * @testdox The scanner finds module references in code and ignores them in comments.
	 */
	public function test_scanner_finds_code_references_and_ignores_comments(): void {
		$referencing = <<<'PHP'
<?php
namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
PHP;
		$relative    = <<<'PHP'
<?php
namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;
$module = wc_get_container()->get( StripeBilling\WooPaymentsStripeBillingModule::class );
PHP;
		$commented   = <<<'PHP'
<?php
// See StripeBilling\WooPaymentsStripeBillingModule for the boundary.
/** Mirrors Providers\WooPayments\StripeBilling\StripeBillingApi. */
$module = WooPaymentsStripeBillingModuleFactory::class;
PHP;

		$this->assertTrue( $this->references_module( $referencing ) );
		$this->assertTrue( $this->references_module( $relative ) );
		$this->assertFalse( $this->references_module( $commented ) );
	}

	/**
	 * @testdox Outside the module folder, only the seam files reference the Stripe Billing module.
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
				if ( 0 === strpos( $path, self::MODULE_DIRECTORY ) || in_array( $path, self::SEAM_FILES, true ) ) {
					continue;
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local production source for a placement assertion.
				if ( $this->references_module( (string) file_get_contents( $file->getPathname() ) ) ) {
					$violations[] = $path;
				}
			}
		}

		$this->assertSame( array(), $violations, 'Only the seam files listed in StripeBilling/README.md may reference the Stripe Billing module.' );
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
	 * @testdox The module documents its boundary, seams and extraction steps.
	 */
	public function test_module_has_boundary_documentation(): void {
		$this->assertFileExists( WC()->plugin_path() . '/' . self::MODULE_DIRECTORY . 'README.md' );
	}

	/**
	 * Tell whether PHP source references the module namespace outside comments.
	 *
	 * @param string $source PHP source.
	 * @return bool
	 */
	private function references_module( string $source ): bool {
		return 1 === preg_match( '/(^|[^\w\\\\])(\\\\?[\w\\\\]*\\\\)?StripeBilling\\\\/', $this->code_without_comments( $source ) );
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
