/**
 * The detail panel's small rules: the remembered tab.
 */
import { TAB_KEY, rememberedTab, rememberTab } from '../../src/Admin/app/grid/panel';

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
