/**
 * The field list of the detail panel (B5-2 on #249), the „Adatok” tab (panel-tabs ruling 2026-11-26): the list has no heading of its own, the tab's name is it
 * [UNAPPROVED N1007]. While the last re-read failed (`stale`) the values are not shown, only the snackbar [UNAPPROVED N1037]: each
 * field the read lists with its text, and for a field the read calls `writable` (and a kind the panel has a
 * control for) an edit button that opens the dialog. Read-only fields have no button and no word: the panel shows
 * only what the read returns and never decides access. After a save the panel re-reads, the grid re-reads, a
 * snackbar says it was done and focus returns to the field's edit button. [UNAPPROVED N230] [UNAPPROVED N231]
 * [UNAPPROVED N232] [UNAPPROVED N237] [UNAPPROVED N243]
 *
 * [UNAPPROVED N1010] Not built: the visual grouping of a request's ask and answer fields, with the pointer to that
 * request on „Kérelmek”. The read does not say which fields belong to a request (`requests.items[]` has no field
 * keys, `fields[]` has no request id): a server shape is needed, see internal/docs/panel-tabs-prototype.md.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, SnackbarList } from '@wordpress/components';
import FieldEditDialog from './FieldEditDialog';
import { controlOf, fieldsOf, textOf } from './fieldEdit';
import { fmt } from './format';
import { SNACKBAR_MS, withNotice, withoutNotice } from './snack';
import { FIELDS } from './texts';

/**
 * @param {Object}   props
 * @param {Object}   props.config  window.kalakaGrid
 * @param {string}   props.code    the registration's code
 * @param {Object}   props.detail  the read
 * @param {boolean}  props.stale   the last re-read failed: no edit is offered
 * @param {Function} props.reload  re-reads the panel's detail; resolves when it is on screen
 * @param {Function} props.onSaved tells the grid something changed
 */
export default function FieldsBlock( {
	config,
	code,
	detail,
	stale,
	reload,
	onSaved,
} ) {
	const [ open, setOpen ] = useState( null ); // the field key being edited
	const [ notices, setNotices ] = useState( [] );
	const openers = useRef( {} );
	const timers = useRef( [] );
	const fields = fieldsOf( detail );

	useEffect(
		() => () => {
			timers.current.forEach( clearTimeout );
		},
		[]
	);
	if ( fields.length === 0 ) {
		return null;
	}

	const focusOpener = ( key ) =>
		window.requestAnimationFrame( () => {
			const button = openers.current[ key ];
			if ( button && button.isConnected ) {
				button.focus();
			}
		} );
	const close = () => {
		const key = open;
		setOpen( null );
		focusOpener( key );
	};
	// `{ changed: false }` (a value equal to the stored one) is done as well. [UNAPPROVED N238] The answer's
	// `over_limit` is not shown: nothing follows the save. [UNAPPROVED N240]
	const done = async () => {
		const key = open;
		await reload();
		onSaved();
		setOpen( null );
		const id = String( Date.now() );
		setNotices( ( list ) =>
			withNotice( list, id, FIELDS.saved, 'success' )
		);
		timers.current.push(
			setTimeout(
				() => setNotices( ( list ) => withoutNotice( list, id ) ),
				SNACKBAR_MS
			)
		);
		focusOpener( key );
	};
	// A re-read can drop a field's edit data (no longer writable): the dialog then closes instead of rendering
	// a field it cannot edit.
	const found = open && fields.find( ( field ) => field.key === open );
	const editing = found && controlOf( found ) !== null ? found : null;

	return (
		<div className="kg-fields">
			{ ! stale && (
				<dl className="kg-field-list">
					{ fields.map( ( field ) => {
						const text = textOf( field );
						return (
							<div className="kg-field" key={ field.key }>
								<dt>{ field.label }</dt>
								<dd>
									<span className="kg-field-value">
										{ text === '' ? FIELDS.empty : text }
									</span>
									{ controlOf( field ) !== null && (
										<Button
											variant="secondary"
											size="small"
											aria-label={ fmt(
												FIELDS.editLabel,
												field.label
											) }
											ref={ ( node ) => {
												openers.current[ field.key ] =
													node;
											} }
											onClick={ () =>
												setOpen( field.key )
											}
										>
											{ FIELDS.edit }
										</Button>
									) }
								</dd>
							</div>
						);
					} ) }
				</dl>
			) }
			{ editing && (
				<FieldEditDialog
					key={ editing.key }
					config={ config }
					code={ code }
					field={ editing }
					onClose={ close }
					onDone={ done }
					reload={ reload }
				/>
			) }
			<div className="kg-snacks">
				<SnackbarList
					notices={ notices }
					onRemove={ ( id ) =>
						setNotices( ( list ) => withoutNotice( list, id ) )
					}
				/>
			</div>
		</div>
	);
}
