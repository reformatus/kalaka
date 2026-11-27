/**
 * The answer dialog (slice B): yes or no for a request, for an aid "yes" the amount awarded, and a note, optional
 * for every answer (a "yes" to an offer included: owner 2026-11-26, N985). A failed save keeps the dialog and what
 * was typed, and while it runs the dialog does not close. A refused "yes" over the limit is not refused: the warning
 * stands above the buttons and the answer may be given. [UNAPPROVED N7] [UNAPPROVED N352]
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Modal, Notice, RadioControl, TextControl, TextareaControl } from '@wordpress/components';
import * as api from './api';
import {
	aidFigures,
	asksAmount,
	buildAnswer,
	hintedAmount,
	savedText,
	validateAnswer,
	validateNote,
	warnsFull,
} from './requests';
import { formatMoney } from './money';
import { MONEY, REQUESTS } from './texts';

/**
 * @param {Object}   props
 * @param {Object}   props.config   window.kalakaGrid
 * @param {string}   props.code     the registration's code
 * @param {string}   props.currency the read's currency
 * @param {Object}   props.item     the request as the read gives it
 * @param {Function} props.onClose closes the dialog
 * @param {Function} props.onDone  called with the snackbar text once it is saved; empty when nothing changed
 */
export default function AnswerDialog( { config, code, currency, item, onClose, onDone } ) {
	const [ answer, setAnswer ] = useState( '' );
	const [ amount, setAmount ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	// One message per field, as the record dialog: { answer, amount, note }.
	const [ errors, setErrors ] = useState( {} );
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
		const found = {};
		if ( answer === '' ) {
			found.answer = REQUESTS.answerEmpty;
		} else {
			const amountMessage = validateAnswer( item, answer, amount );
			if ( amountMessage ) {
				found.amount = amountMessage;
			}
		}
		const noteMessage = validateNote( item, answer, note );
		if ( noteMessage ) {
			found.note = noteMessage;
		}
		setErrors( found );
		if ( Object.keys( found ).length ) {
			// After React has drawn the messages: focus the first field with one (the choice, the amount, the note).
			setTimeout( () => {
				const bad = form.current && form.current.querySelector( found.answer ? 'input' : '[aria-invalid="true"]' );
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

	const fieldError = ( name ) => errors[ name ] && <span className="kg-err">{ errors[ name ] }</span>;

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
					onChange={ ( value ) => {
						// Another choice starts afresh for the choice and the amount; the note's message stays.
						setAnswer( value );
						// A "yes" to an aid hints the amount asked for (owner 2026-11-27, walkthrough 6.8); the
						// read carries it only for a holder with the money fields. [UNAPPROVED T8]
						setAmount( asksAmount( item, value ) ? hintedAmount( item ) : '' );
						setErrors( ( now ) => ( now.note ? { note: now.note } : {} ) );
					} }
					help={ fieldError( 'answer' ) }
				/>
				{ asksAmount( item, answer ) && aidFigures( item ).length > 0 && (
					<ul className="kg-aid">
						{ aidFigures( item ).map( ( figure ) => (
							<li key={ figure.key }>
								{ figure.label }: { formatMoney( figure.value, currency ) }
							</li>
						) ) }
					</ul>
				) }
				{ asksAmount( item, answer ) && (
					<TextControl
						label={ REQUESTS.amountField }
						value={ amount }
						onChange={ setAmount }
						inputMode="decimal"
						autoComplete="off"
						aria-invalid={ errors.amount ? 'true' : undefined }
						help={ fieldError( 'amount' ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				) }
				<TextareaControl
					// The note is always „Megjegyzés”, never a reason, for every request kind (owner 2026-11-27,
					// walkthrough 6.8). [UNAPPROVED N1183]
					label={ REQUESTS.noteField }
					value={ note }
					onChange={ setNote }
					rows={ 3 }
					aria-invalid={ errors.note ? 'true' : undefined }
					help={ fieldError( 'note' ) }
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
