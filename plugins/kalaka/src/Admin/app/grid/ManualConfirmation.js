/**
 * The confirmation row of the detail panel (S20), in the header under the status row and above the tabs, so it is
 * reached without the „Pénzügyek” tab: the confirmation state, and as its children who set the flag, when and
 * why, the button that sets it (with `can.confirm_manually`, while it is not set) and the one „Visszaigazolás
 * visszavonása” (with `can.unconfirm`, on any confirmed registration). The flag itself is read under the money
 * gate, so a user who may confirm but not see money gets the row without the line: setting is idempotent (the
 * unconfirm is not, see `api.js`). The line „Indoklás: …” is left out when the reason is empty ([UNAPPROVED N1223]).
 * [UNAPPROVED N1220] [UNAPPROVED N32 reboxed: the buttons are the confirmation
 * row's children, under the state, not a block of their own] [UNAPPROVED N34]
 * The disabled unconfirm's reason is visible text under the button, tied to it by `aria-describedby`, not a
 * tooltip: a touch screen cannot open a tooltip on a disabled button ([UNAPPROVED N1263], [UNAPPROVED N71
 * reboxed: the reason is help text, not a notice in the dialog and not a tooltip]).
 */
import { Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { Button, SnackbarList } from '@wordpress/components';
import ConfirmDialog from './ConfirmDialog';
import { manualActions, snackbarAfter } from './confirmation';
import { fmt } from './format';
import { formatMoment } from './money';
import { returnFocus } from './panel';
import { ConfirmationRow } from './PanelRows';
import { CONFIRM, PANEL } from './texts';

/** How long the success snackbar stays, as the „Pénzügyek” tab's. */
const SNACKBAR_MS = 5000;

/**
 * The id of the disabled unconfirm's help text: the button describes it, and a tap anywhere on the button does
 * not have to open anything to reach it ([UNAPPROVED N1263]). There is one panel at a time.
 */
const HINT_ID = 'kalaka-unconfirm-help';

/** The moment on one line: its spaces do not break. */
const moment = ( instant, zone ) =>
	formatMoment( instant, zone ).replace( / /g, ' ' );

/**
 * @param {Object}   props
 * @param {Object}   props.config  window.kalakaGrid
 * @param {string}   props.code    the registration's code
 * @param {Object}   props.can     the read's `can`
 * @param {?Object}  props.manual  the read's `registration.manual_confirmation`: { set_at, reason, set_by }, null
 *                                 when it is not set, undefined when the read does not carry it
 * @param {?string}  props.warning the read's `registration.unconfirm_warning` (the server's text, only with
 *                                 `can.unconfirm`), null when the unconfirm would not confirm again at once; the
 *                                 visible help text under the disabled unconfirm button and its description
 *                                 ([UNAPPROVED N1263])
 * @param {boolean}  props.blocked the unconfirm is disabled: the rules would confirm again at once
 * @param {?boolean} props.confirmed the read's `registration.confirmed`; the row is left out when it is not a boolean
 * @param {boolean}  props.stale   the last read failed: the row is left out, the snackbar stays
 * @param {Function} props.reload  re-reads the panel's detail; resolves with it once it is on screen, null if
 *                                 both reads failed
 * @param {Function} props.onSaved tells the grid the flag changed
 * @param {Object}   props.closeRef ref to the panel's close button, where focus goes when the opener is gone
 */
export default function ManualConfirmation( {
	config,
	code,
	can,
	manual,
	warning,
	blocked,
	confirmed,
	stale,
	reload,
	onSaved,
	closeRef,
} ) {
	const [ dialog, setDialog ] = useState( null );
	const [ notices, setNotices ] = useState( [] );
	const opener = useRef( null );
	const actionsRef = useRef();
	const timers = useRef( [] );
	const actions = manualActions( manual, can, confirmed );

	useEffect(
		() => () => {
			timers.current.forEach( clearTimeout );
		},
		[]
	);

	// After a save focus goes back to the button that opened the dialog while it is still on screen, else to the
	// panel's close button ([UNAPPROVED N77 reboxed]): a set removes the set button, an unconfirm its own.
	const focusOpener = () =>
		window.requestAnimationFrame( () =>
			returnFocus( opener.current, closeRef && closeRef.current )
		);
	// The opener is replaced by the act itself (the set gives way to the unconfirm, and back), so after one focus
	// goes to the row's new button, if the row has one, else to the close button ([UNAPPROVED N1265] [UNAPPROVED
	// N77 reboxed: the opener, else the close button, holds for a cancelled dialog and the money tab's dialogs]).
	const focusRow = () =>
		window.requestAnimationFrame( () => {
			const row = actionsRef.current;
			const next = row ? row.querySelector( 'button' ) : null;
			returnFocus( next, closeRef && closeRef.current );
		} );
	const open = ( kind, event ) => {
		opener.current = event.currentTarget;
		setDialog( kind );
	};
	const close = () => {
		setDialog( null );
		focusOpener();
	};
	// The snackbar follows the answer and the re-read: none when nothing changed or the re-read failed.
	const done = async ( unconfirm, result ) => {
		const detail = await reload();
		const text = snackbarAfter( unconfirm, result, detail );
		onSaved();
		setDialog( null );
		if ( ! text ) {
			focusRow();
			return;
		}
		const id = String( Date.now() );
		setNotices( ( list ) =>
			list.concat( {
				id,
				content: text,
				status: 'success',
				spokenMessage: text,
			} )
		);
		timers.current.push(
			setTimeout(
				() =>
					setNotices( ( list ) =>
						list.filter( ( n ) => n.id !== id )
					),
				SNACKBAR_MS
			)
		);
		focusRow();
	};

	const shown = !! manual || actions.length > 0;
	return (
		<Fragment>
			{ typeof confirmed === 'boolean' && ! stale && (
				<ConfirmationRow
					confirmed={ confirmed }
					text={ confirmed ? PANEL.confirmed : PANEL.unconfirmed }
				>
					{ shown && (
				<div className="kg-manual">
					{ manual && (
						<Fragment>
							<p>
								{ manual.set_by
									? fmt(
											CONFIRM.setLine,
											manual.set_by,
											moment(
												manual.set_at,
												config.timezone
											)
										)
									: fmt(
											CONFIRM.setLineAnon,
											moment(
												manual.set_at,
												config.timezone
											)
										) }
							</p>
							{ manual.reason && <p>{ fmt( CONFIRM.reasonLine, manual.reason ) }</p> }
						</Fragment>
					) }
					{ actions.length > 0 && (
						<div className="kg-actions" ref={ actionsRef }>
							{ actions.map( ( kind ) => (
								<Button
									key={ kind }
									variant="secondary"
									onClick={ ( e ) => open( kind, e ) }
									{ ...( kind === 'unconfirm' && blocked
										? {
												disabled: true,
												accessibleWhenDisabled: true,
												'aria-describedby': warning
													? HINT_ID
													: undefined,
											}
										: {} ) }
								>
									{ kind === 'unconfirm'
										? CONFIRM.unconfirm
										: CONFIRM.set }
								</Button>
							) ) }
						</div>
					) }
					{ blocked && warning && (
						<p id={ HINT_ID }>{ warning }</p>
					) }
				</div>
					) }
				</ConfirmationRow>
			) }
			{ dialog && (
				<ConfirmDialog
					config={ config }
					unconfirm={ dialog === 'unconfirm' }
					code={ code }
					onClose={ close }
					onDone={ done }
				/>
			) }
			<div className="kg-snacks">
				<SnackbarList
					notices={ notices }
					onRemove={ ( id ) =>
						setNotices( ( list ) =>
							list.filter( ( n ) => n.id !== id )
						)
					}
				/>
			</div>
		</Fragment>
	);
}
