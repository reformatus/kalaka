/**
 * The registration detail panel (S38): a non-modal side panel over the grid, with the identifying header and
 * the tabs. It reads `GET kalaka-admin/v1/events/{event}/registrations/{code}`, which already cuts every part to
 * the user's views and grants: a header field the views do not list is absent and leaves no gap, and the money
 * tab shows only with `can.view_money`.
 * N4 reboxed: the header is the name, then code and e-mail, then the status row and the confirmation row with a
 * slot each, then the tabs (N1200). N12 reboxed: every tab always shown and the last-clicked one remembered
 * (N1201; written on a click only, see `tabClicks` and [UNAPPROVED N1264]). T3 struck: the disabled „Hamarosan…”
 * tab is gone.
 *
 * S20: the confirmation state is its own line, the confirmation row, under the status row, and under it the
 * manual confirmation (who set the flag, when and why, and the button(s)); `ManualConfirmation` renders the row.
 * N30 reboxed: the state is no longer shown next to the status. When the re-read after a save fails twice, the
 * state and what hangs under it are not shown (the old ones would look current); the load-error message says so.
 * On a „Lemondott” or „Törölt” registration it is not shown either (owner 2026-11-27). [UNAPPROVED N30]
 */
import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { Button, TabPanel } from '@wordpress/components';
import * as api from './api';
import { unconfirmBlocked, warningOf } from './confirmation';
import FieldsBlock from './FieldsBlock';
import ManualConfirmation from './ManualConfirmation';
import MoneyTab from './MoneyTab';
import { awaitingCount, hasRequests, offeredOf, RequestsList, RequestsTabTitle, useAnswering } from './RequestsBlock';
import StatusActs from './StatusActs';
import { tabClicks } from './panel';
import { PANEL } from './texts';

/**
 * @param {Object}   props
 * @param {Object}   props.config  window.kalakaGrid
 * @param {string}   props.code    the registration on screen (the grid row's key)
 * @param {number}   props.again   changes when the open row is activated again: a failed read is tried again
 * @param {Function} props.onClose closes the panel (the grid returns focus to the row)
 * @param {Function} props.onSaved tells the grid an entry was saved, so it re-reads its rows and sums
 */
export default function DetailPanel( { config, code, again, onClose, onSaved } ) {
	const [ detail, setDetail ] = useState( null );
	const [ problem, setProblem ] = useState( null ); // 'missing' (404) or 'failed'
	const root = useRef();
	const closeRef = useRef();
	const shown = useRef( code );
	shown.current = code;

	// Opening, or switching to another row, reads the registration and moves focus to the panel, so a screen
	// reader reads it. A late answer for a row that is no longer shown is dropped.
	useEffect( () => {
		let live = true;
		setDetail( null );
		setProblem( null );
		api.fetchDetail( config, code ).then(
			( result ) => live && setDetail( result ),
			( error ) => live && setProblem( api.detailProblem( error ) )
		);
		if ( root.current ) {
			root.current.focus();
		}
		return () => {
			live = false;
		};
	}, [ config, code ] );

	// After a save: the panel stays on the registration and shows what the server now holds. It never rejects:
	// the entry is saved, and a failed re-read only says so.
	// It resolves with the detail it read, or null when both reads failed.
	const reload = useCallback( async () => {
		const asked = code;
		// One more try before it says so.
		for ( let attempt = 0; attempt < 2; attempt++ ) {
			try {
				const result = await api.fetchDetail( config, asked );
				if ( shown.current === asked ) {
					setDetail( result );
					setProblem( null );
				}
				return result;
			} catch {
				// Tried again below.
			}
		}
		if ( shown.current === asked ) {
			setProblem( 'failed' );
		}
		return null;
	}, [ config, code ] );

	useEffect( () => {
		if ( problem ) {
			reload();
		}
		// Only a new activation of the row tries again.
	}, [ again ] );

	// The tabs are „Adatok”, „Kérelmek” and „Pénzügyek”, in that order (N1004). Every tab is always shown, an empty
	// one with its own empty text, and the last-clicked tab is kept for the browser session, so it stays open when
	// another registration is selected; a remembered tab the viewer lacks falls back to the first. A tab the viewer
	// has no right to is left out: the read carries no part for it (no money read, no requests, no field list).
	// After a failed re-read the data is stale: the tab list and the chosen tab stay (built from the last good
	// read, so the panel does not jump to another tab), the tabs show no values (the field list, the requests and
	// the money show nothing but their snackbars) and the „Kérelmek” badge is gone (its count is 0), so under the
	// load-failure message nothing stale remains. [UNAPPROVED N1036] [UNAPPROVED N1037]
	// The „Kérelmek” tab carries a count badge of the requests that await an answer (owner 2026-11-27; there is no
	// strip above the tabs). [UNAPPROVED N1316]
	// The tab panel shows only the chosen tab, so a tab's snackbar (a saved field, a money act) is gone when another
	// tab is chosen within its five seconds; the answer's snackbar is not, it lives here. [UNAPPROVED N1040]
	const stale = !! problem;
	const hasMoney = !! ( detail && detail.can && detail.can.view_money && detail.money );
	const offered = offeredOf( detail, stale );
	const canAnswer = !! ( detail && detail.can && detail.can.answer_request );
	const tabs = [];
	if ( detail && Array.isArray( detail.fields ) ) {
		tabs.push( { name: 'fields', title: PANEL.tabFields } );
	}
	if ( hasRequests( detail ) ) {
		tabs.push( { name: 'requests', title: <RequestsTabTitle count={ awaitingCount( offered ) } /> } );
	}
	if ( hasMoney ) {
		tabs.push( { name: 'money', title: PANEL.tabMoney } );
	}
	const tabNames = tabs.map( ( tab ) => tab.name ).join( ',' );
	// One memory of the clicks per registration and tab set: a new row opens on the remembered tab, and only a
	// click writes it (N1264).
	const tabMemory = useMemo( () => tabClicks( tabNames ? tabNames.split( ',' ) : [] ), [ tabNames, code ] );
	// The tab list's accessible name: wp-components' TabPanel has no prop for it.
	useEffect( () => {
		const list = root.current && root.current.querySelector( '[role="tablist"]' );
		if ( list ) {
			list.setAttribute( 'aria-label', PANEL.tabsLabel );
		}
	}, [ tabNames ] );
	const answering = useAnswering( config, code, reload, onSaved, closeRef, detail && detail.requests ? detail.requests.currency : undefined );

	const registration = ( detail && detail.registration ) || {};
	return (
		<aside
			className="kg-panel"
			role="dialog"
			aria-modal="false"
			aria-label={ PANEL.panel }
			tabIndex={ -1 }
			ref={ root }
		>
			<div className="kg-panel-top">
				{ detail && (
					<div className="kg-panel-id">
						{ registration.name && <h2 className="kg-panel-name">{ registration.name }</h2> }
						{ ( registration.code || registration.email ) && (
							<p className="kg-panel-meta">
								{ registration.code && <span className="kg-code">{ registration.code }</span> }
								{ registration.email && <span className="kg-panel-email">{ registration.email }</span> }
							</p>
						) }
						{ /* The two state rows: the status row's slot holds the status acts, the confirmation row's the manual confirmation's buttons. */ }
						<StatusActs
							stale={ !! problem }
							config={ config }
							code={ code }
							can={ detail.can || {} }
							money={ detail.money }
							status={ registration.status }
							text={ registration.status_label }
							reload={ reload }
							onSaved={ onSaved }
							closeRef={ closeRef }
						/>
						{ detail && (
							<ManualConfirmation
								stale={ !! problem }
								config={ config }
								code={ code }
								can={ detail.can || {} }
								manual={ registration.manual_confirmation }
								status={ registration.status }
								warning={ warningOf( registration, detail.can ) }
								blocked={ unconfirmBlocked( registration ) }
								confirmed={ registration.confirmed }
								reload={ reload }
								onSaved={ onSaved }
								closeRef={ closeRef }
							/>
						) }
					</div>
				) }
				<Button
					icon="no-alt"
					label={ PANEL.close }
					onClick={ onClose }
					className="kg-panel-close"
					ref={ closeRef }
				/>
			</div>
			{ problem && (
				<p className="kg-load-fail" role="alert">
					{ { missing: PANEL.notFound, noAccess: PANEL.noAccess }[ problem ] || PANEL.loadFail }
				</p>
			) }
			{ ! detail && ! problem && (
				<p className="kg-none" role="status">
					{ PANEL.loading }
				</p>
			) }
			{ tabs.length > 0 && (
				<TabPanel
					key={ tabNames }
					className="kg-tabs-money"
					tabs={ tabs }
					initialTabName={ tabMemory.initial }
					onSelect={ tabMemory.onSelect }
					orientation="horizontal"
				>
					{ ( tab ) => {
						if ( tab.name === 'fields' ) {
							return (
								<FieldsBlock
									config={ config }
									code={ code }
									detail={ detail }
									stale={ stale }
									reload={ reload }
									onSaved={ onSaved }
								/>
							);
						}
						if ( tab.name === 'requests' ) {
							if ( stale ) {
								return null;
							}
							return (
								<RequestsList
									items={ offered }
									currency={ detail.requests.currency }
									start={ answering.start }
									can={ canAnswer }
								/>
							);
						}
						return (
							<MoneyTab
								config={ config }
								code={ code }
								money={ detail.money }
								stale={ stale }
								can={ detail.can }
								reload={ reload }
								onSaved={ onSaved }
								closeRef={ closeRef }
							/>
						);
					} }
				</TabPanel>
			) }
			{ detail && answering.dialog }
		</aside>
	);
}
