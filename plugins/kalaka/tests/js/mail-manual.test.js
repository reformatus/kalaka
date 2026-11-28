/**
 * The manual mail's pure logic: the repeat guard's keys (one per send attempt) and the format helpers.
 */
import { createKeys, isRefusal, isTooMany, newRequestKey, pageKeys } from '../../src/Admin/app/mail/manual';
import { moment, problemText } from '../../src/Admin/app/mail/format';

describe( 'request keys', () => {
	test( 'a key has the shape the server accepts', () => {
		for ( let i = 0; i < 50; i++ ) {
			expect( newRequestKey() ).toMatch( /^[A-Za-z0-9_-]{8,64}$/ );
		}
	} );

	const counting = () => {
		let n = 0;
		return () => 'key' + String( ++n ).padStart( 6, '0' );
	};
	const ALL = { view: 3, grant: 0, filters: [], search: '' };

	test( 'an attempt without an answer keeps its key, in any order of the codes', () => {
		const keys = createKeys( counting() );
		const first = keys.keyFor( 5, { codes: [ 'B', 'A' ] } );
		expect( keys.keyFor( 5, { codes: [ 'A', 'B' ] } ) ).toBe( first );
	} );

	test( 'a spent key is gone: the same body to the same selection gets a fresh one', () => {
		const keys = createKeys( counting() );
		const first = keys.keyFor( 5, ALL );
		keys.spend( first );
		expect( keys.keyFor( 5, { ...ALL } ) ).not.toBe( first );
	} );

	test( 'two deliberate sends of the same body to the same all-matching selection carry two keys', () => {
		const keys = createKeys( counting() );
		const first = keys.keyFor( 5, ALL );
		keys.spend( first );
		const second = keys.keyFor( 5, ALL );
		keys.spend( second );
		expect( new Set( [ first, second, keys.keyFor( 5, ALL ) ] ).size ).toBe( 3 );
	} );

	test( 'another body or selection is another request with its own key, even when the first got no answer', () => {
		// The levélkeret is the template's own property (walkthrough 8), so it is no input and no part of the key.
		const keys = createKeys( counting() );
		const first = keys.keyFor( 5, { codes: [ 'A' ] } );
		const second = keys.keyFor( 6, { codes: [ 'A' ] } );
		const third = keys.keyFor( 6, { codes: [ 'A', 'B' ] } );
		const fourth = keys.keyFor( 6, { ...ALL, search: 'x' } );
		expect( new Set( [ first, second, third, fourth ] ).size ).toBe( 4 );
	} );

	test( 'spending an old key does not end the attempt that replaced it', () => {
		const keys = createKeys( counting() );
		const first = keys.keyFor( 5, { codes: [ 'A' ] } );
		const second = keys.keyFor( 6, { codes: [ 'A' ] } );
		keys.spend( first );
		expect( keys.keyFor( 6, { codes: [ 'A' ] } ) ).toBe( second );
	} );
} );

describe( 'a refusal', () => {
	test( 'is a 4xx answer; no answer and a server error are not (the outcome is unknown)', () => {
		expect( isRefusal( { code: 'kalaka_mail_no_body', data: { status: 422 } } ) ).toBe( true );
		expect( isRefusal( { code: 'rest_invalid_param', data: { status: 400 } } ) ).toBe( true );
		expect( isRefusal( new TypeError( 'Failed to fetch' ) ) ).toBe( false );
		expect( isRefusal( { code: 'fetch_error', message: 'offline' } ) ).toBe( false );
		expect( isRefusal( { code: 'internal_server_error', data: { status: 500 } } ) ).toBe( false );
		expect( isRefusal( null ) ).toBe( false );
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

	test( 'a null (the server\'s zero or unset date) reads as a dash', () => {
		expect( moment( null, 'Europe/Budapest' ) ).toBe( '–' );
	} );
} );

describe( 'the page\'s keys', () => {
	test( 'one event has one set of keys for the page load, however often its action is rebuilt', () => {
		const first = pageKeys( 71 ).keyFor( 20, { codes: [ 'A1', 'A2' ] } );
		expect( pageKeys( 71 ).keyFor( 20, { codes: [ 'A2', 'A1' ] } ) ).toBe( first );
	} );

	test( 'another event has its own keys', () => {
		expect( pageKeys( 72 ).keyFor( 20, { codes: [ 'A1' ] } ) ).not.toBe( pageKeys( 73 ).keyFor( 20, { codes: [ 'A1' ] } ) );
	} );
} );
