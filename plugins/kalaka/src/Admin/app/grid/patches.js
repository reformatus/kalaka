/**
 * Small patches on DataViews' own markup (S37). DataViews 21 has no hook for these, so each one watches the
 * DOM it needs and gives it up when the page goes.
 *
 * - The popups are portalled to the body: a chip's tooltip („Szűrés: …”) only repeats the column name the
 *   chip shows and covers the row above, so it is hidden; the calendar's weekday initials come from `Intl`
 *   and give „Sz” for both szerda and szombat, so each initial is written from the cell's full name.
 * - The Sorszám range's popover carries the hint „Például 1–50: az első 50 jelentkező” under its two inputs:
 *   DataViews' number control takes no description, so the hint is added to the popover of the open Sorszám chip.
 * - Each chip's remove button is named „Szűrő eltávolítása: {oszlop}”; DataViews names them all alike.
 * - Clicking the sort option that is already active in a header menu turns the sort off: DataViews' menu
 *   reports it as the same sort again, so the click is read and the sort is cleared right after the menu's own
 *   handler.
 */
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { fmt } from './format';
import { GRID } from './texts';

const WEEKDAY = {
	hétfő: 'H',
	kedd: 'K',
	szerda: 'Sze',
	csütörtök: 'Cs',
	péntek: 'P',
	szombat: 'Szo',
	vasárnap: 'V',
};

const escapeRegExp = ( text ) => text.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );

/** The patches on the popups DataViews portals to the body; once per page. */
export function usePopupPatches( serialLabel ) {
	useEffect( () => {
		const tooltip = new RegExp( '^' + escapeRegExp( __( 'Filter by: %1$s' ) ).replace( '%1\\$s', '.+' ) + '$' );
		const run = () => {
			document.querySelectorAll( '[class*="__popup"]' ).forEach( ( popup ) => {
				if ( tooltip.test( popup.textContent.trim() ) && popup.parentElement && popup.parentElement.parentElement ) {
					popup.parentElement.parentElement.style.display = 'none';
				}
			} );
			document.querySelectorAll( '.dataviews-filters__summary-popover th[aria-label]' ).forEach( ( th ) => {
				const want = WEEKDAY[ th.getAttribute( 'aria-label' ) ];
				if ( want && th.textContent !== want ) {
					th.textContent = want;
				}
			} );
		};
		const hint = () => {
			const chip = serialLabel
				? [ ...document.querySelectorAll( '.dataviews-filters__summary-chip[aria-expanded="true"]' ) ].find( ( c ) =>
						c.textContent.trim().startsWith( serialLabel )
				  )
				: null;
			const widget = chip && document.querySelector( '.dataviews-filters__summary-popover .dataviews-filters__user-input-widget' );
			if ( widget && ! widget.querySelector( '.kg-hint' ) ) {
				const note = document.createElement( 'p' );
				note.className = 'kg-hint';
				note.textContent = GRID.serialHint;
				widget.appendChild( note );
			}
		};
		const observer = new window.MutationObserver( () => {
			run();
			hint();
		} );
		observer.observe( document.body, { childList: true, subtree: true, characterData: true } );
		return () => observer.disconnect();
	}, [ serialLabel ] );
}

/** Names each chip's remove button after its column. */
export function useChipLabels( chips, columns ) {
	useEffect( () => {
		if ( ! chips ) {
			return undefined;
		}
		// The longest label first, so „Ebéd” does not take the chip of „Ebéd (vegetáriánus)”.
		const labels = columns.map( ( c ) => c.label ).sort( ( a, b ) => b.length - a.length );
		const name = () =>
			chips.querySelectorAll( '.dataviews-filters__summary-chip-container' ).forEach( ( box ) => {
				const button = box.querySelector( '.dataviews-filters__summary-chip-remove' );
				const chip = box.querySelector( '.dataviews-filters__summary-chip' );
				const label = chip && [ ...labels, GRID.unanswered ].find( ( l ) => chip.textContent.trim().startsWith( l ) );
				const want = label ? fmt( GRID.removeFilter, label ) : null;
				if ( button && want && button.getAttribute( 'aria-label' ) !== want ) {
					button.setAttribute( 'aria-label', want );
				}
			} );
		name();
		const observer = new window.MutationObserver( name );
		observer.observe( chips, {
			childList: true,
			subtree: true,
			characterData: true,
			attributes: true,
			attributeFilter: [ 'aria-label' ],
		} );
		return () => observer.disconnect();
	}, [ chips, columns ] );
}

/**
 * Turns the sort off when the active sort option of a header menu is clicked.
 *
 * @param {Function} off called to return to the default order
 */
export function useSortToggle( off ) {
	useEffect( () => {
		const labels = [ __( 'Sort ascending' ), __( 'Sort descending' ) ];
		const onClick = ( event ) => {
			const item = event.target instanceof window.Element ? event.target.closest( '[role="menuitemradio"]' ) : null;
			if ( item && item.getAttribute( 'aria-checked' ) === 'true' && labels.includes( item.textContent.trim() ) ) {
				// After the menu's own handler: it reports the click as the same sort again and would undo this.
				window.setTimeout( off, 0 );
			}
		};
		document.addEventListener( 'click', onClick, true );
		return () => document.removeEventListener( 'click', onClick, true );
	}, [ off ] );
}
