/* global MutationObserver */
import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './Components/App';

const CONTAINER_ID = 'ppcp-settings-container';
let root = null;
let mountedNode = null;

const mount = ( node ) => {
	if ( root || ! node ) {
		return;
	}
	mountedNode = node;
	root = createRoot( node );
	root.render( <App /> );
};

const unmount = () => {
	if ( ! root ) {
		return;
	}
	root.unmount();
	root = null;
	mountedNode = null;
};

const existing = document.getElementById( CONTAINER_ID );
if ( existing ) {
	mount( existing );
}

// The Payments settings app renders the container after this script ran, and removes it on a client-side route change.
// Never disconnected on purpose: this bundle only loads on the route's page, and each mutation batch costs one lookup.
const observer = new MutationObserver( () => {
	const node = document.getElementById( CONTAINER_ID );
	if ( node && node !== mountedNode ) {
		unmount();
		mount( node );
	} else if ( ! node && root ) {
		unmount();
	}
} );
observer.observe( document.body, { childList: true, subtree: true } );
