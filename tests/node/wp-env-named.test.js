'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );

const {
	DEFAULT_PROJECT_NAME,
	buildEnvironment,
	normalizeProjectName,
} = require( '../../bin/wp-env-named' );

test( 'normalizes Docker Compose project names for wp-env containers', () => {
	assert.equal( normalizeProjectName( 'HSP Smart Cache!' ), DEFAULT_PROJECT_NAME );
	assert.equal( normalizeProjectName( '__Bad Name__' ), 'bad-name' );
	assert.equal( normalizeProjectName( '123_PROJECT' ), '123_project' );
	assert.equal( normalizeProjectName( '' ), DEFAULT_PROJECT_NAME );
} );

test( 'sets a stable Compose project name by default', () => {
	const env = buildEnvironment( { PATH: '/bin' } );

	assert.equal( env.COMPOSE_PROJECT_NAME, DEFAULT_PROJECT_NAME );
	assert.equal( env.PATH, '/bin' );
} );

test( 'allows an explicit project name override', () => {
	const env = buildEnvironment( {
		COMPOSE_PROJECT_NAME: 'Ignored',
		HSPSC_WP_ENV_PROJECT_NAME: 'Custom HSPSC Tests',
	} );

	assert.equal( env.COMPOSE_PROJECT_NAME, 'custom-hspsc-tests' );
} );

test( 'npm wp-env scripts use the naming wrapper', () => {
	const packageJson = JSON.parse(
		fs.readFileSync( path.resolve( __dirname, '..', '..', 'package.json' ), 'utf8' )
	);

	for ( const scriptName of [ 'wp-env:start', 'wp-env:stop', 'wp-env:destroy', 'wp-env:tests' ] ) {
		assert.match( packageJson.scripts[ scriptName ], /node \.\/bin\/wp-env-named\.js/ );
	}
} );
