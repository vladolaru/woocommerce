import { loadScript } from '@paypal/paypal-js';
import widgetBuilder from '../Renderer/WidgetBuilder';
import { processConfig } from './ConfigProcessor';

const loadedScripts = new Map();
const scriptPromises = new Map();

export const loadPayPalScript = async ( namespace, config ) => {
	if ( ! namespace ) {
		throw new Error( 'Namespace is required' );
	}

	if ( loadedScripts.has( namespace ) ) {
		return loadedScripts.get( namespace );
	}

	if ( scriptPromises.has( namespace ) ) {
		return scriptPromises.get( namespace );
	}

	const scriptOptions = {
		...processConfig( config ),
		'data-namespace': namespace,
	};

	const scriptPromise = new Promise( ( resolve, reject ) => {
		loadScript( scriptOptions )
			.then( ( script ) => {
				widgetBuilder.setPaypal( script );
				loadedScripts.set( namespace, script );
				resolve( script );
			} )
			.catch( ( error ) => {
				reject( error );
			} )
			.finally( () => {
				scriptPromises.delete( namespace );
			} );
	} );

	scriptPromises.set( namespace, scriptPromise );
	return scriptPromise;
};

export const loadAndRenderPayPalScript = async (
	namespace,
	options,
	renderFunction,
	renderTarget
) => {
	if ( ! namespace ) {
		throw new Error( 'Namespace is required' );
	}

	const scriptOptions = {
		...options,
		'data-namespace': namespace,
	};

	const script = await loadScript( scriptOptions );
	widgetBuilder.setPaypal( script );
	await renderFunction( script, renderTarget );
};
