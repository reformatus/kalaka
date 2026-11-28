/**
 * The registrant's withdrawal confirmation on the status page (R370): the button's form opens a WordPress
 * Modal instead of loading the page's confirm address, and the page is not re-rendered. Every text, the
 * money line included, and the post's address come from the server as data on the form (`data-kalaka-withdraw`,
 * `StatusMarkup`); this file holds no string and decides nothing. Without script the form is a plain GET to
 * the confirm address, which shows the same confirmation as a section. The confirming button is a plain POST
 * of the same form the confirm section holds (to the route, which answers with a 303 back to the page).
 *
 * Like the claim popup (`Forms/Blocks/form/claim.js`), the other front-end Modal, the dialog is marked
 * `aria-modal` and every button takes WordPress's 40 px size. A classic script on `wp-element` and
 * `wp-components` (an extra entry of `webpack.config.js`), enqueued by `ParticipantBlocks` only where the
 * status block renders a withdraw button.
 */
import { Button, Modal } from '@wordpress/components';
import { createElement as el, createRoot } from '@wordpress/element';

/**
 * As the claim popup's `markModal`: WordPress's Modal hides the rest of the page from screen readers
 * (`aria-hidden`) but does not mark its dialog `aria-modal`; this ref, on the dialog's own content inside
 * the Modal, adds it.
 */
function markModal( content ) {
	content?.closest( '[role="dialog"]' )?.setAttribute( 'aria-modal', 'true' );
}

/** The dialog's content: the money line, the post's hidden fields and the two buttons. */
export function Dialog( { data, onClose } ) {
	return el(
		Modal,
		{ title: data.question, onRequestClose: onClose },
		el(
			'form',
			{ method: 'post', action: data.action, ref: markModal },
			data.money ? el( 'p', { className: 'kalaka-withdraw-money' }, data.money ) : null,
			...Object.entries( data.fields ).map( ( [ name, value ] ) =>
				el( 'input', { key: name, type: 'hidden', name, value } )
			),
			el(
				'div',
				{ className: 'kalaka-withdraw-actions', style: { display: 'flex', flexWrap: 'wrap', gap: '0.75em' } },
				el( Button, { variant: 'primary', type: 'submit', __next40pxDefaultSize: true }, data.confirm ),
				el( Button, { variant: 'secondary', type: 'button', onClick: onClose, __next40pxDefaultSize: true }, data.cancel )
			)
		)
	);
}

/** Opens the Modal for one start form; the form's own submit is the no-script path and does not run. */
export function open( form ) {
	let data;
	try {
		data = JSON.parse( form.dataset.kalakaWithdraw );
	} catch {
		return false;
	}
	if ( ! data || typeof data.question !== 'string' || ! data.fields ) {
		return false;
	}
	const host = document.createElement( 'div' );
	document.body.appendChild( host );
	const root = createRoot( host );
	const onClose = () => {
		root.unmount();
		host.remove();
	};
	root.render( el( Dialog, { data, onClose } ) );

	return true;
}

/** Takes over the start forms of the page; one that cannot open keeps its plain GET. */
export function init() {
	document.querySelectorAll( 'form[data-kalaka-withdraw]' ).forEach( ( form ) => {
		form.addEventListener( 'submit', ( event ) => {
			if ( open( form ) ) {
				event.preventDefault();
			}
		} );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
