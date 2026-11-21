/**
 * The „Jelentkezők” page (S37): the view tabs, the „Műveletek” toolbar with „Táblázat másolása”, and
 * DataViews (compact) with the sum row in its header. Kód carries the default order (the order of the
 * applications) and is sortable both ways; the default view state is Kód ascending, so DataViews' own header
 * shows it. The title and the controls stay at the top and the table fills the window (owner's walk, topic 6a
 * G9). Every sum cell's arrow opens its column's menu, with the counting choices where the column counts (G10);
 * the counting is the viewer's own for the session, full access included, and is never saved (B1, round 3).
 */
import { Fragment, useEffect, useLayoutEffect, useMemo, useRef, useState } from '@wordpress/element';
import { Button, SelectControl, SnackbarList } from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews/wp';
import { cellText, fmt, plainText, tableHtml } from './format';
import Cell from './Cell';
import { writeClipboard } from './clipboard';
import * as api from './api';
import { countsToSend, createPicks } from './counting';
import Tabs, { tabUrl } from './Tabs';
import SumRow from './SumRow';
import ColumnMenu from './ColumnMenu';
import { SNACKBAR_MS, withNotice, withoutNotice } from './snack';
import { GRID, SUM } from './texts';

/**
 * The default order: Kód ascending, when the view lists Kód (the ordering time, then the code); a view without
 * it has no sort at all, and only clearing the other sorts gives it the default order. [UNAPPROVED N49]
 */
const defaultSort = ( tab ) =>
	tab.columns.some( ( c ) => c.column === 'code' ) ? { field: 'code', direction: 'asc' } : undefined;

const freshView = ( tab, perPage ) => ( {
	type: 'table',
	page: 1,
	perPage,
	search: '',
	filters: [],
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
	// Never saved; reset on a tab switch, like the sort and the filters (topic 6a G1, B1). [UNAPPROVED N50]
	const picks = useRef( null );
	if ( ! picks.current ) {
		picks.current = createPicks();
	}
	const [ , setPickTick ] = useState( 0 );
	const [ sumsFail, setSumsFail ] = useState( false );
	const v = view._tab === tab.key ? view : freshView( tab, view.perPage );
	const sort = v.sort ? v.sort.field : null;
	const desc = v.sort ? v.sort.direction === 'desc' : false;
	// The counted members the viewer picked: the stored choice with the picks on top, for the view's listed
	// columns only. Nothing is sent before a pick, so the server's stored choice applies.
	const counts = Object.keys( picks.current.all() ).length
		? countsToSend( tab.columns, tab.counts, picks.current.all() )
		: null;
	const countsKey = JSON.stringify( counts || {} );

	const select = ( id ) => {
		setCurrent( id );
		setNotices( [] );
		setData( { rows: [], total: 0 } ); // no old rows under the new columns
		setSums( null );
		setSumsFail( false );
		picks.current.reset();
		window.history.replaceState( null, '', tabUrl( id ) );
	};

	useEffect( () => {
		let live = true;
		setLoading( true );
		api.fetchPage( config, tab, { sort, desc, perPage: v.perPage, page: v.page } ).then(
			( result ) => {
				if ( live ) {
					setData( result );
					setLoading( false );
				}
			},
			() => live && setLoading( false )
		);
		return () => {
			live = false;
		};
	}, [ tab.key, sort, desc, v.page, v.perPage ] );

	useEffect( () => {
		let live = true;
		api.fetchSums( config, tab, counts ).then(
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
	}, [ tab.key, countsKey ] );

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

	const fields = useMemo(
		() =>
			tab.columns.map( ( column ) => ( {
				id: column.column,
				label: column.label,
				type: column.numeric ? 'number' : 'text',
				getValue: ( { item } ) => cellText( column, item ),
				render: ( { item } ) => <Cell column={ column } item={ item } />,
				enableSorting: column.sortable,
				enableHiding: false,
				filterBy: false,
			} ) ),
		[ tab ]
	);

	// The copy result is a snackbar, not text beside the button: a success goes away by itself, an error stays
	// until it is dismissed. [UNAPPROVED N51]
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

	const [ busy, setBusy ] = useState( false );
	const copy = async () => {
		if ( busy ) {
			return;
		}
		setBusy( true );
		try {
			const rows = await api.fetchAll( config, tab, sort, desc );
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

	const menuFor = ( column ) => (
		<ColumnMenu
			column={ column }
			view={ v }
			onSort={ ( sorted ) => setView( { ...v, sort: sorted, page: 1 } ) }
			chosen={ picks.current.chosen( column.column, chosenFor( column ) ) }
			onToggle={ pick }
		/>
	);

	return (
		<Fragment>
			<div className="kg-above">
				<div className="kg-tabrow">
					<Tabs tabs={ tabs } current={ tab.key } onSelect={ select } />
				</div>
				<div className="kg-bulkbar">
					<div className="kg-bulkbar-actions" role="toolbar" aria-label={ GRID.toolbar }>
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
					</div>
				</div>
			</div>
			{ sumsFail && (
				<p className="kg-load-fail" role="alert">
					{ SUM.loadFail }
				</p>
			) }
			<SumRow root={ host } columns={ tab.columns } order={ v.fields } sums={ sums } menuFor={ menuFor } />
			<div ref={ setHost } className="kg-dv">
				<DataViews
					data={ data.rows }
					fields={ fields }
					view={ v }
					onChangeView={ ( next ) => setView( { ...next, _tab: tab.key } ) }
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
				>
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
		</Fragment>
	);
}
