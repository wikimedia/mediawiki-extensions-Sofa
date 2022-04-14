<?php

class SofaContentHandler extends JsonContentHandler {
	/**
	 * @inheritDoc
	 */
	public function __construct( $modelId = 'Sofa' ) {
		parent::__construct( $modelId );
	}

	/**
	 * @inheritDoc
	 */
	public function makeEmptyContent() {
		$class = $this->getContentClass();
		return new $class(
			'{"fields": [], "allowExtraFields": true, "aggregations": [],' .
			' "intersections": []}'
		);
	}
}
