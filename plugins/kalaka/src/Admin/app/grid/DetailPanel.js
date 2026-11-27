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
import ManualConfirmation from './ManualConfirmation';
import MoneyTab from './MoneyTab';
import StatusMark from './StatusMark';
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

	// The tab list's accessible name: wp-components' TabPanel has no prop for it.
	// After a failed re-read the money data is stale: the tab list is hidden and the money tab shows nothing but
	// its snackbar, so only the load-failure message remains. [UNAPPROVED N24]
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
	// While the last read failed the state on screen may be old: it is left out.
	const confirmation = problem ? null : confirmationOf( registration );
	const [ confirmIcon, confirmTone ] = confirmation ? markOf( confirmation.marks, confirmation.state ) : [ '', '' ];
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
										{ confirmIcon && (
											<span className={ `dashicons ${ confirmIcon } ${ confirmTone }` } aria-hidden="true" />
										) }
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
			{ showsTabs && (
				<TabPanel
					className="kg-tabs-money"
					tabs={ [
						{ name: 'money', title: PANEL.tabMoney },
						{ name: 'soon', title: PANEL.tabSoon, disabled: true },
					] }
					initialTabName="money"
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
						/>
					) }
				</TabPanel>
			) }
		</aside>
	);
}
