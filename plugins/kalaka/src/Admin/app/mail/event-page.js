/**
 * The event's „Levelek” page's dynamic parts (section 8): the switch, the choose dialog, the X that removes a
 * point's template, the two new-template buttons, the failures' and the group mails' windows, the clear of the
 * event's queued mail and the levélkeret save. The page itself is PHP's (`Admin\Mail\EventPage`); this file only binds
 * to what it rendered (`data-kalaka-…` attributes) and, after an act, reloads the page so it shows the server's
 * answer. A refused act's text shows on a line of its own: under the row in a table, else under the control's line;
 * a refused switch goes back to where it was. The server decides every call; nothing here holds a rule.
 * [UNAPPROVED N1307]
 */
import { createRoot, useEffect, useRef, useState } from '@wordpress/element';
import { Button, Modal, SelectControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { runAbility } from '../grid/api';
import { fmt } from '../grid/format';
import CodeName from './CodeName';
import { problemText } from './format';
import { BATCHES, BODIES } from './texts';
import './event-page.scss';

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

/**
 * Shows a refused act's text near its control: in a table, on a full-width line right under the control's row; else
 * on a line under the control's own line. A second refusal replaces the first.
 */
export function showError( control, text ) {
	const row = control.closest( 'tr' );
	const anchor = row || control.closest( 'p, .kalaka-mail-heading, li' ) || control;
	const next = anchor.nextElementSibling;
	if ( next && next.classList.contains( 'kalaka-mail-error' ) ) {
		next.remove();
	}
	const notice = document.createElement( 'div' );
	notice.className = 'notice notice-error inline';
	notice.setAttribute( 'role', 'alert' );
	const line = document.createElement( 'p' );
	line.textContent = text;
	notice.appendChild( line );
	let holder = notice;
	if ( row ) {
		holder = document.createElement( 'tr' );
		const cell = document.createElement( 'td' );
		cell.colSpan = row.children.length;
		cell.appendChild( notice );
		holder.appendChild( cell );
	}
	holder.classList.add( 'kalaka-mail-error' );
	anchor.after( holder );
}

/** Runs an act, then reloads; a failure shows the server's text, else the generic one. */
async function act( control, run, undo ) {
	try {
		await run();
		window.location.reload();
	} catch ( error ) {
		if ( undo ) {
			undo();
		}
		showError( control, problemText( error, config.texts.actFail ) );
	}
}

/** The toggle's look follows its input (the components' toggle draws its state from the wrapper's classes). */
function paint( input ) {
	const toggle = input.closest( '.components-form-toggle' );
	if ( toggle ) {
		toggle.classList.toggle( 'is-checked', input.checked );
		toggle.classList.toggle( 'is-disabled', input.disabled );
	}
}

/** The switch: the read's `enabled`, the server's own refusals decide whether it may move. */
export function bindSwitch( input ) {
	input.addEventListener( 'change', () => {
		const on = input.checked;
		input.disabled = true;
		paint( input );
		act(
			input,
			() =>
				runAbility( 'kalaka/update-action-mail', {
					event_id: config.event,
					mail: input.dataset.kalakaSwitch,
					enabled: on,
				} ),
			() => {
				input.checked = ! on;
				input.disabled = false;
				paint( input );
			}
		);
	} );
}

/** „Sablon kiválasztása”: a WordPress Modal with the event's published templates. */
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
							act( button, () =>
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

/** A new draft through `path`, then its editor opens. */
function bindCreate( button, path ) {
	button.addEventListener( 'click', async () => {
		button.disabled = true;
		try {
			const answer = await apiFetch( { path, method: 'POST' } );
			if ( answer && answer.edit_url ) {
				window.location.href = answer.edit_url;
				return;
			}
			button.disabled = false;
		} catch ( error ) {
			button.disabled = false;
			showError( button, problemText( error, config.texts.actFail ) );
		}
	} );
}

/** „Új sablon” on a point: the route makes a draft, assigns it and switches the point off. */
function bindNew( button ) {
	bindCreate(
		button,
		`${ config.rest }/events/${ config.event }/mail/points/${ button.dataset.kalakaNew }/template`
	);
}

/** „Új sablon” at „Hozzá nem rendelt sablonok”: a draft of the event with no point. [UNAPPROVED N1304] */
function bindNewUnassigned( button ) {
	bindCreate( button, `${ config.rest }/events/${ config.event }/mail/templates` );
}

/** The X: the same ability with `body_id: null`; the server switches the point off. */
function bindRemove( button ) {
	button.addEventListener( 'click', () => {
		act( button, () =>
			runAbility( 'kalaka/update-action-mail', {
				event_id: config.event,
				mail: button.dataset.kalakaRemove,
				body_id: null,
			} )
		);
	} );
}

/** A small table of the page's look: one row per item, each cell with its column's name for a phone. */
function Table( { columns, rows } ) {
	return (
		<table className="kalaka-mail-table">
			<thead>
				<tr>
					{ columns.map( ( column, index ) => (
						<th key={ index } scope="col">
							{ column }
						</th>
					) ) }
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row ) => (
					<tr key={ row.key }>
						{ row.cells.map( ( cell, index ) =>
							columns[ index ] ? (
								<td key={ index } data-colname={ columns[ index ] }>
									<span>{ cell }</span>
								</td>
							) : (
								<td key={ index } className="kalaka-mail-acts">
									{ cell }
								</td>
							)
						) }
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/** One read of the window's route: null while loading, `{ error }` on a failure. */
function useWindowRead( path ) {
	const [ data, setData ] = useState( null );
	useEffect( () => {
		if ( ! path ) {
			return;
		}
		setData( null );
		apiFetch( { path } ).then( setData, ( error ) => setData( { error } ) );
	}, [ path ] );
	return data;
}

function ReadState( { data } ) {
	if ( ! data ) {
		return <p>{ config.texts.loading }</p>;
	}
	return <p>{ problemText( data.error, config.texts.actFail ) }</p>;
}

/**
 * The failures' window: the event's deliveries that need a look, each by code and name (the route reads the name
 * through the viewer's view), with the mail, the status and the stored error; the full list and its follow-up stay on
 * the „Levelezés” page, which the window links. [UNAPPROVED N1299 N1305]
 */
export function TroubledWindow( { close } ) {
	const data = useWindowRead( `${ config.rest }/events/${ config.event }/mail/troubled` );
	const texts = config.texts;
	return (
		<Modal title={ texts.troubledTitle } onRequestClose={ close } size="large">
			<div className="kalaka-mail-window">
				{ Array.isArray( data ) ? (
					<Table
						columns={ texts.troubledColumns }
						rows={ data.map( ( item ) => ( {
							key: item.id,
							cells: [
								<CodeName key="code" code={ item.code } name={ item.name } />,
								item.label ||
									( item.batch_id ? fmt( texts.batchManual, item.batch_id ) : item.mail ),
								texts.deliveryStatus[ item.status ] || item.status,
								<span key="error" className="kalaka-mail-stored">
									{ item.error || '' }
								</span>,
							],
						} ) ) }
					/>
				) : (
					<ReadState data={ data } />
				) }
				<p>
					<a href={ config.urls.sender }>{ texts.senderLink }</a>
				</p>
			</div>
			<div className="kalaka-modal-actions">
				<Button variant="primary" onClick={ close }>
					{ texts.close }
				</Button>
			</div>
		</Modal>
	);
}

/** One group mail's members, by code and name, with each one's outcome. */
function Members( { id } ) {
	const data = useWindowRead( `${ config.rest }/events/${ config.event }/mail/batches/${ id }` );
	if ( ! data || data.error || ! data.members ) {
		return <ReadState data={ data } />;
	}
	return (
		<Table
			columns={ config.texts.memberColumns }
			rows={ data.members.map( ( member ) => ( {
				key: member.code,
				cells: [
					<CodeName key="code" code={ member.code } name={ member.name } />,
					BATCHES[ 'outcome_' + member.outcome ] || member.outcome,
				],
			} ) ) }
		/>
	);
}

/**
 * The group mails' window: the list, newest first; a row's „Részletek” shows that mail's members in the same window,
 * with a way back to the list. [UNAPPROVED N1298 N1305]
 */
export function BatchesWindow( { close } ) {
	const [ open, setOpen ] = useState( null );
	const last = useRef( null );
	const frame = useRef( null );
	const texts = config.texts;
	// The view changes inside one window: the focus goes to the back button, and back to the row's button it came
	// from, so the keyboard (and Escape) stays in the window.
	useEffect( () => {
		const host = frame.current;
		if ( ! host ) {
			return;
		}
		const target = open
			? host.querySelector( '[data-kalaka-back]' )
			: last.current && host.querySelector( `[data-kalaka-batch-row="${ last.current }"]` );
		if ( target ) {
			target.focus();
		}
	}, [ open ] );
	return (
		<Modal
			title={ open ? fmt( texts.batchTitle, open ) : texts.batchesTitle }
			onRequestClose={ close }
			size="large"
		>
			<div className="kalaka-mail-window" ref={ frame }>
				{ open ? (
					<>
						<p>
							<Button
								variant="secondary"
								size="compact"
								data-kalaka-back
								onClick={ () => setOpen( null ) }
							>
								{ texts.back }
							</Button>
						</p>
						<Members id={ open } />
					</>
				) : (
					<Table
						columns={ [ ...texts.batchColumns, null ] }
						rows={ config.batches.map( ( batch ) => ( {
							key: batch.id,
							cells: [
								batch.id,
								batch.subject,
								batch.queued,
								batch.skipped,
								<Button
									key="open"
									variant="secondary"
									size="compact"
									aria-label={ fmt( texts.batchTitle, batch.id ) }
									data-kalaka-batch-row={ batch.id }
									onClick={ () => {
										last.current = batch.id;
										setOpen( batch.id );
									} }
								>
									{ texts.details }
								</Button>,
							],
						} ) ) }
					/>
				) }
			</div>
			<div className="kalaka-modal-actions">
				<Button variant="primary" onClick={ close }>
					{ texts.close }
				</Button>
			</div>
		</Modal>
	);
}

function bindWindow( button, Window ) {
	button.addEventListener( 'click', () => {
		openModal( ( close ) => <Window close={ close } /> );
	} );
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
							act( button, () =>
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
			act( save, () =>
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
	document.querySelectorAll( '[data-kalaka-new-unassigned]' ).forEach( bindNewUnassigned );
	document.querySelectorAll( '[data-kalaka-remove]' ).forEach( bindRemove );
	document.querySelectorAll( '[data-kalaka-troubled]' ).forEach( ( button ) => bindWindow( button, TroubledWindow ) );
	document.querySelectorAll( '[data-kalaka-batches]' ).forEach( ( button ) => bindWindow( button, BatchesWindow ) );
	document.querySelectorAll( '[data-kalaka-clear-event]' ).forEach( bindClear );
	document.querySelectorAll( '[data-kalaka-layout]' ).forEach( bindLayout );
}
