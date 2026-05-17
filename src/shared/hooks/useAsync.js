import { useCallback, useState } from '@wordpress/element';

export function useAsync( fn ) {
	const [ state, setState ] = useState( { loading: false, error: null, data: null } );

	const run = useCallback(
		async ( ...args ) => {
			setState( { loading: true, error: null, data: null } );
			try {
				const data = await fn( ...args );
				setState( { loading: false, error: null, data } );
				return data;
			} catch ( error ) {
				setState( { loading: false, error, data: null } );
				throw error;
			}
		},
		[ fn ]
	);

	return { ...state, run };
}
