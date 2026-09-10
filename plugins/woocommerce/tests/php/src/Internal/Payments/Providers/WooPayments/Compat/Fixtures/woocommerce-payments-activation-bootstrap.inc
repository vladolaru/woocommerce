<?php
/**
 * Minimal WooPayments activation-sandbox declaration fixture.
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
