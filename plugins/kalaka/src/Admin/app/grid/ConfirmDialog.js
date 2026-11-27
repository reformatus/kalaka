/**
 * The manual confirmation dialog (S20): the optional reason, for setting the flag or revoking it
 * (`kalaka/set-manual-confirmation`, `kalaka/unconfirm`). The reason field has no length limit of its own (the check
 * on submit counts characters, as the server does). A failed save keeps the dialog and the reason, and
 * while it runs the dialog does not close (Esc, ×, Mégse) and a second submit is ignored: an unconfirm is a new
 * act every time (it is not idempotent, see `api.js`), so a double click must not send it twice. [UNAPPROVED N74 reboxed: the unconfirm gets a retry key on the server
 * (Backend's work); #213's unconfirm input takes none yet, so this guard stays and nothing is sent from here]
 * The unconfirm has no refusal. The dialog has no warning notice: when the rules would
 * confirm the registration again at once the unconfirm button is disabled instead, with the server's text as
 * visible help text under it ([UNAPPROVED N71 reboxed] [UNAPPROVED N78 reboxed: the reason is the button's
 * description and a visible paragraph] [UNAPPROVED N82 reboxed: moot]). It says nothing
 * about what unconfirming does: that text needs a concept the owner has not worded. [UNAPPROVED N33 reboxed]
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Modal, Notice, TextareaControl } from '@wordpress/components';
import * as api from './api';
import { validateReason } from './confirmation';
import { CONFIRM, MONEY } from './texts';

/**
 * @param {Object}   props
 * @param {Object}   props.config  window.kalakaGrid
 * @param {boolean}  props.unconfirm the unconfirm dialog (else the set dialog)
 * @param {string}   props.code    the registration's code
 * @param {Function} props.onClose closes the dialog
 * @param {Function} props.onDone  called with (unconfirm, the ability's answer) once it is saved; it re-reads and
 *                                 decides the snackbar
 */
export default function ConfirmDialog( { config, unconfirm, code, onClose, onDone } ) {
	const [ reason, setReason ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ failed, setFailed ] = useState( null ); // the failure text, or null
	const [ busy, setBusy ] = useState( false );
	const sending = useRef( false ); // the guard against a second submit before the state has re-rendered
	const form = useRef();

	// Focus lands on the field, not on the dialog frame.
	useEffect( () => {
		const timer = setTimeout( () => {
			const input = form.current && form.current.querySelector( 'textarea' );
			if ( input ) {
				input.focus();
			}
		}, 60 );
		return () => clearTimeout( timer );
	}, [] );

	const submit = async ( event ) => {
		event.preventDefault();
		if ( busy || sending.current ) {
			return;
		}
		setFailed( null );
		const found = validateReason( reason );
		setError( found );
		if ( found ) {
			setTimeout( () => {
				const input = form.current && form.current.querySelector( 'textarea' );
				if ( input ) {
					input.focus();
				}
			}, 50 );
			return;
		}
		sending.current = true;
		setBusy( true );
		try {
			const result = await api.setManualConfirmation( config, code, reason.trim(), unconfirm );
			await onDone( unconfirm, result );
		} catch {
			// Whatever the server refused or lost, the dialog stays with the reason.
			sending.current = false;
			setBusy( false );
			setFailed( MONEY.saveFail );
		}
	};

	return (
		<Modal
			title={ unconfirm ? CONFIRM.unconfirm : CONFIRM.set }
			onRequestClose={ () => ! sending.current && onClose() }
			size="medium"
			className="kg-dialog"
			shouldCloseOnClickOutside={ false }
		>
			<form onSubmit={ submit } noValidate ref={ form }>
				<TextareaControl
					label={ CONFIRM.reasonField }
					value={ reason }
					onChange={ setReason }
					rows={ 3 }
					aria-invalid={ error ? 'true' : undefined }
					help={ error && <span className="kg-err">{ error }</span> }
					__nextHasNoMarginBottom
				/>
				{ failed && (
					<Notice status="error" isDismissible={ false }>
						{ failed }
					</Notice>
				) }
				<div className="kg-dialog-actions">
					<Button variant="primary" type="submit" isBusy={ busy } aria-disabled={ busy }>
						{ MONEY.save }
					</Button>
					<Button variant="tertiary" onClick={ onClose } disabled={ busy }>
						{ MONEY.cancel }
					</Button>
				</div>
			</form>
		</Modal>
	);
}
