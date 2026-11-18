/**
 * The „Jelentkezők” page (S37): the view tabs with „Nézetek kezelése”, the „Műveletek” toolbar with
 * „Táblázat másolása”, and DataViews (compact) with the sum row in its header. Sorszám and Kód are not
 * sort toggles: their header returns to the default order (the sorszám order, then the code).
 */
import {
	Fragment,
	useEffect,
	useLayoutEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { Button, Modal, SelectControl } from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews/wp';
import { cellText, fmt, plainText, tableHtml } from './format';
import { writeClipboard } from './clipboard';
import * as api from './api';
import Tabs, { tabUrl } from './Tabs';
import SumRow from './SumRow';
import ViewsDialog from './ViewsDialog';
import { GRID, VIEWS } from './texts';

const STATUS_ICON = {
	registered: [ 'dashicons-yes-alt', 'kg-ok' ],
	waitlisted: [ 'dashicons-clock', 'kg-warn' ],
	withdrawn: [ 'dashicons-undo', 'kg-off' ],
	removed: [ 'dashicons-dismiss', 'kg-off' ],
};
const DECISION_ICON = { 'decision:confirmation': [ 'dashicons-yes', 'kg-ok' ] };

/** A cell as shown: the status with its icon, a decision as an icon with screen-reader text, the code in monospace. */
function Cell( { column, item } ) {
	const text = cellText( column, item );
	if ( text === '' ) {
		return '';
	}
	if ( column.type === 'status' ) {
		const [ icon, tone ] = STATUS_ICON[ item.values.status ] || [ '', '' ];
		return (
			<span className="kg-mark">
				<span className={ `dashicons ${ icon } ${ tone }` } aria-hidden="true" />
				{ text }
			</span>
		);
	}
	if ( column.type === 'decision' ) {
		const [ icon, tone ] = DECISION_ICON[ column.column ] || [ 'dashicons-marker', '' ];
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
	_tab: tab.view,
} );

export default function Page( { config } ) {
	const [ tabs, setTabs ] = useState( config.tabs );
	const [ current, setCurrent ] = useState( config.current );
	const tab = tabs.find( ( t ) => t.view === current ) || tabs[ 0 ];
	const [ view, setView ] = useState( () => freshView( tab, 50 ) );
	const [ data, setData ] = useState( { rows: [], total: 0 } );
	const [ sums, setSums ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ dialog, setDialog ] = useState( false );
	const [ views, setViews ] = useState( null );
	const [ viewsError, setViewsError ] = useState( '' );
	const [ message, setMessage ] = useState( '' );
	const [ host, setHost ] = useState( null );
	const v = view._tab === tab.view ? view : freshView( tab, view.perPage );
	const sort = v.sort ? v.sort.field : null;
	const desc = v.sort ? v.sort.direction === 'desc' : false;

	const select = ( id ) => {
		setCurrent( id );
		setMessage( '' );
		setData( { rows: [], total: 0 } ); // no old rows under the new columns
		setSums( null );
		window.history.replaceState( null, '', tabUrl( id ) );
	};

	useEffect( () => {
		let live = true;
		setLoading( true );
		api.fetchPage( config, tab.view, { sort, desc, perPage: v.perPage, page: v.page } ).then(
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
	}, [ tab.view, sort, desc, v.page, v.perPage ] );

	useEffect( () => {
		let live = true;
		api.fetchSums( config, tab.view ).then(
			( result ) => live && setSums( result ),
			() => {}
		);
		return () => {
			live = false;
		};
	}, [ tab.view ] );

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
					) : undefined,
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

	const [ busy, setBusy ] = useState( false );
	const timer = useRef();
	const copy = async () => {
		if ( busy ) {
			return;
		}
		setMessage( '' );
		setBusy( true );
		try {
			const rows = await api.fetchAll( config, tab.view, sort, desc );
			await writeClipboard( plainText( tab.columns, rows ), tableHtml( tab.columns, rows ) );
			setMessage( fmt( GRID.copied, rows.length ) );
		} catch {
			setMessage( GRID.copyFail );
		}
		setBusy( false );
		clearTimeout( timer.current );
		timer.current = setTimeout( () => setMessage( '' ), 6000 );
	};

	// The counted members are saved at once, one save at a time in click order; a failure reloads the stored state.
	const queue = useRef( Promise.resolve() );
	const seq = useRef( 0 );
	const pick = ( column, members ) => {
		queue.current = queue.current.then( async () => {
			const mine = ++seq.current;
			try {
				const result = await api.saveCounts( config, tab.view, column.column, members );
				if ( mine === seq.current ) {
					setSums( result );
				}
			} catch {
				setSums( await api.fetchSums( config, tab.view ) );
			}
		} );
	};

	const loadViews = () =>
		api.fetchViews( config ).then( ( result ) => {
			setViews( result );
			if ( result.full ) {
				setTabs( [ tabs[ 0 ] ].concat( result.views.map( ( x ) => ( { view: x.view, name: x.name, columns: x.tab } ) ) ) );
			}
			return result;
		} );
	const openDialog = () => {
		setViewsError( '' );
		setDialog( true );
		loadViews();
	};
	const change = async ( what, target, name, keepParam ) => {
		setViewsError( '' );
		try {
			if ( what === 'delete' ) {
				await api.deleteView( config, target.view, target.version );
				if ( target.view === current ) {
					select( 0 );
				}
			} else if ( what === 'add' ) {
				await api.saveView( config, 0, {
					name: VIEWS.newName,
					filter: [],
					columns: [ 'serial', 'code', 'status' ].map( ( column ) => ( { column, write: false } ) ),
				} );
			} else {
				await api.saveView( config, target.view, {
					version: target.version,
					name,
					filter: keepParam ? target.shape.filter : target.shape.filter.filter( ( t ) => ! t.parameter ),
					columns: target.shape.columns,
					counts: target.shape.counts,
				} );
			}
			await loadViews();
			if ( what === 'save' && target.view === current ) {
				setSums( await api.fetchSums( config, current ) );
				setView( { ...v, page: 1 } );
			}
			return true;
		} catch ( e ) {
			setViewsError( e && e.message ? e.message : '' );
			await loadViews();
			return false;
		}
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
					<Tabs tabs={ tabs } current={ tab.view } onSelect={ select } />
					<Button variant="secondary" size="compact" className="kg-manage" onClick={ openDialog }>
						{ GRID.manage }
					</Button>
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
				editable={ config.sumEditable }
				onPick={ pick }
			/>
			<div ref={ setHost } className="kg-dv">
				<DataViews
					data={ data.rows }
					fields={ fields }
					view={ v }
					onChangeView={ ( next ) => setView( { ...next, _tab: tab.view } ) }
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
			{ dialog && (
				<Modal
					title={ VIEWS.dialog }
					onRequestClose={ () => setDialog( false ) }
					size="medium"
					className="kg-views-modal"
				>
					{ views && (
						<ViewsDialog
							data={ views }
							error={ viewsError }
							onChange={ change }
							onOpen={ ( id ) => {
								if ( tabs.some( ( t ) => t.view === id ) ) {
									setDialog( false );
									select( id );
								}
							} }
						/>
					) }
				</Modal>
			) }
		</Fragment>
	);
}
