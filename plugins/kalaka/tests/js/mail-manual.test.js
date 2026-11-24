/**
 * The manual mail's pure logic: the repeat guard's keys (one per body, layout and codes) and the format helpers.
 */
import { createKeys, isTooMany, newRequestKey } from '../../src/Admin/app/mail/manual';
import { moment, problemText } from '../../src/Admin/app/mail/format';

describe( 'request keys', () => {
	test( 'a key has the shape the server accepts', () => {
		for ( let i = 0; i < 50; i++ ) {
			expect( newRequestKey() ).toMatch( /^[A-Za-z0-9_-]{8,64}$/ );
		}
	} );

	test( 'the same body, layout and codes keep one key, in any order of the codes', () => {
		let n = 0;
		const keyFor = createKeys( () => 'key' + String( ++n ).padStart( 6, '0' ) );
		const first = keyFor( 5, true, [ 'B', 'A' ] );
		expect( keyFor( 5, true, [ 'A', 'B' ] ) ).toBe( first );
		expect( n ).toBe( 1 );
	} );

	test( 'another body, layout or codes is another request with its own key', () => {
		let n = 0;
		const keyFor = createKeys( () => 'key' + String( ++n ).padStart( 6, '0' ) );
		const keys = new Set( [
			keyFor( 5, true, [ 'A' ] ),
			keyFor( 6, true, [ 'A' ] ),
			keyFor( 5, false, [ 'A' ] ),
			keyFor( 5, true, [ 'A', 'B' ] ),
		] );
		expect( keys.size ).toBe( 4 );
	} );
} );

describe( 'a failed preview', () => {
	test( 'core\'s 400 for too many codes is told apart from any other failure', () => {
		expect( isTooMany( { code: 'rest_invalid_param', data: { status: 400 } } ) ).toBe( true );
		expect( isTooMany( { code: 'kalaka_permission_denied', data: { status: 403 } } ) ).toBe( false );
		expect( isTooMany( null ) ).toBe( false );
	} );
} );

describe( 'the text of a failed act', () => {
	test( 'Kaláka\'s own refusal text is shown as the server sent it', () => {
		const text = 'A művelet nem hajtható végre. Előbb add meg a sablon szövegét.';
		expect( problemText( { code: 'kalaka_mail_no_body', message: text }, 'fallback' ) ).toBe( text );
	} );

	test( 'core\'s English codes and a lost network fall back', () => {
		expect( problemText( { code: 'rest_forbidden', message: 'Sorry, you are not allowed' }, 'fallback' ) ).toBe( 'fallback' );
		expect( problemText( new TypeError( 'Failed to fetch' ), 'fallback' ) ).toBe( 'fallback' );
	} );
} );

describe( 'a server instant', () => {
	test( 'a real one is the site\'s day and time', () => {
		expect( moment( '2026-11-24T23:10:52Z', 'Europe/Budapest' ) ).toBe( '2026. 11. 25. 00:10' );
	} );

	test( 'a body that was never dated (year -1) and an empty value read as a dash, not an error', () => {
		expect( moment( '-0001-11-30T00:00:00Z', 'Europe/Budapest' ) ).toBe( '–' );
		expect( moment( null, 'Europe/Budapest' ) ).toBe( '–' );
	} );
} );
