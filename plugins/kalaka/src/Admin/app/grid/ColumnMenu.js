/**
 * The menu of a column, opened by the arrow in its sum cell (owner's walk, topic 6a G10, round 3). DataViews'
 * own column header menu has no way to add items, so this is a WordPress `DropdownMenu` beside it: the same
 * sort commands as the header menu, through the view state, and for a column that can count members the
 * counting choices, as native menu checkbox items (WordPress's own checkable menus: `role="menuitemcheckbox"`,
 * `isSelected` and the `check` icon on the chosen ones). Arrow keys, Escape and focus return are the menu's own;
 * a sort click closes the menu, as the native header menu does; a counting toggle keeps it open, so several
 * members can be ticked in a row. A column with nothing to offer shows a disabled arrow.
 * [UNAPPROVED N27, N68]
 */
import { DropdownMenu, MenuGroup, MenuItem, MenuItemsChoice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { check, chevronDown } from '@wordpress/icons';
import { fmt } from './format';
import { COLUMN, SUM } from './texts';

/** The sort hint a column's sort options carry (only Kód has one: it sorts by the order of the applications). */
const hints = ( column ) =>
	column.column === 'code' ? { asc: COLUMN.codeAsc, desc: COLUMN.codeDesc } : {};

/**
 * @param {Object}   props
 * @param {Object}   props.column    the tab's column
 * @param {Object}   props.view      DataViews' view state
 * @param {Function} props.onSort    called with `{ field, direction }`
 * @param {Array}    props.chosen    the counted members now (for a column that counts)
 * @param {Function} props.onToggle  called with `( column, member, on )`
 * @param {*}        [props.children] more groups, between the sort and the counting
 */
export default function ColumnMenu( { column, view, onSort, chosen, onToggle, children } ) {
	const sortable = column.sortable !== false;
	const counts = !! ( column.members && column.members.length );
	const label = fmt( SUM.open, column.label );
	const direction = view.sort && view.sort.field === column.column ? view.sort.direction : null;
	const hint = hints( column );
	return (
		<DropdownMenu
			className="kg-sumopen"
			icon={ chevronDown }
			label={ label }
			popoverProps={ { placement: 'bottom-end', shift: true } }
			toggleProps={ { size: 'small', variant: 'tertiary', accessibleWhenDisabled: true, disabled: ! sortable && ! counts && ! children } }
		>
			{ ( { onClose } ) => (
				<>
					{ sortable && (
						<MenuGroup>
							<MenuItemsChoice
								value={ direction }
								onSelect={ ( next ) => {
									onSort( { field: column.column, direction: next } );
									onClose();
								} }
								choices={ [
									{ value: 'asc', label: __( 'Sort ascending' ), info: hint.asc },
									{ value: 'desc', label: __( 'Sort descending' ), info: hint.desc },
								] }
							/>
						</MenuGroup>
					) }
					{ children }
					{ counts && (
						<MenuGroup label={ SUM.counting }>
							{ column.members.map( ( member ) => (
								<MenuItem
									key={ member.value }
									role="menuitemcheckbox"
									icon={ chosen.includes( member.value ) ? check : null }
									isSelected={ chosen.includes( member.value ) }
									aria-checked={ chosen.includes( member.value ) }
									onClick={ () => onToggle( column, member.value, ! chosen.includes( member.value ) ) }
								>
									{ member.label }
								</MenuItem>
							) ) }
						</MenuGroup>
					) }
				</>
			) }
		</DropdownMenu>
	);
}
