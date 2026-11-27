/**
 * The „Jelentkezők” page (S37): the view tabs, one toolbar row with DataViews' own search and filter button, the
 * filters (the view's own condition as DataViews' locked chip, then the viewer's chips with DataViews' own
 * add-filter and reset), one reset of the sort and the counting, and „Táblázat másolása"; then DataViews
 * (compact) with the sum row in its header. Every column has a filter in its column menu; the sums and the
 * copy follow the filters and the search. Kód carries the default order and the reset returns to it. The title
 * and the controls stay at the top and the table fills the window (owner's walk, topic 6a G9). Every sum cell's
 * arrow, and a click or key on the column's header, opens the one column menu (`ColumnMenu`: sort, add filter, and
 * the counting choices where the column counts; G10); the counting is the viewer's own for the session, full access
 * included, and is never saved (B1, round 3).
 *
 * S38: a row opens the detail panel (`DetailPanel`) over the grid, from the button in its first identifying cell
 * or a click on the row. The panel is not modal: another row switches it, and the grid's filters, search, sort,
 * page and scroll stay. × or Esc closes it and focus returns to the row. [UNAPPROVED N1] [UNAPPROVED N3]
 */
import { Fragment, useEffect, useLayoutEffect, useMemo, useRef, useState } from '@wordpress/element';
import { Button, SelectControl, SnackbarList, Tooltip } from '@wordpress/components';
import { update } from '@wordpress/icons';
import { DataViews } from '@wordpress/dataviews/wp';
import { cellText, codeName, fmt, plainText, tableHtml } from './format';
import Cell from './Cell';
import {
	conditionField,
	filteredIds,
	filterSpec,
	isNarrowed,
	lockedFilters,
	narrowOf,
} from './narrow';
import { editFor } from './NumberFilter';
import { writeClipboard } from './clipboard';
import * as api from './api';
import { countsDiffer, countsToSend, createPicks } from './counting';
import Tabs, { tabUrl } from './Tabs';
import BulkSlot from './BulkSlot';
import { selectionBody } from './selection';
import SumRow from './SumRow';
import { labelRowCheckboxes } from './selectionLabels';
import ColumnMenu from './ColumnMenu';
import revealFilter from './filterReveal';
import useStickyColumns, { stickyCount } from './sticky';
import { SNACKBAR_MS, withNotice, withoutNotice } from './snack';
import DetailPanel from './DetailPanel';
import { manualMailAction } from '../mail/bulk';
import { GRIDACTION } from '../mail/texts';
import { GRID, PANEL, SUM } from './texts';

/**
 * The default order: Kód ascending, when the view lists Kód (the ordering time, then the code); a view without
 * it has no sort at all, and only clearing the other sorts gives it the default order. [UNAPPROVED N60]
 */
const defaultSort = ( tab ) =>
	tab.columns.some( ( c ) => c.column === 'code' ) ? { field: 'code', direction: 'asc' } : undefined;

/** A view as a tab opens: Kód ascending, and the view's own condition as the one locked filter. */
const freshView = ( tab, perPage ) => ( {
	type: 'table',
	page: 1,
	perPage,
	search: '',
	filters: lockedFilters( tab.condition ),
	sort: defaultSort( tab ),
	fields: tab.columns.map( ( c ) => c.column ),
	layout: {
		density: 'compact',
		enableMoving: false,
		styles: Object.assign(
			Object.fromEntries( tab.columns.filter( ( c ) => c.numeric ).map( ( c ) => [ c.column, { align: 'end' } ] ) ),
			{
				serial: { align: 'start', width: '1%', maxWidth: '5em' },
				code: { align: 'start', width: '1%', maxWidth: '5em' },
			}
		),
	},
	_tab: tab.key,
} );

export default function Page( { config } ) {
	const tabs = config.tabs;
	const [ current, setCurrent ] = useState( config.current );
	const tab = tabs.find( ( t ) => t.key === current ) || tabs[ 0 ];
	const [ view, setView ] = useState( () => freshView( tab, 50 ) );
	const [ data, setData ] = useState( { rows: [], total: 0 } );
	const [ sums, setSums ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ notices, setNotices ] = useState( [] );
	const [ host, setHost ] = useState( null );
	// The counting picks since the tab was opened: the viewer's own, for the session, and for full access too.
	// Never saved; reset on a tab switch, like the sort and the filters (topic 6a G1, B1). [UNAPPROVED N61]
	const picks = useRef( null );
	if ( ! picks.current ) {
		picks.current = createPicks();
	}
	const [ , setPickTick ] = useState( 0 );
	const [ sumsFail, setSumsFail ] = useState( false );
	// The one column menu: which column's is open, and the header button it was opened from (null: from the arrow).
	// [UNAPPROVED N943]
	const [ menuState, setMenuState ] = useState( null );
	const keepFocus = useRef( false );
	// The ticked rows' keys (the codes), for the bulk action „Levél küldése”: of this page only (DataViews drops
	// the ones not on the page), and cleared with the tab, the page, the order and the filters. [UNAPPROVED N288]
	const [ selection, setSelection ] = useState( [] );
	// The code of the registration in the panel, or null. [UNAPPROVED N3]
	const [ open, setOpen ] = useState( null );
	const openRef = useRef( null );
	openRef.current = open;
	const v = view._tab === tab.key ? view : freshView( tab, view.perPage );
	const sort = v.sort ? v.sort.field : null;
	const desc = v.sort ? v.sort.direction === 'desc' : false;
	// The counted members the viewer picked: the stored choice with the picks on top, for the view's listed
	// columns only. Nothing is sent before a pick, so the server's stored choice applies.
	const counts = Object.keys( picks.current.all() ).length
		? countsToSend( tab.columns, tab.counts, picks.current.all() )
		: null;
	const countsKey = JSON.stringify( counts || {} );

	// The filters that narrow and the search, as the server takes them. A filter with no value yet, just added
	// from a header menu, narrows nothing and is not sent; the locked condition is the view's own, not sent.
	const narrow = narrowOf( v, tab.columns );
	const narrowKey = JSON.stringify( narrow );
	const narrowed = isNarrowed( narrow );
	const [ said, setSaid ] = useState( '' );
	const [ loadError, setLoadError ] = useState( false );
	const loadedKey = useRef( null );

	const select = ( id ) => {
		if ( id === tab.key ) {
			return; // the tab on screen: nothing to switch, keep its filters and rows
		}
		const next = tabs.find( ( t ) => t.key === id ) || tabs[ 0 ];
		setOpen( null ); // the panel belongs to the tab's rows
		setCurrent( id );
		// A tab switch starts the new tab clean: no filters, search or sort carried over or brought back later.
		setView( freshView( next, v.perPage ) );
		setNotices( [] );
		setMenuState( null );
		setLoadError( false );
		setSumsFail( false );
		picks.current.reset();
		loadedKey.current = null;
		setData( { rows: [], total: 0 } ); // no old rows under the new columns
		setSums( null );
		window.history.replaceState( null, '', tabUrl( id ) );
	};

	// The reset of the sort and the counting: Kód ascending again, the stored counting again. It leaves the
	// search and the filters alone; DataViews' own search clear, filter chips and Reset are theirs. [UNAPPROVED N7]
	const resetOrder = () => {
		setView( { ...v, sort: defaultSort( tab ), page: 1 } );
		picks.current.reset();
		setPickTick( ( n ) => n + 1 );
	};

	// S38: the rows open the detail panel. The button sits in the first identifying cell: Kód, else the first
	// column that is not the Sorszám (the name, when the view lists it, comes right after Kód). [UNAPPROVED N3]
	const opener = (
		tab.columns.find( ( c ) => c.type === 'code' ) ||
		tab.columns.find( ( c ) => c.type !== 'serial' ) ||
		tab.columns[ 0 ] || { column: null }
	).column;
	// The registration's name, when the view lists the name column: shown beside the code wherever the grid names a
	// row (the open button, the row's checkbox). Without it the code stands alone. [UNAPPROVED N940]
	const nameColumn = tab.columns.find( ( c ) => c.name );
	const nameOf = ( row ) => ( nameColumn ? row.values[ nameColumn.column ] || null : null );
	const closePanel = () => {
		const key = openRef.current;
		setOpen( null );
		// Focus returns to the row's button, or to DataViews' search box when that row is gone.
		window.requestAnimationFrame( () => {
			const button = host && key !== null ? host.querySelector( '.kg-open[data-key="' + window.CSS.escape( key ) + '"]' ) : null;
			const box = host && host.querySelector( '.dataviews-search input' );
			const target = button || box;
			if ( target ) {
				target.focus();
			}
		} );
	};
	const closeRef = useRef( closePanel );
	closeRef.current = closePanel;
	// Esc closes the panel, one press for the panel only, and not while a dialog or a popup has its own Esc.
	useEffect( () => {
		const onKey = ( event ) => {
			if ( event.key !== 'Escape' || event.defaultPrevented || openRef.current === null ) {
				return;
			}
			if ( document.querySelector( '.components-modal__screen-overlay, .components-popover' ) ) {
				return;
			}
			// Esc belongs to the control that has focus (a column menu, the search box); the panel takes it only
			// from inside itself, from a row button, or when nothing has focus. [UNAPPROVED N3]
			const active = document.activeElement;
			if ( active && active !== document.body && ! active.closest( '.kg-panel' ) && ! active.classList.contains( 'kg-open' ) ) {
				return;
			}
			event.preventDefault();
			event.stopPropagation();
			closeRef.current();
		};
		document.addEventListener( 'keydown', onKey, true );
		return () => document.removeEventListener( 'keydown', onKey, true );
	}, [] );
	// The open row is marked, and every row with a button is clickable. The look is mine. [UNAPPROVED N2]
	useLayoutEffect( () => {
		if ( ! host ) {
			return;
		}
		host.querySelectorAll( 'tbody tr' ).forEach( ( tr ) => {
			const button = tr.querySelector( '.kg-open' );
			tr.classList.toggle( 'kg-row-open', !! ( button && open !== null && button.dataset.key === open ) );
			tr.classList.toggle( 'kg-row-click', !! button );
		} );
	} );
	const onHostClick = ( event ) => {
		const tr = event.target.closest && event.target.closest( 'tbody tr' );
		if ( ! tr || event.target.closest( 'a, button, input, select, label, [role="button"]' ) ) {
			return;
		}
		const button = tr.querySelector( '.kg-open' );
		if ( button ) {
			setOpen( button.dataset.key );
			setAgain( ( n ) => n + 1 );
		}
	};
	// After an entry is saved the grid quietly re-reads its page and sums (no loading state, scroll kept); an
	// answer for a query that is no longer the one on screen is dropped. [UNAPPROVED N21]
	const queryKey = JSON.stringify( [ tab.key, sort, desc, v.page, v.perPage, narrowKey ] );
	const queryRef = useRef( queryKey );
	queryRef.current = queryKey;
	const sumsKey = JSON.stringify( [ tab.key, narrowKey, countsKey ] );
	const sumsRef = useRef( sumsKey );
	sumsRef.current = sumsKey;
	// Activating the open row again re-reads the panel when its read had failed.
	const [ again, setAgain ] = useState( 0 );
	const refresh = () => {
		const asked = queryKey;
		api.fetchPage( config, tab, { sort, desc, perPage: v.perPage, page: v.page, narrow } ).then(
			( result ) => queryRef.current === asked && setData( result ),
			() => {}
		);
		// The sums depend on the tab, the narrowing and the viewer's counting picks, not on the page or the sort.
		const sumsAsked = sumsKey;
		api.fetchSums( config, tab, narrow, counts ).then(
			( result ) => sumsRef.current === sumsAsked && setSums( result ),
			() => {}
		);
	};
	const refreshRef = useRef( refresh );
	refreshRef.current = refresh;

	useEffect( () => {
		let live = true;
		setLoading( true );
		api.fetchPage( config, tab, { sort, desc, perPage: v.perPage, page: v.page, narrow } ).then(
			( result ) => {
				if ( ! live ) {
					return;
				}
				setData( result );
				setLoadError( false );
				setLoading( false );
				// Say the new count when the filters or the search changed (not on paging or the first load).
				if ( loadedKey.current !== null && loadedKey.current !== narrowKey ) {
					const text = result.total ? fmt( GRID.found, result.total ) : GRID.none;
					setSaid( ( old ) => ( old === text ? text + '\u00a0' : text ) );
				}
				loadedKey.current = narrowKey;
			},
			() => {
				// A failed load (a 500, or a 400 for a refused filter) shows no old rows as if they matched.
				if ( live ) {
					setData( { rows: [], total: 0 } );
					setLoadError( true );
					setLoading( false );
				}
			}
		);
		return () => {
			live = false;
		};
	}, [ tab.key, sort, desc, v.page, v.perPage, narrowKey ] );

	useEffect( () => {
		setSelection( [] );
	}, [ tab.key, sort, desc, v.page, v.perPage, narrowKey ] );

	useEffect( () => {
		let live = true;
		api.fetchSums( config, tab, narrow, counts ).then(
			( result ) => {
				if ( live ) {
					setSums( result );
					setSumsFail( false );
				}
			},
			() => {
				// A failed sums request is never a silently empty row.
				if ( live ) {
					setSums( null );
					setSumsFail( true );
				}
			}
		);
		return () => {
			live = false;
		};
	}, [ tab.key, narrowKey, countsKey ] );

	// The table fills the window (G9): the title and the controls stay put, the rows scroll inside, and the
	// header row, the horizontal scrollbar and the paging stay in view. The footer keeps its place.
	useLayoutEffect( () => {
		const wrap = document.querySelector( '.kalaka-grid-wrap' );
		if ( ! wrap ) {
			return undefined;
		}
		const fit = () => {
			const top = wrap.getBoundingClientRect().top;
			const footer = document.getElementById( 'wpfooter' );
			const footerHeight = footer ? footer.offsetHeight : 0;
			wrap.style.height = Math.max( 320, window.innerHeight - top - footerHeight - 4 ) + 'px';
		};
		fit();
		// The controls above the table change height on their own (chips wrapping), so a window resize alone
		// is not enough (G9).
		const observer = new window.ResizeObserver( fit );
		const above = document.querySelector( '.kg-above' );
		if ( above ) {
			observer.observe( above );
		}
		const footer = document.getElementById( 'wpfooter' );
		if ( footer ) {
			observer.observe( footer );
		}
		window.addEventListener( 'resize', fit );
		const timer = window.setTimeout( fit, 200 );
		return () => {
			observer.disconnect();
			window.removeEventListener( 'resize', fit );
			window.clearTimeout( timer );
			wrap.style.height = '';
		};
	}, [] );

	// A filtered column's header is lightly shaded (CSS, `:has()` on this marker).
	const filtered = filteredIds( narrow );
	const fields = useMemo( () => {
		const own = tab.columns.map( ( column ) => ( {
			id: column.column,
			label: column.label,
			header: (
				<span className={ filtered.has( column.column ) ? 'kg-hd is-filtered' : 'kg-hd' } data-column={ column.column }>
					{ column.label }
				</span>
			),
			getValue: ( { item } ) => cellText( column, item ),
			render: ( { item } ) =>
				column.column === opener ? (
					<button
						type="button"
						className="kg-open"
						data-key={ item.key }
						aria-haspopup="dialog"
						onClick={ () => {
							setOpen( item.key );
							setAgain( ( n ) => n + 1 );
						} }
						aria-label={ fmt( PANEL.openRow, codeName( item.key, nameOf( item ) ) ) }
					>
						<Cell column={ column } item={ item } />
					</button>
				) : (
					<Cell column={ column } item={ item } />
				),
			enableSorting: column.sortable,
			enableHiding: false,
			...filterSpec( column ),
			...editFor( column ),
		} ) );
		const condition = conditionField( tab.condition );
		return condition ? [ ...own, condition ] : own;
	}, [ tab, narrowKey, opener ] );

	// The copy result is a snackbar, not text beside the button: a success goes away by itself, an error stays
	// until it is dismissed. [UNAPPROVED N62]
	const timers = useRef( [] );
	useEffect(
		() => () => {
			timers.current.forEach( clearTimeout );
		},
		[]
	);
	const say = ( text, status ) => {
		const id = String( Date.now() ) + Math.random();
		setNotices( ( list ) => withNotice( list, id, text, status ) );
		if ( status !== 'error' ) {
			timers.current.push( setTimeout( () => setNotices( ( list ) => withoutNotice( list, id ) ), SNACKBAR_MS ) );
		}
	};

	const selectedRows = data.rows.filter( ( row ) => selection.includes( row.key ) );
	const [ busy, setBusy ] = useState( false );
	const copy = async () => {
		if ( busy ) {
			return;
		}
		setBusy( true );
		try {
			// Ticked rows when there are any, else every row the filters and search leave, all pages, in the grid's order.
			// Nothing is said while it pages (the button is busy), and a failure halfway copies nothing and says the existing error. [UNAPPROVED N915] [UNAPPROVED N939]
			const rows = selectedRows.length ? selectedRows : await api.fetchAll( config, tab, sort, desc, narrow );
			await writeClipboard( plainText( tab.columns, rows ), tableHtml( tab.columns, rows ) );
			say( fmt( GRID.copied, rows.length ), 'success' );
		} catch {
			say( GRID.copyFail, 'error' );
		}
		setBusy( false );
	};

	// The members the menu starts from: the server's sum row, which is the stored choice (with its default)
	// or the picks it was asked with.
	const chosenFor = ( column ) =>
		( sums && sums[ column.column ] && sums[ column.column ].members ) || ( tab.counts || {} )[ column.column ] || [];

	// One tick. The pick is kept in the browser and sent with the sums request; the checkbox reflects the click
	// at once, and the last state wins. Nothing is saved to the view.
	const pick = ( column, member, on ) => {
		picks.current.toggle( column.column, member, on, chosenFor( column ) );
		setPickTick( ( n ) => n + 1 );
	};

	const perPage = (
		<SelectControl
			className="kg-perpage"
			label={ GRID.perPage }
			labelPosition="side"
			value={ String( v.perPage ) }
			options={ config.perPages.map( ( n ) => ( { value: String( n ), label: String( n ) } ) ) }
			onChange={ ( n ) => setView( { ...v, perPage: parseInt( n, 10 ), page: 1 } ) }
			__nextHasNoMarginBottom
			size="compact"
		/>
	);

	// A filter is added the way DataViews' own „Add filter” adds it: an entry with no value yet, then the filter bar and
	// the new chip are shown. [UNAPPROVED N944]
	const addFilter = ( column ) => {
		keepFocus.current = true; // the new chip's popover takes focus, so it does not go back to the opener
		const operators = ( filterSpec( column ).filterBy || {} ).operators;
		if ( ! operators ) {
			return;
		}
		setView( { ...v, page: 1, filters: [ ...( v.filters || [] ), { field: column.column, operator: operators[ 0 ], value: undefined } ] } );
		if ( host ) {
			revealFilter( host, column.label );
		}
	};

	const menuFor = ( column ) => (
		<ColumnMenu
			column={ column }
			view={ v }
			onSort={ ( sorted ) => setView( { ...v, sort: sorted, page: 1 } ) }
			chosen={ picks.current.chosen( column.column, chosenFor( column ) ) }
			onToggle={ pick }
			filterable={ !! ( filterSpec( column ).filterBy || {} ).operators }
			filtered={ ( v.filters || [] ).some( ( f ) => f.field === column.column ) }
			onAddFilter={ addFilter }
			open={ !! menuState && menuState.column === column.column }
			onOpenChange={ ( next ) => setMenuState( next ? { column: column.column, from: null } : null ) }
			anchor={ menuState && menuState.column === column.column ? menuState.from || undefined : undefined }
		/>
	);

	// A click or key on a column's header opens the same column menu (DataViews' own header menu is stopped before it
	// opens), anchored to the header; the arrow in the sum row opens it too. Enter, Space and the arrow keys are the
	// header button's own keyboard use, and focus goes back to the header when the menu closes. [UNAPPROVED N943]
	const headerButton = ( event ) => ( event.target.closest ? event.target.closest( '.dataviews-view-table-header-button' ) : null );
	const openFromHeader = ( button ) => {
		const mark = button.querySelector( '.kg-hd[data-column]' );
		if ( mark ) {
			setMenuState( { column: mark.dataset.column, from: button } );
		}
	};
	const stopHeader = ( event ) => {
		if ( headerButton( event ) ) {
			event.stopPropagation();
		}
	};
	const onHeaderClick = ( event ) => {
		const button = headerButton( event );
		if ( button ) {
			event.preventDefault();
			event.stopPropagation();
			openFromHeader( button );
		}
	};
	const onHeaderKey = ( event ) => {
		const button = headerButton( event );
		if ( ! button ) {
			return;
		}
		if ( event.key === 'ArrowDown' || event.key === 'ArrowUp' ) {
			event.preventDefault();
			event.stopPropagation();
			openFromHeader( button );
		} else if ( event.key === 'Enter' || event.key === ' ' ) {
			event.stopPropagation(); // the click the browser then makes opens the menu
		}
	};
	// Focus returns to the opener when the menu closes: the header button, or the arrow in the sum row, whichever it was
	// opened from. A sort reloads the grid and DataViews redraws the header (and the sum row its cells), so the opener
	// the menu was opened from can be gone by then: it is looked up again by its column each time, for a short while,
	// until focus is on it. [UNAPPROVED N948]
	const lastFrom = useRef( null );
	const openerOf = ( target ) => {
		if ( target.header ) {
			if ( ! host ) {
				return null;
			}
			const mark = Array.from( host.querySelectorAll( '.kg-hd[data-column]' ) ).find( ( m ) => m.dataset.column === target.column );
			return mark ? mark.closest( '.dataviews-view-table-header-button' ) : null;
		}
		return Array.from( document.querySelectorAll( '[data-kg-menu]' ) ).find( ( b ) => b.dataset.kgMenu === target.column ) || null;
	};
	// It keeps at it for a few seconds, because the grid redraws again when its reload answers; it gives up as soon as
	// the user presses a key or clicks somewhere.
	const focusOpener = ( target ) => {
		const until = Date.now() + 3000;
		let tries = 0;
		let stopped = false;
		const stop = () => {
			stopped = true;
			document.removeEventListener( 'pointerdown', stop, true );
			document.removeEventListener( 'keydown', stop, true );
		};
		const attempt = () => {
			if ( stopped ) {
				return;
			}
			const element = openerOf( target );
			const active = document.activeElement;
			const lost = ! active || active === document.body || ( active.closest && active.closest( '.components-popover' ) );
			if ( element && element !== active && lost ) {
				element.focus();
			}
			tries++;
			if ( tries < 400 && Date.now() < until ) {
				window.requestAnimationFrame( attempt );
			} else {
				stop();
			}
		};
		// The keydown or click that chose the menu item has already happened; a later one is the user moving on.
		window.setTimeout( () => {
			if ( ! stopped ) {
				document.addEventListener( 'pointerdown', stop, true );
				document.addEventListener( 'keydown', stop, true );
			}
		}, 0 );
		window.requestAnimationFrame( attempt );
	};
	useEffect( () => {
		if ( menuState ) {
			lastFrom.current = { column: menuState.column, header: !! menuState.from };
		} else if ( lastFrom.current ) {
			const target = lastFrom.current;
			lastFrom.current = null;
			if ( keepFocus.current ) {
				keepFocus.current = false;
			} else {
				focusOpener( target );
			}
		}
	}, [ menuState ] );
	// The header button the menu was opened from says it is open.
	useLayoutEffect( () => {
		if ( ! host ) {
			return;
		}
		host.querySelectorAll( '.dataviews-view-table-header-button' ).forEach( ( button ) => {
			const mark = button.querySelector( '.kg-hd[data-column]' );
			const on = !! ( menuState && menuState.from && mark && mark.dataset.column === menuState.column );
			button.setAttribute( 'aria-expanded', on ? 'true' : 'false' );
		} );
	} );

	// A grant holder's counting is theirs too, and so is a full-access user's: the reset is enabled while only
	// the sort or the counting differs from the view's default.
	const defaultView = defaultSort( tab );
	const sortDiffers = JSON.stringify( v.sort || null ) !== JSON.stringify( defaultView || null );
	const orderDiffers = sortDiffers || countsDiffer( tab.columns, tab.counts || {}, picks.current.all() );
	let empty;
	if ( loadError ) {
		empty = (
			<p className="kg-load-fail" role="alert">
				{ GRID.loadFail }
			</p>
		);
	} else if ( narrowed ) {
		// Filters or a search that match nothing say so.
		empty = <p className="kg-no-match">{ GRID.noMatch }</p>;
	} else {
		// A view with no registrations at all. [UNAPPROVED N10]
		empty = <p className="kg-no-match">{ GRID.emptyView }</p>;
	}
	// The bulk action „Levél küldése”, only on a tab that carries the manual mail. [UNAPPROVED N287, N289]
	const actions = useMemo(
		() =>
			tab.manualMail
				? [
						manualMailAction( {
							eligible: true,
							event: config.event,
							previewPath: `${ config.rest }/mail/manual/preview`,
							onSent: () => setSelection( [] ),
						} ),
				  ]
				: [],
		[ tab.manualMail, config.event, config.rest ]
	);
	// Each row's selection checkbox is named with the registration's code and name (DataViews says „(no title)”).
	// [UNAPPROVED T286] [UNAPPROVED N940]
	const rowNames = data.rows.map( ( row ) => codeName( row.key, nameOf( row ) ) ).join( '\u0000' );
	useLayoutEffect( () => {
		if ( ! host || actions.length === 0 ) {
			return undefined;
		}
		const names = rowNames === '' ? [] : rowNames.split( '\u0000' );
		const run = () => labelRowCheckboxes( host, names, ( text ) => fmt( GRIDACTION.selectRow, text ) );
		run();
		const observer = new window.MutationObserver( run );
		observer.observe( host, { childList: true, subtree: true } );
		return () => observer.disconnect();
	}, [ host, actions.length, rowNames ] );

	// The start columns that stay while the table scrolls sideways: the checkbox, Kód and the name. [UNAPPROVED N942]
	useStickyColumns( host, stickyCount( tab.columns, v.fields, actions.length > 0 ) );

	const copyButton = (
		<Button
			variant="secondary"
			size="compact"
			onClick={ copy }
			isBusy={ busy }
			disabled={ ! loading && ! data.total }
			accessibleWhenDisabled
		>
			{ GRID.copy }
		</Button>
	);

	return (
		<Fragment>
			<div className="kg-above">
				<div className="kg-tabrow">
					<Tabs tabs={ tabs } current={ tab.key } onSelect={ select } />
				</div>
			</div>
			<span className="screen-reader-text" role="status" aria-live="polite">
				{ said }
			</span>
			{ sumsFail && (
				<p className="kg-load-fail" role="alert">
					{ SUM.loadFail }
				</p>
			) }
			<SumRow
				root={ host }
				columns={ tab.columns }
				order={ v.fields }
				sums={ sums }
				menuFor={ menuFor }
				narrowed={ narrowed }
				framed={ actions.length > 0 }
			/>
			<div
				ref={ setHost }
				className="kg-dv"
				onClick={ onHostClick }
				onPointerDownCapture={ stopHeader }
				onMouseDownCapture={ stopHeader }
				onClickCapture={ onHeaderClick }
				onKeyDownCapture={ onHeaderKey }
			>
				<DataViews
					key={ tab.key }
					data={ data.rows }
					fields={ fields }
					view={ v }
					onChangeView={ ( next ) => {
						// A late call from the previous tab's DataViews (a pending search) is dropped.
						if ( next._tab !== undefined && next._tab !== tab.key ) {
							return;
						}
						setView( { ...next, _tab: tab.key } );
					} }
					isLoading={ loading }
					paginationInfo={ {
						totalItems: data.total,
						totalPages: Math.max( 1, Math.ceil( data.total / v.perPage ) ),
					} }
					defaultLayouts={ { table: {} } }
					getItemId={ ( item ) => item.key }
					actions={ actions }
					selection={ selection }
					onChangeSelection={ setSelection }
					search={ false }
					config={ { perPageSizes: config.perPages } }
					empty={ empty }
				>
					<div className="kg-bulkbar" role="toolbar" aria-label={ GRID.toolbar }>
						<DataViews.Search />
						{ /* DataViews' own add-filter: the funnel opens its menu while no filter is shown. [UNAPPROVED N63] */ }
						<DataViews.FiltersToggle />
						<DataViews.FiltersToggled className="kg-filters" />
						<Button
							variant="secondary"
							size="compact"
							icon={ update }
							label={ GRID.reload }
							onClick={ resetOrder }
							disabled={ ! orderDiffers }
							accessibleWhenDisabled
						/>
						<span className="kg-spacer" />
						<div className="kg-bulkbar-actions">
							<BulkSlot
								actions={ actions }
								rows={ selectedRows }
								selectionOf={ ( items ) => selectionBody( items, tab, narrow ) }
								total={ data.total }
							/>
							{ narrowed && selectedRows.length === 0 ? <Tooltip text={ GRID.followsFilter }>{ copyButton }</Tooltip> : copyButton }
						</div>
					</div>
					<DataViews.Layout />
					<div className="kg-dv-foot">
						{ perPage }
						<DataViews.Pagination />
					</div>
				</DataViews>
			</div>
			<div className="kg-grid-snacks">
				<SnackbarList
					notices={ notices }
					onRemove={ ( id ) => setNotices( ( list ) => withoutNotice( list, id ) ) }
				/>
			</div>
			{ open !== null && (
				<DetailPanel config={ config } code={ open } again={ again } onClose={ closePanel } onSaved={ () => refreshRef.current() } />
			) }
		</Fragment>
	);
}
