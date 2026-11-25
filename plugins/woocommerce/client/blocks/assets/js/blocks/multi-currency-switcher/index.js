/**
 * External dependencies
 */
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import { BLOCK_NAME, blockSettings } from './block';

registerBlockType( BLOCK_NAME, blockSettings );
