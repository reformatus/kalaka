/**
 * The requests block of the detail panel (slice B, "answer a request"), under the manual confirmation block: each
 * request of the registration with its state, and with `can.answer_request` the button that opens the answer
 * dialog. It shows only what the read carries: nothing without `can.answer_request` and nothing while the last
 * read failed. [UNAPPROVED N7]
 */
import { Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { Button, SnackbarList } from '@wordpress/components';
import AnswerDialog from './AnswerDialog';
import { itemsOf, stateText } from './requests';
import { REQUESTS } from './texts';

/** How long the success snackbar stays, as the other blocks'. */
const SNACKBAR_MS = 5000;

/**
 * @param {Object}   props
 * @param {Object}   props.config  window.kalakaGrid
 * @param {string}   props.code    the registration's code
 * @param {Object}   props.detail  the read
 * @param {boolean}  props.stale   the last read failed: the block is left out
 * @param {Function} props.reload  re-reads the panel's detail; resolves when it is on screen
 * @param {Function} props.onSaved tells the grid something changed
 */
export default function Requests( { config, code, detail, stale, reload, onSaved } ) {
	const [ open, setOpen ] = useState( null ); // the request being answered
	const [ notices, setNotices ] = useState( [] );
	const opener = useRef( null );
	const timers = useRef( [] );
	const items = itemsOf( detail );
	const can = !! ( detail.can && detail.can.answer_request );

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
	const done = async ( text ) => {
		await reload();
		onSaved();
		setOpen( null );
		if ( text === '' ) {
			focusOpener();
			return;
		}
		const id = String( Date.now() );
		setNotices( ( list ) => list.concat( { id, content: text, status: 'success', spokenMessage: text } ) );
		timers.current.push(
			setTimeout( () => setNotices( ( list ) => list.filter( ( n ) => n.id !== id ) ), SNACKBAR_MS )
		);
		focusOpener();
	};

	if ( ! can || ( items.length === 0 && notices.length === 0 ) ) {
		return null;
	}
	return (
		<Fragment>
			{ ! stale && items.length > 0 && (
				<div className="kg-requests">
					<h3 className="kg-requests-title">{ REQUESTS.heading }</h3>
					<ul>
						{ items.map( ( item ) => (
							<li key={ item.id }>
								<span>
									{ item.label }: { stateText( item, detail.requests.currency ) }
								</span>
								<Button
									variant="secondary"
									onClick={ ( e ) => {
										opener.current = e.currentTarget;
										setOpen( item );
									} }
								>
									{ item.state === 'awaiting' ? REQUESTS.answer : REQUESTS.change }
								</Button>
							</li>
						) ) }
					</ul>
				</div>
			) }
			{ open && (
				<AnswerDialog
					config={ config }
					code={ code }
					item={ open }
					onClose={ () => {
						setOpen( null );
						focusOpener();
					} }
					onDone={ done }
				/>
			) }
			<div className="kg-snacks">
				<SnackbarList
					notices={ notices }
					onRemove={ ( id ) => setNotices( ( list ) => list.filter( ( n ) => n.id !== id ) ) }
				/>
			</div>
		</Fragment>
	);
}
