<?php

class SofaMapUpdate implements DeferrableUpdate {

	/** @var array List of sofa maps to add */
	private $smaps;
	/** @var Title */
	private $title;

	/**
	 * @param Title $title
	 * @param array $smaps The sofa attributes being added to the page
	 */
	public function __construct( Title $title, array $smaps ) {
		$this->title = $title;
		$this->smaps = $smaps;
	}

	public function doUpdate() {
		$sdb = new SofaDB;
		$updates = $sdb->getUpdates( $this->title, $this->smaps );
		foreach ( $updates as $update ) {
			$update->doUpdate();
		}
		// We don't have causeAction or causeAgent known to us due to the way hooks
		// are structured.
		// FIXME: This will refresh things modified or added, but does not refresh
		// things deleted.
		/*LinksUpdate::queueRecursiveJobsForTable( $this->title, 'sofa_cache' );
		$job = HTMLCacheUpdateJob::newForBacklinks(
			$this->title,
			'sofa_cache',
		);
		if ( method_exists( MediaWikiServices::class, 'getJobQueueGroup' ) ) {
			// MW 1.37+
			MediaWikiServices::getInstance()->getJobQueueGroup()->lazyPush( $job );
		} else {
			JobQueueGroup::singleton()->lazyPush( $job );
		}
*/
	}

}
