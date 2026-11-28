/**
 * The manual mail (piece 4): the content of the dialog that sends one published body of the event to chosen
 * registrations, and a modal that holds it for the places that have no modal of their own.
 *
 * On opening it asks the server who the send would reach (`POST …/mail/manual/preview`, checked against the user's
 * grant like the send): the codes that would get the mail, the ones that would be skipped with the reason, the codes
 * that do not exist (full access only), and the event's published bodies, which are the choice. Nothing is chosen
 * for the user. The send is the ability `kalaka/send-manual-mail` with a request key that names one send attempt: a
 * retry of an attempt without an answer (a lost answer, a double click) carries the same key and queues nothing
 * twice; a success or a refusal spends it and the next send gets a fresh one (`createKeys`); while a send runs a
 * second submit is ignored. The answer is shown in the dialog
 * (queued, skipped, a repeat, `paused`, `held`) and the dialog then only closes. A refusal shows the server's own
 * text and keeps the dialog. More codes than one send may hold come back as the server's 400, shown as its own
 * sentence. [UNAPPROVED N291]
 */
import { Fragment, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { Button, Modal, Notice, SelectControl, Spinner } from '@wordpress/components';
import * as api from './api';
import { isRefusal, isTooMany, pageKeys, stateReads } from './manual';
import { selectionSignature } from '../grid/selection';
import { bodyLabel, problemText } from './format';
import { fmt } from '../grid/format';
import Skipped from './Skipped';
import { COMMON, MANUAL, RESULT } from './texts';

/**
 * @param {Object}   props
 * @param {number}   props.event       the event
 * @param {string}   props.previewPath the preview route, after the site's REST root
 * @param {Object}   props.selection   `{ codes }` of the ticked rows, or the grid's view with its filters and search
 *                                   (`grid/selection.js`): the server resolves the latter itself
 * @param {Function} props.onClose     closes the dialog
 * @param {Function} props.onSent      called with the answer once a send is made
 * @param {Object}   [props.keys]    the page's request keys (`createKeys()`), so a retry of an attempt without an
 *                                   answer is the same request even from a reopened dialog
 */
export function ManualMailForm( { event, previewPath, selection, onClose, onSent, keys } ) {
	const [ plan, setPlan ] = useState( null );
	const [ planError, setPlanError ] = useState( null );
	const [ bodyId, setBodyId ] = useState( '' );
	const [ noBody, setNoBody ] = useState( false );
	const [ failed, setFailed ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState( null );
	const sending = useRef( false );
	const keyFor = useRef( null );
	const bodyRef = useRef( null );
	if ( ! keyFor.current ) {
		keyFor.current = keys || pageKeys( event );
	}
	// Whether sending is paused and whether the event is held, before anything is queued. Full access's reads: a
	// refusal leaves the answer as the only place to learn it.
	const [ standing, setStanding ] = useState( { paused: false, held: false } );
	useEffect( () => {
		let live = true;
		const reads = stateReads( previewPath );
		api.readPath( reads.site ).then(
			( data ) => live && setStanding( ( old ) => ( { ...old, paused: !! data.paused } ) ),
			() => {}
		);
		api.readPath( reads.event ).then(
			( data ) => live && setStanding( ( old ) => ( { ...old, held: !! data.held } ) ),
			() => {}
		);
		return () => {
			live = false;
		};
	}, [ previewPath ] );

	useEffect( () => {
		let live = true;
		api.previewManual( previewPath, selection ).then(
			( data ) => live && setPlan( data ),
			( error ) =>
				live &&
				setPlanError( isTooMany( error ) ? MANUAL.tooMany : problemText( error, MANUAL.previewFail ) )
		);
		return () => {
			live = false;
		};
	}, [ previewPath, selectionSignature( selection ) ] );

	// The dialog's first focus is the template field once the read answers, not the primary „Küldés” button, which is
	// the only focusable element while the preview is still loading.
	const focusedBody = useRef( false );
	useEffect( () => {
		if ( plan && plan.bodies.length > 0 && ! focusedBody.current ) {
			focusedBody.current = true;
			const select = bodyRef.current && bodyRef.current.querySelector( 'select' );
			if ( select ) {
				select.focus();
			}
		}
	}, [ plan ] );

	const submit = async ( submitted ) => {
		submitted.preventDefault();
		if ( sending.current || result ) {
			return;
		}
		setFailed( null );
		if ( bodyId === '' ) {
			setNoBody( true );
			return;
		}
		setNoBody( false );
		sending.current = true;
		setBusy( true );
		// The attempt's key: the pending one while the last attempt has no answer, else a fresh one.
		const id = parseInt( bodyId, 10 );
		const key = keyFor.current.keyFor( id, selection );
		try {
			const answer = await api.sendManual( event, id, selection, key );
			keyFor.current.spend( key );
			setResult( answer );
			if ( onSent ) {
				onSent( answer );
			}
		} catch ( error ) {
			// A refusal ends the attempt (the server stored nothing), so the next send is a new one. A lost answer or a
			// server error keeps the key, so pressing Küldés again is a retry, not a second send.
			if ( isRefusal( error ) ) {
				keyFor.current.spend( key );
			}
			setFailed( problemText( error, COMMON.actFail ) );
		}
		sending.current = false;
		setBusy( false );
	};

	const body = useMemo(
		() => ( plan ? plan.bodies.find( ( b ) => String( b.id ) === bodyId ) : null ),
		[ plan, bodyId ]
	);

	// The preview gives the names as one map by code (`names`); the send's answer lists only codes, so both lists
	// take the name from that map; a code without an entry shows no name.
	const nameOf = ( code ) => ( plan && plan.names && plan.names[ code ] ) || null;

	if ( result ) {
		return (
			<div className="km-dialog">
				{ result.repeat ? (
					<Notice status="info" isDismissible={ false }>
						{ fmt( RESULT.repeat, result.queued ) }
					</Notice>
				) : (
					<p role="status">
						<strong>{ fmt( RESULT.queued, result.queued ) }</strong>
					</p>
				) }
				<Skipped skipped={ result.skipped } nameOf={ nameOf } />
				{ result.paused && <Notice status="warning" isDismissible={ false }>{ RESULT.paused }</Notice> }
				{ result.held && <Notice status="warning" isDismissible={ false }>{ RESULT.held }</Notice> }
				<div className="km-actions">
					<Button variant="primary" onClick={ onClose }>
						{ COMMON.close }
					</Button>
				</div>
			</div>
		);
	}

	return (
		<form className="km-dialog" onSubmit={ submit } noValidate>
			{ ! plan && ! planError && (
				<p>
					<Spinner /> { MANUAL.loading }
				</p>
			) }
			{ planError && (
				<Notice status="error" isDismissible={ false }>
					{ planError }
				</Notice>
			) }
			{ plan && (
				<Fragment>
					{ /* A view selection that matches nothing gives no recipient (the server refuses a send to it): the line says so and the
					   send is disabled, as for an empty queue. The scope („all matching” or „the filter's”) is no line of the dialog: the
					   action bar's count prefix says it. */ }
					<p role="status">
						<strong>
							{ plan.queue.length ? fmt( MANUAL.toQueue, plan.queue.length ) : MANUAL.toQueueNone }
						</strong>
					</p>
					<Skipped skipped={ plan.skipped } nameOf={ nameOf } />
					{ plan.unknown.length > 0 && (
						<p>
							{ MANUAL.unknown } <span className="km-code-text">{ plan.unknown.join( ', ' ) }</span>
						</p>
					) }
					{ plan.bodies.length === 0 ? (
						<p>{ MANUAL.noBodies }</p>
					) : (
						<div ref={ bodyRef }>
							<SelectControl
								label={ MANUAL.body }
								value={ bodyId }
								options={ [
									{ value: '', label: MANUAL.pick },
									...plan.bodies.map( ( b ) => ( { value: String( b.id ), label: bodyLabel( b, plan.bodies ) } ) ),
								] }
								onChange={ ( value ) => {
									setBodyId( value );
									setNoBody( false );
								} }
								help={ noBody ? <span className="km-err">{ MANUAL.noBody }</span> : undefined }
								__nextHasNoMarginBottom
							/>
						</div>
					) }
				</Fragment>
			) }
			{ standing.paused && <Notice status="warning" isDismissible={ false }>{ RESULT.paused }</Notice> }
			{ standing.held && <Notice status="warning" isDismissible={ false }>{ RESULT.held }</Notice> }
			{ failed && (
				<Notice status="error" isDismissible={ false }>
					{ failed }
				</Notice>
			) }
			<div className="km-actions">
				<Button
					variant="primary"
					type="submit"
					isBusy={ busy }
					aria-disabled={ busy || ! plan || plan.queue.length === 0 }
					disabled={ ! plan || plan.queue.length === 0 }
					accessibleWhenDisabled
				>
					{ busy ? MANUAL.sending : MANUAL.send }
				</Button>
				<Button variant="tertiary" onClick={ onClose } disabled={ busy }>
					{ COMMON.cancel }
				</Button>
			</div>
			{ body && <span className="screen-reader-text">{ body.subject }</span> }
		</form>
	);
}

/** The form in a modal of its own (the failed deliveries' follow-up). */
export default function ManualMailDialog( { config, selection, onClose, onSent, keys } ) {
	return (
		<Modal title={ MANUAL.title } onRequestClose={ onClose } size="medium" shouldCloseOnClickOutside={ false }>
			<ManualMailForm
				event={ config.event }
				previewPath={ `${ config.rest }/events/${ config.event }/mail/manual/preview` }
				selection={ selection }
				onClose={ onClose }
				onSent={ onSent }
				keys={ keys }
			/>
		</Modal>
	);
}
