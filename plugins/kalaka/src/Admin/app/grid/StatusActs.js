/**
 * The status acts block of the detail panel (S39 on #247): a row of buttons between the header and the manual
 * confirmation block, one per `can.*` flag the read carries true. After an act the panel re-reads, a snackbar says
 * it was done, the grid re-reads quietly, and focus goes to the panel's close button. [UNAPPROVED N700]
 * [UNAPPROVED N704] [UNAPPROVED N705]
 */
import { Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { Button, SnackbarList } from '@wordpress/components';
import StatusActDialog from './StatusActDialog';
import { SNACKBAR_MS, withNotice, withoutNotice } from './snack';
import { actsFor, labelOf, snackbarOf } from './statusActs';

/**
 * @param {Object}   props
 * @param {Object}   props.config  window.kalakaGrid
 * @param {string}   props.code    the registration's code
 * @param {Object}   props.can     the read's `can`
 * @param {boolean}  props.stale   the last re-read failed: the block is left out
 * @param {Function} props.reload  re-reads the panel's detail; resolves when it is on screen
 * @param {Function} props.onSaved tells the grid the status changed
 */
export default function StatusActs( { config, code, can, stale, reload, onSaved } ) {
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

	const focusOpener = () =>
		window.requestAnimationFrame( () => {
			if ( opener.current && opener.current.isConnected ) {
				opener.current.focus();
			}
		} );
	// After an act the opened button is usually gone, so focus goes to the panel's close button, which is always
	// there. [UNAPPROVED N705]
	const focusPanel = () =>
		window.requestAnimationFrame( () => {
			const closeButton = document.querySelector( '.kg-panel .kg-panel-close' );
			if ( closeButton ) {
				closeButton.focus();
			}
		} );
	const open = ( act, event ) => {
		opener.current = event.currentTarget;
		setDialog( act );
	};
	const close = () => {
		setDialog( null );
		focusOpener();
	};
	const done = async ( act ) => {
		await reload();
		onSaved();
		setDialog( null );
		const id = String( Date.now() );
		setNotices( ( list ) => withNotice( list, id, snackbarOf( act ), 'success' ) );
		timers.current.push( setTimeout( () => setNotices( ( list ) => withoutNotice( list, id ) ), SNACKBAR_MS ) );
		focusPanel();
	};

	return (
		<Fragment>
			{ ! stale && acts.length > 0 && (
				<div className="kg-status-acts">
					<div className="kg-actions">
						{ acts.map( ( act ) => (
							<Button key={ act } variant="secondary" onClick={ ( event ) => open( act, event ) }>
								{ labelOf( act ) }
							</Button>
						) ) }
					</div>
				</div>
			) }
			{ dialog && (
				<StatusActDialog
					config={ config }
					code={ code }
					act={ dialog }
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
