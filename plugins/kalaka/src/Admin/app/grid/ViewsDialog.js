/**
 * The „Nézetek” dialog's content (S37). Full access: every view of the event with its condition, columns
 * and grants, renamed, its parameter removed (A1, A2) or deleted (A3) through the abilities, and „Új
 * nézet”. A grant holder: their own views, read-only.
 */
import { useState } from '@wordpress/element';
import {
	Button,
	Flex,
	Modal,
	Notice,
	TextControl,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { fmt } from './format';
import { tabUrl } from './Tabs';
import { VIEWS as T } from './texts';

/** A1 and A3 count people: the distinct users across the view's grants. */
const people = ( view ) => new Set( ( view.grants || [] ).map( ( g ) => g.user_id ) ).size;

/** A2 counts the grants that bind a value to the parameter. */
const bound = ( view ) => ( view.grants || [] ).filter( ( g ) => g.bound !== null && g.bound !== undefined ).length;

function Confirm( { title, text, ok, onOk, onCancel } ) {
	return (
		<Modal title={ title } onRequestClose={ onCancel } size="medium">
			<p style={ { marginTop: 0 } }>{ text }</p>
			<Flex justify="flex-end">
				<Button variant="tertiary" onClick={ onCancel }>
					{ T.cancel }
				</Button>
				<Button variant="primary" isDestructive onClick={ onOk }>
					{ ok }
				</Button>
			</Flex>
		</Modal>
	);
}

function Editor( { view, onSave, onCancel } ) {
	const [ name, setName ] = useState( view.name );
	const [ keepParam, setKeepParam ] = useState( view.param !== null );
	const [ ask, setAsk ] = useState( false );
	const save = () => {
		if ( view.param && ! keepParam && bound( view ) > 0 ) {
			setAsk( true );
			return;
		}
		onSave( name, keepParam );
	};
	return (
		<VStack spacing={ 3 } className="kv-editor">
			{ view.grants.length > 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ fmt( T.a1, people( view ) ) }
				</Notice>
			) }
			<TextControl
				label={ T.name }
				value={ name }
				onChange={ setName }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			{ view.param && keepParam && (
				<HStack justify="flex-start">
					<span>{ T.filter + ': ' + view.filterText }</span>
					<Button variant="link" isDestructive onClick={ () => setKeepParam( false ) }>
						{ T.removeParam }
					</Button>
				</HStack>
			) }
			<HStack justify="flex-end">
				<Button variant="tertiary" onClick={ onCancel }>
					{ T.cancel }
				</Button>
				<Button variant="primary" onClick={ save }>
					{ T.save }
				</Button>
			</HStack>
			{ ask && (
				<Confirm
					title={ T.a2Title }
					text={ fmt( T.a2, view.param, bound( view ) ) }
					ok={ T.a2Ok }
					onCancel={ () => setAsk( false ) }
					onOk={ () => {
						setAsk( false );
						onSave( name, false );
					} }
				/>
			) }
		</VStack>
	);
}

function ViewItem( { view, full, onOpen, onSave, onDelete } ) {
	const [ editing, setEditing ] = useState( false );
	const [ asking, setAsking ] = useState( false );
	const head = (
		<div className="kv-head">
			<a
				href={ tabUrl( view.view ) }
				className="kv-name"
				onClick={ ( event ) => {
					event.preventDefault();
					onOpen( view.view );
				} }
			>
				{ view.name }
			</a>
			{ view.count !== null && view.count !== undefined && (
				<span className="kv-count">{ fmt( T.count, view.count ) }</span>
			) }
		</div>
	);
	const columns = <div className="kv-meta">{ T.columns + ': ' + view.columns.join( ', ' ) }</div>;
	if ( ! full ) {
		return (
			<li className="kv-item">
				{ head }
				{ columns }
			</li>
		);
	}
	if ( editing ) {
		return (
			<li className="kv-item">
				<Editor
					view={ view }
					onCancel={ () => setEditing( false ) }
					onSave={ async ( name, keepParam ) => {
						if ( await onSave( view, name, keepParam ) ) {
							setEditing( false );
						}
					} }
				/>
			</li>
		);
	}
	return (
		<li className="kv-item">
			{ head }
			<div className="kv-meta">
				{ ( view.terms > 1 ? T.filters : T.filter ) + ': ' + ( view.filterText || '–' ) }
			</div>
			{ columns }
			<div className="kv-meta">
				{ T.grants + ': ' }
				{ view.grants.length ? (
					<ul className="kv-grants">
						{ view.grants.map( ( g ) => (
							<li key={ g.grant }>
								{ g.user }
								{ g.bound ? ' – ' + g.bound : '' }
								{ g.count !== null ? ': ' + fmt( T.count, g.count ) : '' }
							</li>
						) ) }
					</ul>
				) : (
					T.noGrants
				) }
			</div>
			<HStack justify="flex-start" spacing={ 2 } className="kv-actions">
				<Button variant="secondary" size="compact" onClick={ () => setEditing( true ) }>
					{ T.edit }
				</Button>
				<Button
					variant="tertiary"
					size="compact"
					isDestructive
					onClick={ () => ( view.grants.length ? setAsking( true ) : onDelete( view ) ) }
				>
					{ T.del }
				</Button>
			</HStack>
			{ asking && (
				<Confirm
					title={ T.a3Title }
					text={ fmt( T.a3, view.name, people( view ) ) }
					ok={ T.a3Ok }
					onCancel={ () => setAsking( false ) }
					onOk={ () => {
						setAsking( false );
						onDelete( view );
					} }
				/>
			) }
		</li>
	);
}

/**
 * @param {Object}   props
 * @param {Object}   props.data     the views list (`GET …/views`): { full, views }
 * @param {Function} props.onOpen   opens a view's tab
 * @param {Function} props.onChange runs a change ( 'save' | 'delete' | 'add', view, … ); resolves true on success
 * @param {string}   props.error    the last change's error, shown above the list
 */
export default function ViewsDialog( { data, onOpen, onChange, error } ) {
	const full = data.full;
	return (
		<div className="kv kv-dialog">
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ data.views.length ? (
				<ul className="kv-list">
					{ data.views.map( ( view ) => (
						<ViewItem
							key={ view.view }
							view={ view }
							full={ full }
							onOpen={ onOpen }
							onSave={ ( v, name, keepParam ) => onChange( 'save', v, name, keepParam ) }
							onDelete={ ( v ) => onChange( 'delete', v ) }
						/>
					) ) }
				</ul>
			) : (
				<p style={ { margin: 0 } }>{ full ? T.emptyFull : T.emptyGrant }</p>
			) }
			{ full && (
				<div className="kv-foot">
					<Button variant="secondary" onClick={ () => onChange( 'add' ) }>
						{ T.add }
					</Button>
				</div>
			) }
		</div>
	);
}
