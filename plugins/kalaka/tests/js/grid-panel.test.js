/**
 * The detail panel's small rules: the remembered tab, and where focus goes after a save.
 */
import { TAB_KEY, rememberedTab, rememberTab, returnFocus } from '../../src/Admin/app/grid/panel';

const memory = () => {
	const data = {};
	return {
		getItem: ( key ) => ( key in data ? data[ key ] : null ),
		setItem: ( key, value ) => {
			data[ key ] = String( value );
		},
	};
};

describe( 'the remembered tab', () => {
	test( 'opens the first tab when nothing was clicked yet', () => {
		expect( rememberedTab( [ 'data', 'money' ], memory() ) ).toBe( 'data' );
	} );

	test( 'the last-clicked tab stays for the next registration', () => {
		const store = memory();
		rememberTab( 'money', store );
		expect( store.getItem( TAB_KEY ) ).toBe( 'money' );
		expect( rememberedTab( [ 'data', 'money' ], store ) ).toBe( 'money' );
	} );

	test( 'a remembered tab this branch lacks falls back to the first', () => {
		const store = memory();
		rememberTab( 'requests', store );
		expect( rememberedTab( [ 'money' ], store ) ).toBe( 'money' );
	} );

	test( 'a storage that refuses is ignored', () => {
		const broken = {
			getItem: () => {
				throw new Error( 'denied' );
			},
			setItem: () => {
				throw new Error( 'denied' );
			},
		};
		expect( () => rememberTab( 'money', broken ) ).not.toThrow();
		expect( rememberedTab( [ 'money', 'data' ], broken ) ).toBe( 'money' );
	} );
} );

describe( 'focus after a save', () => {
	const button = ( label ) => {
		const el = document.createElement( 'button' );
		el.textContent = label;
		document.body.appendChild( el );
		return el;
	};

	afterEach( () => {
		document.body.innerHTML = '';
	} );

	test( 'returns to the control that opened the dialog', () => {
		const opener = button( 'open' );
		const close = button( 'close' );
		expect( returnFocus( opener, close ) ).toBe( opener );
		expect( document.activeElement ).toBe( opener );
	} );

	test( 'goes to the close button when the opener is gone', () => {
		const opener = button( 'open' );
		const close = button( 'close' );
		opener.remove();
		expect( returnFocus( opener, close ) ).toBe( close );
		expect( document.activeElement ).toBe( close );
	} );

	test( 'does nothing when neither is on the page', () => {
		const opener = button( 'open' );
		opener.remove();
		expect( returnFocus( opener, null ) ).toBeNull();
		expect( returnFocus( null, null ) ).toBeNull();
	} );
} );
