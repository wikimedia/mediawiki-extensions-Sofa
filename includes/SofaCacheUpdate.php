<?php
/**
 * Update the sofa_cache table (Cache information table).
 *
 * @note This is not responsible for updating caches, just the tracking
 *  table that keeps track of what page needs to be invalidated when.
 */
class SofaCacheUpdate implements DeferrableUpdate {

	/** @var array List of sofa maps to add */
	private $scache;
	/** @var Title */
	private $title;
	/** @var SofaDBManager */
	private $dbm;
	/** @var SofaSchema */
	private $schema;

	/**
	 * @param Title $title
	 * @param array $scache
	 * @param SofaDBManager|null $dbm Null for default instance
	 * @param SofaSchema|null $schema Null for default instance
	 */
	public function __construct( Title $title, array $scache, $dbm = null, $schema = null ) {
		$this->title = $title;
		$this->scache = $scache;
		$this->dbm = $dbm ?: SofaDBManager::singleton();
		$this->schema = $schema ?: SofaSchema::singleton();
	}

	public function doUpdate() {
		$dbw = $this->dbm->getDbw();
		$pageId = $this->title->getArticleID( Title::READ_LATEST );
		$inserts = [];
		foreach ( $this->scache as $schema => $values ) {
			$schemaId = $this->schema->getOrCreateSchemaId( $dbw, $schema );
			foreach ( $values as $startStop ) {
				list( $start, $stop ) = $startStop;
				$inserts[] = [
					'sc_from' => $pageId,
					'sc_schema' => $schemaId,
					'sc_start' => $start,
					'sc_stop' => $stop
				];
			}
		}
		// FIXME Maybe this should avoid deleting rows that stay constant.
		$dbw->delete(
			'sofa_cache',
			[ 'sc_from' => $pageId ],
			__METHOD__
		);
		$dbw->insert(
			'sofa_cache',
			$inserts,
			__METHOD__,
			[ 'IGNORE' ]
		);
	}

}
