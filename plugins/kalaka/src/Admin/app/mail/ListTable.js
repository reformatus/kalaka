/**
 * A small read-only table of the mail screens, in DataViews' own table layout, as the grid uses it: the rows are all
 * in memory, so DataViews sorts them (header menus) and the table has no search, filters or paging of its own.
 * [UNAPPROVED N277]
 */
import { useMemo, useState } from '@wordpress/element';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';

export default function ListTable( { data, fields, getId, empty, className, styles } ) {
	const [ view, setView ] = useState( {
		type: 'table',
		page: 1,
		perPage: 1000,
		search: '',
		filters: [],
		fields: fields.map( ( f ) => f.id ),
		layout: { density: 'compact', enableMoving: false, styles },
	} );
	const shown = useMemo( () => filterSortAndPaginate( data, view, fields ), [ data, view, fields ] );
	return (
		<div className={ 'km-table ' + ( className || '' ) }>
			<DataViews
				data={ shown.data }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				paginationInfo={ shown.paginationInfo }
				defaultLayouts={ { table: {} } }
				getItemId={ getId }
				actions={ [] }
				search={ false }
				empty={ empty }
			>
				<DataViews.Layout />
			</DataViews>
		</div>
	);
}
