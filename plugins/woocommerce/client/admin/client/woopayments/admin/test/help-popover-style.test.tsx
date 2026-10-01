/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import * as sass from 'sass';
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { HelpPopover } from '../overview/components/help-popover';

// Client 11.1.0 `components/tooltip/style.scss:12-27` and Gridicon `help-outline` at 16px: the help icon is a small
// grey glyph that sits on the text baseline (`vertical-align: text-bottom`), so it does not raise its line.
describe( 'HelpPopover toggle', () => {
	afterEach( () => {
		document.head.innerHTML = '';
	} );

	it( 'is a 16px grey icon that does not stretch its line', () => {
		const style = document.createElement( 'style' );
		style.textContent = sass.compileString(
			fs.readFileSync(
				path.resolve(
					__dirname,
					'../overview/components/help-popover.scss'
				),
				'utf8'
			)
		).css;
		document.head.append( style );

		render(
			<p>
				Reason
				<HelpPopover label="Learn more">Details</HelpPopover>
			</p>
		);

		const toggle = screen.getByRole( 'button', { name: 'Learn more' } );
		const icon = toggle.querySelector( 'svg' );
		const toggleStyle = window.getComputedStyle( toggle );

		expect( icon?.getAttribute( 'width' ) ).toBe( '16' );
		expect( icon?.getAttribute( 'height' ) ).toBe( '16' );
		expect( toggleStyle.color ).toBe( 'rgb(148, 148, 148)' );
		expect( toggleStyle.height ).toBe( '16px' );
		expect( toggleStyle.minWidth ).toBe( '0' );
		expect( toggleStyle.verticalAlign ).toBe( 'text-bottom' );
	} );
} );
