/**
 * After a filter is added from the column menu, shows it the way DataViews' own „Add filter” does: the filter bar
 * opens (the funnel toggle is pressed when the bar is hidden) and the new filter's chip opens its choice. DataViews
 * keeps both in its own state and exports no way to set them, so the toggle and the chip are pressed in the DOM,
 * once they exist (they appear on the render after the view changes). [UNAPPROVED N944]
 *
 * @param {Element} host  the grid's host element
 * @param {string}  label the column's label (the chip's text starts with it)
 * @param {number}  [tries] how many animation frames to wait for the chip
 */
export default function revealFilter( host, label, tries = 20 ) {
	// The funnel is DataViews' own „Add filter” menu while no filter is shown; only once a filter exists is it the
	// toggle of the bar (it then has no menu popup).
	const toggle = host.querySelector( '.dataviews-filters__visibility-toggle:not([aria-haspopup])[aria-expanded="false"]' );
	const chips = Array.from( host.querySelectorAll( '.dataviews-filters__summary-chip' ) );
	const chip = chips.find( ( c ) => ( c.textContent || '' ).trim().startsWith( label ) );
	if ( toggle && chip === undefined ) {
		toggle.click();
	}
	if ( chip ) {
		chip.click();
		return;
	}
	if ( tries > 0 ) {
		window.requestAnimationFrame( () => revealFilter( host, label, tries - 1 ) );
	}
}
