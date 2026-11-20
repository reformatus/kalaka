/**
 * The two recording dialogs of the „Pénzügyek” tab (S38), split by the owner's topic 6a G12: one
 * „Tétel rögzítése” dialog records a payment (`kalaka/record-payment`) or a refund (`kalaka/record-refund`), the
 * same fields for both with the direction as a radio that has no default; the correction keeps its own dialog
 * (`kalaka/record-correction`: a direction and a required reason, no method, no date). The amount is never
 * prefilled, the payer is asked only with several payers, and the balance before and after follows the amount
 * as it is typed, signed. A failed save keeps the dialog and its values. [UNAPPROVED N39] [UNAPPROVED N40]
 * [UNAPPROVED N41] [UNAPPROVED N16]
 */
import { Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { Button, Modal, Notice, RadioControl, TextControl } from '@wordpress/components';
import * as api from './api';
import { MONEY } from './texts';
import { amountString, balanceAfter, formatMinor, parseAmount, toMinor, today, validateEntry } from './money';

/** A fresh idempotency key for one entry (lower-case hex, the ability's pattern). */
function newKey() {
	const bytes = new Uint8Array( 16 );
	window.crypto.getRandomValues( bytes );
	return 'ui-' + Array.from( bytes, ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' );
}

/**
 * @param {Object}   props
 * @param {Object}   props.config  window.kalakaGrid
 * @param {string}   props.mode    'entry' (a payment or a refund) or 'correction'
 * @param {Object}   props.can     the read's `can`: which of a payment, a refund and a correction may be recorded
 * @param {string}   props.code    the registration's code
 * @param {Object}   props.money   the read's money part
 * @param {Function} props.onClose closes the dialog
 * @param {Function} props.onDone  called with the snackbar text once the entry is saved
 */
export default function RecordDialog( { config, mode, can, code, money, onClose, onDone } ) {
	const several = money.payers.length > 1;
	// A refund uses the same methods as a payment, the card terminal included (the owner's ruling 2026-11-19).
	const methods = money.methods;
	const correction = mode === 'correction';
	// The direction the dialog offers: a payment and/or a refund by the holder's grants, or a correction's in /
	// out. Only the allowed directions are offered (below), and none is the default. [UNAPPROVED N40]
	const [ direction, setDirection ] = useState( '' );
	// A correction is its own kind; an entry's kind is the direction the user picked.
	const kind = correction ? 'correction' : direction;
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
	// A refund and a correction out raise the balance; a payment and a correction in lower it. Until a
	// direction is picked there is no "after" even with an amount. [UNAPPROVED N15]
	const raises = direction === 'refund' || direction === 'out';
	const after = balanceAfter( before, direction ? parseAmount( amount ) : null, raises );

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
	const directionOptions = correction
		? [
				{ value: 'in', label: MONEY.directionIn },
				{ value: 'out', label: MONEY.directionOut },
		  ]
		: [
				...( can.record_payment ? [ { value: 'payment', label: MONEY.entryIn } ] : [] ),
				...( can.record_refund ? [ { value: 'refund', label: MONEY.entryOut } ] : [] ),
		  ];
	return (
		<Modal
			title={ correction ? MONEY.recordCorrect : MONEY.recordEntry }
			onRequestClose={ () => ! busy && onClose() }
			size="medium"
			className="kg-dialog"
			shouldCloseOnClickOutside={ false }
		>
			<form onSubmit={ submit } noValidate ref={ form }>
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
				<div className={ errors.direction ? 'kg-radio-bad' : '' }>
					<RadioControl
						label={ MONEY.directionField }
						selected={ direction }
						options={ directionOptions }
						onChange={ setDirection }
						help={ fieldError( 'direction' ) }
					/>
				</div>
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
						<dd>{ formatMinor( before ?? 0, money.currency ) }</dd>
						<dt>{ MONEY.balanceAfter }</dt>
						<dd aria-live="polite">
							{ after === null ? (
								<Fragment>
									<span aria-hidden="true">–</span>
									<span className="screen-reader-text">{ MONEY.noAmount }</span>
								</Fragment>
							) : (
								formatMinor( after, money.currency )
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
