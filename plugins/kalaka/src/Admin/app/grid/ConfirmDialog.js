/**
 * The manual confirmation dialog (S20): the required reason, for setting the flag or revoking it
 * (`kalaka/set-manual-confirmation`, `kalaka/revoke-manual-confirmation`). A failed save keeps the dialog and
 * the reason, and while it runs the dialog does not close (Esc, ×, Mégse). The revoke dialog says nothing about what revoking does to the confirmation: that text needs a
 * concept the owner has not worded. [UNAPPROVED N33]
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Modal, Notice, TextareaControl } from '@wordpress/components';
import * as api from './api';
import { validateReason } from './confirmation';
import { CONFIRM, MONEY } from './texts';

/**
 * @param {Object}   props
 * @param {Object}   props.config  window.kalakaGrid
 * @param {boolean}  props.revoke  the revoke dialog (else the set dialog)
 * @param {string}   props.code    the registration's code
 * @param {Function} props.onClose closes the dialog
 * @param {Function} props.onDone  called with the snackbar text once it is saved
 */
export default function ConfirmDialog( { config, revoke, code, onClose, onDone } ) {
	const [ reason, setReason ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ failed, setFailed ] = useState( false );
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
		setFailed( false );
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
			await api.setManualConfirmation( config, code, reason.trim(), revoke );
			await onDone( revoke ? CONFIRM.revoked : CONFIRM.saved );
		} catch {
			// Whatever the server refused or lost, the dialog stays with the reason.
			sending.current = false;
			setBusy( false );
			setFailed( true );
		}
	};

	return (
		<Modal
			title={ revoke ? CONFIRM.revoke : CONFIRM.set }
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
					maxLength={ 500 }
					aria-invalid={ error ? 'true' : undefined }
					help={ error && <span className="kg-err">{ error }</span> }
					__nextHasNoMarginBottom
				/>
				{ failed && (
					<Notice status="error" isDismissible={ false }>
						{ MONEY.saveFail }
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
