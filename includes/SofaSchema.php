<?php

use Wikimedia\Rdbms\IDatabase;

class SofaSchema {
	/** @var SofaSchema */
	private static $instance;
	/** @var MapCacheLRU */
	private static $cache;
	private const CACHE_SIZE = 500;

	/**
	 * @param MapCacheLRU $cache
	 */
	private function __construct( MapCacheLRU $cache ) {
		self::$cache = $cache;
	}

	/**
	 * @return self
	 */
	public static function singleton() {
		if ( !self::$instance ) {
			self::$instance = new self( new MapCacheLRU( self::CACHE_SIZE ) );
		}
		return self::$instance;
	}

	/**
	 * Get the id for a given schema name
	 *
	 * @note Schema names are immutable
	 * @param IDatabase $db
	 * @param string $name Name of schema
	 * @return int|false Schema id or false if does not exist
	 */
	public function getSchemaId( IDatabase $db, $name ) {
		$name = trim( $name );
		$fname = __METHOD__;
		return self::$cache->getWithSetCallback(
			$name,
			static function () use ( $name, $db, $fname ) {
				// Note: will not cache if no results
				$id = $db->selectField(
					'sofa_schema',
					'sms_id',
					[ 'sms_name' => $name ],
					$fname
				);
				return $id ? (int)$id : false;
			}
		);
	}

	/**
	 * Get the schema id given name and insert if doesn't exist
	 *
	 * @param IDatabase $db Must be a DB_PRIMARY not DB_REPLICA
	 * @param string $name The schema name
	 * @return int
	 */
	public function getOrCreateSchemaId( IDatabase $db, $name ) {
		$schema = self::normalizeSchema( $name );
		$id = $this->getSchemaId( $db, $schema );
		if ( $id ) {
			return $id;
		}
		$db->insert(
			'sofa_schema',
			[ 'sms_name' => $schema ],
			__METHOD__,
			[ 'IGNORE' ]
		);

		if ( $db->affectedRows() === 0 ) {
			// Race condition.
			$id = $this->getSchemaId( $db, $schema );
			if ( !$id ) {
				throw new LogicException( "id both does and doesn't exist!?" );
			}
			return $id;
		}
		$id = $db->insertId();
		if ( !is_int( $id ) || $id < 1 ) {
			throw new LogicException( "id is invalid" );
		}
		self::$cache->set( $schema, $id );
		return $id;
	}

	/**
	 * Normalize and validate schema names
	 *
	 * @param string $schema
	 * @return string
	 */
	public static function normalizeSchema( $schema ) {
		$schema = trim( $schema );
		if ( $schema === '' ) {
			// In principle there's no reason not to allow the
			// empty string, but that seems like it'd be confusing.
			throw new InvalidSofaSchemaException( "Empty string not allowed" );
		}
		$a = Title::makeTitleSafe( NS_SOFA, $schema );
		if ( !$a ) {
			throw new InvalidSofaSchemaException( "Must be a valid title" );
		}
		// FIXME, we're now at 255. Should change DB schema maybe?
		if ( strlen( $schema ) > 767 ) {
			throw new InvalidSofaSchemaException( "Too long. Cannot be over 767 bytes" );
		}
		return $a->getDBKey();
	}
}
