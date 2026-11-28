/**
 * The status acts of the detail panel (S39 on #247): a row of buttons, one per `can.*` flag the read carries true,
 * in the slot under the header's status row (N4, the owner's header; N700). After an act the panel re-reads, a snackbar says
 * it was done, the grid re-reads quietly, and focus goes to the control that opened the dialog while it is still
 * there, else to the panel's close button (`returnFocus` with the panel's `closeRef`, as the other blocks).
 * [UNAPPROVED N700] [UNAPPROVED N704] [UNAPPROVED N1316]
 */
import { Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { Button, SnackbarList } from '@wordpress/components';
import { returnFocus } from './panel';
import { StatusRow } from './PanelRows';
import StatusActDialog from './StatusActDialog';
import { SNACKBAR_MS, withNotice, withoutNotice } from './snack';
import { actsFor, labelOf, snackbarOf } from './statusActs';

/**
 * @param {Object}   props
 * @param {Object}   props.config  window.kalakaGrid
 * @param {string}   props.code    the registration's code
 * @param {Object}   props.can     the read's `can`
 * @param {?Object}  props.money   the read's `money`, for the money section of a dialog
 * @param {?string}  props.status  the read's `registration.status`, for the status row's mark
 * @param {?string}  props.text    the read's `registration.status_label`: the status row's word; with none the row
 *                                 is not drawn (a view that does not list it) and the buttons stand alone
 * @param {boolean}  props.stale   the last re-read failed: the block is left out
 * @param {Function} props.reload  re-reads the panel's detail; resolves when it is on screen
 * @param {Function} props.onSaved tells the grid the status changed
 * @param {Object}   props.closeRef ref to the panel's close button, where focus goes when the opener is gone
 */
export default function StatusActs( { config, code, can, money, status, text, stale, reload, onSaved, closeRef } ) {
	const [ dialog, setDialog ] = useState( null );
	const [ notices, setNotices ] = useState( [] );
	const opener = useRef( null );
	const timers = useRef( [] );
	const acts = actsFor( can );

	useEffect(
		() => () => {
			timers.current.forEach( clearTimeout );
		},
		[]
	);

	// After a cancel or an act focus goes back to the button that opened the dialog while it is still on screen,
	// else to the panel's close button: an act usually removes its own button (its `can` flag turns false).
	const focusOpener = () =>
		window.requestAnimationFrame( () => returnFocus( opener.current, closeRef && closeRef.current ) );
	const open = ( act, event ) => {
		opener.current = event.currentTarget;
		setDialog( act );
	};
	const close = () => {
		setDialog( null );
		focusOpener();
	};
	// An answer `{ changed: false }` (a repeat) is done as well: the same re-read, close and snackbar. [UNAPPROVED N711]
	const done = async ( act ) => {
		await reload();
		onSaved();
		setDialog( null );
		const id = String( Date.now() );
		setNotices( ( list ) => withNotice( list, id, snackbarOf( act ), 'success' ) );
		timers.current.push( setTimeout( () => setNotices( ( list ) => withoutNotice( list, id ) ), SNACKBAR_MS ) );
		focusOpener();
	};

	const buttons =
		! stale && acts.length > 0 ? (
			<div className="kg-actions">
				{ acts.map( ( act ) => (
					<Button key={ act } variant="secondary" onClick={ ( event ) => open( act, event ) }>
						{ labelOf( act ) }
					</Button>
				) ) }
			</div>
		) : null;
	// The act buttons are the status row's slot's children; a read with no status word has no row, and the buttons
	// then stand in a row of their own, so no act is lost.
	let row = null;
	if ( text ) {
		row = (
			<StatusRow status={ status } text={ text }>
				{ buttons }
			</StatusRow>
		);
	} else if ( buttons ) {
		row = (
			<div className="kg-panel-row">
				<div className="kg-panel-slot">{ buttons }</div>
			</div>
		);
	}

	return (
		<Fragment>
			{ row }
			{ dialog && (
				<StatusActDialog
					config={ config }
					code={ code }
					act={ dialog }
					money={ money }
					onClose={ close }
					onDone={ done }
					reload={ reload }
				/>
			) }
			<div className="kg-snacks">
				<SnackbarList notices={ notices } onRemove={ ( id ) => setNotices( ( list ) => withoutNotice( list, id ) ) } />
			</div>
		</Fragment>
	);
}
