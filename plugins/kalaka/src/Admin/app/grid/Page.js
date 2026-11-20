/**
 * The „Jelentkezők” page (S37): the view tabs, the „Műveletek” toolbar with „Táblázat másolása”, and
 * DataViews (compact) with the sum row in its header. Sorszám and Kód are not sort toggles: their header
 * returns to the default order (the sorszám order, then the code). The title and the controls stay at the
 * top and the table fills the window (owner's walk, topic 6a G9). The counting choices live in each
 * countable column's own header menu, reached from its sum cell (G10); a grant holder's choice is their own
 * and never changes the shared view (G1).
 */
import {
	createPortal,
	Fragment,
	useEffect,
	useLayoutEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { Button, CheckboxControl, SelectControl } from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews/wp';
import { cellText, fmt, plainText, tableHtml } from './format';
import { writeClipboard } from './clipboard';
import * as api from './api';
import { serial } from './serial';
import { runPick } from './pick';
import Tabs, { tabUrl } from './Tabs';
import SumRow from './SumRow';
import { GRID, SUM } from './texts';

const STATUS_ICON = {
	registered: [ 'dashicons-yes-alt', 'kg-ok' ],
	waitlisted: [ 'dashicons-clock', 'kg-warn' ],
	withdrawn: [ 'dashicons-undo', 'kg-off' ],
	removed: [ 'dashicons-dismiss', 'kg-off' ],
};

/** A cell as shown: the status with its icon, a decision as an icon with screen-reader text, the code in monospace. */
function Cell( { column, item } ) {
	const text = cellText( column, item );
	if ( text === '' ) {
		return '';
	}
	if ( column.type === 'status' ) {
		const [ icon, tone ] = STATUS_ICON[ item.values.status ] || [ '', '' ];
		// An inactive registration (anything but registered) dims its whole row. [UNAPPROVED N22]
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

function ResetHeader( { column, hasSerial, isDefault, onReset } ) {
	const id = 'kg-reset-desc-' + column.column;
	// A view without Sorszám still runs in sorszám order, but the text does not name a column the viewer cannot see.
	const description = hasSerial ? GRID.resetSort : GRID.resetSortShort;
	return (
		<Fragment>
			<button
				type="button"
				className={ 'kg-reset-sort' + ( isDefault ? ' is-default' : '' ) }
				data-col={ column.column }
				title={ description }
				aria-disabled={ isDefault ? 'true' : undefined }
				aria-describedby={ id }
				onClick={ () => ! isDefault && onReset() }
			>
				{ column.label }
				{ isDefault && column.column === 'serial' && (
					<span aria-hidden="true" className="kg-reset-arrow">
						{ ' ↑' }
					</span>
				) }
			</button>
			<span id={ id } className="screen-reader-text">
				{ description }
			</span>
		</Fragment>
	);
}

/**
 * The counting choices as they appear in a column's own header menu: one checkbox per member of the column.
 * Clicks stay in the fieldset, so the menu's own handlers do not take them.
 */
function CountingMenu( { column, chosen, onPick } ) {
	return (
		<div
			className="kg-countbox"
			onPointerDown={ ( event ) => event.stopPropagation() }
			onClick={ ( event ) => event.stopPropagation() }
		>
			<p className="kg-countbox-title">{ SUM.counting }</p>
			{ column.members.map( ( member ) => (
				<CheckboxControl
					key={ member.value }
					label={ member.label }
					checked={ chosen.includes( member.value ) }
					__nextHasNoMarginBottom
					onChange={ ( on ) =>
						onPick(
							column,
							on
								? chosen.concat( member.value )
								: chosen.filter( ( x ) => x !== member.value )
						)
					}
				/>
			) ) }
		</div>
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
	const [ host, setHost ] = useState( null );
	const [ menu, setMenu ] = useState( null );
	// A grant holder's own counted members per column; null: the stored choice. Reset on a tab switch,
	// like the sort and the filters.
	const [ localCounts, setLocalCounts ] = useState( null );
	const v = view._tab === tab.key ? view : freshView( tab, view.perPage );
	const sort = v.sort ? v.sort.field : null;
	const desc = v.sort ? v.sort.direction === 'desc' : false;
	const counts = localCounts || tab.counts || null;
	const countsKey = JSON.stringify( counts || {} );

	const select = ( id ) => {
		setCurrent( id );
		setMessage( '' );
		setData( { rows: [], total: 0 } ); // no old rows under the new columns
		setSums( null );
		setLocalCounts( null );
		setMenu( null );
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
		api.fetchSums( config, tab, config.sumEditable ? null : counts ).then(
			( result ) => live && setSums( result ),
			() => {}
		);
		return () => {
			live = false;
		};
	}, [ tab.key, countsKey ] );

	// The default order is the sorszám order: the Sorszám header cell says so (`aria-sort`).
	useLayoutEffect( () => {
		if ( ! host ) {
			return;
		}
		const marker = host.querySelector( '.kg-reset-sort[data-col="serial"]' );
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
		window.addEventListener( 'resize', fit );
		const timer = window.setTimeout( fit, 200 );
		return () => {
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

	const hasSerial = tab.columns.some( ( c ) => c.column === 'serial' );
	const fields = useMemo(
		() =>
			tab.columns.map( ( column ) => {
				const reset = column.column === 'serial' || column.column === 'code';
				return {
					id: column.column,
					label: column.label,
					header: reset ? (
						<ResetHeader
							column={ column }
							hasSerial={ hasSerial }
							isDefault={ ! v.sort }
							onReset={ () => setView( { ...v, sort: undefined, page: 1 } ) }
						/>
					) : (
						<span className="kg-hd" data-col={ column.column }>
							{ column.label }
						</span>
					),
					type: column.numeric ? 'number' : 'text',
					getValue: ( { item } ) => cellText( column, item ),
					render: ( { item } ) => <Cell column={ column } item={ item } />,
					enableSorting: column.sortable,
					enableHiding: false,
					filterBy: false,
				};
			} ),
		[ tab, v, hasSerial ]
	);

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
			const rows = await api.fetchAll( config, tab, sort, desc );
			await writeClipboard( plainText( tab.columns, rows ), tableHtml( tab.columns, rows ) );
			setMessage( fmt( GRID.copied, rows.length ) );
		} catch {
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
	const pick = ( column, members ) => {
		const picked = tab;
		if ( ! config.sumEditable ) {
			const base = { ...( localCounts || tab.counts || {} ) };
			if ( members.length ) {
				base[ column.column ] = members;
			} else {
				delete base[ column.column ];
			}
			setLocalCounts( base );
			return;
		}
		queue.current( () => {
			const mine = ++seq.current;
			return runPick( {
				save: () => api.saveCounts( config, picked, column.column, members ),
				reload: () => api.fetchSums( config, picked ),
				apply: setSums,
				isShown: () => shown.current === picked.key,
				isLatest: () => mine === seq.current,
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
					<span className="kg-copy-msg" role="status">
						{ message }
					</span>
				</div>
			</div>
			<SumRow
				root={ host }
				columns={ tab.columns }
				order={ v.fields }
				sums={ sums }
				onOpenMenu={ openMenu }
			/>
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
			{ menu &&
				createPortal(
					<CountingMenu
						column={ menu.column }
						chosen={
							config.sumEditable
								? ( sums && sums[ menu.column.column ] && sums[ menu.column.column ].members ) || []
								: ( localCounts || tab.counts || {} )[ menu.column.column ] || []
						}
						onPick={ pick }
					/>,
					menu.popup
				) }
		</Fragment>
	);
}
