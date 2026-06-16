#!/usr/bin/env node
'use strict';

const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { spawnSync } = require( 'node:child_process' );

const DEFAULT_PROJECT_NAME = 'hsp-smart-cache';

function normalizeProjectName( value ) {
	const normalized = String( value || DEFAULT_PROJECT_NAME )
		.toLowerCase()
		.replace( /[^a-z0-9_-]+/g, '-' )
		.replace( /^[^a-z0-9]+/, '' )
		.replace( /[^a-z0-9]+$/, '' );

	return normalized || DEFAULT_PROJECT_NAME;
}

function buildEnvironment( baseEnv = process.env ) {
	const projectName = baseEnv.HSPSC_WP_ENV_PROJECT_NAME || baseEnv.COMPOSE_PROJECT_NAME || DEFAULT_PROJECT_NAME;

	return {
		...baseEnv,
		COMPOSE_PROJECT_NAME: normalizeProjectName( projectName ),
	};
}

function resolveWpEnvCommand() {
	const localNodeBin = path.resolve( __dirname, '..', 'node_modules', '@wordpress', 'env', 'bin', 'wp-env' );
	if ( fs.existsSync( localNodeBin ) ) {
		return {
			command: process.execPath,
			args: [ localNodeBin ],
		};
	}

	const binName = process.platform === 'win32' ? 'wp-env.cmd' : 'wp-env';
	return {
		command: binName,
		args: [],
	};
}

function runWpEnv( args = process.argv.slice( 2 ), options = {} ) {
	const wpEnvCommand = resolveWpEnvCommand();
	const result = spawnSync(
		wpEnvCommand.command,
		[ ...wpEnvCommand.args, ...args ],
		{
			stdio: 'inherit',
			...options,
			env: buildEnvironment( options.env || process.env ),
		}
	);

	if ( result.error ) {
		throw result.error;
	}

	return typeof result.status === 'number' ? result.status : 1;
}

if ( require.main === module ) {
	process.exitCode = runWpEnv();
}

module.exports = {
	DEFAULT_PROJECT_NAME,
	buildEnvironment,
	normalizeProjectName,
	runWpEnv,
};
