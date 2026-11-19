/**
 * The „Pénzügyek” tab (S38): per payer what is owed, paid and the balance (and a total row with several
 * payers), the entries newest first, and the two actions the user's grants allow. Amounts are shown absolute,
 * the type word giving the direction; the balance in words. [UNAPPROVED N5] [UNAPPROVED N6] [UNAPPROVED N7]
 * [UNAPPROVED N17] [UNAPPROVED N18]
 */
import { Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { Button, SnackbarList } from '@wordpress/components';
import RecordDialog from './RecordDialog';
import { balanceText, formatAbsolute, formatDay, formatMoney, toMinor } from './money';
import { MONEY } from './texts';

/** How long the success snackbar stays. [UNAPPROVED N19] */
const SNACKBAR_MS = 5000;

/**
 * @param {Object}   props
 * @param {Object}   props.config  window.kalakaGrid
 * @param {string}   props.code    the registration's code
 * @param {Object}   props.money   the read's money part
 * @param {Object}   props.can     the read's `can`
 * @param {Function} props.reload  re-reads the panel's detail; resolves when it is on screen
 * @param {Function} props.onSaved tells the grid an entry was saved
 */
export default function MoneyTab( { config, code, money, can, reload, onSaved } ) {
	const [ dialog, setDialog ] = useState( null );
	const [ notices, setNotices ] = useState( [] );
	const opener = useRef( null );
	const timers = useRef( [] );
	const several = money.payers.length > 1;

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
	const focusOpener = () =>
		window.requestAnimationFrame( () => {
			if ( opener.current && opener.current.isConnected ) {
				opener.current.focus();
			}
		} );
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
		setNotices( ( list ) => list.concat( { id, content: text, status: 'success', spokenMessage: text } ) );
		timers.current.push( setTimeout( () => setNotices( ( list ) => list.filter( ( n ) => n.id !== id ) ), SNACKBAR_MS ) );
		focusOpener();
	};

	const summary = money.payers.concat(
		several ? [ { payer: '_total', label: MONEY.total, ...money.total } ] : []
	);
	return (
		<Fragment>
			<div
				className="kg-table-wrap"
				tabIndex={ 0 }
				role="region"
				aria-label={ `${ MONEY.owed } / ${ MONEY.paid } / ${ MONEY.balance }` }
			>
				<table className="kg-money">
					<thead>
						<tr>
							<th scope="col">{ MONEY.payer }</th>
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
								<th scope="row">{ p.payer === '_total' ? p.label : payerName( p.payer ) }</th>
								<td className="is-num">{ formatMoney( p.charged ) }</td>
								<td className="is-num">{ formatMoney( p.paid ) }</td>
								<td className="is-num">{ balanceText( toMinor( p.balance ) ?? 0 ) }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
			{ ( can.record_payment || can.record_refund ) && (
				<div className="kg-actions">
					{ can.record_payment && (
						<Button variant="primary" onClick={ ( e ) => open( 'payment', e ) }>
							{ MONEY.recordPay }
						</Button>
					) }
					{ can.record_refund && (
						<Button variant="secondary" onClick={ ( e ) => open( 'refund', e ) }>
							{ MONEY.recordRefund }
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
								{ several && <th scope="col">{ MONEY.payer }</th> }
								<th scope="col" className="is-num">
									{ MONEY.amount }
								</th>
								<th scope="col">{ MONEY.method }</th>
								<th scope="col">{ MONEY.note }</th>
							</tr>
						</thead>
						<tbody>
							{ money.lines.map( ( line ) => (
								<tr key={ line.id }>
									<td className="kg-nowrap">
										{ formatDay( line.value_date || line.recorded_at, config.timezone ) }
									</td>
									<td>
										{ line.kind_label }
										{ line.correction && (
											<Fragment>
												{ ' ' }
												<span className="kg-badge">{ MONEY.correction }</span>
											</Fragment>
										) }
									</td>
									{ several && <td>{ payerName( line.payer ) }</td> }
									<td className="is-num kg-nowrap">{ formatAbsolute( line.amount ) }</td>
									<td>{ line.method_label || '' }</td>
									<td>{ line.note || '' }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
			{ dialog && (
				<RecordDialog
					config={ config }
					refund={ dialog === 'refund' }
					code={ code }
					money={ money }
					onClose={ close }
					onDone={ done }
				/>
			) }
			<div className="kg-snacks">
				<SnackbarList
					notices={ notices }
					onRemove={ ( id ) => setNotices( ( list ) => list.filter( ( n ) => n.id !== id ) ) }
				/>
			</div>
		</Fragment>
	);
}
