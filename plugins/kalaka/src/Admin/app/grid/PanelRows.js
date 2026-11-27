/**
 * The detail panel's two state rows, each a state with its mark and, under it, a slot for that state's buttons:
 * the status row and the confirmation row. A branch above fills a slot by passing children; with none the slot
 * is not rendered. [UNAPPROVED N1200]
 */
import StatusMark from './StatusMark';

/**
 * @param {Object}  props
 * @param {string}  props.status   the status key (`registered`, ...), for the mark's icon
 * @param {string}  props.text     the status word
 * @param {*}       props.children the status-act buttons (none on this branch)
 */
export function StatusRow( { status, text, children } ) {
	return (
		<div className="kg-panel-row kg-panel-status">
			<StatusMark status={ status } text={ text } />
			{ children && <div className="kg-panel-slot">{ children }</div> }
		</div>
	);
}

/**
 * @param {Object}  props
 * @param {boolean} props.confirmed whether the registration is confirmed
 * @param {string}  props.text      the state word
 * @param {*}       props.children  the confirmation button (#218 fills it)
 */
export function ConfirmationRow( { confirmed, text, children } ) {
	return (
		<div className="kg-panel-row kg-panel-confirmation">
			<span className="kg-mark">
				<span
					className={ 'dashicons ' + ( confirmed ? 'dashicons-yes-alt kg-ok' : 'dashicons-marker kg-off' ) }
					aria-hidden="true"
				/>
				{ text }
			</span>
			{ children && <div className="kg-panel-slot">{ children }</div> }
		</div>
	);
}
