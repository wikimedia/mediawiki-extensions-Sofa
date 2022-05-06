<?php
class SofaFetch {

	/** @var SofaDBManager */
	private $dbm;

	/**
	 * @param SofaDBManager|null $dbm
	 */
	public function __construct( $dbm = null ) {
		$this->dbm = $dbm ?: SofaDBManager::singleton();
	}

	/**
	 * @param string $schema Schema name
	 * @param string|null $start Key to start at
	 * @param string|null $stop Key to stop at
	 * @param int $limit
	 * @return Wikimedia\Rdbms\IResultWrapper
	 */
	public function get( $schema, $start, $stop, $limit ) {
		$dbr = $this->dbm->getDbr();

		$conds = [
			'sms_name' => $schema,
			'sms_id=sm_schema'
		];
		if ( $start !== null ) {
			$conds[] = 'sm_key >= ' . $dbr->addQuotes( $start );
		}
		if ( $stop !== null ) {
			$conds[] = 'sm_key <= ' . $dbr->addQuotes( $stop );
		}

		$res = $dbr->select(
			[ 'sofa_map', 'sofa_schema' ],
			[ 'sm_key', 'sm_value', 'sm_page', 'sm_id' ],
			$conds,
			__METHOD__,
			[
				'LIMIT' => $limit,
				'ORDER BY' => 'sm_key'
			],
			// Make sure all returned results have live pages.
			[ 'page' => [ 'JOIN', 'page_id=sm_page' ] ]
		);

		return $res;
	}
}
