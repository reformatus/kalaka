/**
 * The site's „Kaláka › Levelezés” page, the one the paused banner names: whether sending runs or is paused (the
 * cause, since when, by whom, the stored error and the delivery that caused it), the errors in a row, the hourly
 * budget, the pending count, the buttons the read's `can` allows (pause, resume, clear the queue, with a confirm for
 * the clear) and each event's counts with a clear of its held mail. The banner itself is the site warning, which
 * shows on this page like on every admin page. [UNAPPROVED N281] [UNAPPROVED N282] [UNAPPROVED N283] [UNAPPROVED N284]
 */
import { Fragment, useMemo, useRef, useState } from '@wordpress/element';
import { Button, Notice, SnackbarList } from '@wordpress/components';
import * as api from './api';
import ConfirmModal from './ConfirmModal';
import ListTable from './ListTable';
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
				getValue: ( { item } ) => ( names[ item.event_id ] ? names[ item.event_id ].name : fmt( SENDER.unknownEvent, item.event_id ) ),
				render: ( { item } ) =>
					names[ item.event_id ] ? (
						<a href={ names[ item.event_id ].url }>{ names[ item.event_id ].name }</a>
					) : (
						fmt( SENDER.unknownEvent, item.event_id )
					),
				enableHiding: false,
			},
			{
				id: 'held',
				label: COUNTS.published,
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
					item.held && item.queued > 0 ? (
						<Button
							variant="secondary"
							size="compact"
							isDestructive
							onClick={ () => setConfirm( { event: item.event_id, count: item.queued } ) }
						>
							{ CONTROLS.clearEvent }
						</Button>
					) : null,
				enableHiding: false,
				enableSorting: false,
			},
		],
		[ config.events ]
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
	const rows = [
		[ SENDER.errorsInRow, String( data.errors_in_row ) ],
		[ SENDER.budget, String( data.hourly_budget ) ],
		[ SENDER.queued, String( data.queued ) ],
	];
	return (
		<div className="km-page">
			<section className="km-section" aria-labelledby="km-state">
				<h2 id="km-state" className="km-h2">
					{ paused ? ( paused.cause === 'errors' ? SENDER.pausedErrors : SENDER.pausedManual ) : SENDER.running }
				</h2>
				<dl className="km-dl">
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
										{ ( () => {
											const event = names[ paused.delivery.event_id ];
											const text = fmt(
												SENDER.deliveryOf,
												paused.delivery.id,
												event ? event.name : fmt( SENDER.unknownEvent, paused.delivery.event_id )
											);
											return event ? <a href={ event.url + '&tab=troubled' }>{ text }</a> : text;
										} )() }
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
					{ COUNTS.event }
				</h2>
				<ListTable
					data={ data.events }
					fields={ fields }
					getId={ ( item ) => String( item.event_id ) }
					empty={ <p className="km-empty">{ COUNTS.none }</p> }
				/>
			</section>
			{ confirm && (
				<ConfirmModal
					title={ confirm.queue ? CONTROLS.clearQueue : CONTROLS.clearEvent }
					text={ fmt( confirm.queue ? CONTROLS.clearQueueConfirm : CONTROLS.clearEventConfirm, confirm.count ) }
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
