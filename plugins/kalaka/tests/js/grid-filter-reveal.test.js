/**
 * After a filter is added from the column menu the filter bar and the new chip are shown, by pressing DataViews' own
 * toggle and chip once they exist.
 */
import revealFilter from '../../src/Admin/app/grid/filterReveal';

let host;
beforeEach( () => {
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	jest.useFakeTimers();
	window.requestAnimationFrame = ( fn ) => setTimeout( fn, 0 );
} );
afterEach( () => {
	host.remove();
	jest.useRealTimers();
} );

test( 'the hidden bar is opened by its toggle, then the chip of the column is pressed', () => {
	const clicks = [];
	host.innerHTML = '<button class="dataviews-filters__visibility-toggle" aria-expanded="false"></button>';
	host.querySelector( 'button' ).addEventListener( 'click', () => {
		clicks.push( 'toggle' );
		host.insertAdjacentHTML( 'beforeend', '<button class="dataviews-filters__summary-chip">Állapot</button>' );
		host.querySelector( '.dataviews-filters__summary-chip' ).addEventListener( 'click', () => clicks.push( 'chip' ) );
	} );
	revealFilter( host, 'Állapot' );
	jest.runAllTimers();
	expect( clicks ).toEqual( [ 'toggle', 'chip' ] );
} );

test( 'the funnel that is still DataViews\' own add-filter menu is never pressed', () => {
	host.innerHTML = '<button class="dataviews-filters__visibility-toggle" aria-haspopup="menu" aria-expanded="false"></button>';
	const onClick = jest.fn();
	host.querySelector( 'button' ).addEventListener( 'click', onClick );
	revealFilter( host, 'Állapot', 3 );
	jest.runAllTimers();
	expect( onClick ).not.toHaveBeenCalled();
} );

test( 'a bar that is already open only has its chip pressed, and only the column\'s own chip', () => {
	const clicks = [];
	host.innerHTML =
		'<button class="dataviews-filters__visibility-toggle" aria-expanded="true"></button>' +
		'<button class="dataviews-filters__summary-chip" id="a">Név</button>' +
		'<button class="dataviews-filters__summary-chip" id="b">Állapot</button>';
	host.querySelectorAll( '.dataviews-filters__summary-chip' ).forEach( ( chip ) => chip.addEventListener( 'click', () => clicks.push( chip.id ) ) );
	revealFilter( host, 'Állapot' );
	expect( clicks ).toEqual( [ 'b' ] );
} );
