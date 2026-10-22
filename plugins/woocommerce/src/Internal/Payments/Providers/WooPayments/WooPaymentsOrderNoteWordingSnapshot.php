<?php
/**
 * WooPaymentsOrderNoteWordingSnapshot class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use MO;

/**
 * Keeps the WooPayments extension's translated order-note wordings after the switch to the built-in WooPayments.
 *
 * Notes the extension wrote carry no identity, so WooPaymentsOrderNoteService recognises them by their text in the
 * extension's `woocommerce-payments` text domain. The switch stores that domain's translation of every note wording once,
 * for each locale the store uses, in one option that is never autoloaded, so those notes stay recognised after the
 * extension's language packs are deleted. Capturing is best effort: it never throws and never holds up the switch. The
 * snapshot, its option and the job's calls leave with the extension wordings in WooCommerce 12.0.0.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsOrderNoteWordingSnapshot {

	/**
	 * Option holding the snapshot; WooCommerce's uninstall deletes it with every `woocommerce_` option.
	 */
	public const OPTION = 'woocommerce_woopayments_note_wordings';

	/**
	 * Snapshot shape version.
	 */
	public const SCHEMA = 1;

	/**
	 * Locale status: the extension's language pack rendered the wordings.
	 */
	public const STATUS_RENDERED = 'rendered';

	/**
	 * Locale status: no language pack, so the extension wrote its English wordings in this locale.
	 */
	public const STATUS_ENGLISH_FALLBACK = 'english_fallback';

	/**
	 * Locale status: the wordings could not be rendered for this locale.
	 */
	public const STATUS_UNAVAILABLE = 'unavailable';

	/**
	 * The extension's text domain.
	 */
	private const TEXT_DOMAIN = 'woocommerce-payments';

	/**
	 * Largest serialized snapshot stored, in bytes: WordPress's own threshold for an option too large to autoload
	 * (`wp_max_autoloaded_option_size`).
	 */
	private const SIZE_CEILING = 150000;

	/**
	 * Most store managers whose locale is captured.
	 */
	private const MAX_USERS = 50;

	/**
	 * Most locales captured.
	 */
	private const MAX_LOCALES = 30;

	/**
	 * Seconds one capture may spend rendering before it leaves the remaining locales for the next attempt.
	 */
	private const TIME_BUDGET_SECONDS = 20;

	/**
	 * Most captures, the first included.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * The English source of every wording WooPaymentsOrderNoteService renders in the extension's text domain.
	 */
	public const NOTE_FORMATS = array(
		'A payment of %1$s was successfully charged using %2$s (%3$s).',
		'UPE payment failed: %s',
		'We\'re not able to process this payment. Please try again later.',
		'The early fraud warning received for this payment is no longer actionable, because the payment was refunded or disputed. See <a>payment details</a> for more information.',
		'Payment has received an early fraud warning. <refund>Refunding the payment now</refund> can prevent a dispute. See <a>payment details</a> for more information.',
		'Payment has received an early fraud warning with reason "%1$s". <refund>Refunding the payment now</refund> can prevent a dispute. See <a>payment details</a> for more information.',
		'Card never received',
		'Fraudulent card application',
		'Made with counterfeit card',
		'Made with lost card',
		'Made with stolen card',
		'Other',
		'Unauthorized use of card',
		'A test payment of %1$s was processed using %2$s in <strong>test mode</strong> (<a>%3$s</a>). No real funds were collected.',
		'A payment of %1$s was <strong>successfully charged</strong> using %2$s (<a>%3$s</a>).',
		'A payment of %1$s was <strong>authorized</strong> using %2$s (<a>%3$s</a>).',
		'A payment of %1$s was <strong>started</strong> using %2$s (<code>%3$s</code>).',
		'A payment of %1$s was <strong>successfully captured</strong> using %2$s (<a>%3$s</a>).',
		'Payment authorization was successfully <strong>cancelled</strong> (<a>%1$s</a>).',
		'A capture of %1$s <strong>failed</strong> to complete using %2$s (<a>%3$s</a>).',
		'&#x1F6AB; A payment of %1$s was <strong>blocked</strong> by the following risk filters:<br>%2$s<br><br><a>View more details</a>.',
		'&#x1F6AB; A payment of %1$s was <strong>blocked</strong> by one or more risk filters.<br><br><a>View more details</a>.',
		'&#x26D4; A payment of %1$s was <strong>held for review</strong> by the following risk filters:<br>%2$s<br><br><a>View more details</a>.',
		'&#x26D4; A payment of %1$s was <strong>held for review</strong> by one or more risk filters.<br><br><a>View more details</a>.',
		'Place in review if the AVS verification fails',
		'Place in review if the shipping address country differs from the billing address country',
		'Place in review if the country resolved from customer IP is not listed in your selling countries',
		'Place in review if the order originates from a country different from the shipping address country',
		'Place in review if the items count is not in your defined range',
		'Place in review if the purchase price is not in your defined range',
		'Block if the AVS verification fails',
		'Block if the shipping address differs from the billing address',
		'Block if the country resolved from customer IP is not listed in your selling countries',
		'Block if the order originates from a country different from the shipping address country',
		'Block if the items count is not in your defined range',
		'Block if the purchase price is not in your defined range',
		'A payment of %1$s <strong>failed</strong> to complete with the following message: <code>%2$s</code>.',
		'A payment of %1$s <strong>failed</strong>. %2$s',
		'We couldn’t verify the postal code in the billing address. If the issue persists, suggest the customer to reach out to the card issuing bank.',
		'A payment of %1$s <strong>failed</strong> using %2$s (<a>%3$s</a>).',
		'A payment of %1$s <strong>failed</strong>: the saved payment method can no longer be used. A new payment method is required.',
		'A payment of %1$s <strong>failed</strong>: the saved payment method <strong>%2$s</strong> can no longer be used. A new payment method is required.',
		'A terminal payment of %1$s <strong>failed</strong> using %2$s (<a>%3$s</a>)',
		'Payment authorization has <strong>expired</strong> (<a>%1$s</a>).',
		'The customer\'s bank account has been closed.',
		'The customer has notified their bank that this payment was unauthorized.',
		'The customer\'s account has insufficient funds to cover this payment.',
		'The customer\'s bank account could not be located.',
		'Microdeposit transfers failed. Please check the account, institution and transit numbers.',
		'You have exceeded the number of allowed verification attempts.',
		'The mandate used for this renewal payment is invalid. You may need to bring the customer back to your store and ask them to resubmit their payment information.',
		'The customer\'s bank could not send pre-debit notification for the payment.',
		'For recurring payment greater than mandate amount or INR 15000, payment was not approved by the card holder.',
		'With the following message: <code>%s</code>',
		'is pending',
		'was successfully processed',
		'A refund of %1$s %4$s using %2$s (<code>%3$s</code>).',
		'A refund of %1$s %5$s using %2$s. Reason: %3$s. (<code>%4$s</code>)',
	);

	/**
	 * Translations of rendered locales by blog ID, read once per request.
	 *
	 * @var array<int,array<string,array<string,string>>>
	 */
	private array $translations = array();

	/**
	 * Capture the wordings in every locale not yet rendered. A capture that ran out of time is resumed by the next one, at
	 * most three captures per store.
	 */
	public function capture(): void {
		$stored   = $this->read();
		$attempts = (int) ( $stored['attempts']['count'] ?? 0 );
		if ( self::MAX_ATTEMPTS <= $attempts || true === ( $stored['attempts']['complete'] ?? false ) ) {
			return;
		}

		$locales     = is_array( $stored['locales'] ?? null ) ? $stored['locales'] : array();
		$started     = microtime( true );
		$out_of_time = false;
		foreach ( $this->get_locales() as $locale ) {
			if ( self::STATUS_RENDERED === ( $locales[ $locale ]['status'] ?? '' ) ) {
				continue;
			}
			$out_of_time        = $out_of_time || self::TIME_BUDGET_SECONDS < microtime( true ) - $started;
			$locales[ $locale ] = $out_of_time
				? array(
					'status'  => self::STATUS_UNAVAILABLE,
					'strings' => array(),
				)
				: $this->render_locale( $locale );
		}

		$payload = array(
			'schema'            => self::SCHEMA,
			'extension_version' => $this->get_extension_version( (string) ( $stored['extension_version'] ?? '' ) ),
			'captured_at'       => time(),
			'locales'           => $locales,
			'trimmed'           => array(),
			'attempts'          => array(
				'count'    => $attempts + 1,
				'last'     => time(),
				'complete' => ! $out_of_time,
			),
		);
		$this->write( $this->fit_size_ceiling( $payload ) );
	}

	/**
	 * Get the translations of every rendered locale, keyed by locale and then by English source.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function get_rendered_translations(): array {
		$blog_id = get_current_blog_id();
		if ( ! isset( $this->translations[ $blog_id ] ) ) {
			$this->translations[ $blog_id ] = array();
			foreach ( (array) ( $this->read()['locales'] ?? array() ) as $locale => $entry ) {
				if ( is_array( $entry ) && self::STATUS_RENDERED === ( $entry['status'] ?? '' ) && is_array( $entry['strings'] ?? null ) ) {
					$this->translations[ $blog_id ][ (string) $locale ] = array_filter( $entry['strings'], 'is_string' );
				}
			}
		}

		return $this->translations[ $blog_id ];
	}

	/**
	 * Get the largest serialized snapshot stored, in bytes.
	 *
	 * @return int
	 */
	protected function get_size_ceiling(): int {
		return self::SIZE_CEILING;
	}

	/**
	 * Read the header version of the installed extension.
	 *
	 * @param string $stored The version stored by an earlier capture, kept once the extension's files are gone.
	 * @return string
	 */
	private function get_extension_version( string $stored ): string {
		$plugin_file = WP_PLUGIN_DIR . '/' . WooPaymentsRuntimeArbiter::PLUGIN_FILE;
		$headers     = is_readable( $plugin_file ) ? get_file_data( $plugin_file, array( 'Version' => 'Version' ) ) : array();

		return '' !== ( $headers['Version'] ?? '' ) ? $headers['Version'] : $stored;
	}

	/**
	 * Read the stored snapshot; a value that is not an array reads as none.
	 *
	 * @return array<string,mixed>
	 */
	private function read(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Get the locales to capture, in priority order: the site's, the store managers', then every locale the extension has a
	 * language pack for.
	 *
	 * @return string[]
	 */
	private function get_locales(): array {
		global $wp_textdomain_registry;

		$locales = array( get_locale() );
		foreach ( get_users(
			array(
				'capability' => 'manage_woocommerce',
				'number'     => self::MAX_USERS,
				'fields'     => 'ID',
			)
		) as $user_id ) {
			$locales[] = get_user_locale( (int) $user_id );
		}
		foreach ( $wp_textdomain_registry->get_language_files_from_path( WP_LANG_DIR . '/plugins/' ) as $file ) {
			if ( 1 === preg_match( '/^' . self::TEXT_DOMAIN . '-([A-Za-z0-9_@-]+)\.mo$/', basename( (string) $file ), $matches ) ) {
				$locales[] = $matches[1];
			}
		}

		return array_slice( array_values( array_unique( array_filter( $locales ) ) ), 0, self::MAX_LOCALES );
	}

	/**
	 * Render the wordings in one locale: switched to it when WordPress has the locale, else from the extension's .mo file.
	 *
	 * @param string $locale Locale.
	 * @return array{status:string,strings:array<string,string>}
	 */
	private function render_locale( string $locale ): array {
		if ( determine_locale() === $locale ) {
			return $this->render_current_locale();
		}

		if ( switch_to_locale( $locale ) ) {
			try {
				return $this->render_current_locale();
			} finally {
				restore_previous_locale();
			}
		}

		global $wp_textdomain_registry;
		$path = $wp_textdomain_registry->get( self::TEXT_DOMAIN, $locale );
		$mo   = new MO();
		if ( ! $path || ! $mo->import_from_file( $path . self::TEXT_DOMAIN . '-' . $locale . '.mo' ) ) {
			return array(
				'status'  => self::STATUS_UNAVAILABLE,
				'strings' => array(),
			);
		}

		$strings = array();
		foreach ( self::NOTE_FORMATS as $format ) {
			$strings[ $format ] = $mo->translate( $format );
		}

		return array(
			'status'  => self::STATUS_RENDERED,
			'strings' => $strings,
		);
	}

	/**
	 * Render the wordings through the extension's text domain in the current locale.
	 *
	 * @return array{status:string,strings:array<string,string>}
	 */
	private function render_current_locale(): array {
		$strings = array();
		foreach ( self::NOTE_FORMATS as $format ) {
			// phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction, WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralDomain -- Each wording's source is the extension's msgid.
			$strings[ $format ] = translate( $format, self::TEXT_DOMAIN );
		}

		return array(
			'status'  => is_textdomain_loaded( self::TEXT_DOMAIN ) ? self::STATUS_RENDERED : self::STATUS_ENGLISH_FALLBACK,
			'strings' => $strings,
		);
	}

	/**
	 * Drop the lowest-priority locales, never the site's, until the serialized snapshot fits the size ceiling.
	 *
	 * @param array<string,mixed> $payload Snapshot.
	 * @return array<string,mixed>
	 */
	private function fit_size_ceiling( array $payload ): array {
		foreach ( array_reverse( array_diff( array_keys( $payload['locales'] ), array( get_locale() ) ) ) as $locale ) {
			if ( strlen( serialize( $payload ) ) <= $this->get_size_ceiling() ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Measures the size the option stores.
				break;
			}
			unset( $payload['locales'][ $locale ] );
			array_unshift( $payload['trimmed'], $locale );
		}

		return $payload;
	}

	/**
	 * Store the snapshot, retrying once after flushing a stale object-cache entry, and log when both writes fail.
	 *
	 * The payload always carries a new `captured_at`, so update_option() returns false only when the write failed.
	 *
	 * @param array<string,mixed> $payload Snapshot.
	 */
	private function write( array $payload ): void {
		$written = update_option( self::OPTION, $payload, false );
		if ( ! $written ) {
			wp_cache_delete( self::OPTION, 'options' );
			$written = update_option( self::OPTION, $payload, false );
		}

		if ( ! $written ) {
			wc_get_logger()->warning(
				'WooPayments order note wordings could not be stored; notes the WooPayments extension wrote are matched in the current language only.',
				array(
					'source' => WooPaymentsLogger::SOURCE,
					'bytes'  => strlen( serialize( $payload ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Reports the size the option would store.
				)
			);
			return;
		}

		unset( $this->translations[ get_current_blog_id() ] );
	}
}
