/**
 * The bulk-action slot: one button per DataViews action that `supportsBulk`, next to „Táblázat másolása”. DataViews'
 * `actions` stays the registry; a later action (confirmation, deletion) plugs in the same way. An action acts on the
 * ticked rows, or, with nothing ticked, on every row the current filters and search leave, across all pages
 * (`selectionOf`, `grid/selection.js`); with nothing ticked and nothing matching the button is disabled. The rows
 * and the selection are taken when the button is pressed, so a refresh under an open dialog does not change what it
 * acts on. An action with `RenderModal` opens it in a modal, with `items` (the ticked rows, none for „all matching”)
 * and `selection`; one with `callback` is called with the rows.
 */
import { useState } from '@wordpress/element';
import { Button, Modal } from '@wordpress/components';

export default function BulkSlot( { actions, rows, selectionOf, total = 0 } ) {
	const [ opened, setOpened ] = useState( null );
	const bulk = actions.filter( ( action ) => action.supportsBulk );
	if ( bulk.length === 0 ) {
		return null;
	}
	const itemsOf = ( action ) =>
		rows.filter( ( row ) => ! action.isEligible || action.isEligible( row ) );
	const press = ( action ) => {
		const items = itemsOf( action );
		const selection = selectionOf ? selectionOf( items ) : { codes: items.map( ( row ) => row.key ) };
		if ( action.RenderModal ) {
			setOpened( { action, items, selection } );
		} else if ( action.callback ) {
			action.callback( items, { selection } );
		}
	};
	const close = () => setOpened( null );
	// The label never changes with the state (nothing ticked, filtered, ticked): the action bar's count prefix says
	// what the button acts on.
	const labelOf = ( action ) => ( typeof action.label === 'function' ? action.label( rows ) : action.label );
	return (
		<>
			{ bulk.map( ( action ) => (
				<Button
					key={ action.id }
					variant="secondary"
					size="compact"
					onClick={ () => press( action ) }
					disabled={ itemsOf( action ).length === 0 && ( rows.length > 0 || total === 0 ) }
					accessibleWhenDisabled
				>
					{ labelOf( action ) }
				</Button>
			) ) }
			{ opened && (
				<Modal
					title={ opened.action.modalHeader || opened.action.label }
					onRequestClose={ close }
					focusOnMount="firstContentElement"
				>
					<opened.action.RenderModal items={ opened.items } selection={ opened.selection } closeModal={ close } />
				</Modal>
			) }
		</>
	);
}
