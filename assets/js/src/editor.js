import { createHooks } from '@wordpress/hooks';
import domReady from '@wordpress/dom-ready';

window.wpcomsp_51_backfill = window.wpcomsp_51_backfill || {};
window.wpcomsp_51_backfill.hooks = createHooks();

domReady( () => {
	window.wpcomsp_51_backfill.hooks.doAction( 'editor.ready' );
} );
