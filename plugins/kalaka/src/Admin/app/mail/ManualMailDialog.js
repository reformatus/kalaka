/**
 * The manual mail (piece 4): the content of the dialog that sends one published body of the event to chosen
 * registrations, and a modal that holds it for the places that have no modal of their own. [UNAPPROVED N290]
 *
 * On opening it asks the server who the send would reach (`POST …/mail/manual/preview`, checked against the user's
 * grant like the send): the codes that would get the mail, the ones that would be skipped with the reason, the codes
 * that do not exist (full access only), and the event's published bodies, which are the choice. Nothing is chosen
 * for the user. The send is the ability `kalaka/send-manual-mail` with a request key: the same body, layout and
 * codes always carry the same key, so a retry after a lost answer or a double click is the same request and queues
 * nothing twice (`createKeys`); while a send runs a second submit is ignored. The answer is shown in the dialog
 * (queued, skipped, a repeat, `paused`, `held`) and the dialog then only closes. A refusal shows the server's own
 * text and keeps the dialog. More codes than one send may hold come back as the server's 400, shown as its own
 * sentence. [UNAPPROVED N291] [UNAPPROVED N292] [UNAPPROVED N293]
 */
import { Fragment, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { Button, CheckboxControl, Modal, Notice, SelectControl, Spinner } from '@wordpress/components';
import * as api from './api';
import { isTooMany, pageKeys, stateReads } from './manual';
import { bodyLabel, problemText } from './format';
import { fmt } from '../grid/format';
import { COMMON, MANUAL, RESULT } from './texts';

const reasonWord = ( reason ) => MANUAL[ 'reason_' + reason ] || reason;

/**
 * @param {Object}   props
 * @param {number}   props.event       the event
 * @param {string}   props.previewPath the preview route, after the site's REST root
 * @param {string[]} props.codes       the registrations' codes
 * @param {Function} props.onClose     closes the dialog
 * @param {Function} props.onSent      called with the answer once a send is made
 * @param {Function} [props.keys]    the page's request keys (`createKeys()`), so reopening the dialog with the same
 *                                   rows and body is the same request
 */
export function ManualMailForm( { event, previewPath, codes, onClose, onSent, keys } ) {
	const [ plan, setPlan ] = useState( null );
	const [ planError, setPlanError ] = useState( null );
	const [ bodyId, setBodyId ] = useState( '' );
	const [ layout, setLayout ] = useState( true );
	const [ noBody, setNoBody ] = useState( false );
	const [ failed, setFailed ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState( null );
	const sending = useRef( false );
	const keyFor = useRef( null );
	if ( ! keyFor.current ) {
		keyFor.current = keys || pageKeys( event );
	}
	// Whether sending is paused and whether the event is held, before anything is queued. Full access's reads: a
	// refusal leaves the answer as the only place to learn it. [UNAPPROVED N293]
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
		api.previewManual( previewPath, codes ).then(
			( data ) => live && setPlan( data ),
			( error ) =>
				live &&
				setPlanError( isTooMany( error ) ? MANUAL.tooMany : problemText( error, MANUAL.previewFail ) )
		);
		return () => {
			live = false;
		};
	}, [ previewPath, codes.join( ',' ) ] );

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
		try {
			const id = parseInt( bodyId, 10 );
			const answer = await api.sendManual( event, id, codes, layout, keyFor.current( id, layout, codes ) );
			setResult( answer );
			if ( onSent ) {
				onSent( answer );
			}
		} catch ( error ) {
			// The same body, layout and codes keep their key, so pressing Küldés again is a retry, not a second send.
			setFailed( problemText( error, COMMON.actFail ) );
		}
		sending.current = false;
		setBusy( false );
	};

	const body = useMemo(
		() => ( plan ? plan.bodies.find( ( b ) => String( b.id ) === bodyId ) : null ),
		[ plan, bodyId ]
	);

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
				{ result.skipped.length > 0 && (
					<Fragment>
						<p>{ fmt( RESULT.skipped, result.skipped.length ) }</p>
						<ul className="km-members">
							{ result.skipped.map( ( s ) => (
								<li key={ s.code }>
									<span className="km-code-text">{ s.code }</span> – { reasonWord( s.reason ) }
								</li>
							) ) }
						</ul>
					</Fragment>
				) }
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
					<p role="status">
						<strong>
							{ plan.queue.length ? fmt( MANUAL.toQueue, plan.queue.length ) : MANUAL.toQueueNone }
						</strong>
					</p>
					{ plan.skipped.length > 0 && (
						<Fragment>
							<p>{ MANUAL.skipped }</p>
							<ul className="km-members">
								{ plan.skipped.map( ( s ) => (
									<li key={ s.code }>
										<span className="km-code-text">{ s.code }</span> – { reasonWord( s.reason ) }
									</li>
								) ) }
							</ul>
						</Fragment>
					) }
					{ plan.unknown.length > 0 && (
						<p>
							{ MANUAL.unknown } <span className="km-code-text">{ plan.unknown.join( ', ' ) }</span>
						</p>
					) }
					{ plan.bodies.length === 0 ? (
						<p>{ MANUAL.noBodies }</p>
					) : (
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
					) }
					<CheckboxControl label={ MANUAL.layout } checked={ layout } onChange={ setLayout } __nextHasNoMarginBottom />
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
export default function ManualMailDialog( { config, codes, onClose, onSent, keys } ) {
	return (
		<Modal title={ MANUAL.title } onRequestClose={ onClose } size="medium" shouldCloseOnClickOutside={ false }>
			<ManualMailForm
				event={ config.event }
				previewPath={ `${ config.rest }/events/${ config.event }/mail/manual/preview` }
				codes={ codes }
				onClose={ onClose }
				onSent={ onSent }
				keys={ keys }
			/>
		</Modal>
	);
}
