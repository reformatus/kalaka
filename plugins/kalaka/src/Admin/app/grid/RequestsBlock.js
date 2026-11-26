/**
 * The requests of the detail panel (slice B, "answer a request"), split by the panel-tabs ruling (2026-11-26): the
 * requests that await an answer show in a strip above the tabs, with the answer button; every request shows on the
 * „Kérelmek” tab with its state and the answer or change button. Both show only what the read carries: nothing
 * without `can.answer_request` and nothing while the last read failed. One `useAnswering` owns the dialog and the
 * snackbar, so the strip's button and the tab's button open the same dialog. [UNAPPROVED N7] [UNAPPROVED N1000]
 * [UNAPPROVED N1001] [UNAPPROVED N1002]
 *
 * The two buttons of one awaiting request (the strip's and the tab's) are kept, as the owner's hole N1002 stands;
 * each has a screen-reader name that says which request it answers (`REQUESTS.answerLabel`, `changeLabel`).
 * [UNAPPROVED T961] [UNAPPROVED T962] [UNAPPROVED N1039]
 */
import { Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { Button, SnackbarList } from '@wordpress/components';
import AnswerDialog from './AnswerDialog';
import { itemsOf, stateText } from './requests';
import { fmt } from './format';
import { REQUESTS } from './texts';

/** How long the success snackbar stays, as the other blocks'. */
const SNACKBAR_MS = 5000;

/** The requests a panel offers an answer for: none without the ability, none while the last read failed. */
export function offeredOf( detail, stale ) {
	const can = !! ( detail && detail.can && detail.can.answer_request );
	return can && ! stale ? itemsOf( detail ) : [];
}

/** The requests still awaiting an answer: the strip's. [UNAPPROVED N1000] */
export const awaitingOf = ( items ) => items.filter( ( item ) => item.state === 'awaiting' );

/**
 * The answer dialog and its snackbar, and the function the buttons call to open it.
 *
 * @param {Object}   config   window.kalakaGrid
 * @param {string}   code     the registration's code
 * @param {Function} reload   re-reads the panel's detail; resolves when it is on screen
 * @param {Function} onSaved  tells the grid something changed
 * @param {Object}   fallback ref of the element that takes focus when the opener is gone: an answered request
 *                            leaves the strip, so its button no longer exists. [UNAPPROVED N1003]
 * @return {{start: Function, dialog: Object}} `start( item, button )` opens the dialog; `dialog` is to be rendered
 */
export function useAnswering( config, code, reload, onSaved, fallback ) {
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
	// the same every time. It lands on the opener while that button is still there (a cancel, Esc, or an answer
	// from „Kérelmek”), else on the panel. [UNAPPROVED N1038]
	const focusOpener = () =>
		window.requestAnimationFrame( () =>
			window.requestAnimationFrame( () => {
				const target =
					opener.current && opener.current.isConnected ? opener.current : fallback && fallback.current;
				if ( target ) {
					target.focus();
				}
			} )
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

const answerButton = ( item, start ) => (
	<Button
		variant="secondary"
		aria-label={ fmt( item.state === 'awaiting' ? REQUESTS.answerLabel : REQUESTS.changeLabel, item.label ) }
		onClick={ ( e ) => start( item, e.currentTarget ) }
	>
		{ item.state === 'awaiting' ? REQUESTS.answer : REQUESTS.change }
	</Button>
);

/**
 * The strip above the tabs: the requests awaiting an answer, each with its answer button. Nothing when none
 * awaits. [UNAPPROVED N1000] [UNAPPROVED T950] [UNAPPROVED N1001]
 */
export function AwaitingStrip( { items, start } ) {
	const awaiting = awaitingOf( items );
	if ( awaiting.length === 0 ) {
		return null;
	}
	return (
		<div className="kg-requests kg-awaiting">
			<h3 className="kg-requests-title">{ REQUESTS.awaiting }</h3>
			<ul>
				{ awaiting.map( ( item ) => (
					<li key={ item.id }>
						<span>{ item.label }</span>
						{ answerButton( item, start ) }
					</li>
				) ) }
			</ul>
		</div>
	);
}

/** The „Kérelmek” tab: every request with its state and button; an awaiting one is here as well. [UNAPPROVED N1002] */
export function RequestsList( { items, currency, start } ) {
	return (
		<div className="kg-requests">
			<ul>
				{ items.map( ( item ) => (
					<li key={ item.id } id={ 'kg-request-' + item.id.replace( /[^a-z0-9_-]/gi, '-' ) }>
						<span>
							{ item.label }: { stateText( item, currency ) }
						</span>
						{ answerButton( item, start ) }
					</li>
				) ) }
			</ul>
		</div>
	);
}
