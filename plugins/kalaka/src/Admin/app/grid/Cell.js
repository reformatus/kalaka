/**
 * A cell of the grid as shown (S37), and whether its row is inactive. Pure markup: no DataViews, so Jest renders it.
 */
import { cellText } from './format';
import { CONFIRMATION_KIND, markOf } from './confirmation';

const STATUS_ICON = {
	registered: [ 'dashicons-yes-alt', 'kg-ok' ],
	waitlisted: [ 'dashicons-clock', 'kg-warn' ],
	withdrawn: [ 'dashicons-undo', 'kg-off' ],
	removed: [ 'dashicons-dismiss', 'kg-off' ],
};

/** Whether a registration is inactive (waitlisted, withdrawn, removed); a row that carries no status is not. */
export const isInactive = ( item ) =>
	typeof item.values.status === 'string' && item.values.status !== 'registered';

/**
 * A cell as shown: the status with its icon, a decision as an icon with screen-reader text, the code in
 * monospace. Every cell of an inactive row carries `kg-inactive` (dimmed against DataViews' own text colour, which
 * an active row keeps), so a view that does not list Állapot shows no row dimmed. [UNAPPROVED N25]
 */
export default function Cell( { column, item } ) {
	const text = cellText( column, item );
	if ( text === '' ) {
		return '';
	}
	const tone = isInactive( item ) ? ' kg-inactive' : '';
	if ( column.type === 'status' ) {
		const [ icon, color ] = STATUS_ICON[ item.values.status ] || [ '', '' ];
		return (
			<span className={ `kg-mark${ tone }` }>
				<span className={ `dashicons ${ icon } ${ color }` } aria-hidden="true" />
				{ text }
			</span>
		);
	}
	if ( column.type === 'decision' ) {
		// The confirmation column draws its state with the panel's own marks; every other decision keeps the one
		// marker. [UNAPPROVED N912]
		const [ icon, color ] =
			column.column === `decision:${ CONFIRMATION_KIND }`
				? markOf( item.values[ column.column ] )
				: [ 'dashicons-marker', '' ];
		return (
			<span className={ `kg-mark${ tone }` } title={ text }>
				<span className={ `dashicons ${ icon } ${ color }`.trim() } aria-hidden="true" />
				<span className="screen-reader-text">{ text }</span>
			</span>
		);
	}
	if ( column.type === 'code' ) {
		return <span className={ `kg-code${ tone }` }>{ text }</span>;
	}
	if ( column.type === 'serial' ) {
		return <span className={ `kg-serial${ tone }` }>{ text }</span>;
	}
	if ( column.type === 'text' || column.type === 'choice' ) {
		return (
			<span className={ `kg-long${ tone }` } title={ text }>
				{ text }
			</span>
		);
	}
	return <span className={ tone.trim() || undefined }>{ text }</span>;
}
