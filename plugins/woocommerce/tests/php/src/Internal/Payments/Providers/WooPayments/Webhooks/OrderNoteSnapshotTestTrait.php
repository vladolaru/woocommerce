<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteWordingSnapshot;

/**
 * Capture a temporary extension language pack, then remove it before delivering the webhook.
 */
trait OrderNoteSnapshotTestTrait {

	/**
	 * @param string[] $translations Extension strings keyed by English source.
	 * @param bool     $stale        Whether capture already completed before these strings were included.
	 */
	private function capture_note_translations( array $translations, bool $stale = false ): void {
		if ( $stale ) {
			update_option(
				WooPaymentsOrderNoteWordingSnapshot::OPTION,
				array(
					'schema'   => 1,
					'attempts' => array( 'complete' => true ),
					'locales'  => array(
						'fr_FR' => array(
							'status'  => 'rendered',
							'strings' => array( 'Other' => 'Autre' ),
						),
					),
				)
			);
		}
		$snapshot = new WooPaymentsOrderNoteWordingSnapshot();
		wc_get_container()->replace( WooPaymentsOrderNoteWordingSnapshot::class, $snapshot );
		$domain = 'woocommerce-payments';
		$file   = trailingslashit( get_temp_dir() ) . wp_generate_uuid4() . '.mo';
		$pack   = new \MO();
		foreach ( $translations as $source => $translation ) {
			$pack->add_entry(
				array(
					'singular'     => $source,
					'translations' => array( $translation ),
				)
			);
		}
		try {
			$this->assertTrue( $pack->export_to_file( $file ) );
			$this->assertTrue( load_textdomain( $domain, $file ) );
			$snapshot->capture();
		} finally {
			\WP_Translation_Controller::get_instance()->unload_file( $file, $domain );
			unload_textdomain( $domain, true );
			wp_delete_file( $file );
		}
	}

	/**
	 * Write the first built-in note with a WooCommerce translation that will be unavailable on replay.
	 *
	 * @param string   $source      Note template in English.
	 * @param string   $translation Template in the previous language.
	 * @param callable $deliver     First delivery.
	 */
	private function deliver_in_prior_note_language( string $source, string $translation, callable $deliver ): void {
		$translate = static fn( $current, $text ) => $source === $text ? $translation : $current;
		add_filter( 'gettext_woocommerce', $translate, 10, 2 );
		try {
			$deliver();
		} finally {
			remove_filter( 'gettext_woocommerce', $translate );
		}
	}

	/**
	 * Cross each event with the histories that can change its handling.
	 *
	 * @param string[] $statuses Event statuses.
	 * @return array<string,array{string,string}>
	 */
	private static function note_history_cases( array $statuses ): array {
		$cases = array();
		foreach ( $statuses as $status ) {
			foreach ( array( 'first', 'neighbour', 'English', 'snapshot', 'stale snapshot', 'old format', 'built-in' ) as $history ) {
				$cases[ $status . ' / ' . $history ] = array( $status, $history );
			}
		}
		return $cases;
	}
}
