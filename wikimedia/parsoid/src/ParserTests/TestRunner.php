<?php
declare( strict_types = 1 );

namespace Wikimedia\Parsoid\ParserTests;

use Error;
use Psr\Log\LoggerInterface;
use Wikimedia\Alea\Alea;
use Wikimedia\Assert\Assert;
use Wikimedia\Parsoid\Config\Api\DataAccess;
use Wikimedia\Parsoid\Config\Api\PageConfig;
use Wikimedia\Parsoid\Config\Env;
use Wikimedia\Parsoid\Core\SelserData;
use Wikimedia\Parsoid\DOM\Document;
use Wikimedia\Parsoid\DOM\Element;
use Wikimedia\Parsoid\DOM\Node;
use Wikimedia\Parsoid\Mocks\MockPageConfig;
use Wikimedia\Parsoid\Mocks\MockPageContent;
use Wikimedia\Parsoid\Tools\ScriptUtils;
use Wikimedia\Parsoid\Utils\ContentUtils;
use Wikimedia\Parsoid\Utils\DOMCompat;
use Wikimedia\Parsoid\Utils\DOMUtils;
use Wikimedia\Parsoid\Utils\PHPUtils;
use Wikimedia\Parsoid\Utils\Utils;
use Wikimedia\Parsoid\Utils\WTUtils;
use Wikimedia\Parsoid\Wt2Html\PageConfigFrame;

/**
 * Test runner for parser tests
 */
class TestRunner {
	// Hard-code some interwiki prefixes, as is done
	// in parserTest.inc:setupInterwikis()
	private const PARSER_TESTS_IWPS = [
		[
			'prefix' => 'wikinvest',
			'local' => true,
			// This url doesn't have a $1 to exercise the fix in
			// ConfigUtils::computeInterwikiMap
			'url' => 'https://meta.wikimedia.org/wiki/Interwiki_map/discontinued#Wikinvest',
			'protorel' => false
		],
		[
			'prefix' => 'local',
			'url' => 'http://doesnt.matter.org/$1',
			'localinterwiki' => true
		],
		[
			'prefix' => 'wikipedia',
			'url' => 'http://en.wikipedia.org/wiki/$1'
		],
		[
			'prefix' => 'meatball',
			// this has been updated in the live wikis, but the parser tests
			// expect the old value (as set in parserTest.inc:setupInterwikis())
			'url' => 'http://www.usemod.com/cgi-bin/mb.pl?$1'
		],
		[
			'prefix' => 'memoryalpha',
			'url' => 'http://www.memory-alpha.org/en/index.php/$1'
		],
		[
			'prefix' => 'zh',
			'url' => 'http://zh.wikipedia.org/wiki/$1',
			'language' => "中文",
			'local' => true
		],
		[
			'prefix' => 'es',
			'url' => 'http://es.wikipedia.org/wiki/$1',
			'language' => "español",
			'local' => true
		],
		[
			'prefix' => 'fr',
			'url' => 'http://fr.wikipedia.org/wiki/$1',
			'language' => "français",
			'local' => true
		],
		[
			'prefix' => 'ru',
			'url' => 'http://ru.wikipedia.org/wiki/$1',
			'language' => "русский",
			'local' => true
		],
		[
			'prefix' => 'mi',
			'url' => 'http://mi.wikipedia.org/wiki/$1',
			// better for testing if one of the
			// localinterwiki prefixes is also a language
			'language' => 'Test',
			'local' => true,
			'localinterwiki' => true
		],
		[
			'prefix' => 'mul',
			'url' => 'http://wikisource.org/wiki/$1',
			'extralanglink' => true,
			'linktext' => 'Multilingual',
			'sitename' => 'WikiSource',
			'local' => true
		],
		// not in PHP setupInterwikis(), but needed
		[
			'prefix' => 'en',
			'url' => 'http://en.wikipedia.org/wiki/$1',
			'language' => 'English',
			'local' => true,
			'protorel' => true
		],
		[
			'prefix' => 'stats',
			'local' => true,
			'url' => 'https://stats.wikimedia.org/$1'
		],
		[
			'prefix' => 'gerrit',
			'local' => true,
			'url' => 'https://gerrit.wikimedia.org/$1'
		]
	];

	/** @var bool */
	private $runDisabled;

	/** @var bool */
	private $runPHP;

	/** @var string */
	private $offsetType;

	/** @var string */
	private $testFileName;

	/** @var string */
	private $testFilePath;

	/** @var string */
	private $knownFailuresPath;

	/** @var array */
	private $articles;

	/** @var LoggerInterface */
	private $defaultLogger;

	/**
	 * Sets one of 'regex' or 'string' properties
	 * - $testFilter['raw'] is the value of the filter
	 * - if $testFilter['regex'] is true, $testFilter['raw'] is used as a regex filter.
	 * - If $testFilter['string'] is true, $testFilter['raw'] is used as a plain string filter.
	 * @var array
	 */
	private $testFilter;

	/** @var Test[] */
	private $testCases;

	/** @var Stats */
	private $stats;

	/** @var MockApiHelper */
	private $mockApi;

	/** @var SiteConfig */
	private $siteConfig;

	/** @var DataAccess */
	private $dataAccess;

	/**
	 * Global cross-test env object only to be used for title processing while
	 * reading the parserTests file.
	 *
	 * Every test constructs its own private $env object.
	 *
	 * @var Env
	 */
	private $dummyEnv;

	/**
	 * Options needed to construct the per-test private $env object
	 * @var array
	 */
	private $envOptions;

	/**
	 * @param string $testFilePath
	 * @param string[] $modes
	 */
	public function __construct( string $testFilePath, array $modes ) {
		$this->testFilePath = $testFilePath;

		$testFilePathInfo = pathinfo( $testFilePath );
		$this->testFileName = $testFilePathInfo['basename'];

		$newModes = [];
		foreach ( $modes as $mode ) {
			$newModes[$mode] = new Stats();
			$newModes[$mode]->failList = [];
			$newModes[$mode]->result = ''; // XML reporter uses this.
		}

		$this->stats = new Stats();
		$this->stats->modes = $newModes;

		$this->mockApi = new MockApiHelper();
		$this->siteConfig = new SiteConfig( $this->mockApi, [] );
		$this->dataAccess = new DataAccess( $this->mockApi, $this->siteConfig, [ 'stripProto' => false ] );
		$this->dummyEnv = new Env(
			$this->siteConfig,
			// Unused; needed to satisfy Env signature requirements
			new MockPageConfig( [], new MockPageContent( [ 'main' => '' ] ) ),
			// Unused; needed to satisfy Env signature requirements
			$this->dataAccess
		);

		// Init interwiki map to parser tests info.
		// This suppresses interwiki info from cached configs.
		$this->siteConfig->setupInterwikiMap( self::PARSER_TESTS_IWPS );
	}

	/**
	 * @param Test $test
	 * @param string $wikitext
	 * @return Env
	 */
	private function newEnv( Test $test, string $wikitext ): Env {
		$pageNs = $this->dummyEnv->makeTitleFromURLDecodedStr(
			$test->pageName()
		)->getNameSpaceId();

		$opts = [
			'title' => $test->pageName(),
			'pagens' => $pageNs,
			'pageContent' => $wikitext,
			'pageLanguage' => $this->siteConfig->lang(),
			'pageLanguagedir' => $this->siteConfig->rtl() ? 'rtl' : 'ltr'
		];

		$pageConfig = new PageConfig( null, $opts );

		$env = new Env(
			$this->siteConfig,
			$pageConfig,
			$this->dataAccess,
			$this->envOptions
		);

		$env->pageCache = $this->articles;
		// Set parsing resource limits.
		// $env->setResourceLimits();

		return $env;
	}

	/**
	 * Parser the test file and set up articles and test cases
	 */
	private function buildTests(): void {
		// Startup by loading .txt test file
		$warnFunc = static function ( string $warnMsg ):void {
			error_log( $warnMsg );
		};
		$normFunc = function ( string $title ):string {
			return $this->dummyEnv->normalizedTitleKey( $title, false, true );
		};
		$testReader = TestFileReader::read(
			$this->testFilePath, $warnFunc, $normFunc
		);
		$this->knownFailuresPath = $testReader->knownFailuresPath;
		$this->testCases = $testReader->testCases;
		$this->articles = [];
		foreach ( $testReader->articles as $art ) {
			$key = $normFunc( $art->title );
			$this->articles[$key] = $art->text;
			$this->mockApi->addArticle( $key, $art );
		}
		if ( $this->knownFailuresPath ) {
			error_log( 'Loaded known failures from ' . $this->knownFailuresPath );
		} else {
			error_log( 'No known failures found.' );
		}
	}

	/**
	 * For a selser test, check if a change we could make has already been
	 * tested in this round.
	 * Used for generating unique tests.
	 *
	 * @param array $allChanges Already-tried changes.
	 * @param array $change Candidate change.
	 * @return bool
	 */
	private function isDuplicateChangeTree( array $allChanges, array $change ): bool {
		foreach ( $allChanges as $c ) {
			if ( $c == $change ) {
				return true;
			}
		}
		return false;
	}

	// Random string used as selser comment content
	private const STATIC_RANDOM_STRING = 'ahseeyooxooZ8Oon0boh';

	/**
	 * Make changes to a DOM in order to run a selser test on it.
	 *
	 * @param Env $env
	 * @param Test $test
	 * @param Document $doc
	 * @param array $changelist
	 */
	private function applyChanges(
		Env $env, Test $test, Document $doc, array $changelist
	) {
		// Seed the random-number generator based on the item title and changelist
		$alea = new Alea( ( json_encode( $changelist ) ) . ( $test->testName ?? '' ) );

		// Keep the changes in the test object
		// to check for duplicates while building tasks
		$test->changes = $changelist;

		// Helper function for getting a random string
		$randomString = static function () use ( &$alea ): string {
			return base_convert( (string)$alea->uint32(), 10, 36 );
		};

		$insertNewNode = static function ( Node $n ) use ( $randomString ): void {
			// Insert a text node, if not in a fosterable position.
			// If in foster position, enter a comment.
			// In either case, dom-diff should register a new node
			$str = $randomString();
			$ownerDoc = $n->ownerDocument;
			$wrapperName = null;
			$newNode = null;

			// Don't separate legacy IDs from their H? node.
			if ( WTUtils::isFallbackIdSpan( $n ) ) {
				$n = $n->nextSibling ?? $n->parentNode;
			}

			// For these container nodes, it would be buggy
			// to insert text nodes as children
			switch ( DOMCompat::nodeName( $n->parentNode ) ) {
				case 'ol':
				case 'ul':
					$wrapperName = 'li';
					break;
				case 'dl':
					$wrapperName = 'dd';
					break;
				case 'tr':
					$prev = DOMCompat::getPreviousElementSibling( $n );
					if ( $prev ) {
						// TH or TD
						$wrapperName = DOMCompat::nodeName( $prev );
					} else {
						$next = DOMCompat::getNextElementSibling( $n );
						if ( $next ) {
							// TH or TD
							$wrapperName = DOMCompat::nodeName( $next );
						} else {
							$wrapperName = 'td';
						}
					}
					break;
				case 'body':
					$wrapperName = 'p';
					break;
				default:
					if ( WTUtils::isBlockNodeWithVisibleWT( $n ) ) {
						$wrapperName = 'p';
					}
					break;
			}

			if ( DOMUtils::isFosterablePosition( $n ) && DOMCompat::nodeName( $n->parentNode ) !== 'tr' ) {
				$newNode = $ownerDoc->createComment( $str );
			} elseif ( $wrapperName ) {
				$newNode = $ownerDoc->createElement( $wrapperName );
				$newNode->appendChild( $ownerDoc->createTextNode( $str ) );
			} else {
				$newNode = $ownerDoc->createTextNode( $str );
			}

			$n->parentNode->insertBefore( $newNode, $n );
		};

		$removeNode = static function ( Node $n ): void {
			$n->parentNode->removeChild( $n );
		};

		$applyChangesInternal = static function ( Node $node, array $changes ) use (
			&$env, &$applyChangesInternal, $removeNode, $insertNewNode,
			$randomString
		): void {
			if ( count( $node->childNodes ) < count( $changes ) ) {
				throw new Error( "Error: more changes than nodes to apply them to!" );
			}

			// Clone array since we are mutating the children in the changes loop below
			$nodeArray = [];
			foreach ( $node->childNodes as $n ) {
				$nodeArray[] = $n;
			}

			foreach ( $changes as $i => $change ) {
				$child = $nodeArray[$i];

				if ( is_array( $change ) ) {
					$applyChangesInternal( $child, $change );
				} else {
					switch ( $change ) {
						// No change
						case 0:
							break;

						// Change node wrapper
						// (sufficient to insert a random attr)
						case 1:
							if ( DOMUtils::isElt( $child ) ) {
								$child->setAttribute( 'data-foobar', $randomString() );
							} else {
								$env->log( 'error',
									'Buggy changetree. changetype 1 (modify attribute)' .
									' cannot be applied on text/comment nodes.' );
							}
							break;

						// Insert new node before child
						case 2:
							$insertNewNode( $child );
							break;

						// Delete tree rooted at child
						case 3:
							$removeNode( $child );
							break;

						// Change tree rooted at child
						case 4:
							$insertNewNode( $child );
							$removeNode( $child );
							break;
					}

				}
			}
		};

		$body = DOMCompat::getBody( $doc );

		if ( $env->hasDumpFlag( 'dom:post-changes' ) ) {
			ContentUtils::dumpDOM( $body, 'Original DOM' );
		}

		if ( $test->changes === [ 5 ] ) {
			// Hack so that we can work on the parent node rather than just the
			// children: Append a comment with known content. This is later
			// stripped from the output, and the result is compared to the
			// original wikitext rather than the non-selser wt2wt result.
			$body->appendChild( $doc->createComment( self::STATIC_RANDOM_STRING ) );
		} elseif ( $test->changes !== [] ) {
			$applyChangesInternal( $body, $test->changes );
		}

		if ( $env->hasDumpFlag( 'dom:post-changes' ) ) {
			error_log( 'Change tree : ' . json_encode( $test->changes ) . "\n" );
			ContentUtils::dumpDOM( $body, 'Edited DOM' );
		}
	}

	/**
	 * Generate a change object for a document, so we can apply it during a selser test.
	 *
	 * @param array $options
	 * @param Test $test
	 * @param Document $doc
	 * @return array The list of changes.
	 */
	private function generateChanges(
		array $options, Test $test, Document $doc
	): array {
		$alea = new Alea( ( $test->seed ?? '' ) . ( $test->testName ?? '' ) );

		/**
		 * If no node in the DOM subtree rooted at 'node' is editable in the VE,
		 * this function should return false.
		 *
		 * Currently true for template and extension content, and for entities.
		 */
		$domSubtreeIsEditable = static function ( Node $node ): bool {
			return !( $node instanceof Element ) ||
				( !WTUtils::isEncapsulationWrapper( $node ) &&
					// These wrappers can only be edited in restricted ways.
					// Simpler to just block all editing on them.
					!DOMUtils::matchTypeOf( $node, '#^mw:(Entity|Placeholder|DisplaySpace)(/|$)#' ) &&
					// Deleting these wrappers is tantamount to removing the
					// references-tag encapsulation wrappers, which results in errors.
					!preg_match( '/\bmw-references-wrap\b/', $node->getAttribute( 'class' ) ?? '' )
				);
		};

		/**
		 * Even if a DOM subtree might be editable in the VE,
		 * certain nodes in the DOM might not be directly editable.
		 *
		 * Currently, this restriction is only applied to DOMs generated for images.
		 * Possibly, there are other candidates.
		 */
		$nodeIsUneditable = static function ( Node $node ) use ( &$nodeIsUneditable ): bool {
			// Text and comment nodes are always editable
			if ( !( $node instanceof Element ) ) {
				return false;
			}

			// - Image wrapper is an uneditable image elt.
			// - Any node nested in an image elt that is not a fig-caption
			//   is an uneditable image elt.
			// - Entity spans are uneditable as well
			// - Placeholder is defined to be uneditable in the spec
			return DOMUtils::matchTypeOf( $node, '#^mw:(Image|Video|Audio|Entity|Placeholder|DisplaySpace)(/|$)#' ) || (
				DOMCompat::nodeName( $node ) !== 'figcaption' &&
				$node->parentNode &&
				DOMCompat::nodeName( $node->parentNode ) !== 'body' &&
				$nodeIsUneditable( $node->parentNode )
			);
		};

		$defaultChangeType = 0;

		$hasChangeMarkers = static function ( array $list ) use (
			&$hasChangeMarkers, $defaultChangeType
		): bool {
			// If all recorded changes are 0, then nothing has been modified
			foreach ( $list as $c ) {
				if ( ( is_array( $c ) && $hasChangeMarkers( $c ) ) ||
					( !is_array( $c ) && $c !== $defaultChangeType )
				) {
					return true;
				}
			}
			return false;
		};

		$genChangesInternal = static function ( Node $node ) use (
			&$genChangesInternal, &$hasChangeMarkers,
			$domSubtreeIsEditable, $nodeIsUneditable, $alea,
			$defaultChangeType
		): array {
			// Seed the random-number generator based on the item title
			$changelist = [];
			$children = $node->childNodes ? iterator_to_array( $node->childNodes ) : [];
			foreach ( $children as $child ) {
				$changeType = $defaultChangeType;

				if ( $domSubtreeIsEditable( $child ) ) {
					if ( $nodeIsUneditable( $child ) || $alea->random() < 0.5 ) {
						// This call to random is a hack to preserve the current
						// determined state of our knownFailures entries after a
						// refactor.
						$alea->uint32();
						$changeType = $genChangesInternal( $child );
						// `$genChangesInternal` returns an array, which can be
						// empty.  Revert to the `$defaultChangeType` if that's
						// the case.
						if ( count( $changeType ) === 0 ) {
							$changeType = $defaultChangeType;
						}
					} else {
						if ( !DOMUtils::isElt( $child ) ) {
							// Text or comment node -- valid changes: 2, 3, 4
							// since we cannot set attributes on these
							$changeType = floor( $alea->random() * 3 ) + 2;
						} else {
							$changeType = floor( $alea->random() * 4 ) + 1;
						}
					}
				}

				$changelist[] = $changeType;
			}

			return $hasChangeMarkers( $changelist ) ? $changelist : [];
		};

		$body = DOMCompat::getBody( $doc );

		$changetree = null;
		$numAttempts = 0;
		do {
			$numAttempts++;
			$changetree = $genChangesInternal( $body );
		} while (
			$numAttempts < 1000 &&
			( count( $changetree ) === 0 ||
				$this->isDuplicateChangeTree( $test->selserChangeTrees, $changetree ) )
		);

		if ( $numAttempts === 1000 ) {
			// couldn't generate a change ... marking as such
			$test->duplicateChange = true;
		}

		return $changetree;
	}

	/**
	 * Apply manually-specified changes, which are provided in a pseudo-jQuery
	 * format.
	 *
	 * @param Document $doc
	 * @param array $changes
	 */
	private function applyManualChanges( Document $doc, array $changes ) {
		$err = null;
		// changes are specified using jquery methods.
		//  [x,y,z...] becomes $(x)[y](z....)
		// that is, ['fig', 'attr', 'width', '120'] is interpreted as
		//   $('fig').attr('width', '120')
		// See http://api.jquery.com/ for documentation of these methods.
		// "contents" as second argument calls the jquery .contents() method
		// on the results of the selector in the first argument, which is
		// a good way to get at the text and comment nodes
		$jquery = [
			'after' => static function ( Node $node, string $html ) {
				$div = null;
				$tbl = null;
				if ( DOMCompat::nodeName( $node->parentNode ) === 'tbody' ) {
					$tbl = $node->ownerDocument->createElement( 'table' );
					DOMCompat::setInnerHTML( $tbl, $html );
					// <tbody> is implicitly added when inner html is set to <tr>..</tr>
					DOMUtils::migrateChildren( $tbl->firstChild, $node->parentNode, $node->nextSibling );
				} elseif ( DOMCompat::nodeName( $node->parentNode ) === 'tr' ) {
					$tbl = $node->ownerDocument->createElement( 'table' );
					DOMCompat::setInnerHTML( $tbl, '<tbody><tr></tr></tbody>' );
					$tr = $tbl->firstChild->firstChild;
					'@phan-var Element $tr'; // @var Element $tr
					DOMCompat::setInnerHTML( $tr, $html );
					DOMUtils::migrateChildren( $tbl->firstChild->firstChild,
						$node->parentNode, $node->nextSibling );
				} else {
					$div = $node->ownerDocument->createElement( 'div' );
					DOMCompat::setInnerHTML( $div, $html );
					DOMUtils::migrateChildren( $div, $node->parentNode, $node->nextSibling );
				}
			},
			'append' => static function ( Node $node, string $html ) {
				if ( DOMCompat::nodeName( $node ) === 'tr' ) {
					$tbl = $node->ownerDocument->createElement( 'table' );
					DOMCompat::setInnerHTML( $tbl, $html );
					// <tbody> is implicitly added when inner html is set to <tr>..</tr>
					DOMUtils::migrateChildren( $tbl->firstChild, $node );
				} else {
					$div = $node->ownerDocument->createElement( 'div' );
					DOMCompat::setInnerHTML( $div, $html );
					DOMUtils::migrateChildren( $div, $node );
				}
			},
			'attr' => static function ( Node $node, string $name, string $val ) {
				'@phan-var Element $node'; // @var Element $node
				$node->setAttribute( $name, $val );
			},
			'before' => static function ( Node $node, string $html ) {
				$div = null;
				$tbl = null;
				if ( DOMCompat::nodeName( $node->parentNode ) === 'tbody' ) {
					$tbl = $node->ownerDocument->createElement( 'table' );
					DOMCompat::setInnerHTML( $tbl, $html );
					// <tbody> is implicitly added when inner html is set to <tr>..</tr>
					DOMUtils::migrateChildren( $tbl->firstChild, $node->parentNode, $node );
				} elseif ( DOMCompat::nodeName( $node->parentNode ) === 'tr' ) {
					$tbl = $node->ownerDocument->createElement( 'table' );
					DOMCompat::setInnerHTML( $tbl, '<tbody><tr></tr></tbody>' );
					$tr = $tbl->firstChild->firstChild;
					'@phan-var Element $tr'; // @var Element $tr
					DOMCompat::setInnerHTML( $tr, $html );
					DOMUtils::migrateChildren( $tbl->firstChild->firstChild, $node->parentNode, $node );
				} else {
					$div = $node->ownerDocument->createElement( 'div' );
					DOMCompat::setInnerHTML( $div, $html );
					DOMUtils::migrateChildren( $div, $node->parentNode, $node );
				}
			},
			'removeAttr' => static function ( Node $node, string $name ) {
				'@phan-var Element $node'; // @var Element $node
				$node->removeAttribute( $name );
			},
			'removeClass' => static function ( Node $node, string $c ) {
				'@phan-var Element $node'; // @var Element $node
				DOMCompat::getClassList( $node )->remove( $c );
			},
			'addClass' => static function ( Node $node, string $c ) {
				'@phan-var Element $node'; // @var Element $node
				DOMCompat::getClassList( $node )->add( $c );
			},
			'text' => static function ( Node $node, string $t ) {
				$node->textContent = $t;
			},
			'html' => static function ( Node $node, string $h ) {
				'@phan-var Element $node'; // @var Element $node
				DOMCompat::setInnerHTML( $node, $h );
			},
			'remove' => static function ( Node $node, string $optSelector = null ) {
				// jquery lets us specify an optional selector to further
				// restrict the removed elements.
				// text nodes don't have the "querySelectorAll" method, so
				// just include them by default (jquery excludes them, which
				// is less useful)
				if ( !$optSelector ) {
					$what = [ $node ];
				} elseif ( !( $node instanceof Element ) ) {
					$what = [ $node ];/* text node hack! */
				} else {
					'@phan-var Element $node'; // @var Element $node
					$what = DOMCompat::querySelectorAll( $node, $optSelector );
				}
				foreach ( $what as $node ) {
					if ( $node->parentNode ) {
						$node->parentNode->removeChild( $node );
					}
				}
			},
			'empty' => static function ( Node $node ) {
				'@phan-var Element $node'; // @var Element $node
				DOMCompat::replaceChildren( $node );
			},
			'wrap' => static function ( Node $node, string $w ) {
				$frag = $node->ownerDocument->createElement( 'div' );
				DOMCompat::setInnerHTML( $frag, $w );
				$first = $frag->firstChild;
				$node->parentNode->replaceChild( $first, $node );
				while ( $first->firstChild ) {
					$first = $first->firstChild;
				}
				$first->appendChild( $node );
			}
		];

		$body = DOMCompat::getBody( $doc );

		foreach ( $changes as $change ) {
			if ( $err ) {
				continue;
			}
			if ( count( $change ) < 2 ) {
				$err = new Error( 'bad change: ' . $change );
				continue;
			}
			// use document.querySelectorAll as a poor man's $(...)
			$els = PHPUtils::iterable_to_array(
				DOMCompat::querySelectorAll( $body, $change[0] )
			);
			if ( !count( $els ) ) {
				$err = new Error( $change[0] .
					' did not match any elements: ' . DOMCompat::getOuterHTML( $body ) );
				continue;
			}
			if ( $change[1] === 'contents' ) {
				$change = array_slice( $change, 1 );
				$acc = [];
				foreach ( $els as $el ) {
					$acc = array_merge( $acc, iterator_to_array( $el->childNodes ) );
				}
				$els = $acc;
			}
			$fn = $jquery[$change[1]] ?? null;
			if ( !$fn ) {
				$err = new Error( 'bad mutator function: ' . $change[1] );
				continue;
			}
			foreach ( $els as $el ) {
				call_user_func_array( $fn, array_merge( [ $el ], array_slice( $change, 2 ) ) );
			}
		}

		if ( $err ) {
			print TestUtils::colorString( (string)$err, "red" ) . "\n";
			throw $err;
		}
	}

	/**
	 * Convert a wikitext string to an HTML Node
	 *
	 * @param Env $env
	 * @param Test $test
	 * @param string $mode
	 * @param string $wikitext
	 * @return Document
	 */
	private function convertWt2Html(
		Env $env, Test $test, string $mode, string $wikitext
	): Document {
		// FIXME: Ugly!  Maybe we should switch to using the entrypoint to
		// the library for parserTests instead of reusing the environment
		// and touching these internals.
		$content = $env->getPageConfig()->getRevisionContent();
		// @phan-suppress-next-line PhanUndeclaredProperty
		$content->data['main']['content'] = $wikitext;
		$env->topFrame = new PageConfigFrame(
			$env, $env->getPageConfig(), $env->getSiteConfig()
		);
		if ( $mode === 'html2html' ) {
			// Since this was set when serializing we need to setup a new doc
			$env->setupTopLevelDoc();
		}
		$handler = $env->getContentHandler();
		$doc = $handler->toDOM( $env );
		return $doc;
	}

	/**
	 * Convert a DOM to Wikitext.
	 *
	 * @param Env $env
	 * @param Test $test
	 * @param array $options
	 * @param string $mode
	 * @param Document $doc
	 * @return string
	 */
	private function convertHtml2Wt(
		Env $env, Test $test, array $options, string $mode, Document $doc
	): string {
		$selserData = null;
		$startsAtWikitext = $mode === 'wt2wt' || $mode === 'wt2html' || $mode === 'selser';
		if ( $mode === 'selser' ) {
			if ( $startsAtWikitext ) {
				$selserData = new SelserData( $test->wikitext, $test->cachedBODYstr );
			}
		}
		$handler = $env->getContentHandler();
		$env->topLevelDoc = $doc;
		return $handler->fromDOM( $env, $selserData );
	}

	/**
	 * Run test in the requested mode
	 * @param Test $test
	 * @param string $mode
	 * @param array $options
	 */
	private function runTest( Test $test, string $mode, array $options ): void {
		$test->time = [];

		// These changes are for environment options that change between runs of
		// different modes. See `processTest` for changes per test.
		if ( $test->options ) {
			// Page language matches "wiki language" (which is set by
			// the item 'language' option).
			if ( isset( $test->options['langconv'] ) ) {
				$this->envOptions['wtVariantLanguage'] = $test->options['sourceVariant'] ?? null;
				$this->envOptions['htmlVariantLanguage'] = $test->options['variant'] ?? null;
			} else {
				// variant conversion is disabled by default
				$this->envOptions['wtVariantLanguage'] = null;
				$this->envOptions['htmlVariantLanguage'] = null;
			}
		}

		$env = $this->newEnv( $test, $test->wikitext ?? '' );

		// Some useful booleans
		$startsAtHtml = $mode === 'html2html' || $mode === 'html2wt';
		$endsAtWikitext = $mode === 'wt2wt' || $mode === 'selser' || $mode === 'html2wt';
		$endsAtHtml = $mode === 'wt2html' || $mode === 'html2html';

		$parsoidOnly = isset( $test->sections['html/parsoid'] ) ||
			( !empty( $test->options['parsoid'] ) &&
			!isset( $test->options['parsoid']['normalizePhp'] ) );
		$test->time['start'] = microtime( true );
		$doc = null;
		$wt = null;

		// Source preparation
		if ( $startsAtHtml ) {
			$html = $test->parsoidHtml;
			if ( !$parsoidOnly ) {
				// Strip some php output that has no wikitext representation
				// (like .mw-editsection) and won't html2html roundtrip and
				// therefore causes false failures.
				$html = TestUtils::normalizePhpOutput( $html );
			}
			$doc = ContentUtils::createDocument( $html );
			$wt = $this->convertHtml2Wt( $env, $test, $options, $mode, $doc );
		} else { // startsAtWikitext
			// Always serialize DOM to string and reparse before passing to wt2wt
			if ( $test->cachedBODYstr === null ) {
				$doc = $this->convertWt2Html( $env, $test, $mode, $test->wikitext );
				// Caching stage 1 - save the result of the first two stages
				// so we can maybe skip them later

				// Cache parsed HTML
				$test->cachedBODYstr = ContentUtils::toXML( DOMCompat::getBody( $doc ) );

				// - In wt2html mode, pass through original DOM
				//   so that it is serialized just once.
				// - In wt2wt and selser modes, pass through serialized and
				//   reparsed DOM so that fostering/normalization effects
				//   are reproduced.
				if ( $mode === 'wt2html' ) {
					// no-op
				} else {
					$doc = ContentUtils::createDocument( $test->cachedBODYstr );
				}
			} else {
				$doc = ContentUtils::createDocument( $test->cachedBODYstr );
			}
		}

		// Generate and make changes for the selser test mode
		if ( $mode === 'selser' ) {
			if ( ( $options['selser'] === 'noauto' || $test->changetree === [ 'manual' ] ) &&
				isset( $test->options['parsoid']['changes'] )
			) {
				// Ensure that we have this set here in case it hasn't been
				// set in buildTasks because the 'selser=noauto' option was passed.
				$test->changetree = [ 'manual' ];
				$this->applyManualChanges( $doc, $test->options['parsoid']['changes'] );
			} else {
				$changetree = isset( $options['changetree'] ) ?
					json_decode( $options['changetree'] ) : $test->changetree;
				if ( !$changetree ) {
					$changetree = $this->generateChanges( $options, $test, $doc );
				}
				$this->applyChanges( $env, $test, $doc, $changetree );
			}
			// Save the modified DOM so we can re-test it later
			// Always serialize to string and reparse before passing to selser/wt2wt
			$test->changedHTMLStr = ContentUtils::toXML( DOMCompat::getBody( $doc ) );
			$doc = ContentUtils::createDocument( $test->changedHTMLStr );
		} elseif ( $mode === 'wt2wt' ) {
			// handle a 'changes' option if present.
			if ( isset( $test->options['parsoid']['changes'] ) ) {
				$this->applyManualChanges( $doc, $test->options['parsoid']['changes'] );
			}
		}

		// Roundtrip stage
		if ( $mode === 'wt2wt' || $mode === 'selser' ) {
			$wt = $this->convertHtml2Wt( $env, $test, $options, $mode, $doc );
		} elseif ( $mode === 'html2html' ) {
			$doc = $this->convertWt2Html( $env, $test, $mode, $wt );
		}

		// Processing stage
		if ( $endsAtWikitext ) {
			$this->processSerializedWT( $env, $test, $options, $mode, $wt );
		} elseif ( $endsAtHtml ) {
			$this->processParsedHTML( $test, $options, $mode, $doc );
		}
	}

	/**
	 * Check the given HTML result against the expected result, and throw an
	 * exception if necessary.
	 *
	 * @param Test $test
	 * @param array $options
	 * @param string $mode
	 * @param Document $doc
	 */
	private function processParsedHTML(
		Test $test, array $options, string $mode, Document $doc
	): void {
		$test->time['end'] = microtime( true );
		// Check the result vs. the expected result.
		$checkPassed = $this->checkHTML(
			$test, DOMCompat::getBody( $doc ), $options, $mode
		);

		// Only throw an error if --exit-unexpected was set and there was an error
		// Otherwise, continue running tests
		if ( $options['exit-unexpected'] && !$checkPassed ) {
			throw new UnexpectedException;
		}
	}

	/**
	 * Check the given wikitext result against the expected result, and throw an
	 * exception if necessary.
	 *
	 * @param Env $env
	 * @param Test $test
	 * @param array $options
	 * @param string $mode
	 * @param string $wikitext
	 */
	private function processSerializedWT(
		Env $env, Test $test, array $options, string $mode, string $wikitext
	): void {
		$test->time['end'] = microtime( true );

		if ( $mode === 'selser' && $options['selser'] !== 'noauto' ) {
			if ( $test->changetree === [ 5 ] ) {
				$test->resultWT = $test->wikitext;
			} else {
				$doc = ContentUtils::createDocument( $test->changedHTMLStr );
				$test->resultWT = $this->convertHtml2Wt( $env, $test, $options, 'wt2wt', $doc );
			}
		}

		// Check the result vs. the expected result.
		$checkPassed = $this->checkWikitext( $test, $wikitext, $options, $mode );

		// Only throw an error if --exit-unexpected was set and there was an error
		// Otherwise, continue running tests
		if ( $options['exit-unexpected'] && !$checkPassed ) {
			throw new UnexpectedException;
		}
	}

	/**
	 * @param Test $test
	 * @param Element $out
	 * @param array $options
	 * @param string $mode
	 * @return bool
	 */
	private function checkHTML(
		Test $test, Element $out, array $options, string $mode
	): bool {
		$normalizedOut = null;
		$normalizedExpected = null;
		$parsoidOnly = isset( $test->sections['html/parsoid'] ) ||
			( isset( $test->sections['html/parsoid+langconv'] ) ) ||
			( isset( $test->options['parsoid'] ) && !isset( $test->options['parsoid']['normalizePhp'] ) );

		$normOpts = [
			'parsoidOnly' => $parsoidOnly,
			'preserveIEW' => isset( $test->options['parsoid']['preserveIEW'] ),
			'scrubWikitext' => isset( $test->options['parsoid']['scrubWikitext'] )
		];

		$normalizedOut = TestUtils::normalizeOut( $out, $normOpts );
		$out = ContentUtils::toXML( $out, [ 'innerXML' => true ] );

		if ( $test->cachedNormalizedHTML === null ) {
			if ( $parsoidOnly ) {
				$normalizedExpected = TestUtils::normalizeOut( $test->parsoidHtml, $normOpts );
			} else {
				$normalizedExpected = TestUtils::normalizeHTML( $test->parsoidHtml );
			}
			$test->cachedNormalizedHTML = $normalizedExpected;
		} else {
			$normalizedExpected = $test->cachedNormalizedHTML;
		}

		$input = ( $mode === 'html2html' ) ? $test->parsoidHtml : $test->wikitext;
		$expected = [ 'normal' => $normalizedExpected, 'raw' => $test->parsoidHtml ];
		$actual = [ 'normal' => $normalizedOut, 'raw' => $out, 'input' => $input ];

		return $options['reportResult'](
			$this->stats, $test, $options, $mode, $expected, $actual
		);
	}

	/**
	 * @param Test $test
	 * @param string $out
	 * @param array $options
	 * @param string $mode
	 * @return bool
	 */
	private function checkWikitext(
		Test $test, string $out, array $options, string $mode
	): bool {
		$testWikitext = $test->wikitext;
		$out = preg_replace( '/<!--' . self::STATIC_RANDOM_STRING . '-->/', '', $out );
		if ( $mode === 'selser' && $test->resultWT !== null &&
			$test->changes !== [ 5 ] && $test->changetree !== [ 'manual' ]
		) {
			$testWikitext = $test->resultWT;
		} elseif ( ( $mode === 'wt2wt' ||
				( $mode === 'selser' && $test->changetree === [ 'manual' ] )
			) && isset( $test->options['parsoid']['changes'] )
		) {
			$testWikitext = $test->sections['wikitext/edited'];
		}

		$toWikiText = $mode === 'html2wt' || $mode === 'wt2wt' || $mode === 'selser';
		// FIXME: normalization not in place yet
		$normalizedExpected = $toWikiText ?
			preg_replace( '/\n+$/D', '', $testWikitext, 1 ) : $testWikitext;

		// FIXME: normalization not in place yet
		$normalizedOut = ( $toWikiText ) ? preg_replace( '/\n+$/D', '', $out, 1 ) : $out;

		$input = $mode === 'selser' ? $test->changedHTMLStr :
			( $mode === 'html2wt' ? $test->parsoidHtml : $testWikitext );
		$expected = [ 'normal' => $normalizedExpected, 'raw' => $testWikitext ];
		$actual = [ 'normal' => $normalizedOut, 'raw' => $out, 'input' => $input ];

		return $options['reportResult'](
			$this->stats, $test, $options, $mode, $expected, $actual );
	}

	/**
	 * FIXME: clean up this mess!
	 * - generate all changes at once (generateChanges should return a tree
	 *   really) rather than going to all these lengths of interleaving change
	 *   generation with tests
	 * - set up the changes in item directly rather than juggling around with
	 *   indexes etc
	 * - indicate whether to compare to wt2wt or the original input
	 * - maybe make a full selser test one method that uses others rather than the
	 *   current chain of methods that sometimes do something for selser
	 *
	 * @param Test $test
	 * @param array $targetModes
	 * @param array $options
	 */
	private function buildTasks(
		Test $test, array $targetModes, array $options
	): void {
		if ( !$test->testName ) {
			throw new Error( 'Missing title from test case.' );
		}

		foreach ( $targetModes as $targetMode ) {
			if ( $targetMode === 'selser' && $options['numchanges'] &&
				$options['selser'] !== 'noauto' && !isset( $options['changetree'] )
			) {
				// Prepend manual changes, if present, but not if 'selser' isn't
				// in the explicit modes option.
				if ( isset( $test->options['parsoid']['changes'] ) ) {
					$newitem = Utils::clone( $test );
					// Mutating the item here is necessary to output 'manual' in
					// the test's title and to differentiate it for knownFailures.
					// It can only get here in two cases:
					// * When there's no changetree specified in the command line,
					//   buildTasks creates the items by cloning the original one,
					//   so there should be no problem setting it.
					//   In fact, it will override the existing 'manual' value
					//   (lines 1765 and 1767).
					// * When a changetree is specified in the command line and
					//   it's 'manual', there shouldn't be a problem setting the
					//   value here as no other items will be processed.
					// Still, protect against changing a different copy of the item.
					Assert::invariant(
						$newitem->changetree === [ 'manual' ] || $newitem->changetree === null,
						'Expecting manual changetree OR no changetree'
					);
					$newitem->changetree = [ 'manual' ];
					$this->runTest( $newitem, 'selser', $options );
				}
				// And if that's all we want, next one.
				if ( ( $test->options['parsoid']['selser'] ?? '' ) === 'noauto' ) {
					continue;
				}

				$test->selserChangeTrees = [];

				// Prepend a selser test that appends a comment to the root node
				$newitem = Utils::clone( $test );
				$newitem->changetree = [ 5 ];
				$this->runTest( $newitem, 'selser', $options );

				for ( $j = 0; $j < $options['numchanges']; $j++ ) {
					$newitem = Utils::clone( $test );
					// Make sure we aren't reusing the one from manual changes
					Assert::invariant( $newitem->changetree === null, "Expected changetree to be null" );
					$newitem->seed = $j . '';
					$this->runTest( $newitem, $targetMode, $options );
					if ( $this->isDuplicateChangeTree( $test->selserChangeTrees, $newitem->changes ) ) {
						// Once we get a duplicate change tree, we can no longer
						// generate and run new tests.  So, be done now!
						break;
					} else {
						$test->selserChangeTrees[$j] = $newitem->changes;
					}
				}
			} else {
				if ( $targetMode === 'selser' && $options['selser'] === 'noauto' ) {
					// Manual changes were requested on the command line,
					// check that the item does have them.
					if ( isset( $test->options['parsoid']['changes'] ) ) {
						// If it does, we need to clone the item so that previous
						// results don't clobber this one.
						$this->runTest( Utils::clone( $test ), $targetMode, $options );
					} else {
						// If it doesn't have manual changes, just skip it.
						continue;
					}
				} else {
					// The order here is important, in that cloning `item` should
					// happen before `item` is used in `runTest()`, since
					// we cache some properties (`cachedBODYstr`,
					// `cachedNormalizedHTML`) that should be cleared before use
					// in `newitem`.
					if ( $targetMode === 'wt2html' &&
						isset( $test->sections['html/parsoid+langconv'] )
					) {
						$newitem = Utils::clone( $test );
						$newitem->options['langconv'] = true;
						$newitem->parsoidHtml = $test->sections['html/parsoid+langconv'];
						$this->runTest( $newitem, $targetMode, $options );
						if ( $test->parsoidHtml === null ) {
							// Don't run the same test in non-langconv mode
							// unless we have a non-langconv section
							continue;
						}
					}
					// A non-selser task, we can reuse the item.
					$this->runTest( $test, $targetMode, $options );
				}
			}
		}
	}

	/**
	 * @param array $options
	 * @return array
	 */
	private function updateKnownFailures( array $options ): array {
		// Sanity check in case any tests were removed but we didn't update
		// the knownFailures
		$knownFailuresChanged = false;
		$allModes = $options['wt2html'] && $options['wt2wt'] &&
			$options['html2wt'] && $options['html2html'] &&
			isset( $options['selser'] ) &&
			!( isset( $options['filter'] ) || isset( $options['regex'] ) ||
				isset( $options['maxtests'] ) );
		$offsetType = $options['offsetType'] ?? 'byte';

		// update the knownFailures, if requested
		if ( $allModes || ScriptUtils::booleanOption( $options['updateKnownFailures'] ?? null ) ) {
			if ( $this->knownFailuresPath !== null ) {
				$old = file_get_contents( $this->knownFailuresPath );
			} else {
				// If file doesn't exist, use the JSON representation of an
				// empty array, so it compares equal in the case that we
				// end up with an empty array of known failures below.
				$old = '[]';
			}
			$testKnownFailures = [];
			foreach ( $options['modes'] as $mode ) {
				foreach ( $this->stats->modes[$mode]->failList as $fail ) {
					if ( !isset( $testKnownFailures[$fail['testName']] ) ) {
						$testKnownFailures[$fail['testName']] = [];
					}
					$testKnownFailures[$fail['testName']][$mode . $fail['suffix']] = $fail['raw'];
				}
			}
			// Sort, otherwise, titles get added above based on the first
			// failing mode, which can make diffs harder to verify when
			// failing modes change.
			ksort( $testKnownFailures );
			$contents = json_encode(
				$testKnownFailures,
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES |
				JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE
			);
			if ( ScriptUtils::booleanOption( $options['updateKnownFailures'] ?? null ) ) {
				file_put_contents( $this->knownFailuresPath, $contents );
			} elseif ( $allModes && $offsetType === 'byte' ) {
				$knownFailuresChanged = $contents !== $old;
			}
		}

		// Write updated tests from failed ones
		if ( isset( $options['update-tests'] ) ||
			 ScriptUtils::booleanOption( $options['update-unexpected'] ?? null )
		) {
			$updateFormat = $options['update-tests'] === 'raw' ? 'raw' : 'actualNormalized';
			$fileContent = file_get_contents( $this->testFilePath );
			foreach ( $this->stats->modes['wt2html']->failList as $fail ) {
				if ( isset( $options['update-tests'] ) || $fail['unexpected'] ) {
					$exp = '/(!!\s*test\s*' .
						 preg_quote( $fail['testName'], '/' ) .
						 '(?:(?!!!\s*end)[\s\S])*' .
						 ')(' . preg_quote( $fail['expected'], '/' ) .
						 ')/m';
					$fileContent = preg_replace_callback(
						$exp,
						static function ( array $matches ) use ( $fail, $updateFormat ) {
							return $matches[1] . $fail[$updateFormat];
						},
						$fileContent
					);
				}
			}
			file_put_contents( $this->testFilePath, $fileContent );
		}

		// print out the summary
		// note: these stats won't necessarily be useful if someone
		// reimplements the reporting methods, since that's where we
		// increment the stats.
		$failures = $options['reportSummary'](
			$options['modes'], $this->stats, $this->testFileName,
			$this->testFilter, $knownFailuresChanged
		);

		// we're done!
		// exit status 1 == uncaught exception
		$exitCode = ( $failures > 0 || $knownFailuresChanged ) ? 2 : 0;
		if ( ScriptUtils::booleanOption( $options['exit-zero'] ?? null ) ) {
			$exitCode = 0;
		}

		return [
			'exitCode' => $exitCode,
			'stats' => $this->stats,
			'file' => $this->testFileName,
			'knownFailuresChanged' => $knownFailuresChanged
		];
	}

	/**
	 * @param Test $test
	 * @param array $options
	 */
	private function processTest( Test $test, array $options ): void {
		if ( !$test->options ) {
			$test->options = [];
		}

		// ensure that test is not skipped if it has a wikitext/edited or
		// html/parsoid+langconv section (but not a parsoid html section)
		$haveHtml = ( $test->parsoidHtml !== null ) ||
			isset( $test->sections['wikitext/edited'] ) ||
			isset( $test->sections['html/parsoid+langconv'] );

		// Reset the cached results for the new case.
		// All test modes happen in a single run of processCase.
		$test->cachedBODYstr = null;
		$test->cachedNormalizedHTML = null;

		$targetModes = $options['modes'];
		if ( $test->wikitext === null || !$haveHtml
			|| ( isset( $test->options['disabled'] ) && !$this->runDisabled )
			|| ( isset( $test->options['php'] )
				&& !( isset( $test->sections['html/parsoid'] ) || $this->runPHP ) )
			|| !$test->matchesFilter( $this->testFilter )
		) {
			// Skip test whose title does not match --filter
			// or which is disabled or php-only
			return;
		}

		// Set logger
		$suppressErrors = !empty( $test->options['parsoid']['suppressErrors'] );
		$this->siteConfig->setLogger( $suppressErrors ?
			$this->siteConfig->suppressLogger : $this->defaultLogger );

		$testModes = $test->options['parsoid']['modes'] ?? null;
		if ( $testModes ) {
			// Avoid filtering out the selser test
			if ( isset( $options['selser'] ) &&
				array_search( 'selser', $testModes, true ) === false &&
				array_search( 'wt2wt', $testModes, true ) !== false
			) {
				$testModes[] = 'selser';
			}

			$targetModes = array_filter( $targetModes, static function ( string $mode ) use ( $testModes ): bool {
				return array_search( $mode, $testModes, true ) !== false;
			} );
		}

		if ( !count( $targetModes ) ) {
			// No matching target modes
			return;
		}

		// Honor language option in parserTests.txt
		$prefix = $test->options['language'] ?? 'enwiki';
		if ( !preg_match( '/wiki/', $prefix ) ) {
			// Convert to our enwiki.. format
			$prefix .= 'wiki';
		}

		// Switch to requested wiki
		$this->mockApi->setApiPrefix( $prefix );
		$this->siteConfig->reset();

		// Update $wgInterwikiMagic flag
		// default (undefined) setting is true
		$iwmVal = $test->options['wginterwikimagic'] ?? null;
		if ( !$iwmVal ) {
			$this->siteConfig->setInterwikiMagic( true );
		} else {
			$this->siteConfig->setInterwikiMagic( $iwmVal === 1 || $iwmVal === true );
		}

		if ( $test->options ) {
			Assert::invariant( !isset( $test->options['extensions'] ),
				'Cannot configure extensions in tests' );

			$this->siteConfig->disableSubpagesForNS( 0 );
			if ( isset( $test->options['subpage'] ) ) {
				$this->siteConfig->enableSubpagesForNS( 0 );
			}

			$allowedPrefixes = [ '' ]; // all allowed
			if ( isset( $test->options['wgallowexternalimages'] ) &&
				!preg_match( '/^(1|true|)$/D', $test->options['wgallowexternalimages'] )
			) {
				$allowedPrefixes = [];
			}
			$this->siteConfig->allowedExternalImagePrefixes = $allowedPrefixes;

			// Process test-specific options
			$defaults = [
				'scrubWikitext' => false,
				'wrapSections' => false
			]; // override for parser tests
			foreach ( $defaults as $opt => $defaultVal ) {
				$this->envOptions[$opt] = $test->options['parsoid'][$opt] ?? $defaultVal;
			}

			$this->siteConfig->responsiveReferences =
				$test->options['parsoid']['responsiveReferences'] ?? $this->siteConfig->responsiveReferences;

			// Emulate PHP parser's tag hook to tunnel content past the sanitizer
			if ( isset( $test->options['styletag'] ) ) {
				$this->siteConfig->registerParserTestExtension( new StyleTag() );
			}

			if ( ( $test->options['wgrawhtml'] ?? null ) === '1' ) {
				$this->siteConfig->registerParserTestExtension( new RawHTML() );
			}
		}

		$this->buildTasks( $test, $targetModes, $options );
	}

	/**
	 * Run parser tests for the file with the provided options
	 *
	 * @param array $options
	 * @return array
	 */
	public function run( array $options ): array {
		$this->runDisabled = ScriptUtils::booleanOption( $options['run-disabled'] ?? null );
		$this->runPHP = ScriptUtils::booleanOption( $options['run-php'] ?? null );
		$this->offsetType = $options['offsetType'] ?? 'byte';

		// Test case filtering
		$this->testFilter = null;
		if ( isset( $options['filter'] ) || isset( $options['regex'] ) ) {
			$this->testFilter = [
				'raw' => $options['regex'] ?? $options['filter'],
				'regex' => isset( $options['regex'] ),
				'string' => isset( $options['filter'] )
			];
		}

		$this->buildTests();

		if ( isset( $options['maxtests'] ) ) {
			$n = $options['maxtests'];
			error_log( 'maxtests:' . $n );
			if ( $n > 0 ) {
				// Trim test cases to the desired amount
				$this->testCases = array_slice( $this->testCases, 0, $n );
			}
		}

		// Register parser tests parser hook
		$this->siteConfig->registerParserTestExtension( new ParserHook() );

		$this->envOptions = [
			'wrapSections' => false,
			'nativeTemplateExpansion' => true,
			'offsetType' => $this->offsetType,
		];
		ScriptUtils::setDebuggingFlags( $this->envOptions, $options );
		ScriptUtils::setTemplatingAndProcessingFlags( $this->envOptions, $options );

		if ( ScriptUtils::booleanOption( $options['quiet'] ?? null ) ) {
			$this->envOptions['logLevels'] = [ 'fatal', 'error' ];
		}

		// Save default logger so we can be reset it after temporarily
		// switching to the suppressLogger to suppress expected error
		// messages.
		$this->defaultLogger = $this->siteConfig->getLogger();

		/**
		 * PORT-FIXME(T238722)
		 * // Enable sampling to assert it's working while testing.
		 * $parsoidConfig->loggerSampling = [ [ '/^warn(\/|$)/', 100 ] ];
		 *
		 * // Override env's `setLogger` to record if we see `fatal` or `error`
		 * // while running parser tests.  (Keep it clean, folks!  Use
		 * // "suppressError" option on the test if error is expected.)
		 * $env->setLogger = ( ( function ( $parserTests, $superSetLogger ) {
		 * return function ( $_logger ) use ( &$parserTests ) {
		 * call_user_func( 'superSetLogger', $_logger );
		 * $this->log = function ( $level ) use ( &$_logger, &$parserTests ) {
		 * if ( $_logger !== $parserTests->suppressLogger &&
		 * preg_match( '/^(fatal|error)\b/', $level )
		 * ) {
		 * $parserTests->stats->loggedErrorCount++;
		 * }
		 * return call_user_func_array( [ $_logger, 'log' ], $arguments );
		 * };
		 * };
		 * } ) );
		 */

		$options['reportStart']();

		// Run tests
		foreach ( $this->testCases as $test ) {
			try {
				$this->processTest( $test, $options );
			} catch ( UnexpectedException $e ) {
				// Exit unexpected
				break;
			}
		}

		// Update knownFailures
		return $this->updateKnownFailures( $options );
	}
}
