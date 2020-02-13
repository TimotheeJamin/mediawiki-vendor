<?php

namespace Wikimedia\Parsoid\Wt2Html\PP\Processors;

use DOMElement;
use Wikimedia\Parsoid\Utils\DOMCompat;

class Normalize {
	/**
	 * @param DOMElement $body
	 */
	public function run( DOMElement $body ): void {
		DOMCompat::normalize( $body );
	}
}
