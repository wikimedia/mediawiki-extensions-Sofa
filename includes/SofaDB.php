<?php

// FIXME: This class structure made more sense in my head
// then it does when I wrote it all out. I already think it should be refactored.
class SofaDB {

	/** @var IDatabase */
	private $dbw;

	/**
	 * @param SofaDBManager|null $dbm
	 */
	public function __construct( $dbm = null ) {
		$this->dbw = $dbm ? $dbm->getDbw() : wfGetDB( DB_MASTER );
	}

	/**
	 * Set sofa_map entries for a page and remove previous entries
	 *
	 * @param Title $title
	 * @param array $maps Array containing assoc array of 'key' and 'value'
	 */
	public function setForPage( Title $title, array $maps ) {
		$pageId = $title->getArticleId( Title::READ_LATEST );
		if ( $pageId <= 0 && $maps ) {
			// If the page is deleted, should have no maps.
			// Currently not used to refresh for page delete, but maybe
			// do so in future.
			throw new LogicException( "page id is out of range" );
		}
		$dbw = $this->dbw;
		$cur = $dbw->select(
			[ 'sofa_map', 'sofa_schema' ],
			[ 'sm_id', 'sm_key', 'sm_value', 'sms_name', 'sm_schema' ],
			[ 'sm_page' => $pageId, 'sms_id=sm_schema' ],
			__METHOD__
		);
		// This could be made more efficient
		// Keep in mind, having duplicate entries with same key/value is allowed.
		$idsToDelete = [];
		$schemasModified = [];
		foreach ( $cur as $row ) {
			foreach ( $maps as &$newmap ) {
				if (
					$newmap &&
					$newmap['key'] === $row->sm_key
					&& $newmap['value'] === $row->sm_value
					&& $newmap['schema'] === $row->sms_name
				) {
					$newmap = null;
					continue 2;
				}
			}
			$idsToDelete[] = $row->sm_id;
			$schemasModified[] = $row->sm_schema;
		}
		$dbw->startAtomic( __METHOD__ );
		if ( $idsToDelete ) {
			$dbw->delete( 'sofa_map', [ 'sm_id' => $idsToDelete ], __METHOD__ );
		}
		$inserts = [];
		$sofaSchema = SofaSchema::singleton();
		foreach ( $maps as $newmap ) {
			if ( $newmap === null ) {
				continue;
			}
			$schemaId = $sofaSchema->getOrCreateSchemaId( $dbw, $newmap['schema'] );
			$inserts[] = [
				'sm_page' => $pageId,
				'sm_key' => $newmap['key'],
				'sm_value' => $newmap['value'],
				'sm_schema' => $schemaId,
			];
			$schemasModified[] = $schemaId;
		}
		$dbw->insert( 'sofa_map', $inserts, __METHOD__ );
		$dbw->endAtomic( __METHOD__ );
		$schemasModified = array_unique( $schemasModified );
		$this->queueCacheInvalidationJobs( $schemasModified );
	}

	/**
	 * Queue job to clear page caches that might be out of date due to edit.
	 *
	 * We use a hack of clearing a page named Special:<schema number>. See the BacklinkCache
	 * hooks for details on how that works.
	 *
	 * @param int[] $schemasModified Which schemas were changed.
	 */
	private function queueCacheInvalidationJobs( array $schemasModified ) {
		// We don't have causeAction or causeAgent known to us due to the way hooks
		// are structured.

		// EVIL HACK. This seems to work but is very sketch. We might be better off
		// duplicating code from core.
		foreach ( $schemasModified as $schema ) {
			$encTitle = Title::makeTitle( NS_SPECIAL, (string)$schema );
			LinksUpdate::queueRecursiveJobsForTable( $encTitle, 'sofa_cache' );
			$job = HTMLCacheUpdateJob::newForBacklinks(
				$encTitle,
				'sofa_cache'
			);
			JobQueueGroup::singleton()->lazyPush( $job );
		}
	}

	public function getUpdates( Title $title, array $smaps ) {
		// Fixme figure out transaction stuff better here.
		// Should this be in its own transaction?
		// Should this be in an AutoCommitUpdate?
		// Does the structure of this class even make sense?
		// Also, later todo, make this possibly be a different db.
		$that = $this;
		return [
			new MWCallableUpdate(
				function () use ( $title, $smaps, $that ) {
					$that->setForPage( $title, $smaps );
				},
				__METHOD__,
				$this->dbw
			)
		];
	}

	/**
	 * Get an array of updates to cleanup entries from a soon to be deleted page
	 *
	 * @param int $pageId
	 * @return DeferrableUpdate[]
	 */
	public function getDeletionUpdates( $pageId ) {
		$sofaDb = $this;
		return [
			new MWCallableUpdate(
				function () use ( $pageId, $sofaDb ) {
					$sofaDb->delete( $pageId );
				},
				__METHOD__,
				$this->dbw
			)
		];
	}

	/**
	 * Delete all entries related to a page
	 *
	 * @param int $pageId Id of page to delete stuff for
	 */
	public function delete( $pageId ) {
		$schemas = $this->dbw->selectFieldValues(
			'sofa_map',
			'sm_schema',
			[ 'sm_page' => $pageId ],
			__METHOD__,
			[ 'DISTINCT' ]
		);
		$this->dbw->delete(
			'sofa_map',
			[ 'sm_page' => $pageId ],
			__METHOD__
		);
		$this->dbw->delete(
			'sofa_cache',
			[ 'sc_from' => $pageId ],
			__METHOD__
		);
		$this->queueCacheInvalidationJobs( $schemas );
	}

	/**
	 * Normalize and validate key names
	 *
	 * @param string $key
	 * @return null|string Null if invalid, otherwise the normalized key name
	 */
	public static function normalizeKey( $key ) {
		$key = trim( $key );
		if ( $key === '' ) {
			// In principle there's no reason not to allow the
			// empty string, but that seems like it'd be confusing.
			return null;
		}
		if ( strlen( $key ) > 767 ) {
			return null;
		}
		return $key;
	}

	/**
	 * Validate a value
	 *
	 * @note This does not check that the JSON is valid. It should be.
	 * @param string|null $value JSON or null
	 * @return bool
	 */
	public static function validateValue( $value ) {
		if ( !is_string( $value ) && $value !== null ) {
			return false;
		}
		if ( $value !== null && strlen( $value ) > 65535 ) {
			return false;
		}
		return true;
	}

	/**
	 * Hook for BacklinkCacheGetPrefix used by cache invalidation job
	 *
	 * @param string $table Table name
	 * @param string &$prefix prefix for column names
	 * @return bool
	 */
	public static function onBacklinkCacheGetPrefix( $table, &$prefix ) {
		if ( $table === 'sofa_cache' ) {
			$prefix = 'sc';
		}
		return false;
	}

	/**
	 * Hook for BacklinkCacheGetConditions. Used for cache invalidation job
	 *
	 * @param string $table
	 * @param Title $title
	 * @param array|null &$conds WHERE conditions for SQL query
	 * @return bool|void
	 */
	public static function onBacklinkCacheGetConditions( $table, Title $title, &$conds ) {
		// This is hacky because the core backlinks abstraction doesn't work the way i want
		// it to. In the long run, we may have to duplicate core's BacklinkUtils.

		$dbr = wfGetDB( DB_REPLICA );
		if ( $table === 'sofa_cache' ) {
			// HACK HACK HACK
			// Store schemas as Special:<schemaid>
			if ( $title->inNamespace( NS_SPECIAL ) ) {
				$subquery = (int)$title->getDBKey();
			} else {
				// This doesn't really work because the current state of
				// the page doesn't reflect what schemas used to be set there.
				$subquery = $dbr->selectSQLText(
					'sofa_map',
					'sm_schema',
					[
						'sm_page' => $title->getArticleId()
					]
				);
			}
			$conds = [
				// FIXME no conditions on sc_start or sc_stop? We probably
				// refresh way more than we have to.
				'sc_schema IN (' . $subquery . ')',
				'page_id=sc_from',
			];
		}
		return false;
	}
}
