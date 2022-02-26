<?php

use Wikimedia\Rdbms\IDatabase;

/**
 * Stub for now, might do more if we support sharding things in future.
 *
 * I don't really know if i like this structure.
 */
class SofaDBManager {
	/** @var IDatabase Read-only DB */
	private $dbr;
	/** @var IDatabase Master DB */
	private $dbw;

	/**
	 * @param IDatabase|null $dbr Read only db handle
	 * @param IDatabase|null $dbw read-write db handle.
	 */
	public function __construct( $dbr = null, $dbw = null ) {
		$this->dbr = $dbr ?: wfGetDB( DB_REPLICA );
		$this->dbw = $dbw ?: wfGetDB( DB_PRIMARY );
	}

	/**
	 * @return self
	 */
	public static function singleton() {
		static $singleton;
		if ( !$singleton ) {
			$singleton = new self;
		}
		return $singleton;
	}

	/**
	 * Get a DB_REPLICA
	 *
	 * @return IDatabase
	 */
	public function getDbr() {
		return $this->dbr;
	}

	/**
	 * Get a DB_PRIMARY
	 *
	 * @return IDatabase
	 */
	public function getDbw() {
		return $this->dbw;
	}
}
