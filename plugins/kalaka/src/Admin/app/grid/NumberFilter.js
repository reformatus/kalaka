/**
 * The number control of a column's filter (topic 6a fix pass 1, A4). DataViews reuses a field's `Edit` for its
 * filter, and `Edit` may be a component (DataViews' own field API), so a number column gives it this one instead
 * of DataViews' `<input type="number">`: a browser's number input drops a typed decimal comma (`2,5` becomes
 * `25`, silently), and which separator it takes depends on the browser's locale. This is a text input with
 * `inputmode="decimal"` that keeps what is typed and hands DataViews the number it reads (`decimal()` in
 * `narrow.js`: a comma or a dot is one decimal point), as a number, or `[ min, max ]` for the range, the shapes
 * DataViews' own control gives. Szám's range carries its approved hint under the two inputs (N11). [UNAPPROVED N65, N67, N69]
 */
import { BaseControl, Flex, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { decimal } from './narrow';
import { GRID } from './texts';

/** The number DataViews gets for a typed text, or '' when it is no number. */
const numberOf = ( text ) => {
	const read = decimal( text );
	return read === '' ? '' : Number( read );
};

/** The text a stored value shows when the user has not typed it (a number as Hungarian text). */
const textOf = ( value ) => ( value === undefined || value === null || value === '' ? '' : String( value ).replace( '.', ',' ) );

/** A text input for one decimal, which keeps exactly what is typed. */
function DecimalInput( { label, value, onText, hideLabelFromVision } ) {
	return (
		<TextControl
			__next40pxDefaultSize
			label={ label }
			hideLabelFromVision={ hideLabelFromVision }
			type="text"
			inputMode="decimal"
			autoComplete="off"
			value={ value }
			onChange={ onText }
		/>
	);
}

/**
 * The typed texts of a control, kept while the user types (`2,` must stay `2,` until the 5 comes) and
 * re-read from the value only when the value no longer is what the typed text reads as (a reset, another filter).
 */
function useTyped( shown ) {
	const [ typed, setTyped ] = useState( shown );
	useEffect( () => {
		setTyped( ( now ) => ( shown.map( numberOf ).join( '|' ) === now.map( numberOf ).join( '|' ) ? now : shown ) );
	}, [ shown.join( '|' ) ] );
	return [ typed, setTyped ];
}

/**
 * @param {string} hint the help line of the range, or '' for none
 * @return {Function} the `Edit` component of a number field
 */
export function numberEdit( hint ) {
	return function NumberFilter( { data, field, onChange, operator, hideLabelFromVision } ) {
		const value = field.getValue( { item: data } );
		const range = operator === 'between';
		const pair = range && Array.isArray( value ) && value.length === 2 ? value : [ '', '' ];
		const shown = range ? pair.map( textOf ) : [ textOf( value ) ];
		const [ typed, setTyped ] = useTyped( shown );
		const give = ( next ) => {
			setTyped( next );
			const read = next.map( numberOf );
			onChange( field.setValue( { item: data, value: range ? read : read[ 0 ] === '' ? undefined : read[ 0 ] } ) );
		};
		if ( ! range ) {
			return (
				<DecimalInput
					label={ field.label }
					hideLabelFromVision={ hideLabelFromVision }
					value={ typed[ 0 ] ?? '' }
					onText={ ( text ) => give( [ text ] ) }
				/>
			);
		}
		return (
			<BaseControl
				help={
					<>
						{ __( 'The max. value must be greater than the min. value.' ) }
						{ hint && (
							<>
								<br />
								{ hint }
							</>
						) }
					</>
				}
			>
				<Flex direction="row" gap={ 4 }>
					<DecimalInput
						label={ __( 'Min.' ) }
						hideLabelFromVision={ hideLabelFromVision }
						value={ typed[ 0 ] ?? '' }
						onText={ ( text ) => give( [ text, typed[ 1 ] ?? '' ] ) }
					/>
					<DecimalInput
						label={ __( 'Max.' ) }
						hideLabelFromVision={ hideLabelFromVision }
						value={ typed[ 1 ] ?? '' }
						onText={ ( text ) => give( [ typed[ 0 ] ?? '', text ] ) }
					/>
				</Flex>
			</BaseControl>
		);
	};
}

/** The `Edit` of a number column and of Szám (with N11's hint under its range). */
export const NumberEdit = numberEdit( '' );
export const SerialEdit = numberEdit( GRID.serialHint );

/** The field props that give a column this control: only Szám and the number columns. */
export const editFor = ( column ) => {
	if ( column.type === 'serial' ) {
		return { Edit: SerialEdit };
	}
	return column.type === 'number' ? { Edit: NumberEdit } : {};
};
