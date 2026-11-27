/**
 * The registration detail panel (S38): a non-modal side panel over the grid, with the identifying header and
 * the tabs. It reads `GET kalaka-admin/v1/events/{event}/registrations/{code}`, which already cuts every part to
 * the user's views and grants: a header field the views do not list is absent and leaves no gap, and the money
 * tab shows only with `can.view_money`. [UNAPPROVED N1200]
 * N4 reboxed: the header is the name, then code and e-mail, then the status row and the confirmation row with a
 * slot each, then the tabs (N1200). N12 reboxed: every tab always shown and the last-clicked one remembered
 * (N1201; written on a click only, see `tabClicks` and [UNAPPROVED N1264]). T3 struck: the disabled „Hamarosan…”
 * tab is gone.
 *
 * S20: the confirmation state is its own line, the confirmation row, under the status row, and under it the
 * manual confirmation (who set the flag, when and why, and the button(s)); `ManualConfirmation` renders the row.
 * N30 reboxed: the state is no longer shown next to the status. When the re-read after a save fails twice, the
 * state and what hangs under it are not shown (the old ones would look current); the load-error message says so.
 * [UNAPPROVED N30]
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Button, TabPanel } from '@wordpress/components';
import * as api from './api';
import { unconfirmBlocked, warningOf } from './confirmation';
import ManualConfirmation from './ManualConfirmation';
import MoneyTab from './MoneyTab';
import { StatusRow } from './PanelRows';
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

	// Every tab is always shown, an empty one with its own empty text, and the last-clicked tab is kept for the
	// browser session, so it stays open when another registration is selected; a tab the branch lacks falls
	// back to the first. A tab the viewer has no right to is left out, as the code already did. [UNAPPROVED N1201]
	const tabs = [ { name: 'money', title: PANEL.tabMoney } ];
	const tabMemory = useRef();
	if ( ! tabMemory.current ) {
		tabMemory.current = tabClicks( tabs.map( ( t ) => t.name ) );
	}
	// The tab list's accessible name: wp-components' TabPanel has no prop for it.
	// After a failed re-read the money data is stale: the tab list is hidden and the money tab shows nothing but
	// its snackbar, so only the load-failure message remains.
	const showsTabs = !! ( detail && detail.can && detail.can.view_money && detail.money );
	const stale = !! problem;
	useEffect( () => {
		const list = root.current && root.current.querySelector( '[role="tablist"]' );
		if ( list ) {
			list.setAttribute( 'aria-label', PANEL.tabsLabel );
			list.style.display = stale ? 'none' : '';
		}
	}, [ showsTabs, stale ] );

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
						{ /* The two state rows: the confirmation row's slot holds the manual confirmation's buttons; the status row's stays empty here. [UNAPPROVED N1200] */ }
						{ registration.status_label && (
							<StatusRow status={ registration.status } text={ registration.status_label } />
						) }
						{ detail && (
							<ManualConfirmation
								stale={ !! problem }
								config={ config }
								code={ code }
								can={ detail.can || {} }
								manual={ registration.manual_confirmation }
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
			{ showsTabs && (
				<TabPanel
					className="kg-tabs-money"
					tabs={ tabs }
					initialTabName={ tabMemory.current.initial }
					onSelect={ tabMemory.current.onSelect }
					orientation="horizontal"
				>
					{ () => (
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
					) }
				</TabPanel>
			) }
		</aside>
	);
}
