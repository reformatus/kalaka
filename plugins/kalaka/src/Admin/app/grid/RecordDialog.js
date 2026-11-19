/**
 * The two recording dialogs of the „Pénzügyek” tab (S38): a manual payment (`kalaka/record-payment`) and the one
 * refund / correction dialog, which switches between a refund (`kalaka/record-refund`: a method and a date) and a
 * correction (`kalaka/record-correction`: a direction and a required reason, no method, no date). The amount is
 * never prefilled, the payer is asked only with several payers, and the balance before and after follows the
 * amount as it is typed. A failed save keeps the dialog and its values. [UNAPPROVED N14] [UNAPPROVED N15]
 * [UNAPPROVED N16]
 */
import { Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { Button, Modal, Notice, RadioControl, TextControl } from '@wordpress/components';
import * as api from './api';
import { MONEY } from './texts';
import { amountString, balanceAfter, balanceText, parseAmount, toMinor, today, validateEntry } from './money';

/** A fresh idempotency key for one entry (lower-case hex, the ability's pattern). */
function newKey() {
	const bytes = new Uint8Array( 16 );
	window.crypto.getRandomValues( bytes );
	return 'ui-' + Array.from( bytes, ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' );
}

/**
 * @param {Object}   props
 * @param {Object}   props.config  window.kalakaGrid
 * @param {boolean}  props.refund  the refund / correction dialog (else a payment)
 * @param {Object}   props.can     the read's `can`: which of a refund and a correction the holder may record
 * @param {string}   props.code    the registration's code
 * @param {Object}   props.money   the read's money part
 * @param {Function} props.onClose closes the dialog
 * @param {Function} props.onDone  called with the snackbar text once the entry is saved
 */
export default function RecordDialog( { config, refund, can, code, money, onClose, onDone } ) {
	const several = money.payers.length > 1;
	// A refund uses the same methods as a payment, the card terminal included (the owner's ruling 2026-11-19).
	const methods = money.methods;
	// The kinds this dialog may record: a payment, or a refund and/or a correction by the holder's grants.
	const kinds = refund ? [ can.record_refund && 'refund', can.record_correction && 'correction' ].filter( Boolean ) : [ 'payment' ];
	const [ kind, setKind ] = useState( kinds[ 0 ] );
	const [ direction, setDirection ] = useState( '' ); // a correction's direction: never defaulted
	const correction = kind === 'correction';
	const [ payer, setPayer ] = useState( several ? '' : money.payers[ 0 ].payer );
	const [ amount, setAmount ] = useState( '' ); // never prefilled
	const [ date, setDate ] = useState( () => today( config.timezone ) );
	const [ method, setMethod ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ errors, setErrors ] = useState( {} );
	const [ failed, setFailed ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const form = useRef();
	// One key per entry as sent: a retry of the same values reuses it, so a save that did reach the server is
	// not written twice; changed values take a new one. [UNAPPROVED N22]
	const attempt = useRef( { sig: null, key: null } );

	// Focus lands on the first field, not on the dialog frame.
	useEffect( () => {
		const timer = setTimeout( () => {
			const input = form.current && form.current.querySelector( 'input' );
			if ( input ) {
				input.focus();
			}
		}, 60 );
		return () => clearTimeout( timer );
	}, [] );

	const row = money.payers.find( ( p ) => p.payer === payer );
	const before = row ? toMinor( row.balance ) : null;
	// A refund and a correction out raise the balance; a payment and a correction in lower it.
	const after = balanceAfter( before, correction && ! direction ? null : parseAmount( amount ), kind === 'refund' || direction === 'out' );

	const submit = async ( event ) => {
		event.preventDefault();
		if ( busy ) {
			return;
		}
		setFailed( false );
		const found = validateEntry( { several, payer, amount, date, method, note, kind, direction }, today( config.timezone ) );
		setErrors( found );
		if ( Object.keys( found ).length ) {
			// After React has drawn the messages: focus the first invalid field.
			setTimeout( () => {
				const bad = form.current && form.current.querySelector( '[aria-invalid="true"], .kg-radio-bad input' );
				if ( bad ) {
					bad.focus();
				}
			}, 50 );
			return;
		}
		const entry = {
			kind,
			code,
			currency: money.currency,
			payer,
			amount: amountString( parseAmount( amount ) ),
			valueDate: correction ? null : date,
			method: correction ? null : method,
			direction: correction ? direction : null,
			note: note.trim(),
		};
		const sig = JSON.stringify( entry );
		if ( attempt.current.sig !== sig ) {
			attempt.current = { sig, key: newKey() };
		}
		setBusy( true );
		try {
			await api.recordEntry( config, { ...entry, key: attempt.current.key } );
			await onDone( { payment: MONEY.saved, refund: MONEY.savedRefund, correction: MONEY.savedCorrection }[ kind ] );
		} catch {
			// Whatever the server refused or lost, the dialog stays with the values. [UNAPPROVED N22]
			setBusy( false );
			setFailed( true );
		}
	};

	const fieldError = ( name ) => errors[ name ] && <span className="kg-err">{ errors[ name ] }</span>;
	return (
		<Modal
			title={ refund ? MONEY.recordRefund : MONEY.recordPay }
			onRequestClose={ () => ! busy && onClose() }
			size="medium"
			className="kg-dialog"
			shouldCloseOnClickOutside={ false }
		>
			<form onSubmit={ submit } noValidate ref={ form }>
				{ kinds.length > 1 && (
					<RadioControl
						label={ MONEY.kindField }
						selected={ kind }
						options={ kinds.map( ( k ) => ( { value: k, label: k === 'refund' ? MONEY.kindRefund : MONEY.kindCorrection } ) ) }
						onChange={ setKind }
					/>
				) }
				{ several && (
					<div className={ errors.payer ? 'kg-radio-bad' : '' }>
						<RadioControl
							label={ MONEY.payer }
							selected={ payer }
							options={ money.payers.map( ( p ) => ( { value: p.payer, label: p.label ?? p.payer } ) ) }
							onChange={ setPayer }
							help={ fieldError( 'payer' ) }
						/>
					</div>
				) }
				<TextControl
					label={ MONEY.amountField }
					value={ amount }
					onChange={ setAmount }
					inputMode="decimal"
					autoComplete="off"
					aria-invalid={ errors.amount ? 'true' : undefined }
					help={ fieldError( 'amount' ) }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				{ correction && (
					<div className={ errors.direction ? 'kg-radio-bad' : '' }>
						<RadioControl
							label={ MONEY.directionField }
							selected={ direction }
							options={ [
								{ value: 'in', label: MONEY.directionIn },
								{ value: 'out', label: MONEY.directionOut },
							] }
							onChange={ setDirection }
							help={ fieldError( 'direction' ) }
						/>
					</div>
				) }
				{ ! correction && (
					<Fragment>
						<TextControl
							label={ MONEY.dateField }
							type="date"
							max={ today( config.timezone ) }
							value={ date }
							onChange={ setDate }
							aria-invalid={ errors.value_date ? 'true' : undefined }
							help={ fieldError( 'value_date' ) }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
						<div className={ errors.method ? 'kg-radio-bad' : '' }>
							<RadioControl
								label={ MONEY.methodField }
								selected={ method }
								options={ methods.map( ( m ) => ( { value: m.value, label: m.label } ) ) }
								onChange={ setMethod }
								help={ fieldError( 'method' ) }
							/>
						</div>
					</Fragment>
				) }
				<TextControl
					label={ correction ? MONEY.reasonField : MONEY.noteField }
					value={ note }
					onChange={ setNote }
					autoComplete="off"
					aria-invalid={ errors.note ? 'true' : undefined }
					help={ fieldError( 'note' ) }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				{ row && (
					<dl className="kg-bal">
						<dt>{ MONEY.balanceNow }</dt>
						<dd>{ balanceText( before, money.currency ) }</dd>
						<dt>{ MONEY.balanceAfter }</dt>
						<dd aria-live="polite">
							{ after === null ? (
								<Fragment>
									<span aria-hidden="true">–</span>
									<span className="screen-reader-text">{ MONEY.noAmount }</span>
								</Fragment>
							) : (
								balanceText( after, money.currency )
							) }
						</dd>
					</dl>
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
