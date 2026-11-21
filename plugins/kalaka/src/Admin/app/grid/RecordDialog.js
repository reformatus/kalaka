/**
 * The two recording dialogs of the „Pénzügyek” tab (S38), split by the owner's topic 6a G12 and topic 10: one
 * „Tétel rögzítése” dialog whose first field is „Típus” (Befizetés, Visszafizetés, Díj, Kedvezmény: only the
 * types the read allows, none preselected unless exactly one is allowed); the correction keeps its own dialog
 * (`kalaka/record-correction`: a direction and a required reason, no method, no date). The amount is always
 * typed positive and the type gives the direction. A Díj or Kedvezmény has a participant-visible title instead
 * of the method and date, and may be saved „completed immediately”. The amount is never prefilled, the payer is
 * asked only with several payers, and „Tartozás” before and after follows the amount as it is typed. A failed
 * save keeps the dialog and its values. [UNAPPROVED N39] [UNAPPROVED N40] [UNAPPROVED N41] [UNAPPROVED N16]
 */
import { Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { Button, CheckboxControl, Modal, Notice, RadioControl, TextControl } from '@wordpress/components';
import * as api from './api';
import { MONEY } from './texts';
import {
	allowedTypes,
	amountString,
	formatBalanceMinor,
	formatMinor,
	pairPreview,
	parseAmount,
	previewAfter,
	savedText,
	toMinor,
	today,
	validateEntry,
} from './money';

/** A fresh idempotency key for one entry (lower-case hex, the ability's pattern). */
function newKey() {
	const bytes = new Uint8Array( 16 );
	window.crypto.getRandomValues( bytes );
	return 'ui-' + Array.from( bytes, ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' );
}

/**
 * @param {Object}   props
 * @param {Object}   props.config  window.kalakaGrid
 * @param {string}   props.mode    'entry' (a payment, a refund, a charge or a discount) or 'correction'
 * @param {Object}   props.can     the read's `can`: which types may be recorded (`allowedTypes`), and the correction
 * @param {string}   props.status  the registration's status (Díj and Kedvezmény only while `registered`)
 * @param {string}   props.code    the registration's code
 * @param {Object}   props.money   the read's money part
 * @param {Function} props.onClose closes the dialog
 * @param {Function} props.onDone  called with the snackbar text once the entry is saved
 */
export default function RecordDialog( { config, mode, can, status, code, money, onClose, onDone } ) {
	const several = money.payers.length > 1;
	// A refund uses the same methods as a payment, the card terminal included (the owner's ruling 2026-11-19).
	const methods = money.methods;
	const correction = mode === 'correction';
	// The types the dialog offers by the holder's grants; none is the default unless exactly one is allowed.
	// [UNAPPROVED N40]
	const types = allowedTypes( can, status );
	const [ type, setType ] = useState( () => ( types.length === 1 ? types[ 0 ] : '' ) );
	const [ direction, setDirection ] = useState( '' ); // the correction's, 'in' or 'out'
	// A correction is its own kind; an entry's kind is the type the user picked.
	const kind = correction ? 'correction' : type;
	const owedSide = kind === 'charge' || kind === 'discount';
	const [ payer, setPayer ] = useState( several ? '' : money.payers[ 0 ].payer );
	const [ amount, setAmount ] = useState( '' ); // never prefilled
	const [ date, setDate ] = useState( () => today( config.timezone ) );
	const [ method, setMethod ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	// The „Közlemény” of a payment or a refund (and a correction's reason) and the one of a „completed
	// immediately” pair are two fields with two values: typing in one never decides the other. [UNAPPROVED N53]
	const [ pairNote, setPairNote ] = useState( '' );
	const [ pairNoteEdited, setPairNoteEdited ] = useState( false );
	const [ title, setTitle ] = useState( '' );
	const [ completed, setCompleted ] = useState( false ); // unticked by default [UNAPPROVED N45]
	const [ errors, setErrors ] = useState( {} );
	// Another type starts afresh: the paid-side line's „Közlemény” of the pair follows the title again, and the
	// messages of the earlier submit go. The values typed for the other type stay (they are not sent). [UNAPPROVED N53]
	// [UNAPPROVED N58]
	const pickType = ( value ) => {
		setType( value );
		setPairNoteEdited( false );
		setPairNote( '' );
		setErrors( {} );
	};
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
	// A refund, a charge and a correction that lowers „Befizetett” raise „Tartozás”; a payment, a discount and a
	// correction that raises it lower it. Until the type is picked there is no "after" even with an amount.
	// [UNAPPROVED N15] [UNAPPROVED N57] [UNAPPROVED N46]
	const paired = owedSide && completed;
	// „Közlemény” of the paid-side line starts as the title and follows it until the admin edits that field itself.
	// [UNAPPROVED N53]
	const noteValue = pairNoteEdited ? pairNote : title;
	const pair = paired ? pairPreview( { kind, minor: parseAmount( amount ) } ) : [];
	const after = previewAfter( { before, minor: parseAmount( amount ), kind, direction, completed: paired } );

	const submit = async ( event ) => {
		event.preventDefault();
		if ( busy ) {
			return;
		}
		setFailed( false );
		const found = validateEntry(
			{ several, payer, amount, date, method, note, title, kind, direction, completed: paired },
			today( config.timezone )
		);
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
			// A charge or discount carries a method and a date only when saved „completed immediately”.
			valueDate: correction || ( owedSide && ! paired ) ? null : date,
			method: correction || ( owedSide && ! paired ) ? null : method,
			direction: correction ? direction : null,
			note: owedSide && ! paired ? '' : ( paired ? noteValue : note ).trim(),
			title: owedSide ? title.trim() : '',
			completed: paired,
		};
		const sig = JSON.stringify( entry );
		if ( attempt.current.sig !== sig ) {
			attempt.current = { sig, key: newKey() };
		}
		setBusy( true );
		try {
			await api.recordEntry( config, { ...entry, key: attempt.current.key }, can, status );
			await onDone( savedText( kind, paired ) );
		} catch {
			// Whatever the server refused or lost, the dialog stays with the values. [UNAPPROVED N22]
			setBusy( false );
			setFailed( true );
		}
	};

	const fieldError = ( name ) => errors[ name ] && <span className="kg-err">{ errors[ name ] }</span>;
	// The title's hint stays under the field while its message shows (the message first). [UNAPPROVED N59]
	const titleHelp = (
		<Fragment>
			{ fieldError( 'title' ) }
			{ MONEY.titleHint }
		</Fragment>
	);
	const directionOptions = [
		{ value: 'in', label: MONEY.directionIn },
		{ value: 'out', label: MONEY.directionOut },
	];
	const typeLabels = {
		payment: MONEY.entryIn,
		refund: MONEY.entryOut,
		charge: MONEY.entryCharge,
		discount: MONEY.entryDiscount,
	};
	const typeOptions = types.map( ( value ) => ( { value, label: typeLabels[ value ] } ) );
	const payerField = several && (
		<div className={ errors.payer ? 'kg-radio-bad' : '' }>
			<RadioControl
				label={ MONEY.payer }
				selected={ payer }
				options={ money.payers.map( ( p ) => ( { value: p.payer, label: p.label ?? p.payer } ) ) }
				onChange={ setPayer }
				help={ fieldError( 'payer' ) }
			/>
		</div>
	);
	// The paid-side fields: the date and the method of a payment, a refund, or an owed-side line saved
	// „completed immediately”. [UNAPPROVED N45]
	const paidFields = (
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
	);
	return (
		<Modal
			title={ correction ? MONEY.recordCorrect : MONEY.recordEntry }
			onRequestClose={ () => ! busy && onClose() }
			size="medium"
			className="kg-dialog"
			shouldCloseOnClickOutside={ false }
		>
			<form onSubmit={ submit } noValidate ref={ form }>
				{ ! correction && (
					<div className={ errors.type ? 'kg-radio-bad' : '' }>
						<RadioControl
							label={ MONEY.typeField }
							selected={ type }
							options={ typeOptions }
							onChange={ pickType }
							help={ fieldError( 'type' ) }
						/>
					</div>
				) }
				{ payerField }
				{ correction && (
					<div className={ errors.direction ? 'kg-radio-bad' : '' }>
						<RadioControl
							label={ MONEY.directionField }
							selected={ direction }
							options={ directionOptions }
							onChange={ setDirection }
							help={ fieldError( 'direction' ) }
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
				{ owedSide ? (
					<Fragment>
						{ /* The title the participant sees; required. The label and hint are #209's lektor-approved text, built by
						#217; the message is [UNAPPROVED T28]. [UNAPPROVED N48] */ }
						<TextControl
							label={ MONEY.titleField }
							value={ title }
							onChange={ setTitle }
							autoComplete="off"
							aria-invalid={ errors.title ? 'true' : undefined }
							help={ titleHelp }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
						{ /* „Completed immediately”: also records the matching paid-side line. The label and help are #209's
						lektor-approved text, built by #217. */ }
						<CheckboxControl
							label={ kind === 'charge' ? MONEY.completedCharge : MONEY.completedDiscount }
							checked={ completed }
							onChange={ ( value ) => {
								setCompleted( value );
								if ( ! value ) {
									// The paid-side fields go; so do their messages from an earlier submit.
									setErrors( {} );
								}
							} }
							help={ kind === 'charge' ? MONEY.completedChargeHelp : MONEY.completedDiscountHelp }
							__nextHasNoMarginBottom
						/>
						{ completed && paidFields }
						{ completed && (
							<TextControl
								label={ MONEY.noteField }
								value={ noteValue }
								onChange={ ( value ) => {
									setPairNote( value );
									setPairNoteEdited( true );
								} }
								autoComplete="off"
								__next40pxDefaultSize
								__nextHasNoMarginBottom
							/>
						) }
					</Fragment>
				) : (
					<Fragment>
						{ /* Before a type is picked the payment and refund fields show. [UNAPPROVED N56] */ }
						{ ! correction && paidFields }
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
					</Fragment>
				) }
				{ row && (
					<dl className="kg-bal">
						<dt>{ MONEY.balanceNow }</dt>
						<dd>{ formatBalanceMinor( before ?? 0, money.currency ) }</dd>
						{ /* A „completed immediately” pair: both lines with their effect on „Tartozás”. [UNAPPROVED N46] */ }
						{ pair.flatMap( ( line ) => [
							<dt key={ line.kind + 'dt' }>{ typeLabels[ line.kind ] }</dt>,
							<dd key={ line.kind + 'dd' }>{ formatMinor( line.effect, money.currency ) }</dd>,
						] ) }
						<dt>{ MONEY.balanceAfter }</dt>
						<dd aria-live="polite">
							{ after === null ? (
								<Fragment>
									{ /* The empty value and its screen-reader text. [UNAPPROVED T35] [UNAPPROVED T13] */ }
									<span aria-hidden="true">–</span>
									<span className="screen-reader-text">{ MONEY.noAmount }</span>
								</Fragment>
							) : (
								formatBalanceMinor( after, money.currency )
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
					{ /* „Mentés” and „Mégse”. [UNAPPROVED T34] */ }
					<Button variant="primary" type="submit" isBusy={ busy } aria-disabled={ busy }>
						{ paired ? MONEY.saveTwo : MONEY.save }
					</Button>
					<Button variant="tertiary" onClick={ onClose } disabled={ busy }>
						{ MONEY.cancel }
					</Button>
				</div>
			</form>
		</Modal>
	);
}
