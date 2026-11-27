/**
 * The „Pénzügyek” tab (S38): per payer what is owed („Fizetendő”), what was paid („Befizetett”) and „Tartozás”
 * (and a total row with several payers), the entries newest first in two side columns, and the two actions the
 * user's grants allow. Positive means what the column's name says and a real minus the opposite; „Tartozás” is
 * Fizetendő − Befizetett, positive still owed, and a negative value reads „−5 000 Ft (túlfizetés)” (topic 10).
 * One „Tétel rögzítése” action records a payment, a refund, a charge or a discount, the correction keeps its
 * own (G12). [UNAPPROVED N39] [UNAPPROVED N42] [UNAPPROVED N17] [UNAPPROVED N18] [UNAPPROVED N1203]
 */
import { Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { Button, SnackbarList } from '@wordpress/components';
import RecordDialog from './RecordDialog';
import { allowedTypes, canCorrect, entryColumns, formatBalance, formatDay, formatMoney, lineNote, showsPayerColumn, typeWord } from './money';
import { SNACKBAR_MS, withNotice, withoutNotice } from './snack';
import { returnFocus } from './panel';
import { MONEY } from './texts';

/**
 * @param {Object}   props
 * @param {Object}   props.config  window.kalakaGrid
 * @param {string}   props.code    the registration's code
 * @param {Object}   props.money   the read's money part
 * @param {Object}   props.can     the read's `can`
 * @param {Function} props.reload  re-reads the panel's detail; resolves when it is on screen
 * @param {boolean}  props.stale   the last re-read failed: the figures are hidden, the snackbar stays
 * @param {Function} props.onSaved tells the grid an entry was saved
 * @param {Object}   props.closeRef ref to the panel's close button, where focus goes when the opener is gone
 */
export default function MoneyTab( { config, code, money, can, reload, stale, onSaved, closeRef } ) {
	const [ dialog, setDialog ] = useState( null );
	const [ notices, setNotices ] = useState( [] );
	const opener = useRef( null );
	const timers = useRef( [] );
	const several = money.payers.length > 1; // the total row
	const payerColumn = showsPayerColumn( money );

	useEffect(
		() => () => {
			timers.current.forEach( clearTimeout );
		},
		[]
	);

	// A payer the kind of which the site no longer declares has no name: it is shown by its key. [UNAPPROVED N20]
	const payerName = ( key ) => {
		const found = money.payers.find( ( p ) => p.payer === key );
		return found && found.label ? found.label : key;
	};
	// After a save, or a cancel, focus returns to the control that opened the dialog; when that is gone, to the
	// panel's close button. [UNAPPROVED N1202]
	const focusOpener = () =>
		window.requestAnimationFrame( () => returnFocus( opener.current, closeRef && closeRef.current ) );
	// G12: the „Tétel rögzítése” dialog (a payment or a refund) and the correction's own. [UNAPPROVED N39]
	const open = ( kind, event ) => {
		opener.current = event.currentTarget;
		setDialog( kind );
	};
	const close = () => {
		setDialog( null );
		focusOpener();
	};
	const done = async ( text ) => {
		await reload(); // the panel stays and shows the new entry and balances
		onSaved();
		setDialog( null );
		const id = String( Date.now() );
		// The success snackbar stays for SNACKBAR_MS. [UNAPPROVED N19]
		setNotices( ( list ) => withNotice( list, id, text, 'success' ) );
		timers.current.push( setTimeout( () => setNotices( ( list ) => withoutNotice( list, id ) ), SNACKBAR_MS ) );
		focusOpener();
	};

	const summary = money.payers.concat(
		several ? [ { payer: '_total', label: MONEY.total, ...money.total } ] : []
	);
	const snacks = (
		<div className="kg-snacks">
			<SnackbarList
				notices={ notices }
				onRemove={ ( id ) => setNotices( ( list ) => withoutNotice( list, id ) ) }
			/>
		</div>
	);
	if ( stale ) {
		return snacks;
	}
	return (
		<Fragment>
			<div
				className="kg-table-wrap"
				tabIndex={ 0 }
				role="region"
				// The region's screen-reader name, from the column names.
				aria-label={ `${ MONEY.owed } / ${ MONEY.paid } / ${ MONEY.balance }` }
			>
				{ /* „Befizetett” and the total row are net: a refund lowers „Befizetett”. [UNAPPROVED N7] */ }
				<table className="kg-money">
					<thead>
						<tr>
							{ payerColumn && <th scope="col">{ MONEY.payer }</th> }
							<th scope="col" className="is-num">
								{ MONEY.owed }
							</th>
							<th scope="col" className="is-num">
								{ MONEY.paid }
							</th>
							<th scope="col" className="is-num">
								{ MONEY.balance }
							</th>
						</tr>
					</thead>
					<tbody>
						{ summary.map( ( p ) => (
							<tr key={ p.payer } className={ p.payer === '_total' ? 'is-total' : '' }>
								{ payerColumn && (
									<th scope="row">{ p.payer === '_total' ? p.label : payerName( p.payer ) }</th>
								) }
								<td className="is-num">{ formatMoney( p.charged, money.currency ) }</td>
								<td className="is-num">{ formatMoney( p.paid, money.currency ) }</td>
								{ /* The total row's „Tartozás” nets one payer against another: a hole. */ }
								<td className="is-num">{ formatBalance( p.balance, money.currency ) }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
			{ /* Two buttons, „Tétel rögzítése” and the correction's own, for the owner's G12. [UNAPPROVED N14] [UNAPPROVED T17] */ }
			{ ( allowedTypes( can ).length > 0 || canCorrect( can ) ) && (
				<div className="kg-actions">
					{ allowedTypes( can ).length > 0 && (
						<Button variant="primary" onClick={ ( e ) => open( 'entry', e ) }>
							{ MONEY.recordEntry }
						</Button>
					) }
					{ canCorrect( can ) && (
						<Button variant="secondary" onClick={ ( e ) => open( 'correction', e ) }>
							{ MONEY.recordCorrect }
						</Button>
					) }
				</div>
			) }
			<h3 className="kg-sub">{ MONEY.entries }</h3>
			{ money.lines.length === 0 ? (
				<p className="kg-none">{ MONEY.none }</p>
			) : (
				<div className="kg-table-wrap" tabIndex={ 0 } role="region" aria-label={ MONEY.entries }>
					<table className="kg-money kg-lines">
						<thead>
							<tr>
								<th scope="col">{ MONEY.date }</th>
								<th scope="col">{ MONEY.type }</th>
								{ /* Kept with several payers, though the owner's column list does not name it. */ }
								{ payerColumn && <th scope="col">{ MONEY.payer }</th> }
								<th scope="col" className="is-num">
									{ MONEY.owed }
								</th>
								<th scope="col" className="is-num">
									{ MONEY.paid }
								</th>
								<th scope="col">{ MONEY.method }</th>
								<th scope="col">{ MONEY.note }</th>
							</tr>
						</thead>
						<tbody>
							{ money.lines.map( ( line ) => {
								// Each line has its amount in exactly one of the two side columns. [UNAPPROVED N5]
								const side = entryColumns( line, money.currency );
								return (
								<tr key={ line.id }>
									<td className="kg-nowrap">
										{ formatDay( line.value_date || line.recorded_at, config.timezone ) }
									</td>
									<td>
										{ typeWord( line ) }
										{ line.correction && (
											<Fragment>
												{ ' ' }
												<span className="kg-badge">{ MONEY.correction }</span>
											</Fragment>
										) }
									</td>
									{ payerColumn && <td>{ payerName( line.payer ) }</td> }
									<td className="is-num kg-nowrap">{ side.owed }</td>
									<td className="is-num kg-nowrap">{ side.paid }</td>
									<td>{ line.method_label || '' }</td>
									<td>{ lineNote( line ) }</td>
								</tr>
								);
							} ) }
						</tbody>
					</table>
				</div>
			) }
			{ dialog && (
				<RecordDialog
					config={ config }
					mode={ dialog }
					can={ can }
					code={ code }
					money={ money }
					onClose={ close }
					onDone={ done }
				/>
			) }
			{ snacks }
		</Fragment>
	);
}
