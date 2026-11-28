/**
 * The counting menu of a column, opened by clicking the number (or the grey placeholder) in its sum cell.
 * DataViews' own column header menu stays exactly as it is (owner, 2026-11-27); this menu holds only the
 * counting, no DataViews control. The number is the trigger, the whole cell is a generous click target, and
 * there is no down arrow. A tick keeps the menu open, so several members can be ticked in a row; a click
 * anywhere else or the pointer leaving the menu closes it.
 */
import { DropdownMenu, MenuGroup, MenuItem } from '@wordpress/components';
import { check } from '@wordpress/icons';
import { fmt } from './format';
import { SUM } from './texts';

/**
 * @param {Object}   props
 * @param {Object}   props.column    the tab's column (it carries `members` when it can count)
 * @param {string}   [props.shown]   the sum as the cell shows it; absent when nothing counts yet
 * @param {Array}    props.chosen    the counted members now
 * @param {Function} props.onToggle  called with `( column, member, on )`
 */
export default function ColumnMenu( { column, shown, chosen, onToggle } ) {
	const label = fmt( SUM.open, column.label );
	return (
		<DropdownMenu
			className="kg-sumcount"
			icon={ null }
			label={ label }
			text={ shown || '–' }
			toggleProps={ {
				className: 'kg-sumcount-btn' + ( shown ? '' : ' is-empty' ),
				size: 'small',
				variant: 'tertiary',
			} }
			popoverProps={ { placement: 'bottom-end', shift: true } }
		>
			{ ( { onClose } ) => (
				<div
					className="kg-sumcount-menu"
					onMouseLeave={ ( event ) => {
						// A keyboard user inside the menu must not lose focus to a stray pointer.
						if ( ! event.currentTarget.contains( document.activeElement ) ) {
							onClose();
						}
					} }
				>
					<MenuGroup label={ SUM.counting }>
						{ column.members.map( ( member ) => {
							const on = chosen.includes( member.value );
							return (
								<MenuItem
									key={ member.value }
									role="menuitemcheckbox"
									icon={ on ? check : null }
									isSelected={ on }
									aria-checked={ on }
									onClick={ () => onToggle( column, member.value, ! on ) }
								>
									{ member.label }
								</MenuItem>
							);
						} ) }
					</MenuGroup>
				</div>
			) }
		</DropdownMenu>
	);
}
