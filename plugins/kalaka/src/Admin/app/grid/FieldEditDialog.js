/**
 * The dialog that edits one field's value (B5-2 on #249): the control the kind needs, opened on the read's
 * `edit.answer`, the "full" warning when the picked value is full (asked from the ability's preview, which needs
 * no reason: the text is the server's), the reason, and the save. The save goes through when a value is full:
 * placing is never stopped. A failed save keeps the dialog and the reason and
 * shows the server's text (a stored value changed by someone else is the 409's own text). While it runs the
 * dialog does not close and a second submit is ignored. [UNAPPROVED N233] [UNAPPROVED N234] [UNAPPROVED N235]
 * [UNAPPROVED N236] [UNAPPROVED N239] [UNAPPROVED N241]
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, CheckboxControl, Modal, Notice, RadioControl, TextareaControl, TextControl } from '@wordpress/components';
import * as api from './api';
import { validateReason } from './confirmation';
import { controlOf, inputOf, isFull, openingOf } from './fieldEdit';
import { failureOf, staleAfter, warningsOf } from './statusActs';
import { CONFIRM, GRID, MONEY } from './texts';

const NONE = '';

/**
 * @param {Object}   props
 * @param {Object}   props.config  window.kalakaGrid
 * @param {string}   props.code    the registration's code
 * @param {Object}   props.field   the read's `fields[]` item (writable, with `edit`)
 * @param {Function} props.onClose closes the dialog
 * @param {Function} props.onDone  called with the ability's answer once it is saved
 * @param {Function} props.reload  re-reads the panel's detail; called after a refusal that says it is stale
 */
export default function FieldEditDialog( { config, code, field, onClose, onDone, reload } ) {
	const control = controlOf( field );
	const [ picked, setPicked ] = useState( () => openingOf( field ) );
	const [ reason, setReason ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ failed, setFailed ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const sending = useRef( false );
	const form = useRef();
	const options = field.edit.options || [];
	const [ warnings, setWarnings ] = useState( [] );
	const full = isFull( field, picked );

	// The "full" warning is the preview's, asked when the picked value is a full one. A failed preview shows
	// nothing: the save's own answer decides. [UNAPPROVED N236]
	useEffect( () => {
		let live = true;
		setWarnings( [] );
		if ( full ) {
			api.editFieldValue( inputOf( config.event, code, field, picked, '', true ) ).then(
				( result ) => live && setWarnings( warningsOf( result ) ),
				() => {}
			);
		}
		return () => {
			live = false;
		};
	}, [ full, picked ] );

	// Focus lands on the first control, not on the dialog frame. [UNAPPROVED N241]
	useEffect( () => {
		const timer = setTimeout( () => {
			const input = form.current && form.current.querySelector( 'input:not([type="hidden"]), textarea' );
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
			const result = await api.editFieldValue( inputOf( config.event, code, field, picked, reason.trim() ) );
			await onDone( result );
		} catch ( failure ) {
			sending.current = false;
			setBusy( false );
			setFailed( failureOf( failure ) );
			if ( staleAfter( failure ) ) {
				reload();
			}
		}
	};

	const radios = [ { label: GRID.unanswered, value: NONE }, ...options.map( ( o ) => ( { label: o.label, value: o.value } ) ) ];
	return (
		<Modal
			title={ field.label }
			onRequestClose={ () => ! sending.current && onClose() }
			size="medium"
			className="kg-dialog"
			shouldCloseOnClickOutside={ false }
		>
			<form onSubmit={ submit } noValidate ref={ form }>
				{ ( control === 'text' || control === 'number' ) && (
					<TextControl
						label={ field.label }
						hideLabelFromVision
						value={ picked }
						onChange={ setPicked }
						type={ control === 'number' ? 'number' : 'text' }
						step={ control === 'number' ? 'any' : undefined }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				) }
				{ control === 'choice' && (
					<RadioControl
						label={ field.label }
						hideLabelFromVision
						selected={ picked }
						options={ radios }
						onChange={ setPicked }
					/>
				) }
				{ control === 'multi_choice' && (
					<fieldset className="kg-multi">
						<legend className="screen-reader-text">{ field.label }</legend>
						{ options.map( ( option ) => (
							<CheckboxControl
								key={ option.value }
								label={ option.label }
								checked={ picked.includes( option.value ) }
								onChange={ ( on ) =>
									setPicked( on ? picked.concat( option.value ) : picked.filter( ( v ) => v !== option.value ) )
								}
								__nextHasNoMarginBottom
							/>
						) ) }
					</fieldset>
				) }
				{ control === 'consent' && (
					<CheckboxControl label={ field.label } checked={ picked } onChange={ setPicked } __nextHasNoMarginBottom />
				) }
				{ warnings.map( ( text ) => (
					<Notice key={ text } status="warning" isDismissible={ false }>
						{ text }
					</Notice>
				) ) }
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
