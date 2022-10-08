<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;

// FIXME: This class structure made more sense in my head
// then it does when I wrote it all out. I already think it should be refactored.
class SofaDB {

	public const SOFA_CACHE_HTML_ONLY = 0;
	public const SOFA_CACHE_LINKS = 0;
	/** @var IDatabase */
	private $dbw;

	/**
	 * @param SofaDBManager|null $dbm
	 */
	public function __construct( $dbm = null ) {
		$this->dbw = $dbm ? $dbm->getDbw() : wfGetDB( DB_PRIMARY );
	}

	/**
	 * Set sofa_map entries for a page and remove previous entries
	 *
	 * @param Title $title
	 * @param array $maps Array containing assoc array of 'key' and 'value'
	 */
	public function setForPage( Title $title, array $maps ) {
		// FIXME, there is a possibility of indef loop, since a page
		// can both depend on a schema and have it on itself.
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
		}
		$dbw->startAtomic( __METHOD__ );
		if ( $idsToDelete ) {
			$dbw->delete( 'sofa_map', [ 'sm_id' => $idsToDelete ], __METHOD__ );
		}
		$inserts = [];
		$sofaSchema = SofaSchema::singleton();
		$invalidationsToExpand = [];
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
			if ( !isset( $invalidationsToExpand[$schemaId] ) ) {
				$invalidationsToExpand[$schemaId] = [];
			}
			$invalidationsToExpand[$schemaId][] = $newmap['key'];
		}
		$dbw->insert( 'sofa_map', $inserts, __METHOD__ );
		$dbw->endAtomic( __METHOD__ );

		$additionalInvalidations = $this->expandInvalidations( $invalidationsToExpand );
		$invalidations = array_merge( $additionalInvalidations, $idsToDelete );
		$invalidations = array_unique( $invalidations );
		$this->queueCacheInvalidationJobs( $invalidations );
	}

	/**
	 * Add the item immediately above and below for invalidation
	 *
	 * Since most pages use a range, we want to ensure we also clear
	 * cache of pages that use a range that ends in the middle of
	 * existing items. To do that, we clear the item immediately
	 * above and below the new item to purge the "gaps".
	 *
	 * Its not clear if this is the best place to do the expanding.
	 * it might make more sense to do so in onBacklinkCacheGetConditions
	 * since there is likely to be a lot of duplication in the results.
	 *
	 * @param array $invalidations [ schema => [keys] ]
	 * @return int[] ids
	 */
	private function expandInvalidations( array $invalidations ) {
		// FIXME, do we need to batch these? Should this be in a job?
		// somewhere else? I'm not super happy with this tbh.
		$queries = [];
		foreach ( $invalidations as $schema => $keys ) {
			foreach ( $keys as $key ) {
				// It's possible we have multiple items with same key
				$queries[] = $this->dbw->selectSQLText(
					'sofa_map',
					'sm_id',
					[
						'sm_schema' => $schema,
						'sm_key =' . $this->dbw->addQuotes( $key )
					],
					__METHOD__
				);
				$queries[] = $this->dbw->selectSQLText(
					'sofa_map',
					'sm_id',
					[
						'sm_schema' => $schema,
						'sm_key >' . $this->dbw->addQuotes( $key )
					],
					__METHOD__,
					[
						'LIMIT' => 1,
						'ORDER BY' => 'sm_key ASC'
					]
				);
				$queries[] = $this->dbw->selectSQLText(
					'sofa_map',
					'sm_id',
					[
						'sm_schema' => $schema,
						'sm_key <' . $this->dbw->addQuotes( $key )
					],
					__METHOD__,
					[
						'LIMIT' => 1,
						'ORDER BY' => 'sm_key DESC'
					]
				);
			}
		}
		if ( !$queries ) {
			return [];
		}
		$unionQuery = $this->dbw->unionQueries( $queries, $this->dbw::UNION_ALL );
		$res = $this->dbw->query( $unionQuery, __METHOD__ );
		$ids = [];
		foreach ( $res as $row ) {
			$ids[] = $row->sm_id;
		}
		return $ids;
	}

	/**
	 * Queue job to clear page caches that might be out of date due to edit.
	 *
	 * We use a hack of clearing a page named Sofa:Cache/<mapid>. See the BacklinkCache
	 * hooks for details on how that works.
	 *
	 * @param array $idsToPurge sc_id's to purge
	 */
	private function queueCacheInvalidationJobs( array $idsToPurge ) {
		// We don't have causeAction or causeAgent known to us due to the way hooks
		// are structured.

		// EVIL HACK. This seems to work but is very sketch. We might be better off
		// duplicating code from core.
		$jobs = [];
		foreach ( $idsToPurge as $id ) {
			// FIXME this doesn't adequetely defend against indef loops.
			// Future, maybe we should have multiple map ids at once
			$encTitle = Title::makeTitle( NS_SOFA, 'Cache' . '/' . $id );
			// At one point, i considered trying to tell if any metadata
			// depended on this, and only do html cache update in that case.
			// It doesn't seem worth it for just the list output, but might
			// revisit in future.
			LinksUpdate::queueRecursiveJobsForTable( $encTitle, 'sofa_cache' );
			$jobs[] = HTMLCacheUpdateJob::newForBacklinks(
				$encTitle,
				'sofa_cache'
			);
		}
		if ( method_exists( MediaWikiServices::class, 'getJobQueueGroup' ) ) {
			// MW 1.37+
			MediaWikiServices::getInstance()->getJobQueueGroup()->lazyPush( $jobs );
		} else {
			// @phan-suppress-next-line PhanUndeclaredStaticMethod
			JobQueueGroup::singleton()->lazyPush( $jobs );
		}
	}

	/**
	 * @param Title $title
	 * @param array $smaps
	 * @return MWCallableUpdate[]
	 */
	public function getUpdates( Title $title, array $smaps ) {
		// Fixme figure out transaction stuff better here.
		// Should this be in its own transaction?
		// Should this be in an AutoCommitUpdate?
		// Does the structure of this class even make sense?
		// Also, later todo, make this possibly be a different db.
		return [
			new MWCallableUpdate(
				function () use ( $title, $smaps ) {
					$this->setForPage( $title, $smaps );
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
		return [
			new MWCallableUpdate(
				function () use ( $pageId ) {
					$this->delete( $pageId );
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
		$res = $this->dbw->select(
			[ 'sofa_map', 'sofa_schema' ],
			[ 'sms_name', 'sm_id', 'sm_key' ],
			[ 'sm_page' => $pageId, 'sms_id=sm_schema' ],
			__METHOD__
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

		// Since we are deleting we do not need to purge gaps.
		$invalidations = [];
		foreach ( $res as $row ) {
			$invalidations[] = $row->sm_id;
		}
		$this->queueCacheInvalidationJobs( $invalidations );
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
		// Leave a little room for subpage hack
		if ( strlen( $key ) > 240 ) {
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
			$id = self::getIdsFromEncodedTitle( $title, $dbr );
			$conds = [
				'page_id=sc_from',
				'sc_map_id' => $id
			];
		}
		return false;
	}

	/**
	 * Given a Title of the form Sofa:SchemaName get subquery to get schema id, and map_ids
	 *
	 * @param Title $title e.g. Sofa:Foo/1234
	 * @param IDatabase $dbr
	 * @return int Id
	 */
	private static function getIdsFromEncodedTitle( Title $title, IDatabase $dbr ) {
		if ( !$title->inNamespace( NS_SOFA ) ) {
			throw new UnexpectedValueException( "Expected NS to be NS_SOFA" );
		}

		list( $pageName, $id ) = explode( '/', $title->getDBKey(), 2 );
		if ( $id === null || $pageName !== 'Cache' || (int)$id <= 0 ) {
			throw new UnexpectedValueException( "invalid title format" );
		}

		return (int)$id;
	}
}
