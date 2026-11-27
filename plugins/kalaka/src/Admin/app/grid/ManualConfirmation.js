/**
 * The confirmation row of the detail panel (S20), in the header under the status row and above the tabs, so it is
 * reached without the „Pénzügyek” tab: the confirmation state, and as its children who set the flag, when and
 * why, the button that sets it (with `can.confirm_manually`, while it is not set) and the one „Visszaigazolás
 * visszavonása” (with `can.unconfirm`, on any confirmed registration). The flag itself is read under the money
 * gate, so a user who may confirm but not see money gets the row without the line: setting is idempotent (the
 * unconfirm is not, see `api.js`). [UNAPPROVED N1220] [UNAPPROVED N32 reboxed: the buttons are the confirmation
 * row's children, under the state, not a block of their own] [UNAPPROVED N34]
 */
import { Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { Button, SnackbarList } from '@wordpress/components';
import ConfirmDialog from './ConfirmDialog';
import { manualActions, snackbarAfter } from './confirmation';
import { fmt } from './format';
import { formatMoment } from './money';
import { ConfirmationRow } from './PanelRows';
import { CONFIRM, PANEL } from './texts';

/** How long the success snackbar stays, as the „Pénzügyek” tab's. */
const SNACKBAR_MS = 5000;

/** The moment on one line: its spaces do not break. [UNAPPROVED N36] */
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
 *                                 tooltip and the description of the disabled unconfirm button
 * @param {boolean}  props.blocked the unconfirm is disabled: the rules would confirm again at once
 * @param {?boolean} props.confirmed the read's `registration.confirmed`; the row is left out when it is not a boolean
 * @param {boolean}  props.stale   the last read failed: the row is left out, the snackbar stays
 * @param {Function} props.reload  re-reads the panel's detail; resolves with it once it is on screen, null if
 *                                 both reads failed
 * @param {Function} props.onSaved tells the grid the flag changed
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
} ) {
	const [ dialog, setDialog ] = useState( null );
	const [ notices, setNotices ] = useState( [] );
	const opener = useRef( null );
	const block = useRef( null );
	const timers = useRef( [] );
	const actions = manualActions( manual, can, confirmed );

	useEffect(
		() => () => {
			timers.current.forEach( clearTimeout );
		},
		[]
	);

	// Focus goes back to the button that opened the dialog; when the act removed that button (a plain unconfirm
	// removes its own, a set removes the set button), to the block itself, which is focusable by script only
	// ([UNAPPROVED N77]). When the block is not there either (nothing left to show) focus stays where the browser puts it.
	const focusOpener = () =>
		window.requestAnimationFrame( () => {
			if ( opener.current && opener.current.isConnected ) {
				opener.current.focus();
			} else if ( block.current && block.current.isConnected ) {
				block.current.focus();
			}
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
			focusOpener();
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
		focusOpener();
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
				<div className="kg-manual" tabIndex={ -1 } ref={ block }>
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
							<p>{ fmt( CONFIRM.reasonLine, manual.reason ) }</p>
						</Fragment>
					) }
					{ actions.length > 0 && (
						<div className="kg-actions">
							{ actions.map( ( kind ) => (
								<Button
									key={ kind }
									variant="secondary"
									onClick={ ( e ) => open( kind, e ) }
									{ ...( kind === 'unconfirm' && blocked
										? {
												disabled: true,
												accessibleWhenDisabled: true,
												label: CONFIRM.unconfirm,
												showTooltip: !! warning,
												description: warning || undefined,
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
