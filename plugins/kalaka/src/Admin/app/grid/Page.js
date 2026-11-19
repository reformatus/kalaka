/**
 * The „Jelentkezők” page (S37): the view tabs, the „Műveletek” toolbar with the search, the filters (the
 * view's own condition as a locked chip), the reload button and „Táblázat másolása”, and DataViews (compact)
 * with the sum row in its header. Every column has a filter in its header menu; the sums and the copy follow
 * the filters and the search. The default order is the sorszám order, then the code: the reload button and a
 * second click on the active sort option return to it. The title and the controls stay at the top and the
 * table fills the window (owner's walk, topic 6a G9). The counting choices live in each countable column's
 * own header menu, reached from its sum cell (G10); a grant holder's choice is their own and never changes
 * the shared view (G1).
 *
 * S38: a row opens the detail panel (`DetailPanel`) over the grid, from the button in its first identifying cell
 * or a click on the row. The panel is not modal: another row switches it, and the grid's filters, search, sort,
 * page and scroll stay. × or Esc closes it and focus returns to the row. [UNAPPROVED N1] [UNAPPROVED N3]
 */
import {
	createPortal,
	Fragment,
	useCallback,
	useEffect,
	useLayoutEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { Button, CheckboxControl, Dropdown, SelectControl, Tooltip } from '@wordpress/components';
import { update } from '@wordpress/icons';
import { DataViews } from '@wordpress/dataviews/wp';
import { cellText, fmt, plainText, tableHtml } from './format';
import { filteredIds, filterSpec, isNarrowed, narrowOf, unansweredField } from './narrow';
import { useChipLabels, usePopupPatches, useSortToggle } from './patches';
import { writeClipboard } from './clipboard';
import * as api from './api';
import { serial } from './serial';
import { runPick } from './pick';
import { countsDiffer, countsToSend, createPicks } from './counting';
import Tabs, { tabUrl } from './Tabs';
import SumRow from './SumRow';
import DetailPanel from './DetailPanel';
import { STATUS_ICON } from './StatusMark';
import { GRID, PANEL, SUM, VIEWS } from './texts';

/** A cell as shown: the status with its icon, a decision as an icon with screen-reader text, the code in monospace. */
function Cell( { column, item } ) {
	const text = cellText( column, item );
	if ( text === '' ) {
		return '';
	}
	if ( column.type === 'status' ) {
		const [ icon, tone ] = STATUS_ICON[ item.values.status ] || [ '', '' ];
		// An inactive registration (anything but registered) dims its whole row. [UNAPPROVED N25]
		const inactive = item.values.status !== 'registered';
		return (
			<span className={ 'kg-mark' + ( inactive ? ' kg-inactive' : '' ) }>
				<span className={ `dashicons ${ icon } ${ tone }` } aria-hidden="true" />
				{ text }
			</span>
		);
	}
	if ( column.type === 'decision' ) {
		const [ icon, tone ] = [ 'dashicons-marker', '' ];
		return (
			<span className="kg-mark" title={ text }>
				<span className={ `dashicons ${ icon } ${ tone }` } aria-hidden="true" />
				<span className="screen-reader-text">{ text }</span>
			</span>
		);
	}
	if ( column.type === 'code' ) {
		return <span className="kg-code">{ text }</span>;
	}
	if ( column.type === 'serial' ) {
		return <span className="kg-serial">{ text }</span>;
	}
	if ( column.type === 'text' || column.type === 'choice' ) {
		return (
			<span className="kg-long" title={ text }>
				{ text }
			</span>
		);
	}
	return text;
}

/**
 * The counting choices as they appear in a column's own header menu: one checkbox per member of the column.
 * Clicks stay in the fieldset, so the menu's own handlers do not take them. Each tick is kept locally at
 * once, so quick successive ticks all apply and the checkbox reflects the click before the save returns.
 * While the grid is narrowed, full access sees the note that the setting covers the whole view.
 */
function CountingMenu( { column, chosen, onToggle, narrowed } ) {
	return (
		<div
			className="kg-countbox"
			onPointerDown={ ( event ) => event.stopPropagation() }
			onClick={ ( event ) => event.stopPropagation() }
		>
			<p className="kg-countbox-title">{ SUM.counting }</p>
			{ narrowed && <p className="kg-countbox-note">{ SUM.pickNote }</p> }
			{ column.members.map( ( member ) => (
				<CheckboxControl
					key={ member.value }
					label={ member.label }
					checked={ chosen.includes( member.value ) }
					__nextHasNoMarginBottom
					onChange={ ( on ) => onToggle( column, member.value, on ) }
				/>
			) ) }
		</div>
	);
}

/**
 * „Szűrő hozzáadása” on a clean grid: DataViews' own filters render nothing until a filter exists, so this
 * one offers the same columns and adds the filter to DataViews' own view state. With a filter present,
 * DataViews' own add-filter menu shows instead (owner's walk, topic 6a G4).
 */
function AddFilterFallback( { columns, onAdd } ) {
	const choices = columns
		.map( ( column ) => ( { id: column.column, label: column.label, spec: filterSpec( column ) } ) )
		.filter( ( c ) => c.spec && c.spec.filterBy && c.spec.filterBy.operators && c.spec.filterBy.operators.length );
	const none = unansweredField( columns );
	if ( none ) {
		choices.push( { id: none.id, label: none.label, spec: none } );
	}
	return (
		<Dropdown
			className="kg-addfilter"
			popoverProps={ { placement: 'bottom-start' } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					variant="tertiary"
					size="compact"
					aria-expanded={ isOpen }
					onClick={ onToggle }
				>
					{ 'Szűrő hozzáadása' }
				</Button>
			) }
			renderContent={ ( { onClose } ) => (
				<div className="kg-addfilter-list">
					{ choices.map( ( c ) => (
						<button
							key={ c.id }
							type="button"
							className="components-menu-item__button"
							onClick={ () => {
								onClose();
								onAdd( {
									field: c.id,
									value: undefined,
									operator: c.spec.filterBy.operators[ 0 ],
								} );
							} }
						>
							{ c.label }
						</button>
					) ) }
				</div>
			) }
		/>
	);
}

const freshView = ( tab, perPage ) => ( {
	type: 'table',
	page: 1,
	perPage,
	search: '',
	filters: [],
	sort: undefined,
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

/** The column id of the header menu button now open, from the table's own `colgroup`. */
function openColumnId( button ) {
	const th = button.closest( 'th' );
	const table = button.closest( 'table' );
	if ( ! th || ! table || ! th.parentElement ) {
		return null;
	}
	const index = Array.prototype.indexOf.call( th.parentElement.children, th );
	const col = table.querySelector( 'colgroup' )?.children[ index ];
	const match = col && /dataviews-view-table__col-(.+?)(?:\s|$)/.exec( col.className );
	return match ? match[ 1 ] : null;
}

export default function Page( { config } ) {
	const tabs = config.tabs;
	const [ current, setCurrent ] = useState( config.current );
	const tab = tabs.find( ( t ) => t.key === current ) || tabs[ 0 ];
	const [ view, setView ] = useState( () => freshView( tab, 50 ) );
	const [ data, setData ] = useState( { rows: [], total: 0 } );
	const [ sums, setSums ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ message, setMessage ] = useState( '' );
	const [ messageKey, setMessageKey ] = useState( '' );
	const [ host, setHost ] = useState( null );
	const [ menu, setMenu ] = useState( null );
	const [ searchSlot, setSearchSlot ] = useState( null );
	const [ chipSlot, setChipSlot ] = useState( null );
	const [ loadError, setLoadError ] = useState( false );
	const [ said, setSaid ] = useState( '' );
	// The counting picks since the tab was opened: a grant holder's own, and full access's optimistic state
	// until the save reloads the server's. Reset on a tab switch, like the sort and the filters.
	const picks = useRef( null );
	if ( ! picks.current ) {
		picks.current = createPicks();
	}
	const [ , setPickTick ] = useState( 0 );
	const [ sumsFail, setSumsFail ] = useState( false );
	// The code of the registration in the panel, or null. [UNAPPROVED N3]
	const [ open, setOpen ] = useState( null );
	const openRef = useRef( null );
	openRef.current = open;
	const v = view._tab === tab.key ? view : freshView( tab, view.perPage );
	const sort = v.sort ? v.sort.field : null;
	const desc = v.sort ? v.sort.direction === 'desc' : false;
	// A grant holder's own counted members: the stored choice with their picks on top, for the view's listed
	// columns only (a view need not list Állapot). Nothing is sent for full access, whose pick is saved.
	const counts = config.sumEditable
		? null
		: countsToSend( tab.columns, tab.counts, picks.current.all() );
	const countsKey = JSON.stringify( counts || {} );

	// The filters that narrow and the search, as the server takes them. A filter with no value yet, just added
	// from a header menu, narrows nothing and is not sent.
	const narrow = narrowOf( v, tab.columns );
	const narrowKey = JSON.stringify( narrow );
	const narrowed = isNarrowed( narrow );
	const narrowRef = useRef( narrow );
	narrowRef.current = narrow;
	const keyRef = useRef( narrowKey );
	keyRef.current = narrowKey;
	const loadedKey = useRef( null );

	// A tab switch starts the new tab clean: no filters, search or sort carried over or brought back later.
	const select = ( id ) => {
		if ( id === tab.key ) {
			return; // the tab on screen: nothing to switch, keep its filters and rows
		}
		const next = tabs.find( ( t ) => t.key === id ) || tabs[ 0 ];
		setOpen( null ); // the panel belongs to the tab's rows
		setCurrent( id );
		setView( freshView( next, v.perPage ) );
		setMessage( '' );
		setLoadError( false );
		setSumsFail( false );
		picks.current.reset();
		setMenu( null );
		loadedKey.current = null;
		setData( { rows: [], total: 0 } ); // no old rows under the new columns
		setSums( null );
		window.history.replaceState( null, '', tabUrl( id ) );
	};
	// Focus that was in the chips row when its chip or a filter vanished goes to the search box.
	const chipFocus = useRef( false );
	useEffect( () => {
		if ( ! chipFocus.current ) {
			return;
		}
		chipFocus.current = false;
		window.requestAnimationFrame( () => {
			const active = document.activeElement;
			if ( ! active || active === document.body || ! active.isConnected ) {
				const box = searchSlot && searchSlot.querySelector( 'input' );
				if ( box ) {
					box.focus();
				}
			}
		} );
	} );
	const reload = () => {
		setView( freshView( tab, v.perPage ) );
		// A grant holder's counting is theirs, like the sort and the filters: the reset clears it too.
		picks.current.reset();
		setPickTick( ( n ) => n + 1 );
	};
	const sortOff = useCallback( () => setView( ( old ) => ( { ...old, sort: undefined, page: 1 } ) ), [] );
	useSortToggle( sortOff );
	const serialColumn = tab.columns.find( ( c ) => c.column === 'serial' );
	usePopupPatches( serialColumn ? serialColumn.label : null );
	useChipLabels( chipSlot, tab.columns );

	// S38: the rows open the detail panel. The button sits in the first identifying cell: Kód, else the first
	// column that is not the Sorszám (the name, when the view lists it, comes right after Kód). [UNAPPROVED N3]
	const opener = (
		tab.columns.find( ( c ) => c.type === 'code' ) ||
		tab.columns.find( ( c ) => c.type !== 'serial' ) ||
		tab.columns[ 0 ] || { column: null }
	).column;
	const closePanel = () => {
		const key = openRef.current;
		setOpen( null );
		// Focus returns to the row's button, or to the search box when that row is gone.
		window.requestAnimationFrame( () => {
			const button = host && key !== null ? host.querySelector( '.kg-open[data-key="' + window.CSS.escape( key ) + '"]' ) : null;
			const box = searchSlot && searchSlot.querySelector( 'input' );
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
		}
	};
	// After an entry is saved the grid quietly re-reads its page and sums (no loading state, scroll kept); an
	// answer for a query that is no longer the one on screen is dropped. [UNAPPROVED N21]
	const queryKey = JSON.stringify( [ tab.key, sort, desc, v.page, v.perPage, narrowKey ] );
	const queryRef = useRef( queryKey );
	queryRef.current = queryKey;
	const refresh = () => {
		const asked = queryKey;
		api.fetchPage( config, tab, { sort, desc, perPage: v.perPage, page: v.page, narrow } ).then(
			( result ) => queryRef.current === asked && setData( result ),
			() => {}
		);
		api.fetchSums( config, tab, narrow ).then(
			( result ) => queryRef.current === asked && setSums( result ),
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
		let live = true;
		api.fetchSums( config, tab, narrow, config.sumEditable ? null : counts ).then(
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

	// The default order is the sorszám order: the Sorszám header cell says so (`aria-sort`).
	useLayoutEffect( () => {
		if ( ! host ) {
			return;
		}
		const marker = host.querySelector( '.kg-hd[data-col="serial"]' );
		const target = marker ? marker.closest( 'th' ) : null;
		host.querySelectorAll( 'thead th[data-kg-default]' ).forEach( ( th ) => {
			th.removeAttribute( 'aria-sort' );
			th.removeAttribute( 'data-kg-default' );
		} );
		if ( target && ! v.sort ) {
			target.setAttribute( 'aria-sort', 'ascending' );
			target.setAttribute( 'data-kg-default', '1' );
		}
	} );

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
		// The controls above the table change height on their own (chips wrapping, the copy message), so a
		// window resize alone is not enough (G9).
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

	// One header menu is open at a time; its counting choices are portalled into it.
	useEffect( () => {
		const find = () => {
			const button = document.querySelector(
				'.kg-dv thead button.dataviews-view-table-header-button[aria-expanded="true"]'
			);
			if ( ! button ) {
				setMenu( null );
				return;
			}
			const id = openColumnId( button );
			const column = id && tab.columns.find( ( c ) => c.column === id );
			const popup = button.getAttribute( 'aria-controls' )
				? document.getElementById( button.getAttribute( 'aria-controls' ) )
				: null;
			if ( ! column || ! popup || ! ( column.members && column.members.length ) ) {
				setMenu( null );
				return;
			}
			setMenu( { column, popup } );
		};
		find();
		const observer = new window.MutationObserver( find );
		observer.observe( document.body, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'aria-expanded' ] } );
		return () => observer.disconnect();
	}, [ tab, host ] );

	// A filtered column's header is lightly shaded (CSS, `:has()` on this marker).
	const filtered = filteredIds( narrow );
	const fields = useMemo( () => {
		const own = tab.columns.map( ( column ) => ( {
			id: column.column,
			label: column.label,
			header: (
				<span className={ 'kg-hd' + ( filtered.has( column.column ) ? ' is-filtered' : '' ) } data-col={ column.column }>
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
						onClick={ () => setOpen( item.key ) }
						aria-label={ fmt( PANEL.openRow, cellText( column, item ) || item.key ) }
					>
						<Cell column={ column } item={ item } />
					</button>
				) : (
					<Cell column={ column } item={ item } />
				),
			enableSorting: column.sortable,
			enableHiding: false,
			...filterSpec( column ),
		} ) );
		const none = unansweredField( tab.columns );
		return none
			? [ ...own, { ...none, header: <span className={ 'kg-hd' + ( filtered.has( none.id ) ? ' is-filtered' : '' ) } data-col={ none.id }>{ none.label }</span> } ]
			: own;
	}, [ tab, narrowKey, opener ] );

	// The sum row's arrow opens the column's own header menu, where the counting choices are (G10).
	const openMenu = ( column ) => {
		if ( ! host ) {
			return;
		}
		const marker = host.querySelector( '.kg-hd[data-col="' + column.column + '"]' );
		const button = marker && marker.closest( 'th' )?.querySelector( 'button.dataviews-view-table-header-button' );
		if ( button ) {
			button.click();
		}
	};

	const [ busy, setBusy ] = useState( false );
	const timer = useRef();
	const copy = async () => {
		if ( busy ) {
			return;
		}
		setMessage( '' );
		setBusy( true );
		try {
			// The text now typed counts even while DataViews' debounce has not passed it on: the copy is the set
			// the grid is about to show.
			const box = searchSlot && searchSlot.querySelector( 'input' );
			const now = narrowOf( v, tab.columns, box ? box.value : null );
			const rows = await api.fetchAll( config, tab, sort, desc, now );
			await writeClipboard( plainText( tab.columns, rows ), tableHtml( tab.columns, rows ) );
			setMessageKey( tab.key + JSON.stringify( now ) );
			setMessage( fmt( GRID.copied, rows.length ) );
		} catch {
			setMessageKey( tab.key + narrowKey );
			setMessage( GRID.copyFail );
		}
		setBusy( false );
		clearTimeout( timer.current );
		timer.current = setTimeout( () => setMessage( '' ), 6000 );
	};

	// The counted members are saved at once, one save at a time in click order; a failure reloads the stored
	// state. A failed reload leaves the row as it is, and the next pick still runs. A save or reload that ends
	// after a tab switch leaves the new tab's row alone. A grant holder's pick is their own: it is kept in the
	// browser and sent with the sums request, never saved to the shared view (topic 6a G1).
	const queue = useRef( null );
	if ( ! queue.current ) {
		queue.current = serial();
	}
	const seq = useRef( 0 );
	const shown = useRef( tab.key );
	shown.current = tab.key;
	// The members the menu starts from: the server's sum row for full access, the stored choice otherwise.
	const chosenFor = ( column ) =>
		config.sumEditable
			? ( sums && sums[ column.column ] && sums[ column.column ].members ) || []
			: ( tab.counts || {} )[ column.column ] || [];

	// One tick. The pick is kept locally first, so a second tick before the first save returns builds on it
	// and the checkbox reflects the click at once; the last state wins and includes every tick.
	const pick = ( column, member, on ) => {
		const picked = tab;
		const members = picks.current.toggle( column.column, member, on, chosenFor( column ) );
		setPickTick( ( n ) => n + 1 );
		if ( ! config.sumEditable ) {
			return;
		}
		queue.current( () => {
			const mine = ++seq.current;
			const asked = keyRef.current;
			return runPick( {
				save: () => api.saveCounts( config, picked, column.column, members, narrowRef.current ),
				reload: () => api.fetchSums( config, picked, narrowRef.current ),
				apply: setSums,
				// A failed save reloads the stored sums; the menu then shows those, not the unsaved pick.
				revert: () => {
					picks.current.revert( column.column );
					setPickTick( ( n ) => n + 1 );
				},
				isShown: () => shown.current === picked.key,
				isLatest: () => mine === seq.current,
				isFresh: () => keyRef.current === asked,
			} );
		} );
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

	// A grant holder's counting is their own too: the reset is enabled while only it differs from the view.
	const countingDiffers =
		! config.sumEditable && countsDiffer( tab.columns, tab.counts || {}, picks.current.all() );
	const isDefault = ! v.sort && ! ( v.filters || [] ).length && ! v.search && ! countingDiffers;
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
	// The view's own condition is a locked, unremovable chip in the filter area (owner's walk, topic 6a G3).
	const conditionText = tab.condition
		? ( tab.condition.terms > 1 ? VIEWS.filters : VIEWS.filter ) + ': ' + tab.condition.text
		: null;

	return (
		<Fragment>
			<div className="kg-above">
				<div className="kg-tabrow">
					<Tabs tabs={ tabs } current={ tab.key } onSelect={ select } />
				</div>
				<div className="kg-bulkbar" role="toolbar" aria-label={ GRID.toolbar }>
					<div className="kg-searchslot" ref={ setSearchSlot } />
					<span className="kg-sep" aria-hidden="true" />
					<div className="kg-filterslot">
						{ conditionText && (
							<span className="kg-lockchip" title={ GRID.lockedFilter }>
								<span className="dashicons dashicons-lock" aria-hidden="true" />
								<span className="screen-reader-text">{ GRID.lockedFilter } </span>
								{ conditionText }
							</span>
						) }
						<div className="kg-chips" ref={ setChipSlot } />
						{ ! ( v.filters || [] ).length && (
							<AddFilterFallback
								columns={ tab.columns }
								onAdd={ ( filter ) =>
									setView( {
										...v,
										page: 1,
										filters: [ ...( v.filters || [] ), filter ],
									} )
								}
							/>
						) }
					</div>
					<Button
						variant="secondary"
						size="compact"
						icon={ update }
						label={ GRID.reload }
						onClick={ reload }
						disabled={ isDefault }
						accessibleWhenDisabled
					/>
					<span className="kg-spacer" />
					<div className="kg-bulkbar-actions">
						{ narrowed ? <Tooltip text={ GRID.followsFilter }>{ copyButton }</Tooltip> : copyButton }
					</div>
					<span className="kg-copy-msg" role="status">
						{ messageKey === tab.key + narrowKey ? message : '' }
					</span>
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
				onOpenMenu={ openMenu }
				narrowed={ narrowed }
			/>
			<div ref={ setHost } className="kg-dv" onClick={ onHostClick }>
				<DataViews
					key={ tab.key }
					data={ data.rows }
					fields={ fields }
					view={ v }
					onChangeView={ ( next ) => {
						// A late call from the previous tab's DataViews (a pending search) is dropped.
						if ( next._tab !== undefined && next._tab !== shown.current ) {
							return;
						}
						chipFocus.current = !! ( chipSlot && chipSlot.contains( document.activeElement ) );
						setView( { ...next, _tab: shown.current } );
					} }
					isLoading={ loading }
					paginationInfo={ {
						totalItems: data.total,
						totalPages: Math.max( 1, Math.ceil( data.total / v.perPage ) ),
					} }
					defaultLayouts={ { table: {} } }
					getItemId={ ( item ) => item.key }
					actions={ [] }
					search={ false }
					config={ { perPageSizes: config.perPages } }
					empty={ empty }
				>
					{ searchSlot && createPortal( <div className="kg-search"><DataViews.Search /></div>, searchSlot ) }
					{ chipSlot && createPortal( <DataViews.Filters />, chipSlot ) }
					<DataViews.Layout />
					<div className="kg-dv-foot">
						{ perPage }
						<DataViews.Pagination />
					</div>
				</DataViews>
			</div>
			{ menu &&
				createPortal(
					<CountingMenu
						column={ menu.column }
						chosen={ picks.current.chosen( menu.column.column, chosenFor( menu.column ) ) }
						onToggle={ pick }
						narrowed={ config.sumEditable && narrowed }
					/>,
					menu.popup
				) }
			{ open !== null && (
				<DetailPanel config={ config } code={ open } onClose={ closePanel } onSaved={ () => refreshRef.current() } />
			) }
		</Fragment>
	);
}
