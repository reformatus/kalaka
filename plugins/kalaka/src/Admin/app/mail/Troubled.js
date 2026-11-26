/**
 * One event's failed deliveries, on the „Levelezés” page (the owner's ruling: the queue lists live there only): the event's deliveries that need a look (failed, pending with the error of an
 * earlier attempt, stuck in „sending”), by registration code and, when the read gives one, the name (the viewer's
 * held view decides: `name` is null without name access). The error
 * is shown as the server stored it. The one follow-up is a manual mail to that code, the same dialog as the grid's.
 * Only a failed delivery offers the follow-up: a mail that still waits (with the error of an earlier attempt) or is
 * stuck in „sending” may yet go out, so a second copy would be a duplicate. [UNAPPROVED N285] [UNAPPROVED N286]
 */
import { Fragment, useMemo, useRef, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import CodeName from './CodeName';
import ListTable from './ListTable';
import ManualMailDialog from './ManualMailDialog';
import { pageKeys } from './manual';
import useRead from './useRead';
import { deliveryStatus, moment } from './format';
import { codeName, fmt } from '../grid/format';
import { COMMON, TROUBLED } from './texts';

export default function Troubled( { config, version } ) {
	const read = useRead( config.rest, `/events/${ config.event }/mail/troubled` );
	const [ sending, setSending ] = useState( null );
	const keys = useRef( pageKeys( config.event ) );
	const fields = useMemo(
		() => [
			{
				id: 'code',
				label: TROUBLED.code,
				getValue: ( { item } ) => codeName( item.code, item.name ),
				// The code in its mark, as the batch members and the dialog's lists show it. [UNAPPROVED N949]
				render: ( { item } ) => <CodeName code={ item.code } name={ item.name } />,
				enableHiding: false,
			},
			{
				id: 'mail',
				label: TROUBLED.mail,
				getValue: ( { item } ) => item.label || fmt( TROUBLED.manual, item.batch_id || 0 ),
				enableHiding: false,
			},
			{ id: 'status', label: TROUBLED.status, getValue: ( { item } ) => deliveryStatus( item.status ), enableHiding: false },
			{ id: 'attempts', label: TROUBLED.attempts, type: 'integer', getValue: ( { item } ) => item.attempts, enableHiding: false },
			{
				id: 'queued_at',
				label: TROUBLED.queued,
				getValue: ( { item } ) => item.queued_at,
				render: ( { item } ) => moment( item.queued_at, config.timezone ),
				enableHiding: false,
			},
			{
				id: 'last_attempt_at',
				label: TROUBLED.lastAttempt,
				getValue: ( { item } ) => item.last_attempt_at || '',
				render: ( { item } ) => ( item.last_attempt_at ? moment( item.last_attempt_at, config.timezone ) : COMMON.none ),
				enableHiding: false,
			},
			{
				id: 'error',
				label: TROUBLED.error,
				getValue: ( { item } ) => item.error || '',
				render: ( { item } ) => <span className="km-stored">{ item.error || COMMON.none }</span>,
				enableHiding: false,
				enableSorting: false,
			},
			{
				id: 'send',
				label: '',
				header: <span className="screen-reader-text">{ TROUBLED.send }</span>,
				getValue: () => '',
				render: ( { item } ) =>
					item.status === 'failed' ? (
						<Button variant="secondary" size="compact" onClick={ () => setSending( item.code ) } aria-label={ fmt( TROUBLED.sendFor, codeName( item.code, item.name ) ) }>
							{ TROUBLED.send }
						</Button>
					) : null,
				enableHiding: false,
				enableSorting: false,
			},
		],
		[ config.timezone ]
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
	return (
		<Fragment>
			<ListTable
				key={ version }
				data={ read.data }
				fields={ fields }
				getId={ ( item ) => String( item.id ) }
				styles={ { error: { minWidth: '18em' } } }
				empty={ <p className="km-empty">{ TROUBLED.none }</p> }
			/>
			{ sending && (
				<ManualMailDialog
					config={ config }
					selection={ { codes: [ sending ] } }
					keys={ keys.current }
					onClose={ () => setSending( null ) }
					onSent={ () => read.reload() }
				/>
			) }
		</Fragment>
	);
}
