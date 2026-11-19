/**
 * The manual confirmation block of the detail panel (S20), between the header and the tabs, so it is reached
 * without the „Pénzügyek” tab: who set the flag, when and why, and the button that sets or revokes it (only
 * with `can.confirm_manually`). The flag itself is read under the money gate, so a user who may confirm but not
 * see money gets the block without the line and with both buttons: the abilities are idempotent. [UNAPPROVED N32]
 * [UNAPPROVED N34]
 */
import { Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { Button, SnackbarList } from '@wordpress/components';
import ConfirmDialog from './ConfirmDialog';
import { manualActions } from './confirmation';
import { fmt } from './format';
import { formatMoment } from './money';
import { CONFIRM } from './texts';

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
 * @param {boolean}  props.stale   the last read failed: the block is left out, the snackbar stays
 * @param {Function} props.reload  re-reads the panel's detail; resolves when it is on screen
 * @param {Function} props.onSaved tells the grid the flag changed
 */
export default function ManualConfirmation( {
	config,
	code,
	can,
	manual,
	stale,
	reload,
	onSaved,
} ) {
	const [ dialog, setDialog ] = useState( null );
	const [ notices, setNotices ] = useState( [] );
	const opener = useRef( null );
	const timers = useRef( [] );
	const actions = manualActions( manual, can );

	useEffect(
		() => () => {
			timers.current.forEach( clearTimeout );
		},
		[]
	);

	const focusOpener = () =>
		window.requestAnimationFrame( () => {
			if ( opener.current && opener.current.isConnected ) {
				opener.current.focus();
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
	const done = async ( text ) => {
		await reload();
		onSaved();
		setDialog( null );
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
			{ shown && ! stale && (
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
								>
									{ kind === 'unconfirm'
										? CONFIRM.revoke
										: CONFIRM.set }
								</Button>
							) ) }
						</div>
					) }
				</div>
			) }
			{ dialog && (
				<ConfirmDialog
					config={ config }
					revoke={ dialog === 'unconfirm' }
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
