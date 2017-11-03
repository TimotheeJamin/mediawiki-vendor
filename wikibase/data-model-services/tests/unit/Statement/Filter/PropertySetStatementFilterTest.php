<?php

namespace Wikibase\DataModel\Services\Tests\Statement\Filter;

use PHPUnit_Framework_TestCase;
use Wikibase\DataModel\Services\Statement\Filter\PropertySetStatementFilter;
use Wikibase\DataModel\Snak\PropertyNoValueSnak;
use Wikibase\DataModel\Statement\Statement;

/**
 * @covers Wikibase\DataModel\Services\Statement\Filter\PropertySetStatementFilter
 *
 * @license GPL-2.0+
 * @author Thiemo Mättig
 */
class PropertySetStatementFilterTest extends PHPUnit_Framework_TestCase {

	/**
	 * @dataProvider statementProvider
	 */
	public function testIsMatch( Statement $statement, $propertyIds, $expected ) {
		$filter = new PropertySetStatementFilter( $propertyIds );
		$this->assertSame( $expected, $filter->statementMatches( $statement ) );
	}

	public function statementProvider() {
		$p1 = new Statement( new PropertyNoValueSnak( 1 ) );
		$p2 = new Statement( new PropertyNoValueSnak( 2 ) );

		return [
			[ $p1, null, false ],
			[ $p1, [], false ],
			[ $p1, 'P1', true ],
			[ $p1, 'P11', false ],
			[ $p2, 'P1', false ],
			[ $p2, 'P2', true ],
			[ $p1, 'P2', false ],
			[ $p1, [ 'P1' ], true ],
			[ $p1, [ 'P2', 'P1' ], true ],
		];
	}

}
