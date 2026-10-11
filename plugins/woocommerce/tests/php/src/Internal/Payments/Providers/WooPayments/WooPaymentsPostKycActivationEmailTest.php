<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPostKycActivationEmail;
use WC_Unit_Test_Case;

/**
 * Tests for the post-KYC activation reminder email.
 *
 * Expectations come from client 11.1.0 `includes/emails/class-wc-payments-email-post-kyc-activation.php` and
 * `templates/emails/post-kyc-activation.php`, `templates/emails/plain/post-kyc-activation.php`.
 */
class WooPaymentsPostKycActivationEmailTest extends WC_Unit_Test_Case {

	/**
	 * Mail arguments wp_mail() was called with.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $mails = array();

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->mails = array();
		// WC_Emails::email_header() prints the stage heading; an earlier test may have reset the hooks of the shared mailer.
		if ( false === has_action( 'woocommerce_email_header', array( WC()->mailer(), 'email_header' ) ) ) {
			add_action( 'woocommerce_email_header', array( WC()->mailer(), 'email_header' ) );
		}
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		parent::tearDown();
	}

	/**
	 * Record a mail and report it sent.
	 *
	 * @param null|bool           $short_circuit Earlier short-circuit value.
	 * @param array<string,mixed> $atts          The wp_mail() arguments.
	 * @return bool
	 */
	public function capture_mail( $short_circuit, $atts ): bool {
		unset( $short_circuit );
		$this->mails[] = $atts;

		return true;
	}

	/**
	 * @testdox Sends each stage to the store admin with the client's copy and a CTA naming the stage.
	 * @dataProvider provide_stages
	 *
	 * @param int    $stage   Stage day.
	 * @param string $heading The client's stage heading.
	 * @param string $body    The client's stage body.
	 */
	public function test_sends_each_stage_with_the_client_copy( int $stage, string $heading, string $body ): void {
		$email             = new WooPaymentsPostKycActivationEmail();
		$email->email_type = 'html';

		$this->assertTrue( $email->trigger( $stage ) );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( get_option( 'admin_email' ), $this->mails[0]['to'] );
		$this->assertSame( 'Ready for your first sale on ' . get_bloginfo( 'name' ) . '?', $this->mails[0]['subject'] );
		$html = html_entity_decode( (string) $this->mails[0]['message'], ENT_QUOTES, 'UTF-8' );
		$this->assertStringContainsString( $heading, $html );
		$this->assertStringContainsString( $body, $html );
		$document = new \DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8"?>' . (string) $this->mails[0]['message'] );
		libxml_clear_errors();
		$cta = null;
		foreach ( $document->getElementsByTagName( 'a' ) as $link ) {
			if ( 'Promote my store' === trim( $link->textContent ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode property.
				$cta = $link;
			}
		}
		$this->assertNotNull( $cta, 'The CTA is a link labelled Promote my store.' );
		$this->assertSame( $this->get_client_cta_url( $stage ), $cta->getAttribute( 'href' ) );
	}

	/**
	 * @testdox The plain text email carries the client's stage copy and the CTA link.
	 * @dataProvider provide_stages
	 *
	 * @param int    $stage   Stage day.
	 * @param string $heading The client's stage heading.
	 * @param string $body    The client's stage body.
	 */
	public function test_plain_email_carries_the_client_copy( int $stage, string $heading, string $body ): void {
		$email             = new WooPaymentsPostKycActivationEmail();
		$email->email_type = 'plain';

		$this->assertTrue( $email->trigger( $stage ) );

		// Plain emails are word-wrapped, so compare with whitespace collapsed.
		$text = (string) preg_replace( '/\s+/', ' ', (string) $this->mails[0]['message'] );
		$this->assertStringContainsString( $heading, $text );
		$this->assertStringContainsString( $body, $text );
		$this->assertStringContainsString( 'Promote my store: ' . $this->get_client_cta_url( $stage ), $text );
	}

	/**
	 * The CTA destination the client builds (client 11.1.0 `includes/emails/class-wc-payments-email-post-kyc-activation.php:125-134`).
	 *
	 * @param int $stage Stage day.
	 * @return string
	 */
	private function get_client_cta_url( int $stage ): string {
		return admin_url( 'admin.php' ) . '?page=wc-admin&path=/marketing&wcpay_referrer=post_kyc_email&wcpay_referrer_stage=' . $stage;
	}

	/**
	 * The client's stages and copy.
	 *
	 * @return array<string,array{int,string,string}>
	 */
	public static function provide_stages(): array {
		return array(
			'day 7'  => array( 7, 'Your store is ready — let’s make your first sale', 'Your WooPayments account is approved and ready to accept payments. Now it’s about getting eyes on your store — share your link, tell your network, and make your first sale.' ),
			'day 14' => array( 14, 'Two weeks in — have you shared your store yet?', 'Your account is fully approved and accepting payments. Share your store with your first potential customers to get that first sale.' ),
			'day 30' => array( 30, 'Your payments are ready — your first sale can be too', 'Everything on the payments side is ready. The next step is getting your first customer through the door — share your store link and start spreading the word.' ),
		);
	}

	/**
	 * @testdox Sends nothing for a stage outside the client's 7, 14 and 30 day sequence.
	 */
	public function test_sends_nothing_for_an_unknown_stage(): void {
		$email = new WooPaymentsPostKycActivationEmail();

		$this->assertFalse( $email->trigger( 21 ) );
		$this->assertSame( array(), $this->mails );
	}

	/**
	 * @testdox The default heading is the client's.
	 */
	public function test_default_heading_is_the_client_heading(): void {
		$this->assertSame( 'Your store is ready — let’s make your first sale', ( new WooPaymentsPostKycActivationEmail() )->get_default_heading() );
	}
}
