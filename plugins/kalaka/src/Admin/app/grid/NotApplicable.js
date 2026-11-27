/**
 * A „–” (not applicable) with a visually hidden spoken text, so a screen reader tells it from „Nem”: the grid's
 * awaits cell and an inactive request's state. [UNAPPROVED N1236] [UNAPPROVED T1225]
 */
import { Fragment } from '@wordpress/element';
import { VisuallyHidden } from '@wordpress/components';
import { NOT_APPLICABLE_SPOKEN } from './texts';

export default function NotApplicable( { text } ) {
	return (
		<Fragment>
			<span aria-hidden="true">{ text }</span>
			<VisuallyHidden>{ NOT_APPLICABLE_SPOKEN }</VisuallyHidden>
		</Fragment>
	);
}
