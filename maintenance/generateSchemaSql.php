<?php

/**
 * Convert a JSON abstract schema to a schema file in the given DBMS type
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 */

use Doctrine\SqlFormatter\NullHighlighter;
use Doctrine\SqlFormatter\SqlFormatter;
use Wikimedia\Rdbms\DoctrineSchemaBuilderFactory;

require_once __DIR__ . '/Maintenance.php';

/**
 * Maintenance script to generate schema from abstract json files.
 *
 * @ingroup Maintenance
 */
class GenerateSchemaSql extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Build SQL files from abstract JSON files' );

		$this->addOption(
			'json',
			'Path to the json file. Default: tables.json',
			false,
			true
		);
		$this->addOption(
			'sql',
			'Path to output. Default: tables-generated.sql',
			false,
			true
		);
		$this->addOption(
			'type',
			'Can be either \'mysql\', \'sqlite\', or \'postgres\'. Default: mysql',
			false,
			true
		);
	}

	public function execute() {
		global $IP;
		$platform = $this->getOption( 'type', 'mysql' );
		$jsonPath = $this->getOption( 'json', __DIR__ . '/tables.json' );
		$relativeJsonPath = str_replace( "$IP/", '', $jsonPath );
		$sqlPath = $this->getOption( 'sql', __DIR__ . '/tables-generated.sql' );
		$abstractSchema = json_decode( file_get_contents( $jsonPath ), true );
		$schemaBuilder = ( new DoctrineSchemaBuilderFactory() )->getSchemaBuilder( $platform );

		if ( $abstractSchema === null ) {
			$this->fatalError( "'$jsonPath' seems to be invalid json. Check the syntax and try again!" );
		}

		foreach ( $abstractSchema as $table ) {
			$schemaBuilder->addTable( $table );
		}
		$sql = "-- This file is automatically generated using maintenance/generateSchemaSql.php.\n" .
			"-- Source: $relativeJsonPath\n" .
			"-- Do not modify this file directly.\n" .
			"-- See https://www.mediawiki.org/wiki/Manual:Schema_changes\n";

		$tables = $schemaBuilder->getSql();
		if ( $tables !== [] ) {
			// Temporary
			$sql = $sql . implode( ";\n\n", $tables ) . ';';
			$sql = ( new SqlFormatter( new NullHighlighter() ) )->format( $sql );
		}

		// Postgres hacks
		if ( $platform === 'postgres' ) {
			// Remove table prefixes from Postgres schema, people should not set it
			// but better safe than sorry.
			$sql = str_replace( "\n/*_*/\n", ' ', $sql );

			// FIXME: Also fix a lot of weird formatting issues caused by
			// presence of partial index's WHERE clause, this should probably
			// be done in some better way, but for now this can work temporaily
			$sql = str_replace(
				[ "WHERE\n ", "\n  /*_*/\n  ", "    ", "  );", "KEY(\n  " ],
				[ "WHERE", ' ', "  ", ');', "KEY(\n    " ],
				$sql
			);

			// MySQL goes with varbinary for collation reasons, but postgres can't
			// properly understand BYTEA type and works just fine with TEXT type
			// FIXME: This should be fixed at some point (T257755)
			$sql = str_replace( "BYTEA", 'TEXT', $sql );
		}

		if ( $platform === 'mysql' ) {
			// Temporary
			// Convert DOUBLE PRECISION (which is default float format in DBAL) to FLOAT
			$sql = str_replace( "DOUBLE PRECISION", 'FLOAT', $sql );
		}

		// Until the linting issue is resolved
		// https://github.com/doctrine/sql-formatter/issues/53
		$sql = str_replace( "\n/*_*/\n", " /*_*/", $sql );
		$sql = str_replace( "; CREATE ", ";\n\nCREATE ", $sql );
		$sql = str_replace( ";\n\nCREATE TABLE ", ";\n\n\nCREATE TABLE ", $sql );
		$sql = str_replace(
			"\n" . '/*$wgDBTableOptions*/' . ";",
			' /*$wgDBTableOptions*/;',
			$sql
		);
		$sql = str_replace(
			"\n" . '/*$wgDBTableOptions*/' . "\n;",
			' /*$wgDBTableOptions*/;',
			$sql
		);
		$sql .= "\n";

		file_put_contents( $sqlPath, $sql );
	}

}

$maintClass = GenerateSchemaSql::class;
require_once RUN_MAINTENANCE_IF_MAIN;
