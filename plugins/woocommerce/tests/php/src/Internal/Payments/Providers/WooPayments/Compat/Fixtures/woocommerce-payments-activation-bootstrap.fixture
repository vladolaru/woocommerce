<?php
/**
 * Minimal WooPayments activation-sandbox declaration fixture.
 *
 * Declares both plugin-owned names, a superset of the real activation include: client 11.1.0 woocommerce-payments.php:28
 * declares WC_Payments_Features at file scope, and WC_Payments only in wcpay_init() (:163-165). A test that loads this
 * fixture proves that neither name was taken by a facade.
 */

declare( strict_types = 1 );

// phpcs:disable Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Classes.ValidClassName.NotCamelCaps,Generic.Files.OneObjectStructurePerFile.MultipleFound -- The fixture reproduces the plugin-owned global declarations that collide during activation.
/**
 * WooPayments feature class activation fixture.
 */
class WC_Payments_Features {

	/**
	 * Identify the fixture declaration.
	 */
	public const DECLARATION_OWNER = 'plugin';
}

/**
 * WooPayments bootstrap class activation fixture.
 */
class WC_Payments {

	/**
	 * Identify the fixture declaration.
	 */
	public const DECLARATION_OWNER = 'plugin';
}
// phpcs:enable Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Classes.ValidClassName.NotCamelCaps,Generic.Files.OneObjectStructurePerFile.MultipleFound
