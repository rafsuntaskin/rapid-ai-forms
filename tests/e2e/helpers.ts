import { execSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';

/** Run a WP-CLI command inside the wp-env `cli` container. */
export function wpCli( args: string ): string {
	return execSync( `npx wp-env run cli wp ${ args }`, {
		cwd: path.resolve( __dirname, '..', '..' ),
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} );
}

export interface Fixtures {
	contactFormId: number;
	contactFormUuid: string;
	contactPageUrl: string;
	seededAt: string;
}

/** Read the fixtures written by seed.php during globalSetup. */
export function readFixtures(): Fixtures {
	const file = path.resolve( __dirname, '.fixtures.json' );
	return JSON.parse( readFileSync( file, 'utf8' ) ) as Fixtures;
}
