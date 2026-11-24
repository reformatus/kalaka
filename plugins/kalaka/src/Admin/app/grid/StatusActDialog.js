/**
 * One status act's dialog (S39 on #247): the title, what will happen, for admit and restore the preview's
 * warning when a seat would go over the limit, and the reason field. A failed act keeps the dialog and the
 * reason and shows the server's text; while it runs the dialog does not close and a second submit is ignored.
 * The money effect of withdraw and remove is not here: #247's preview carries none. [UNAPPROVED N702]
 * [UNAPPROVED N706] [UNAPPROVED N707] [UNAPPROVED N708] [UNAPPROVED N709]
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Modal, Notice, TextareaControl } from '@wordpress/components';
import * as api from './api';
import {
	bodyOf,
	failureOf,
	goesOver,
	reasonProblem,
	reasonRequired,
	staleAfter,
	takesSeats,
	titleOf,
} from './statusActs';
import { CONFIRM, MONEY, STATUS_ACTS } from './texts';

/**
 * @param {Object}   props
 * @param {Object}   props.config  window.kalakaGrid
 * @param {string}   props.code    the registration's code
 * @param {string}   props.act     `admit`, `withdraw`, `remove` or `restore`
 * @param {Function} props.onClose closes the dialog
 * @param {Function} props.onDone  called with the act once it is saved
 * @param {Function} props.reload  re-reads the panel's detail; called after a refusal that says it is stale
 */
export default function StatusActDialog( { config, code, act, onClose, onDone, reload } ) {
	const [ reason, setReason ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ failed, setFailed ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ preview, setPreview ] = useState( null );
	const sending = useRef( false );
	const form = useRef();
	const required = reasonRequired( act );

	const focusField = ( wait ) =>
		setTimeout( () => {
			const input = form.current && form.current.querySelector( 'textarea' );
			if ( input ) {
				input.focus();
			}
		}, wait );

	useEffect( () => {
		const timer = focusField( 60 );
		return () => clearTimeout( timer );
	}, [] );

	// Admit and restore take seats again: ask what they would do. A failed preview shows nothing: the act's own
	// answer decides. [UNAPPROVED N706]
	useEffect( () => {
		let live = true;
		if ( takesSeats( act ) ) {
			api.runStatusAct( config, act, code, '', true ).then(
				( result ) => live && setPreview( result ),
				() => {}
			);
		}
		return () => {
			live = false;
		};
	}, [ config, act, code ] );

	const submit = async ( event ) => {
		event.preventDefault();
		if ( busy || sending.current ) {
			return;
		}
		setFailed( null );
		const found = reasonProblem( act, reason );
		setError( found );
		if ( found ) {
			focusField( 50 );
			return;
		}
		sending.current = true;
		setBusy( true );
		try {
			await api.runStatusAct( config, act, code, reason.trim() );
			await onDone( act );
		} catch ( failure ) {
			sending.current = false;
			setBusy( false );
			setFailed( failureOf( failure ) );
			if ( staleAfter( failure ) ) {
				reload();
			}
		}
	};

	return (
		<Modal
			title={ titleOf( act ) }
			onRequestClose={ () => ! sending.current && onClose() }
			size="medium"
			className="kg-dialog"
			shouldCloseOnClickOutside={ false }
		>
			<form onSubmit={ submit } noValidate ref={ form }>
				<p>{ bodyOf( act ) }</p>
				{ goesOver( preview ) && (
					<Notice status="warning" isDismissible={ false }>
						{ STATUS_ACTS.full }
					</Notice>
				) }
				{ act === 'admit' && <p>{ STATUS_ACTS.serialEnd }</p> }
				<TextareaControl
					label={ CONFIRM.reasonField }
					value={ reason }
					onChange={ setReason }
					rows={ 3 }
					aria-invalid={ error ? 'true' : undefined }
					help={ error ? <span className="kg-err">{ error }</span> : required ? undefined : STATUS_ACTS.reasonOptional }
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
