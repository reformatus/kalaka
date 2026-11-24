/**
 * The answer dialog (slice B): yes or no for a request, for an aid "yes" the amount awarded, and an optional
 * note (`kalaka/answer-request`). A failed save keeps the dialog and what was typed, and while it runs the
 * dialog does not close. A refused "yes" over the limit is not refused: the warning stands above the buttons and
 * the answer may be given. [UNAPPROVED N7]
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Modal, Notice, RadioControl, TextControl, TextareaControl } from '@wordpress/components';
import * as api from './api';
import { asksAmount, buildAnswer, savedText, validateAnswer, warnsFull } from './requests';
import { MONEY, REQUESTS } from './texts';

/**
 * @param {Object}   props
 * @param {Object}   props.config  window.kalakaGrid
 * @param {string}   props.code    the registration's code
 * @param {Object}   props.item    the request as the read gives it
 * @param {Function} props.onClose closes the dialog
 * @param {Function} props.onDone  called with the snackbar text once it is saved; empty when nothing changed
 */
export default function AnswerDialog( { config, code, item, onClose, onDone } ) {
	const [ answer, setAnswer ] = useState( '' );
	const [ amount, setAmount ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ failed, setFailed ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const sending = useRef( false );
	const form = useRef();

	// Focus lands on the first field, not on the dialog frame, as the panel's other dialogs.
	useEffect( () => {
		const timer = setTimeout( () => {
			const input = form.current && form.current.querySelector( 'input' );
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
		const found = answer === '' ? REQUESTS.answerEmpty : validateAnswer( item, answer, amount );
		setError( found );
		if ( found ) {
			// After React has drawn the message: focus the field it is about (the choice, or the amount).
			setTimeout( () => {
				const bad = form.current && form.current.querySelector( answer === '' ? 'input' : '[aria-invalid="true"]' );
				if ( bad ) {
					bad.focus();
				}
			}, 50 );
			return;
		}
		sending.current = true;
		setBusy( true );
		try {
			const result = await api.answerRequest( buildAnswer( config.event, code, item, answer, amount, note ) );
			await onDone( savedText( result ) );
		} catch {
			sending.current = false;
			setBusy( false );
			setFailed( true );
		}
	};

	return (
		<Modal
			title={ REQUESTS.dialogTitle }
			onRequestClose={ () => ! sending.current && onClose() }
			size="medium"
			className="kg-dialog"
			shouldCloseOnClickOutside={ false }
		>
			<form ref={ form } onSubmit={ submit } noValidate>
				<p>{ item.label }</p>
				<RadioControl
					label={ REQUESTS.answerField }
					selected={ answer }
					options={ [
						{ value: 'yes', label: REQUESTS.yes },
						{ value: 'no', label: REQUESTS.no },
					] }
					onChange={ setAnswer }
					help={ answer === '' && error ? <span className="kg-err">{ error }</span> : undefined }
				/>
				{ asksAmount( item, answer ) && (
					<TextControl
						label={ REQUESTS.amountField }
						value={ amount }
						onChange={ setAmount }
						inputMode="decimal"
						aria-invalid={ answer !== '' && error ? 'true' : undefined }
						help={ answer !== '' && error && <span className="kg-err">{ error }</span> }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				) }
				<TextareaControl
					label={ REQUESTS.noteField }
					value={ note }
					onChange={ setNote }
					rows={ 3 }
					maxLength={ 500 }
					__nextHasNoMarginBottom
				/>
				{ warnsFull( item, answer ) && (
					<Notice status="warning" isDismissible={ false }>
						{ REQUESTS.full }
					</Notice>
				) }
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
