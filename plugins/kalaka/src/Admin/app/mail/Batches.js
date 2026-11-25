/**
 * The „Csoportos levelek” tab: the event's past manual mail batches from the batch's own record (who sent which body
 * when, how many were queued and skipped, and per registration code the outcome). Whether the queued ones were then
 * sent or failed is not in the record (Backend's N550). [UNAPPROVED N294]
 */
import { Fragment, useMemo, useState } from '@wordpress/element';
import { Button, Modal } from '@wordpress/components';
import ListTable from './ListTable';
import useRead from './useRead';
import { moment, outcomeWord } from './format';
import { fmt } from '../grid/format';
import { BATCHES, COMMON, WHO } from './texts';

function Members( { config, id, onClose } ) {
	const read = useRead( config.rest, `/events/${ config.event }/mail/batches/${ id }` );
	return (
		<Modal title={ fmt( BATCHES.open, id ) } onRequestClose={ onClose } size="medium">
			{ read.loading && ! read.data && <p>{ COMMON.loading }</p> }
			{ ! read.data && ! read.loading && <p role="alert">{ COMMON.loadFail }</p> }
			{ read.data && (
				<Fragment>
					<p>
						<strong>{ read.data.subject === null ? BATCHES.deleted : read.data.subject }</strong>
					</p>
					<h2 className="km-h2">{ BATCHES.members }</h2>
					<ul className="km-members">
						{ ( read.data.members || [] ).map( ( member ) => (
							<li key={ member.code }>
								<span className="km-code-text">{ member.code }</span>{ ' – ' }
								{ outcomeWord( member.outcome ) }
							</li>
						) ) }
					</ul>
				</Fragment>
			) }
			<div className="km-actions">
				<Button variant="tertiary" onClick={ onClose }>
					{ COMMON.close }
				</Button>
			</div>
		</Modal>
	);
}

export default function Batches( { config, version } ) {
	const read = useRead( config.rest, `/events/${ config.event }/mail/batches` );
	const [ open, setOpen ] = useState( null );
	const fields = useMemo(
		() => [
			{
				id: 'id',
				label: BATCHES.id,
				type: 'integer',
				getValue: ( { item } ) => item.id,
				render: ( { item } ) => (
					<Button variant="link" onClick={ () => setOpen( item.id ) } aria-label={ fmt( BATCHES.open, item.id ) }>
						{ item.id }
					</Button>
				),
				enableHiding: false,
			},
			{
				id: 'subject',
				label: BATCHES.subject,
				getValue: ( { item } ) => item.subject || BATCHES.deleted,
				enableHiding: false,
			},
			{
				id: 'created_at',
				label: BATCHES.created,
				getValue: ( { item } ) => item.created_at,
				render: ( { item } ) => moment( item.created_at, config.timezone ),
				enableHiding: false,
			},
			{
				id: 'by',
				label: BATCHES.by,
				getValue: ( { item } ) => ( item.created_by ? item.created_by.name || String( item.created_by.id ) : WHO.nobody ),
				enableHiding: false,
			},
			{ id: 'queued', label: BATCHES.queued, type: 'integer', getValue: ( { item } ) => item.queued, enableHiding: false },
			{ id: 'skipped', label: BATCHES.skipped, type: 'integer', getValue: ( { item } ) => item.skipped, enableHiding: false },
			{
				id: 'layout',
				label: BATCHES.layout,
				getValue: ( { item } ) => ( item.layout ? COMMON.yes : COMMON.no ),
				enableHiding: false,
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
				empty={ <p className="km-empty">{ BATCHES.none }</p> }
			/>
			{ open !== null && <Members config={ config } id={ open } onClose={ () => setOpen( null ) } /> }
		</Fragment>
	);
}
