/**
 * The registrations a send skips (withdrawn or removed), in the dialog and in its answer: only the per-status totals
 * („Kihagyva: 1 törölt, 1 lemondott”), and a text button that opens their list in a popup of its own. The button's label
 * never changes with the state. [UNAPPROVED N1033] [UNAPPROVED T970] [UNAPPROVED T971] [UNAPPROVED T972]
 */
import { useState } from '@wordpress/element';
import { Button, Modal } from '@wordpress/components';
import CodeName from './CodeName';
import { reasonWord, skippedTotals } from './format';
import { fmt } from '../grid/format';
import { COMMON, MANUAL } from './texts';

/**
 * @param {Object}   props
 * @param {Object[]} props.skipped `{ code, reason }` of each one skipped
 * @param {Function} props.nameOf  the name of a code, or null
 */
export default function Skipped( { skipped, nameOf } ) {
	const [ open, setOpen ] = useState( false );
	if ( skipped.length === 0 ) {
		return null;
	}
	return (
		<>
			<p className="km-skipped">
				{ fmt( MANUAL.skippedTotals, skippedTotals( skipped ) ) }{ ' ' }
				{ /* `type="button"`: inside the dialog's form, a bare button would send it. */ }
				<Button variant="link" type="button" onClick={ () => setOpen( true ) }>
					{ MANUAL.skippedOpen }
				</Button>
			</p>
			{ open && (
				<Modal title={ MANUAL.skippedTitle } onRequestClose={ () => setOpen( false ) } size="medium">
					<ul className="km-members">
						{ skipped.map( ( s ) => (
							<li key={ s.code }>
								<CodeName code={ s.code } name={ nameOf( s.code ) } /> – { reasonWord( s.reason ) }
							</li>
						) ) }
					</ul>
					<div className="km-actions">
						<Button variant="tertiary" type="button" onClick={ () => setOpen( false ) }>
							{ COMMON.close }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}
