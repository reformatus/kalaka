/**
 * The status as the grid shows it: its icon and its word. The panel's header uses the same mark (S38).
 */
export const STATUS_ICON = {
	registered: [ 'dashicons-yes-alt', 'kg-ok' ],
	waitlisted: [ 'dashicons-clock', 'kg-warn' ],
	withdrawn: [ 'dashicons-undo', 'kg-off' ],
	removed: [ 'dashicons-dismiss', 'kg-off' ],
};

export default function StatusMark( { status, text } ) {
	const [ icon, tone ] = STATUS_ICON[ status ] || [ '', '' ];
	return (
		<span className="kg-mark">
			<span className={ `dashicons ${ icon } ${ tone }` } aria-hidden="true" />
			{ text }
		</span>
	);
}
