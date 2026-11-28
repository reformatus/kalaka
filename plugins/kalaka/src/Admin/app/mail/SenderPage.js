/**
 * The site's „Kaláka › Levelezés” page, the one the paused banner names: whether sending runs or is paused (the
 * cause, since when, by whom, the stored error and the delivery that caused it), the errors in a row, the hourly
 * budget as text (it is set in the PHP settings form below this app), the pending count, the buttons the read's `can` allows (pause, resume, clear the queue, with a confirm for
 * the clear) and each event's counts with a clear of its held mail. The banner itself is the site warning, which
 * shows on this page like on every admin page.
 *
 * Fix pass 1: the queue lists are here and nowhere else (the owner's ruling of 2026-11-07: „Levelezés … holds mail
 * settings, controls and the queue; the queue lives only there”): below the counts each event with failed, waiting-
 * after-an-error or stuck deliveries has its list, with the manual mail follow-up on the failed rows.
 */
import { Fragment, useMemo, useRef, useState } from '@wordpress/element';
import { Button, Notice, SnackbarList } from '@wordpress/components';
import * as api from './api';
import ConfirmModal from './ConfirmModal';
import ListTable from './ListTable';
import Troubled from './Troubled';
import useRead from './useRead';
import { moment, problemText } from './format';
import { fmt } from '../grid/format';
import { SNACKBAR_MS, withNotice, withoutNotice } from '../grid/snack';
import { COMMON, CONTROLS, COUNTS, SENDER } from './texts';

export default function SenderPage( { config } ) {
	const read = useRead( config.rest, '/mail' );
	const [ confirm, setConfirm ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ failed, setFailed ] = useState( null );
	const [ notices, setNotices ] = useState( [] );
	const timers = useRef( [] );
	const names = Object.fromEntries( config.events.map( ( e ) => [ e.id, e ] ) );
	const eventsById = Object.fromEntries( ( read.data ? read.data.events : [] ).map( ( e ) => [ e.event_id, e ] ) );
	const eventName = ( id ) => ( eventsById[ id ] && eventsById[ id ].name ? eventsById[ id ].name : fmt( SENDER.unknownEvent, id ) );
	const say = ( text ) => {
		const id = String( Date.now() ) + Math.random();
		setNotices( ( list ) => withNotice( list, id, text, 'success' ) );
		timers.current.push( setTimeout( () => setNotices( ( list ) => withoutNotice( list, id ) ), SNACKBAR_MS ) );
	};
	const run = async ( act, text ) => {
		if ( busy ) {
			return;
		}
		setBusy( true );
		setFailed( null );
		try {
			const answer = await act();
			say( typeof text === 'function' ? text( answer ) : text );
		} catch ( error ) {
			// A refusal shows alone: the success snackbar of an earlier act goes away with it. [UNAPPROVED N909]
			setNotices( [] );
			setFailed( problemText( error, COMMON.actFail ) );
		}
		await read.reload();
		setBusy( false );
	};

	const fields = useMemo(
		() => [
			{
				id: 'event',
				label: COUNTS.event,
				getValue: ( { item } ) => item.name || fmt( SENDER.unknownEvent, item.event_id ),
				render: ( { item } ) =>
					names[ item.event_id ] ? (
						<a href={ names[ item.event_id ].url }>{ item.name || fmt( SENDER.unknownEvent, item.event_id ) }</a>
					) : (
						item.name || fmt( SENDER.unknownEvent, item.event_id )
					),
				enableHiding: false,
			},
			{
				id: 'held',
				label: COUNTS.publication,
				getValue: ( { item } ) => ( item.held ? COUNTS.held : COUNTS.published ),
				enableHiding: false,
			},
			{ id: 'queued', label: COUNTS.queued, type: 'integer', getValue: ( { item } ) => item.queued, enableHiding: false },
			{ id: 'sent', label: COUNTS.sent, type: 'integer', getValue: ( { item } ) => item.sent, enableHiding: false },
			{ id: 'failed', label: COUNTS.failed, type: 'integer', getValue: ( { item } ) => item.failed, enableHiding: false },
			{ id: 'with_error', label: COUNTS.withError, type: 'integer', getValue: ( { item } ) => item.with_error, enableHiding: false },
			{ id: 'stuck', label: COUNTS.stuck, type: 'integer', getValue: ( { item } ) => item.stuck_sending, enableHiding: false },
			{
				id: 'clear',
				label: '',
				header: <span className="screen-reader-text">{ CONTROLS.clearEvent }</span>,
				getValue: () => '',
				render: ( { item } ) =>
					item.can && item.can.clear_event ? (
						<Button
							variant="secondary"
							size="compact"
							isDestructive
							aria-label={ fmt( CONTROLS.clearEventFor, eventName( item.event_id ) ) }
							onClick={ () => setConfirm( { event: item.event_id, count: item.queued } ) }
						>
							{ CONTROLS.clearEvent }
						</Button>
					) : null,
				enableHiding: false,
				enableSorting: false,
			},
		],
		// The names come from the read, which arrives after the first render: the fields follow it.
		[ config.events, read.data ]
	);

	if ( read.loading && ! read.data ) {
		return <p>{ COMMON.loading }</p>;
	}
	if ( ! read.data ) {
		return (
			<p className="km-fail" role="alert">
				{ COMMON.loadFail }
			</p>
		);
	}
	const data = read.data;
	const paused = data.paused;
	// Events whose deliveries need a look: failed, waiting after an error, or stuck in „sending”.
	const troubled = data.events.filter( ( row ) => row.failed + row.with_error + row.stuck_sending > 0 );
	const rows = [
		[ SENDER.errorsInRow, String( data.errors_in_row ) ],
	];
	return (
		<div className="km-page">
			<section className="km-section" aria-labelledby="km-state">
				<h2 id="km-state" className="km-h2">
					{ SENDER.state }
				</h2>
				<dl className="km-dl">
					<dt>{ SENDER.stateLabel }</dt>
					<dd>{ paused ? ( paused.cause === 'errors' ? SENDER.pausedErrors : SENDER.pausedManual ) : SENDER.running }</dd>
					{ paused && (
						<Fragment>
							<dt>{ SENDER.since }</dt>
							<dd>{ moment( paused.since, config.timezone ) }</dd>
							<dt>{ SENDER.by }</dt>
							<dd>{ paused.by ? paused.by.name || String( paused.by.id ) : SENDER.system }</dd>
							{ paused.delivery && (
								<Fragment>
									<dt>{ SENDER.delivery }</dt>
									<dd>
										<a href={ `#km-troubled-${ paused.delivery.event_id }` }>
											{ fmt( SENDER.deliveryOf, paused.delivery.id, eventName( paused.delivery.event_id ) ) }
										</a>
									</dd>
								</Fragment>
							) }
							{ paused.error && (
								<Fragment>
									<dt>{ SENDER.error }</dt>
									<dd>
										<span className="km-stored">{ paused.error }</span>
									</dd>
								</Fragment>
							) }
						</Fragment>
					) }
					{ rows.map( ( [ label, value ] ) => (
						<Fragment key={ label }>
							<dt>{ label }</dt>
							<dd>{ value }</dd>
						</Fragment>
					) ) }
					{ /* The stored budget as text, for every viewer, after the errors in a row and before the pending count; it is
					set in the settings form below the page's app (PHP, WordPress's settings saving). [UNAPPROVED N900, N902] */ }
					<dt>{ SENDER.budget }</dt>
					<dd>
						{ String( data.hourly_budget ) }
						<span className="km-budget-default">{ ' (' + fmt( SENDER.budgetDefaultInline, data.hourly_budget_default ) + ')' }</span>
					</dd>
					<dt>{ SENDER.queued }</dt>
					<dd>{ String( data.queued ) }</dd>
				</dl>
				{ failed && (
					<Notice status="error" isDismissible={ false }>
						{ failed }
					</Notice>
				) }
				<div className="km-actions">
					{ data.can.pause && (
						<Button variant="secondary" disabled={ busy } onClick={ () => run( api.pause, ( a ) => ( a ? CONTROLS.paused : CONTROLS.alreadyPaused ) ) }>
							{ CONTROLS.pause }
						</Button>
					) }
					{ data.can.resume && (
						<Button variant="primary" disabled={ busy } onClick={ () => run( api.resume, ( a ) => ( a ? CONTROLS.resumed : CONTROLS.notPaused ) ) }>
							{ CONTROLS.resume }
						</Button>
					) }
					{ data.can.clear_queue && (
						<Button variant="secondary" isDestructive disabled={ busy } onClick={ () => setConfirm( { queue: true, count: data.queued } ) }>
							{ CONTROLS.clearQueue }
						</Button>
					) }
				</div>
			</section>
			<section className="km-section" aria-labelledby="km-counts">
				<h2 id="km-counts" className="km-h2">
					{ COUNTS.section }
				</h2>
				<ListTable
					data={ data.events }
					fields={ fields }
					getId={ ( item ) => String( item.event_id ) }
					empty={ <p className="km-empty">{ COUNTS.none }</p> }
				/>
			</section>
			{ troubled.length > 0 && (
				<section className="km-section" aria-labelledby="km-troubled">
					<h2 id="km-troubled" className="km-h2">
						{ SENDER.troubled }
					</h2>
					{ troubled.map( ( row ) => (
						<div key={ row.event_id } id={ `km-troubled-${ row.event_id }` }>
							<h3 className="km-h3">{ eventName( row.event_id ) }</h3>
							<Troubled config={ { ...config, event: row.event_id } } version={ row.event_id } />
						</div>
					) ) }
				</section>
			) }
			{ confirm && (
				<ConfirmModal
					title={ confirm.queue ? CONTROLS.clearQueue : CONTROLS.clearEvent }
					text={
						confirm.queue
							? fmt( CONTROLS.clearQueueConfirm, confirm.count )
							: fmt( CONTROLS.clearEventConfirm, confirm.count, eventName( confirm.event ) )
					}
					label={ CONTROLS.confirm }
					onClose={ () => setConfirm( null ) }
					onConfirm={ async () => {
						const deleted = await ( confirm.queue ? api.clearQueue() : api.clearEvent( confirm.event ) );
						say( fmt( CONTROLS.cleared, deleted ) );
						await read.reload();
					} }
				/>
			) }
			<div className="km-snacks">
				<SnackbarList
					notices={ notices }
					onRemove={ ( id ) => setNotices( ( list ) => withoutNotice( list, id ) ) }
				/>
			</div>
		</div>
	);
}
