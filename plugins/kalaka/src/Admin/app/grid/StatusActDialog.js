/**
 * One status act's dialog (S39 on #247): the title, then the owner's plain order (4.2 a: "clean that dialog up as
 * well - info not structured well"): the act's one body sentence; the server's warnings, one core `Notice` each;
 * the money effect as one table under a heading (the payer column only with several payer rows), with the capacity
 * lines under it and the retention and refund sentences after them; the optional reason field; the buttons. Nothing
 * but that order is laid out. A failed act
 * keeps the dialog and the reason and shows the server's text; while it runs the dialog does not close and a second
 * submit is ignored. Everything the preview shows is the server's, chosen by its own values; no warning text is
 * written here. The body sentence and the block under it (the money table, the capacity lines and the sentences)
 * are the dialog's description (`aria-describedby`), in that order; the warnings are outside it, each notice speaks
 * its own (N719), and a polite live region says the capacity and money once the preview answers (N722).
 * [UNAPPROVED N702] [UNAPPROVED N706] [UNAPPROVED N707] [UNAPPROVED N708] [UNAPPROVED N709]
 * [UNAPPROVED N714] [UNAPPROVED N715] [UNAPPROVED N716] [UNAPPROVED N717] [UNAPPROVED N718] [UNAPPROVED N719] [UNAPPROVED N721] [UNAPPROVED N722] [UNAPPROVED N1310]
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Modal, Notice, TextareaControl } from '@wordpress/components';
import * as api from './api';
import {
	bodyOf,
	failureOf,
	moneyRowsOf,
	reasonProblem,
	retentionLineOf,
	spokenOf,
	seatLinesOf,
	showsRefundLine,
	staleAfter,
	titleOf,
	warningsOf,
} from './statusActs';
import { CONFIRM, MONEY, STATUS_ACTS } from './texts';

// The description is the body sentence (first id) and the block under it, the money table with the capacity lines
// and the sentences (second id); the warnings are not in it, each warning notice speaks itself once, through
// WordPress's own `speak` region (not N722's region). [UNAPPROVED N719] [UNAPPROVED N722] [UNAPPROVED N1310]
// The line's „x / y” figure, kept on one line at 320 px (N716): [ text, figure ] or [ the line ].
const capacityParts = ( line ) => {
	const found = /^(.*: )(\d+ \/ \d+)$/.exec( line );
	return found ? [ found[ 1 ], found[ 2 ] ] : [ line ];
};

const DESCRIPTION_IDS = 'kg-act-description kg-act-money';

/**
 * @param {Object}   props
 * @param {Object}   props.config  window.kalakaGrid
 * @param {string}   props.code    the registration's code
 * @param {string}   props.act     `admit`, `withdraw`, `remove` or `restore`
 * @param {?Object}  props.money   the detail read's `money`, for the payers' names and the currency
 * @param {Function} props.onClose closes the dialog
 * @param {Function} props.onDone  called with the act once it is saved
 * @param {Function} props.reload  re-reads the panel's detail; called after a refusal that says it is stale
 */
export default function StatusActDialog( { config, code, act, money, onClose, onDone, reload } ) {
	const [ reason, setReason ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ failed, setFailed ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ preview, setPreview ] = useState( null );
	const sending = useRef( false );
	const form = useRef();

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

	// The preview is asked as the dialog opens, for every act, with no reason (the server takes none on a
	// preview). A failed preview shows nothing: the act's own answer decides. [UNAPPROVED N706]
	useEffect( () => {
		let live = true;
		api.runStatusAct( config, act, code, '', true ).then(
			( result ) => live && setPreview( result ),
			() => {}
		);
		return () => {
			live = false;
		};
	}, [ config, act, code ] );
	const warnings = warningsOf( preview );
	const rows = moneyRowsOf( preview, money );
	// With one payer row the table drops „Fizető”: the name says nothing then (owner 4.3, everywhere). [UNAPPROVED N714]
	const onePayer = !! rows && rows.length === 1;
	const seats = seatLinesOf( preview );
	const retention = retentionLineOf( preview );
	// What the preview says, for a screen reader, once it has answered: focus is in the dialog before that, so
	// the description alone was read too early. [UNAPPROVED N722]
	const spoken = spokenOf( act, { seats, rows, retention } );

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
			aria={ { describedby: DESCRIPTION_IDS } }
		>
			<form onSubmit={ submit } noValidate ref={ form }>
				<div className="screen-reader-text" role="status" aria-live="polite" aria-atomic="true">
					{ spoken }
				</div>
				{ /* The owner's order (4.2 a): the one body sentence; the warnings; the money table under its heading,
				     the capacity list and the sentences; the reason; the buttons. [UNAPPROVED N1310] */ }
				<div id="kg-act-description">
					<p>{ bodyOf( act ) }</p>
				</div>
				{ warnings.map( ( text ) => (
					<Notice key={ text } status="warning" isDismissible={ false }>
						{ text }
					</Notice>
				) ) }
				<div id="kg-act-money" className="kg-act-block">
					{ /* Admit's serial line keeps its place under the warning. [UNAPPROVED T713] */ }
					{ act === 'admit' && <p className="kg-act-serial">{ STATUS_ACTS.serialEnd }</p> }
					{ rows && (
						<div className="kg-act-money">
							<h3>{ STATUS_ACTS.moneyHeading }</h3>
							<table className="kg-money">
								<thead>
									<tr>
										{ ! onePayer && <th scope="col">{ MONEY.payer }</th> }
										<th scope="col" className="is-num">{ MONEY.paid }</th>
										<th scope="col" className="is-num">{ MONEY.balanceNow }</th>
										<th scope="col" className="is-num">{ MONEY.balanceAfter }</th>
									</tr>
								</thead>
								<tbody>
									{ rows.map( ( row ) => (
										<tr key={ row.key }>
											{ ! onePayer && <th scope="row" data-label={ MONEY.payer }>{ row.payer }</th> }
											<td className="is-num" data-label={ MONEY.paid }>
												{ row.paid }
											</td>
											<td className="is-num" data-label={ MONEY.balanceNow }>
												{ row.now }
											</td>
											<td className="is-num" data-label={ MONEY.balanceAfter }>
												{ row.after }
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) }
					{ seats.length > 0 && (
						<ul className="kg-act-capacity">
							{ seats.map( ( seat ) => (
								<li key={ seat.key }>
									{ capacityParts( seat.line ).map( ( part, index ) =>
										index === 1 ? (
											<span key="figure" className="kg-act-figure">
												{ part }
											</span>
										) : (
											part
										)
									) }
								</li>
							) ) }
						</ul>
					) }
					{ rows && retention && <p className="kg-act-sentence">{ retention }</p> }
					{ rows && showsRefundLine( act ) && <p className="kg-act-sentence">{ STATUS_ACTS.noAutomaticRefund }</p> }
				</div>
				{ /* No act requires the reason, and no „Nem kötelező.” hint is shown (T712 stays out). [UNAPPROVED N1042] */ }
				<TextareaControl
					label={ CONFIRM.reasonField }
					value={ reason }
					onChange={ setReason }
					rows={ 3 }
					aria-invalid={ error ? 'true' : undefined }
					help={ error ? <span className="kg-err">{ error }</span> : undefined }
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
