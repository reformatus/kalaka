/**
 * The selection-only bulk-action slot: one button per DataViews action that `supportsBulk`, next to „Táblázat
 * másolása”. DataViews' `actions` stays the registry; a later action (confirmation, deletion) plugs in the same way.
 * An action acts on the selected rows only, so with nothing selected the buttons are disabled. The rows are taken
 * when the button is pressed, so a refresh under an open dialog does not change what it acts on. An action with
 * `RenderModal` opens it in a modal; one with `callback` is called with the rows. [UNAPPROVED N913] [UNAPPROVED N914]
 */
import { useState } from '@wordpress/element';
import { Button, Modal } from '@wordpress/components';

export default function BulkSlot( { actions, rows } ) {
	const [ opened, setOpened ] = useState( null );
	const bulk = actions.filter( ( action ) => action.supportsBulk );
	if ( bulk.length === 0 ) {
		return null;
	}
	const itemsOf = ( action ) =>
		rows.filter( ( row ) => ! action.isEligible || action.isEligible( row ) );
	const press = ( action ) => {
		const items = itemsOf( action );
		if ( action.RenderModal ) {
			setOpened( { action, items } );
		} else if ( action.callback ) {
			action.callback( items, {} );
		}
	};
	const close = () => setOpened( null );
	return (
		<>
			{ bulk.map( ( action ) => (
				<Button
					key={ action.id }
					variant="secondary"
					size="compact"
					onClick={ () => press( action ) }
					disabled={ itemsOf( action ).length === 0 }
					accessibleWhenDisabled
				>
					{ typeof action.label === 'function' ? action.label( rows ) : action.label }
				</Button>
			) ) }
			{ opened && (
				<Modal
					title={ opened.action.modalHeader || opened.action.label }
					onRequestClose={ close }
					focusOnMount="firstContentElement"
				>
					<opened.action.RenderModal items={ opened.items } closeModal={ close } />
				</Modal>
			) }
		</>
	);
}
