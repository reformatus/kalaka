/**
 * The requests of the detail panel (slice B, "answer a request"), on the „Kérelmek” tab: every request with its
 * state and the answer or change button. The tab shows only what the read carries: nothing without the requests in
 * the read (only holders of the answer ability get them) and nothing while the last read failed; only the button
 * needs `can.answer_request` (false while the registration is not registered, N655), and an inactive request shows
 * its label with „–” as its state and no button. Answering happens here only (owner 2026-11-27: no awaiting strip
 * above the tabs; the tab's count badge says how many await, `awaitingCount`). One `useAnswering` owns the dialog
 * and the snackbar. [UNAPPROVED N7] [UNAPPROVED N1315]
 *
 * Each button has a screen-reader name that says which request it answers (`REQUESTS.answerLabel`, `changeLabel`).
 * [UNAPPROVED T961] [UNAPPROVED T962]
 */
import { Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { Button, SnackbarList, VisuallyHidden } from '@wordpress/components';
import AnswerDialog from './AnswerDialog';
import NotApplicable from './NotApplicable';
import { itemsOf, stateText } from './requests';
import { fmt } from './format';
import { returnFocus } from './panel';
import { PANEL, REQUESTS } from './texts';

/** How long the success snackbar stays, as the other blocks'. */
const SNACKBAR_MS = 5000;

/** The requests a panel lists: what the read carries (none without the ability), none while the last read failed. */
export function offeredOf( detail, stale ) {
	return stale ? [] : itemsOf( detail );
}

/** Whether the read carries the requests part at all: only holders of the answer ability get it. */
export const hasRequests = ( detail ) => !! ( detail && detail.requests );

/** How many of the requests await an answer: the „Kérelmek” tab's badge. An inactive request does not count. */
export const awaitingCount = ( items ) => items.filter( ( item ) => item.state === 'awaiting' ).length;

/**
 * The answer dialog and its snackbar, and the function the buttons call to open it.
 *
 * @param {Object}   config   window.kalakaGrid
 * @param {string}   code     the registration's code
 * @param {Function} reload   re-reads the panel's detail; resolves when it is on screen
 * @param {Function} onSaved  tells the grid something changed
 * @param {Object}   fallback ref of the element that takes focus when the opener is gone: the panel's close
 *                            button (`closeRef`), as the other blocks do
 * @param {string}   currency the read's `requests.currency`, for the aid figures of the dialog
 * @return {{start: Function, dialog: Object}} `start( item, button )` opens the dialog; `dialog` is to be rendered
 */
export function useAnswering( config, code, reload, onSaved, fallback, currency ) {
	const [ open, setOpen ] = useState( null ); // the request being answered
	const [ notices, setNotices ] = useState( [] );
	const opener = useRef( null );
	const timers = useRef( [] );

	useEffect(
		() => () => {
			timers.current.forEach( clearTimeout );
		},
		[]
	);

	// Two frames after the dialog closes, so the dialog's own focus return has run first: where focus lands is then
	// the same every time. It lands on the opener while that button is still there (a cancel, Esc, or an answer),
	// else on the panel's close button (`returnFocus`, as the other blocks). [UNAPPROVED N1316]
	const focusOpener = () =>
		window.requestAnimationFrame( () =>
			window.requestAnimationFrame( () => returnFocus( opener.current, fallback && fallback.current ) )
		);
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

	const dialog = (
		<Fragment>
			{ open && (
				<AnswerDialog
					config={ config }
					code={ code }
					currency={ currency }
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
	return {
		start: ( item, button ) => {
			opener.current = button;
			setOpen( item );
		},
		dialog,
	};
}

const answerButton = ( item, start, can ) =>
	can && item.state !== 'inactive' && (
	<Button
		variant="secondary"
		aria-label={ fmt( item.state === 'awaiting' ? REQUESTS.answerLabel : REQUESTS.changeLabel, item.label ) }
		onClick={ ( e ) => start( item, e.currentTarget ) }
	>
		{ item.state === 'awaiting' ? REQUESTS.answer : REQUESTS.change }
	</Button>
	);

/**
 * The title of the „Kérelmek” tab: the name and, with requests awaiting an answer, a count badge. The badge shows
 * only the number and is hidden from a screen reader, which hears the visually hidden „, 2 vár válaszra” instead,
 * so the tab reads „Kérelmek, 2 vár válaszra”. No badge for a count of 0 (and `count` is 0 while the read is
 * stale). [UNAPPROVED N1314] [UNAPPROVED T1309]
 */
export function RequestsTabTitle( { count } ) {
	if ( count < 1 ) {
		return PANEL.tabRequests;
	}
	return (
		<Fragment>
			{ PANEL.tabRequests }
			<span className="kg-tab-count" aria-hidden="true">
				{ count }
			</span>
			<VisuallyHidden as="span">{ ', ' + fmt( PANEL.tabRequestsAwaiting, count ) }</VisuallyHidden>
		</Fragment>
	);
}

/**
 * The „Kérelmek” tab: every request with its state and, with `can.answer_request`, its button; an inactive one
 * shows „–” and no button, and with no request the tab says so. [UNAPPROVED T1308]
 */
export function RequestsList( { items, currency, start, can } ) {
	if ( items.length === 0 ) {
		return <p className="kg-none">{ REQUESTS.none }</p>;
	}
	return (
		<div className="kg-requests">
			<ul>
				{ items.map( ( item ) => (
					<li key={ item.id } id={ 'kg-request-' + item.id.replace( /[^a-z0-9_-]/gi, '-' ) }>
						<span>
							{ item.label }
							{ item.state === 'inactive' ? (
								<Fragment>
									{ ': ' }
									<NotApplicable text={ stateText( item, currency ) } />
								</Fragment>
							) : (
								`: ${ stateText( item, currency ) }`
							) }
						</span>
						{ answerButton( item, start, can ) }
					</li>
				) ) }
			</ul>
		</div>
	);
}
