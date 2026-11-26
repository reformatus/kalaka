/**
 * The registration detail panel (S38): a non-modal side panel over the grid, with the identifying header and
 * the tabs. It reads `GET kalaka-admin/v1/events/{event}/registrations/{code}`, which already cuts every part to
 * the user's views and grants: a header field the views do not list is absent and leaves no gap, and the money
 * tab shows only with `can.view_money`. [UNAPPROVED N2] [UNAPPROVED N4] [UNAPPROVED N12]
 *
 * S20: next to the status, the confirmation decision's state, only when the read has it, and under the header
 * the manual confirmation block. When the re-read after a save fails twice, the state and the block are not
 * shown (the old ones would look current); the load-error message says so. [UNAPPROVED N30] [UNAPPROVED N37]
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Button, TabPanel } from '@wordpress/components';
import * as api from './api';
import { confirmationOf, markOf, warningOf } from './confirmation';
import FieldsBlock from './FieldsBlock';
import ManualConfirmation from './ManualConfirmation';
import MoneyTab from './MoneyTab';
import { AwaitingStrip, offeredOf, RequestsList, useAnswering } from './RequestsBlock';
import StatusActs from './StatusActs';
import StatusMark from './StatusMark';
import { fieldsOf } from './fieldEdit';
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

	// The tabs: „Adatok”, „Kérelmek” and „Pénzügyek”, each only when the read has something for it, so a holder
	// sees only the tabs they may use; „Hamarosan…” stays last beside „Pénzügyek” as before. The default is the
	// first tab. [UNAPPROVED N1004] [UNAPPROVED N1005] [UNAPPROVED N1006]
	// The tab list's accessible name: wp-components' TabPanel has no prop for it.
	// After a failed re-read the data is stale: the tab list and the chosen tab stay, the tabs show no values
	// („Adatok”, „Kérelmek” and „Pénzügyek” show nothing but their snackbars) and the strip is gone, so under the
	// load-failure message nothing stale remains. The tab list is built from the last good read, so the tabs do not
	// change and the panel does not jump to another tab. [UNAPPROVED N24] [UNAPPROVED N1036] [UNAPPROVED N1037]
	// A tab list with one tab is still shown. [UNAPPROVED N1041]
	// The tab panel shows only the chosen tab, so a tab's snackbar (a saved field, a money act) is gone when another
	// tab is chosen within its five seconds; the answer's snackbar is not, it lives here. [UNAPPROVED N1040]
	const stale = !! problem;
	const hasMoney = !! ( detail && detail.can && detail.can.view_money && detail.money );
	const fields = detail ? fieldsOf( detail ) : [];
	const offered = offeredOf( detail, stale );
	const listed = offeredOf( detail, false );
	const tabs = [];
	if ( fields.length > 0 ) {
		tabs.push( { name: 'fields', title: PANEL.tabFields } );
	}
	if ( listed.length > 0 ) {
		tabs.push( { name: 'requests', title: PANEL.tabRequests } );
	}
	if ( hasMoney ) {
		tabs.push( { name: 'money', title: PANEL.tabMoney }, { name: 'soon', title: PANEL.tabSoon, disabled: true } );
	}
	const tabNames = tabs.map( ( tab ) => tab.name ).join( ',' );
	useEffect( () => {
		const list = root.current && root.current.querySelector( '[role="tablist"]' );
		if ( list ) {
			list.setAttribute( 'aria-label', PANEL.tabsLabel );
		}
	}, [ tabNames ] );
	const answering = useAnswering( config, code, reload, onSaved, root );

	const registration = ( detail && detail.registration ) || {};
	// While the last read failed the state on screen may be old: it is left out.
	const confirmation = problem ? null : confirmationOf( registration );
	const [ confirmIcon, confirmTone ] = confirmation ? markOf( confirmation.state ) : [ '', '' ];
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
						{ ( registration.code || registration.status_label || confirmation || registration.email ) && (
							<p className="kg-panel-meta">
								{ registration.code && <span className="kg-code">{ registration.code }</span> }
								{ registration.status_label && (
									<StatusMark status={ registration.status } text={ registration.status_label } />
								) }
								{ confirmation && (
									<span className="kg-mark">
										<span className={ `dashicons ${ confirmIcon } ${ confirmTone }` } aria-hidden="true" />
										<span className="screen-reader-text">{ confirmation.label }: </span>
										{ confirmation.state_label }
									</span>
								) }
								{ registration.email && <span className="kg-panel-email">{ registration.email }</span> }
							</p>
						) }
					</div>
				) }
				<Button icon="no-alt" label={ PANEL.close } onClick={ onClose } className="kg-panel-close" />
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
			{ detail && (
				<StatusActs
					stale={ !! problem }
					config={ config }
					code={ code }
					can={ detail.can || {} }
					money={ detail.money }
					reload={ reload }
					onSaved={ onSaved }
				/>
			) }
			{ detail && (
				<ManualConfirmation
					stale={ !! problem }
					config={ config }
					code={ code }
					can={ detail.can || {} }
					manual={ registration.manual_confirmation }
					warning={ warningOf( registration, detail.can ) }
					reload={ reload }
					onSaved={ onSaved }
				/>
			) }
			{ detail && <AwaitingStrip items={ offered } start={ answering.start } /> }
			{ tabs.length > 0 && (
				<TabPanel
					className="kg-tabs-money"
					tabs={ tabs }
					initialTabName={ tabs[ 0 ].name }
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
							return (
								<RequestsList
									items={ offered }
									currency={ detail.requests.currency }
									start={ answering.start }
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
							/>
						);
					} }
				</TabPanel>
			) }
			{ detail && answering.dialog }
		</aside>
	);
}
