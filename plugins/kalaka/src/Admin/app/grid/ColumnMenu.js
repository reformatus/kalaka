/**
 * The menu of a column, opened by the arrow in its sum cell (owner's walk, topic 6a G10, round 3). DataViews'
 * own column header menu has no way to add items, so this is a WordPress `DropdownMenu` beside it: the same
 * sort commands as the header menu, through the view state, and for a column that can count members the
 * counting choices, as native menu checkbox items (WordPress's own checkable menus: `role="menuitemcheckbox"`,
 * `isSelected` and the `check` icon on the chosen ones). Arrow keys, Escape and focus return are the menu's own;
 * a sort click closes the menu, as the native header menu does; a counting toggle keeps it open, so several
 * members can be ticked in a row. A column with nothing to offer shows a disabled arrow. [UNAPPROVED N27, N68]
 *
 * It is the one column menu: the arrow opens it, and so does a click or key on the column's header (the grid
 * intercepts DataViews' own header menu, which has no way to be extended, and opens this one instead), then
 * anchored to the header. DataViews' header menu had the sort commands and „Add filter”; both are here. Its move,
 * hide and insert commands are not carried over: the grid turns them off. [UNAPPROVED N943]
 */
import { DropdownMenu, MenuGroup, MenuItem, MenuItemsChoice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { check, chevronDown, funnel } from '@wordpress/icons';
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
 * @param {boolean}  [props.filterable] the column has a filter to add (DataViews' „Add filter”)
 * @param {boolean}  [props.filtered]   the view already has a filter on the column (it is added once)
 * @param {Function} [props.onAddFilter] called with the column
 * @param {boolean}  [props.open]       the controlled open state (the header opens the menu too)
 * @param {Function} [props.onOpenChange] called with the next open state
 * @param {Element}  [props.anchor]     where the menu opens when it was opened from the header
 * @param {*}        [props.children] more groups, between the sort and the counting
 */
export default function ColumnMenu( {
	column,
	view,
	onSort,
	chosen,
	onToggle,
	filterable = false,
	filtered = false,
	onAddFilter,
	open,
	onOpenChange,
	anchor,
	children,
} ) {
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
			popoverProps={ anchor ? { placement: 'bottom-start', anchor } : { placement: 'bottom-end' } }
			toggleProps={ {
				size: 'small',
				variant: 'tertiary',
				accessibleWhenDisabled: true,
				'data-kg-menu': column.column,
				disabled: ! sortable && ! counts && ! filterable && ! children,
			} }
			{ ...( open === undefined ? {} : { open, onToggle: onOpenChange } ) }
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
					{ filterable && (
						<MenuGroup>
							<MenuItem
								icon={ funnel }
								disabled={ filtered }
								accessibleWhenDisabled
								onClick={ () => {
									onAddFilter( column );
									onClose();
								} }
							>
								{ __( 'Add filter' ) }
							</MenuItem>
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
