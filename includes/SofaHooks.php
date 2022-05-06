<?php

use MediaWiki\Revision\RenderedRevision;
use MediaWiki\Revision\RevisionRecord;

class SofaHooks {
	/**
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( $updater ) {
		$base = dirname( __DIR__ );
		$updater->addExtensionTable( 'sofa_map', "$base/sql/tables.sql" );
	}

	/**
	 * @param Parser $parser
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook( 'sofaset', 'SofaHooks::sofaSet' );
		$parser->setFunctionHook( 'sofaget', 'SofaHooks::sofaGet' );
	}

	/**
	 * @param Parser $parser
	 * @param string|null $schema
	 * @param string|null $key
	 * @param string|null $value
	 *
	 * @return string
	 */
	public static function sofaSet( Parser $parser, $schema = null, $key = null, $value = null ) {
		if ( $schema === null || $key === null ) {
			return '<strong class="error">' .
				wfMessage( 'sofa-noschemakey' )->inContentLanguage()->text() .
				'</strong>';
		}

		try {
			$schema = SofaSchema::normalizeSchema( $schema );
		} catch ( InvalidSofaSchemaException $e ) {
			return '<strong class="error">' .
				wfMessage( 'sofa-invalidschema' )->inContentLanguage()->text() .
				'</strong>';
		}
		$key = SofaDB::normalizeKey( $key );

		if ( $key === null ) {
			return '<strong class="error">' .
				wfMessage( 'sofa-invalidkey' )->inContentLanguage()->text() .
				'</strong>';
		}

		$value = $value === null ?: FormatJson::encode( trim( $value ), false, FormatJson::ALL_OK );

		// TODO: Maybe in future we allow empty/null values. Unsure.
		if ( !SofaDB::validateValue( $value ) ) {
			return '<strong class="error">' .
				wfMessage( 'sofa-invalidvalue' )->inContentLanguage()->text() .
				'</strong>';
		}

		$maps = $parser->getOutput()->getExtensionData( 'SofaMaps' ) ?: [];
		// Note, we can have duplicate keys & values.
		$maps[] = [
			'schema' => $schema,
			'key' => $key,
			'value' => $value,
		];
		$parser->getOutput()->setExtensionData( 'SofaMaps', $maps );
		return '';
	}

	/**
	 * Mostly meant for debugging. Maybe should delete later and only have scribunto for get
	 *
	 * @param Parser $parser
	 * @param string $schema Schema name
	 * @param string ...$params Other parameters
	 * @return string Wikitext to output
	 */
	public static function sofaGet( Parser $parser, $schema, ...$params ) {
		try {
			$schema = SofaSchema::normalizeSchema( $schema );
		} catch ( InvalidSofaSchemaException $e ) {
			return '<strong class="error">' .
				wfMessage( 'sofa-invalidschema' )->inContentLanguage()->text() .
				'</strong>';
		}

		$start = null;
		$stop = null;
		$limit = 25;
		foreach ( $params as $param ) {
			if ( !strpos( $param, '=' ) ) {
				continue;
			}
			list( $left, $right ) = explode( "=", trim( $param ), 2 );
			switch ( trim( $left ) ) {
				case 'start':
					$start = trim( $right );
					break;
				case 'stop':
					$stop = trim( $right );
					break;
				case 'limit':
					$lim = (int)trim( $right );
					if ( $lim >= 1 && $lim <= 5000 ) {
						$limit = $lim;
					}
					break;
			}
		}

		$sf = new SofaFetch;
		$res = $sf->get( $schema, $start, $stop, $limit );

		$last = null;
		$count = $res->numRows();
		$out = '';
		$cacheInfo = $parser->getOutput()->getExtensionData( 'SofaCacheInfo' ) ?: [];
		foreach ( $res as $row ) {
			$last = $row->sm_key;

			// FIXME should be optimized to not do a new query each page.
			$title = Title::newFromId( $row->sm_page );
			if ( !$title ) {
				wfDebug( __METHOD__ . ' page=' . $row->sm_page . ' missing!' );
				continue;
			}
			$out .= ';[[' . wfEscapeWikiText( $title->getFullText() ) . '|'
				. wfEscapeWikiText( $row->sm_key ) . "]]\n";
			if ( $row->sm_value !== null ) {
				$out .= ':' . wfEscapeWikiText( $row->sm_value ) . "\n";
			}
			$cacheInfo[$row->sm_id] = true;
		}
		$parser->getOutput()->setExtensionData( 'SofaCacheInfo', $cacheInfo );
		return $out;
	}

	/**
	 * Insert our update job
	 *
	 * @param Title $title
	 * @param RenderedRevision $rendRev Wrapper containing ParserOutput
	 * @param DeferrableUpdate[] &$allUpdates List of updates for this page
	 */
	public static function onRevisionDataUpdates(
		Title $title,
		RenderedRevision $rendRev,
		array &$allUpdates
	) {
		$pout = $rendRev->getRevisionParserOutput();
		$smaps = $pout->getExtensionData( 'SofaMaps' );
		if ( $smaps ) {
			// We have data, so schedule an update
			$allUpdates[] = new SofaMapUpdate( $title, $smaps );
		}

		$scache = $pout->getExtensionData( 'SofaCacheInfo' );
		if ( $scache ) {
			$allUpdates[] = new SofaCacheUpdate( $title, $scache );
		}
	}

	/**
	 * Hook called for generating page deletion related updates
	 *
	 * @param Title $title Page being deleted
	 * @param RevisionRecord $revRec RevisionRecord of page being deleted
	 * @param DeferrableUpdate[] &$updates List of updates to run
	 */
	public static function onPageDeletionDataUpdates(
		Title $title,
		RevisionRecord $revRec,
		array &$updates
	) {
		// FIXME: This does not trigger refresh page jobs for things
		// referencing the now deleted page.
		$id = $revRec->getPageId();
		if ( !$id ) {
			throw new LogicException( "Unknown page id" );
		}
		// @todo Maybe this should be its own job
		$sofaDB = new SofaDB;
		$updates = array_merge( $updates, $sofaDB->getDeletionUpdates( $id ) );
	}

	/**
	 * Hook to load our lua library
	 *
	 * @param string $engine
	 * @param array &$extraLibraries
	 */
	public static function onScribuntoExternalLibraries( $engine, &$extraLibraries ) {
		$extraLibraries['mw.ext.sofa'] = SofaLuaLibrary::class;
	}
}
