import { cellText, fmt, plainText, sumParts, tableHtml } from '../../src/Admin/app/grid/format';

const TEXT = ' style="mso-number-format:\'\\@\'" sdnum="1038;0;@"';

const columns = [
	{ column: 'serial', label: 'Sorszám', type: 'serial', numeric: true },
	{ column: 'code', label: 'Kód', type: 'code', numeric: false },
	{ column: 'status', label: 'Állapot', type: 'status', options: { registered: 'Rögzített' } },
	{ column: 'field:lunch', label: 'Ebéd', type: 'choice', options: { veg: 'Vegetáriánus', normal: 'Normál' } },
	{ column: 'field:photo', label: 'Fénykép', type: 'consent', options: { 1: 'Igen' } },
	{ column: 'field:nights', label: 'Éjszakák', type: 'number', numeric: true },
	{ column: 'field:born', label: 'Születési idő', type: 'date' },
	{
		column: 'decision:confirmation',
		label: 'Visszaigazolás',
		type: 'decision',
		states: { confirmed: 'Visszaigazolva' },
	},
	{ column: 'field:note', label: 'Megjegyzés', type: 'text' },
];

const row = {
	key: '00A0',
	values: {
		serial: 1,
		code: '00A0',
		status: 'registered',
		'field:lunch': '["veg","normal"]',
		'field:photo': '1',
		'field:nights': '2.5',
		'field:born': '2010-05-04',
		'decision:confirmation': 'confirmed',
		'field:note': 'Két\tsor\nhosszú <b>',
	},
};

describe( 'fmt', () => {
	it( 'fills placeholders in order and by position', () => {
		expect( fmt( '%d sor', 46 ) ).toBe( '46 sor' );
		expect( fmt( '%1$s (%2$s): %3$d jelentkező', 'Ebéd', 'Normál', 9 ) ).toBe( 'Ebéd (Normál): 9 jelentkező' );
	} );
} );

describe( 'cellText', () => {
	it( 'shows each type as the grid shows it', () => {
		expect( columns.map( ( c ) => cellText( c, row ) ) ).toEqual( [
			'1',
			'00A0',
			'Rögzített',
			'Vegetáriánus, Normál',
			'Igen',
			'2,5',
			'2010. 05. 04.',
			'Visszaigazolva',
			'Két\tsor\nhosszú <b>',
		] );
	} );

	it( 'shows an empty value as nothing and an unknown option as stored', () => {
		const lunch = columns[ 3 ];
		expect( cellText( lunch, { values: { 'field:lunch': null } } ) ).toBe( '' );
		expect( cellText( lunch, { values: { 'field:lunch': '' } } ) ).toBe( '' );
		expect( cellText( lunch, { values: { 'field:lunch': 'fish' } } ) ).toBe( 'fish' );
		expect( cellText( lunch, { values: { 'field:lunch': '[broken' } } ) ).toBe( '[broken' );
	} );
} );

describe( 'sumParts', () => {
	const sums = {
		code: { kind: 'rows', n: 46 },
		status: { kind: 'members', n: 40, members: [ 'registered' ], labels: [ 'Rögzített' ] },
		'field:photo': { kind: 'count', n: 36, member: 'Igen' },
		'field:nights': { kind: 'sum', n: '19.5' },
		'field:rating': { kind: 'average', n: '3.25' },
		'field:note': { kind: 'filled', n: 8 },
		'field:empty': { kind: 'sum', n: null },
	};
	const part = ( column, label = 'X' ) => sumParts( { column, label }, sums );

	it( 'gives the shown number and the full text of every kind', () => {
		expect( part( 'code' ) ).toEqual( { shown: '46', full: '46 sor' } );
		expect( part( 'status', 'Állapot' ) ).toEqual( {
			shown: '40',
			full: 'Állapot (Rögzített): 40 jelentkező',
			named: true,
		} );
		expect( part( 'field:photo' ) ).toEqual( { shown: '36', full: 'Igen: 36' } );
		expect( part( 'field:nights' ) ).toEqual( { shown: '19,5', full: 'Összesen: 19,5' } );
		expect( part( 'field:rating' ) ).toEqual( { shown: '3,3', full: 'Átlag: 3,3' } );
		expect( part( 'field:note' ) ).toEqual( { shown: '8', full: 'Kitöltve: 8' } );
	} );

	it( 'gives nothing for a column without a sum or over no value', () => {
		expect( part( 'field:empty' ) ).toBeNull();
		expect( part( 'field:other' ) ).toBeNull();
		expect( sumParts( { column: 'code' }, null ) ).toBeNull();
	} );
} );

describe( 'the copy', () => {
	it( 'is tab-separated text with tabs and line breaks in a cell made spaces', () => {
		expect( plainText( columns, [ row ] ).split( '\n' ) ).toEqual( [
			'Sorszám\tKód\tÁllapot\tEbéd\tFénykép\tÉjszakák\tSzületési idő\tVisszaigazolás\tMegjegyzés',
			'1\t00A0\tRögzített\tVegetáriánus, Normál\tIgen\t2,5\t2010. 05. 04.\tVisszaigazolva\tKét sor hosszú <b>',
		] );
	} );

	it( 'is an HTML table: text cells as text, numbers and dates typed, everything escaped', () => {
		const cells = tableHtml( columns, [ row ] ).match( /<td[^>]*>[^<]*(?:<(?!\/td)[^<]*)*<\/td>/g );
		expect( cells ).toEqual( [
			'<td sdval="1" sdnum="1038;0;0">1</td>',
			'<td' + TEXT + '>00A0</td>',
			'<td' + TEXT + '>Rögzített</td>',
			'<td' + TEXT + '>Vegetáriánus, Normál</td>',
			'<td' + TEXT + '>Igen</td>',
			'<td sdval="2.5" sdnum="1033;0;General">2,5</td>',
			'<td sdval="40302" sdnum="1033;0;YYYY-MM-DD">2010. 05. 04.</td>',
			'<td' + TEXT + '>Visszaigazolva</td>',
			'<td' + TEXT + '>Két\tsor\nhosszú &lt;b&gt;</td>',
		] );
		expect( tableHtml( columns, [] ) ).toBe(
			'<table><tr>' + columns.map( ( c ) => '<th' + TEXT + '>' + c.label + '</th>' ).join( '' ) + '</tr></table>'
		);
	} );
} );
