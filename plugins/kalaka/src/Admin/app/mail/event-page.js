/**
 * The event's „Levelek” page's dynamic parts (section 8): the switch, the choose dialog, the X that removes a
 * point's template, the group-mail members dialog, the clear of the event's queued mail and the levélkeret save.
 * The page itself is PHP's (`Admin\Mail\EventPage`); this file only binds to what it rendered (`data-kalaka-…`
 * attributes) and, after an act, reloads the page so it shows the server's answer. The server decides every call;
 * nothing here holds a rule. [UNAPPROVED N1286]
 */
import { createRoot, useEffect, useState } from '@wordpress/element';
import { Button, Modal, SelectControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { runAbility } from '../grid/api';
import { BATCHES, BODIES, TROUBLED } from './texts';

const config = window.kalakaMailPage || null;

/** A modal in a container of its own; `render( close )` gets the closer. */
function openModal( render ) {
	const host = document.createElement( 'div' );
	document.body.appendChild( host );
	const root = createRoot( host );
	const close = () => {
		root.unmount();
		host.remove();
	};
	root.render( render( close ) );

	return close;
}

/** Runs an act, then reloads; a failure shows the server's text, else the generic one. */
async function act( cell, run ) {
	try {
		await run();
		window.location.reload();
	} catch ( error ) {
		const text = ( error && error.message ) || config.texts.actFail;
		const line = document.createElement( 'p' );
		line.className = 'notice notice-error inline';
		line.setAttribute( 'role', 'alert' );
		line.textContent = text;
		cell.appendChild( line );
	}
}

/** The switch: the read's `enabled`, the server's own refusals decide whether it may move. */
function bindSwitch( input ) {
	input.addEventListener( 'change', async () => {
		const on = input.checked;
		input.disabled = true;
		await act( input.parentElement, () =>
			runAbility( 'kalaka/update-action-mail', {
				event_id: config.event,
				mail: input.dataset.kalakaSwitch,
				enabled: on,
			} )
		);
	} );
}

/** „Sablon választása”: a WordPress Modal with the event's published templates. */
function bindChoose( button ) {
	button.addEventListener( 'click', () => {
		let chosen = config.bodies.length ? String( config.bodies[ 0 ].id ) : '';
		openModal( ( close ) => (
			<Modal
				title={ config.texts.chooseTitle }
				onRequestClose={ close }
				shouldCloseOnClickOutside={ false }
			>
				{ config.bodies.length === 0 ? (
					<p>{ BODIES.none }</p>
				) : (
					<SelectControl
						label=""
						hideLabelFromVision
						value={ chosen }
						options={ config.bodies.map( ( body ) => ( {
							value: String( body.id ),
							label: body.label,
						} ) ) }
						onChange={ ( value ) => ( chosen = value ) }
					/>
				) }
				<div className="kalaka-modal-actions">
					<Button
						variant="primary"
						disabled={ ! chosen }
						onClick={ () => {
							close();
							act( button.parentElement, () =>
								runAbility( 'kalaka/update-action-mail', {
									event_id: config.event,
									mail: button.dataset.kalakaChoose,
									body_id: Number( chosen ),
								} )
							);
						} }
					>
						{ config.texts.choose }
					</Button>
					<Button variant="tertiary" onClick={ close }>
						{ config.texts.cancel }
					</Button>
				</div>
			</Modal>
		) );
	} );
}

/** „Új sablon”: the route makes a draft, assigns it and switches the point off; then its editor opens. */
function bindNew( button ) {
	button.addEventListener( 'click', async () => {
		button.disabled = true;
		try {
			const answer = await apiFetch( {
				path: `${ config.rest }/events/${ config.event }/mail/points/${ button.dataset.kalakaNew }/template`,
				method: 'POST',
			} );
			if ( answer && answer.edit_url ) {
				window.location.href = answer.edit_url;
				return;
			}
		} catch ( error ) {
			button.disabled = false;
			const line = document.createElement( 'p' );
			line.className = 'notice notice-error inline';
			line.setAttribute( 'role', 'alert' );
			line.textContent = ( error && error.message ) || config.texts.actFail;
			button.parentElement.appendChild( line );
		}
	} );
}

/** The X: the same ability with `body_id: null`; the server switches the point off. */
function bindRemove( button ) {
	button.addEventListener( 'click', () => {
		act( button.closest( 'td' ), () =>
			runAbility( 'kalaka/update-action-mail', {
				event_id: config.event,
				mail: button.dataset.kalakaRemove,
				body_id: null,
			} )
		);
	} );
}

/** A group mail's members, from the batch read. */
function bindBatch( button ) {
	button.addEventListener( 'click', () => {
		openModal( ( close ) => (
			<BatchDialog id={ button.dataset.kalakaBatch } close={ close } />
		) );
	} );
}

function BatchDialog( { id, close } ) {
	const [ batch, setBatch ] = useState( null );
	useEffect( () => {
		apiFetch( { path: `${ config.rest }/events/${ config.event }/mail/batches/${ id }` } ).then(
			setBatch,
			() => setBatch( { error: true } )
		);
	}, [ id ] );
	return (
		<Modal
			title={ config.texts.batchTitle.replace( '%d', id ) }
			onRequestClose={ close }
		>
			{ ! batch && <p>…</p> }
			{ batch && batch.error && <p>{ config.texts.actFail }</p> }
			{ batch && batch.members && (
				<table className="widefat striped">
					<thead>
						<tr>
							<th scope="col">{ BATCHES.members }</th>
							<th scope="col">{ TROUBLED.status }</th>
						</tr>
					</thead>
					<tbody>
						{ batch.members.map( ( member ) => (
							<tr key={ member.code }>
								<td>
									<code>{ member.code }</code>
								</td>
								<td>{ BATCHES[ 'outcome_' + member.outcome ] || member.outcome }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			<div className="kalaka-modal-actions">
				<Button variant="primary" onClick={ close }>
					{ config.texts.close }
				</Button>
			</div>
		</Modal>
	);
}

/** The clear of the event's queued mail, after a confirm. */
function bindClear( button ) {
	button.addEventListener( 'click', () => {
		openModal( ( close ) => (
			<Modal title={ config.texts.clear } onRequestClose={ close }>
				<p>{ config.texts.clearConfirm }</p>
				<div className="kalaka-modal-actions">
					<Button
						variant="primary"
						isDestructive
						onClick={ () => {
							close();
							act( button.parentElement, () =>
								runAbility( 'kalaka/clear-event-mail', { event_id: config.event } )
							);
						} }
					>
						{ config.texts.clear }
					</Button>
					<Button variant="tertiary" onClick={ close }>
						{ config.texts.cancel }
					</Button>
				</div>
			</Modal>
		) );
	} );
}

/** The levélkeret: the plain editor, its save, and the leave warning while it differs from the saved text. */
function bindLayout( area ) {
	const saved = area.value;
	const save = document.querySelector( '[data-kalaka-save-layout]' );
	window.addEventListener( 'beforeunload', ( event ) => {
		if ( area.value === saved ) {
			return;
		}
		event.preventDefault();
		event.returnValue = '';
	} );
	if ( save ) {
		save.addEventListener( 'click', () => {
			act( save.parentElement, () =>
				runAbility( 'kalaka/set-event-mail-layout', {
					event_id: area.dataset.kalakaLayout,
					html: area.value,
				} )
			);
		} );
	}
}

if ( config ) {
	document.querySelectorAll( '[data-kalaka-switch]' ).forEach( bindSwitch );
	document.querySelectorAll( '[data-kalaka-choose]' ).forEach( bindChoose );
	document.querySelectorAll( '[data-kalaka-new]' ).forEach( bindNew );
	document.querySelectorAll( '[data-kalaka-remove]' ).forEach( bindRemove );
	document.querySelectorAll( '[data-kalaka-batch]' ).forEach( bindBatch );
	document.querySelectorAll( '[data-kalaka-clear-event]' ).forEach( bindClear );
	document.querySelectorAll( '[data-kalaka-layout]' ).forEach( bindLayout );
}
