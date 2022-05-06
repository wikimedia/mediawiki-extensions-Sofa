<?php

class SofaLuaLibrary extends Scribunto_LuaLibraryBase {

	/**
	 * @return array|null
	 */
	public function register() {
		// FIXME add callbacks
		$lib = [
			'query' => [ $this, 'query' ]
		];

		return $this->getEngine()->registerInterface(
			__DIR__ . '/../lua/mw.ext.sofa.lua',
			$lib,
			// argument to setup function
			[]
		);
	}

	/**
	 * Interface exposed to lua to do a query
	 *
	 * @param array $args List of args: schema, start, stop, limit
	 * @return array
	 */
	public function query( $args ) {
		$this->checkType( 'query', 1, $args, 'table' );

		if ( $this->getLuaType( $args['schema'] ?? false ) !== 'string' ) {
			// FIXME should errors in lua be i18n??
			throw new Scribunto_LuaError( "mw.ext.sofa.query() expects schema to be set and be a string" );
		}
		if ( $this->getLuaType(
			$args['limit'] ?? 0 ) !== 'number' ||
			!is_int( $args['limit'] ?? 0 ) ||
			( $args['limit'] ?? 1 ) <= 0 ||
			( $args['limit'] ?? 1 ) >= 5000
		) {
			// FIXME upper limit should not be hardcoded.
			throw new Scribunto_LuaError( "mw.ext.sofa.query() expects limit to be a number" );
		}

		// FIXME validate start and stop. Need to figure
		// out what plan is for multi-valued keys.

		$this->incrementExpensiveFunctionCount();

		try {
			$schema = SofaSchema::normalizeSchema( $args['schema'] );

		} catch ( InvalidSofaSchemaException $e ) {
			// FIXME, should this be i18n?
			throw new Scribunto_LuaError( wfMessage( 'sofa-invalidschema' )->inContentLanguage()->text() );
		}

		$limit = is_int( $args['limit'] ) ? $args['limit'] : 25;
		$start = isset( $args['start'] ) ? trim( $args['start'] ) : null;
		$stop = isset( $args['stop'] ) ? trim( $args['stop'] ) : null;

		$sf = new SofaFetch;
		$res = $sf->get( $schema, $start, $stop, $limit );
		$cacheInfo = $this->getParser()->getOutput()->getExtensionData( 'SofaCacheInfo' ) ?: [];

		$luaResults = [ null ];
		foreach ( $res as $item ) {
			// FIXME, should batch this.
			$title = Title::newFromId( $item->sm_page );
			$luaResults[] = [
				// Not sure if we can preload page id into lua Title obj.
				'pageId' => $item->sm_page,
				'titleText' => $title->getText(),
				'titleNS' => $title->getNamespace(),
				// FIXME convert to JSON or whatever.
				'value' => $item->sm_value,
				// FIXME, this has to be formatted and split into multiple values
				'key' => $item->sm_key
			];
			$cacheInfo[$item->sm_id] = true;
		}

		$this->getParser()->getOutput()->setExtensionData( 'SofaCacheInfo', $cacheInfo );
		unset( $luaResults[0] );
		return [ $luaResults ];
	}
}
