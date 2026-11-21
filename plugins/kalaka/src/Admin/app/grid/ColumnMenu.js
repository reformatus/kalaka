/**
 * The menu of a column, opened by the arrow in its sum cell (owner's walk, topic 6a G10, round 3). DataViews'
 * own column header menu has no way to add items, so this is a WordPress `DropdownMenu` beside it: the same
 * sort commands as the header menu, through the view state, and for a column that can count members the
 * counting choices, as native menu checkbox items. Arrow keys, Escape and focus return are the menu's own.
 * A column with nothing to offer shows a disabled arrow. [UNAPPROVED N27]
 */
import { DropdownMenu, MenuGroup, MenuItem, MenuItemsChoice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
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
			icon="arrow-down-alt2"
			label={ label }
			popoverProps={ { placement: 'bottom-end' } }
			toggleProps={ { size: 'compact', variant: 'tertiary', accessibleWhenDisabled: true, disabled: ! sortable && ! counts && ! children } }
		>
			{ () => (
				<>
					{ sortable && (
						<MenuGroup>
							<MenuItemsChoice
								value={ direction }
								onSelect={ ( next ) => onSort( { field: column.column, direction: next } ) }
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
