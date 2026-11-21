/**
 * The one patch left on DataViews' own markup (S37). DataViews 21 has no slot for it, so it watches the DOM it
 * needs and gives it up when the page goes.
 *
 * The Sorszám range's popover carries the hint „Például 1–50: az első 50 jelentkező” (approved by the owner
 * 2026-11-18) under its two inputs: DataViews' number control takes no description, so the hint is added to the
 * popover of the open Szám chip.
 */
import { useEffect } from '@wordpress/element';
import { GRID } from './texts';

export function useSerialHint( serialLabel ) {
	useEffect( () => {
		if ( ! serialLabel ) {
			return undefined;
		}
		const hint = () => {
			const chip = [ ...document.querySelectorAll( '.dataviews-filters__summary-chip[aria-expanded="true"]' ) ].find( ( c ) =>
				c.textContent.trim().startsWith( serialLabel )
			);
			const widget = chip && document.querySelector( '.dataviews-filters__summary-popover .dataviews-filters__user-input-widget' );
			if ( widget && ! widget.querySelector( '.kg-hint' ) ) {
				const note = document.createElement( 'p' );
				note.className = 'kg-hint';
				note.textContent = GRID.serialHint;
				widget.appendChild( note );
			}
		};
		const observer = new window.MutationObserver( hint );
		observer.observe( document.body, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'aria-expanded' ] } );
		return () => observer.disconnect();
	}, [ serialLabel ] );
}
