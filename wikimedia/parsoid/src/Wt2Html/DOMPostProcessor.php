<?php
declare( strict_types = 1 );

namespace Wikimedia\Parsoid\Wt2Html;

use Closure;
use DateTime;
use Generator;
use Wikimedia\ObjectFactory;
use Wikimedia\Parsoid\Config\Env;
use Wikimedia\Parsoid\DOM\Document;
use Wikimedia\Parsoid\DOM\Element;
use Wikimedia\Parsoid\DOM\Node;
use Wikimedia\Parsoid\Ext\DOMProcessor as ExtDOMProcessor;
use Wikimedia\Parsoid\Ext\ParsoidExtensionAPI;
use Wikimedia\Parsoid\Tokens\SourceRange;
use Wikimedia\Parsoid\Utils\ContentUtils;
use Wikimedia\Parsoid\Utils\DOMCompat;
use Wikimedia\Parsoid\Utils\DOMDataUtils;
use Wikimedia\Parsoid\Utils\DOMTraverser;
use Wikimedia\Parsoid\Utils\DOMUtils;
use Wikimedia\Parsoid\Utils\PHPUtils;
use Wikimedia\Parsoid\Utils\Utils;
use Wikimedia\Parsoid\Wt2Html\PP\Handlers\CleanUp;
use Wikimedia\Parsoid\Wt2Html\PP\Handlers\DedupeStyles;
use Wikimedia\Parsoid\Wt2Html\PP\Handlers\DisplaySpace;
use Wikimedia\Parsoid\Wt2Html\PP\Handlers\HandleLinkNeighbours;
use Wikimedia\Parsoid\Wt2Html\PP\Handlers\Headings;
use Wikimedia\Parsoid\Wt2Html\PP\Handlers\LiFixups;
use Wikimedia\Parsoid\Wt2Html\PP\Handlers\TableFixups;
use Wikimedia\Parsoid\Wt2Html\PP\Handlers\UnpackDOMFragments;
use Wikimedia\Parsoid\Wt2Html\PP\Processors\AddLinkClasses;
use Wikimedia\Parsoid\Wt2Html\PP\Processors\AddMediaInfo;
use Wikimedia\Parsoid\Wt2Html\PP\Processors\AddRedLinks;
use Wikimedia\Parsoid\Wt2Html\PP\Processors\ComputeDSR;
use Wikimedia\Parsoid\Wt2Html\PP\Processors\ConvertOffsets;
use Wikimedia\Parsoid\Wt2Html\PP\Processors\I18n;
use Wikimedia\Parsoid\Wt2Html\PP\Processors\LangConverter;
use Wikimedia\Parsoid\Wt2Html\PP\Processors\Linter;
use Wikimedia\Parsoid\Wt2Html\PP\Processors\MarkFosteredContent;
use Wikimedia\Parsoid\Wt2Html\PP\Processors\MigrateTemplateMarkerMetas;
use Wikimedia\Parsoid\Wt2Html\PP\Processors\MigrateTrailingNLs;
use Wikimedia\Parsoid\Wt2Html\PP\Processors\Normalize;
use Wikimedia\Parsoid\Wt2Html\PP\Processors\ProcessTreeBuilderFixups;
use Wikimedia\Parsoid\Wt2Html\PP\Processors\PWrap;
use Wikimedia\Parsoid\Wt2Html\PP\Processors\WrapSections;
use Wikimedia\Parsoid\Wt2Html\PP\Processors\WrapTemplates;

/**
 * Perform post-processing steps on an already-built HTML DOM.
 */
class DOMPostProcessor extends PipelineStage {
	/** @var array */
	private $options;

	/** @var array */
	private $seenIds;

	/** @var array */
	private $processors;

	/** @var ParsoidExtensionAPI Provides post-processing support to extensions */
	private $extApi;

	/** @var array */
	private $metadataMap;

	/** @var string */
	private $timeProfile = '';

	/**
	 * @param Env $env
	 * @param array $options
	 * @param string $stageId
	 * @param ?PipelineStage $prevStage
	 */
	public function __construct(
		Env $env, array $options = [], string $stageId = "",
		?PipelineStage $prevStage = null
	) {
		parent::__construct( $env, $prevStage );

		$this->options = $options;
		$this->seenIds = [];
		$this->processors = [];
		$this->extApi = new ParsoidExtensionAPI( $env );

		// map from mediawiki metadata names to RDFa property names
		$this->metadataMap = [
			'ns' => [
				'property' => 'mw:pageNamespace',
				'content' => '%d',
			],
			'id' => [
				'property' => 'mw:pageId',
				'content' => '%d',
			],

			// DO NOT ADD rev_user, rev_userid, and rev_comment (See T125266)

			// 'rev_revid' is used to set the overall subject of the document, we don't
			// need to add a specific <meta> or <link> element for it.

			'rev_parentid' => [
				'rel' => 'dc:replaces',
				'resource' => 'mwr:revision/%d',
			],
			'rev_timestamp' => [
				'property' => 'dc:modified',
				'content' => static function ( $m ) {
					# Convert from TS_MW ("mediawiki timestamp") format
					$dt = DateTime::createFromFormat( 'YmdHis', $m['rev_timestamp'] );
					# Note that DateTime::ISO8601 is not actually ISO8601, alas.
					return $dt->format( 'Y-m-d\TH:i:s.000\Z' );
				},
			],
			'rev_sha1' => [
				'property' => 'mw:revisionSHA1',
				'content' => '%s',
			]
		];
	}

	/**
	 * @param ?array $processors
	 */
	public function registerProcessors( ?array $processors ): void {
		if ( empty( $processors ) ) {
			$processors = $this->getDefaultProcessors();
		}

		foreach ( $processors as $p ) {
			if ( !empty( $p['omit'] ) ) {
				continue;
			}
			if ( empty( $p['name'] ) ) {
				$p['name'] = Utils::stripNamespace( $p['Processor'] );
			}
			if ( empty( $p['shortcut'] ) ) {
				$p['shortcut'] = $p['name'];
			}
			if ( !empty( $p['isTraverser'] ) ) {
				$t = new DOMTraverser();
				foreach ( $p['handlers'] as $h ) {
					$t->addHandler( $h['nodeName'], $h['action'] );
				}
				$p['proc'] = function ( ...$args ) use ( $t ) {
					$args[] = null;
					return $t->traverse( $this->env, ...$args );
				};
			} else {
				$classNameOrSpec = $p['Processor'];
				if ( empty( $p['isExtPP'] ) ) {
					// Internal processor w/ ::run() method, class name given
					// @phan-suppress-next-line PhanNonClassMethodCall
					$c = new $classNameOrSpec();
					$p['proc'] = function ( ...$args ) use ( $c ) {
						return $c->run( $this->env, ...$args );
					};
				} else {
					// Extension post processor, object factory spec given
					$c = ObjectFactory::getObjectFromSpec( $classNameOrSpec, [
						'allowClassName' => true,
						'assertClass' => ExtDOMProcessor::class,
					] );
					$p['proc'] = function ( ...$args ) use ( $c ) {
						return $c->wtPostprocess( $this->extApi, ...$args );
					};
				}
			}
			$this->processors[] = $p;
		}
	}

	/**
	 * @return array
	 */
	public function getDefaultProcessors(): array {
		$env = $this->env;
		$options = $this->options;
		$seenIds = &$this->seenIds;
		$usedIdIndex = [];

		$tableFixer = new TableFixups( $env );

		/* ---------------------------------------------------------------------------
		 * FIXME:
		 * 1. PipelineFactory caches pipelines per env
		 * 2. PipelineFactory.parse uses a default cache key
		 * 3. ParserTests uses a shared/global env object for all tests.
		 * 4. ParserTests also uses PipelineFactory.parse (via env.getContentHandler())
		 *    => the pipeline constructed for the first test that runs wt2html
		 *       is used for all subsequent wt2html tests
		 * 5. If we are selectively turning on/off options on a per-test basis
		 *    in parser tests, those options won't work if those options are
		 *    also used to configure pipeline construction (including which DOM passes
		 *    are enabled).
		 *
		 *    Ex: if (env.wrapSections) { addPP('wrapSections', wrapSections); }
		 *
		 *    This won't do what you expect it to do. This is primarily a
		 *    parser tests script issue -- but given the abstraction layers that
		 *    are on top of the parser pipeline construction, fixing that is
		 *    not straightforward right now. So, this note is a warning to future
		 *    developers to pay attention to how they construct pipelines.
		 * --------------------------------------------------------------------------- */

		$processors = [
			// Common post processing
			[
				'Processor' => MarkFosteredContent::class,
				'shortcut' => 'fostered'
			],
			[
				'Processor' => ProcessTreeBuilderFixups::class,
				'shortcut' => 'process-fixups'
			],
			[
				'Processor' => Normalize::class
			],
			[
				'Processor' => PWrap::class,
				'shortcut' => 'pwrap',
				'skipNested' => true
			],
			// This is run at all levels since, for now, we don't have a generic
			// solution to running top level passes on HTML stashed in data-mw.
			// See T214994 for that.
			//
			// Also, the gallery extension's "packed" mode would otherwise need a
			// post-processing pass to scale media after it has been fetched.  That
			// introduces an ordering dependency that may or may not complicate things.
			[
				'Processor' => AddMediaInfo::class,
				'shortcut' => 'media'
			],
			// Run this after 'ProcessTreeBuilderFixups' because the mw:StartTag
			// and mw:EndTag metas would otherwise interfere with the
			// firstChild/lastChild check that this pass does.
			[
				'Processor' => MigrateTemplateMarkerMetas::class,
				'shortcut' => 'migrate-metas'
			],
			[
				'Processor' => MigrateTrailingNLs::class,
				'shortcut' => 'migrate-nls'
			],
			// dsr computation and tpl encap are only relevant for top-level content
			[
				'Processor' => ComputeDSR::class,
				'shortcut' => 'dsr',
				'omit' => !empty( $options['inTemplate'] )
			],
			[
				'Processor' => WrapTemplates::class,
				'shortcut' => 'tplwrap',
				'omit' => !empty( $options['inTemplate'] )
			],
			// 1. Link prefixes and suffixes
			// 2. Unpack DOM fragments
			[
				'name' => 'HandleLinkNeighbours,UnpackDOMFragments',
				'shortcut' => 'dom-unpack',
				'isTraverser' => true,
				'handlers' => [
					[
						'nodeName' => 'a',
						'action' => [ HandleLinkNeighbours::class, 'handler' ]
					],
					[
						'nodeName' => null,
						'action' => [ UnpackDOMFragments::class, 'handler' ]
					]
				]
			]
		];

		/**
		 * FIXME: There are two potential ordering problems here.
		 *
		 * 1. unpackDOMFragment should always run immediately
		 *    before these extensionPostProcessors, which we do currently.
		 *    This ensures packed content get processed correctly by extensions
		 *    before additional transformations are run on the DOM.
		 *
		 * This ordering issue is handled through documentation.
		 *
		 * 2. This has existed all along (in the PHP parser as well as Parsoid
		 *    which is probably how the ref-in-ref hack works - because of how
		 *    parser functions and extension tags are procesed, #tag:ref doesn't
		 *    see a nested ref anymore) and this patch only exposes that problem
		 *    more clearly with the unpackOutput property.
		 *
		 * * Consider the set of extensions that
		 *   (a) process wikitext
		 *   (b) provide an extensionPostProcessor
		 *   (c) run the extensionPostProcessor only on the top-level
		 *   As of today, there is exactly one extension (Cite) that has all
		 *   these properties, so the problem below is a speculative problem
		 *   for today. But, this could potentially be a problem in the future.
		 *
		 * * Let us say there are at least two of them, E1 and E2 that
		 *   support extension tags <e1> and <e2> respectively.
		 *
		 * * Let us say in an instance of <e1> on the page, <e2> is present
		 *   and in another instance of <e2> on the page, <e1> is present.
		 *
		 * * In what order should E1's and E2's extensionPostProcessors be
		 *   run on the top-level? Depending on what these handlers do, you
		 *   could get potentially different results. You can see this quite
		 *   starkly with the unpackOutput flag.
		 *
		 * * The ideal solution to this problem is to require that every extension's
		 *   extensionPostProcessor be idempotent which lets us run these
		 *   post processors repeatedly till the DOM stabilizes. But, this
		 *   still doesn't necessarily guarantee that ordering doesn't matter.
		 *   It just guarantees that with the unpackOutput flag set to false
		 *   multiple extensions, all sealed fragments get fully processed.
		 *   So, we still need to worry about that problem.
		 *
		 *   But, idempotence *could* potentially be a sufficient property in most cases.
		 *   To see this, consider that there is a Footnotes extension which is similar
		 *   to the Cite extension in that they both extract inline content in the
		 *   page source to a separate section of output and leave behind pointers to
		 *   the global section in the output DOM. Given this, the Cite and Footnote
		 *   extension post processors would essentially walk the dom and
		 *   move any existing inline content into that global section till it is
		 *   done. So, even if a <footnote> has a <ref> and a <ref> has a <footnote>,
		 *   we ultimately end up with all footnote content in the footnotes section
		 *   and all ref content in the references section and the DOM stabilizes.
		 *   Ordering is irrelevant here.
		 *
		 *   So, perhaps one way of catching these problems would be in code review
		 *   by analyzing what the DOM postprocessor does and see if it introduces
		 *   potential ordering issues.
		 */
		foreach ( $env->getSiteConfig()->getExtDOMProcessors() as $extName => $domProcs ) {
			foreach ( $domProcs as $i => $domProcSpec ) {
				$processors[] = [
					'isExtPP' => true, // This is an extension DOM post processor
					'name' => "pp:$extName:$i",
					'Processor' => $domProcSpec,
				];
			}
		}

		$processors = array_merge( $processors, [
			[
				'name' => 'MigrateTrailingCategories,TableFixups,DedupeStyles',
				'shortcut' => 'fixups',
				'isTraverser' => true,
				'skipNested' => true,
				'handlers' => [
					// Move trailing categories in <li>s out of the list
					[
						'nodeName' => 'li',
						'action' => [ LiFixups::class, 'migrateTrailingCategories' ]
					],
					[
						'nodeName' => 'dt',
						'action' => [ LiFixups::class, 'migrateTrailingCategories' ]
					],
					[
						'nodeName' => 'dd',
						'action' => [ LiFixups::class, 'migrateTrailingCategories' ]
					],
					// 2. Fix up issues from templated table cells and table cell attributes
					[
						'nodeName' => 'td',
						'action' => function ( $node, $env, $options ) use ( &$tableFixer ) {
							return $tableFixer->stripDoubleTDs( $node, $this->frame );
						}
					],
					[
						'nodeName' => 'td',
						'action' => function ( $node, $env, $options ) use ( &$tableFixer ) {
							return $tableFixer->handleTableCellTemplates( $node, $this->frame );
						}
					],
					[
						'nodeName' => 'th',
						'action' => function ( $node, $env, $options ) use ( &$tableFixer ) {
							return $tableFixer->handleTableCellTemplates( $node, $this->frame );
						}
					],
					// 3. Deduplicate template styles
					// (should run after dom-fragment expansion + after extension post-processors)
					[
						'nodeName' => 'style',
						'action' => [ DedupeStyles::class, 'dedupe' ]
					]
				]
			],
			// Benefits from running after determining which media are redlinks
			[
				'name' => 'Headings-genAnchors',
				'shortcut' => 'heading-ids',
				'isTraverser' => true,
				'skipNested' => true,
				'handlers' => [
					[
						'nodeName' => null,
						'action' => [ Headings::class, 'genAnchors' ]
					],
					[
						'nodeName' => null,
						'action' => static function ( $node, $env ) use ( &$seenIds ) {
							return Headings::dedupeHeadingIds( $seenIds, $node );
						}
					]
				]
			],
			[
				'Processor' => Linter::class,
				'omit' => !$env->getSiteConfig()->linting(),
				'skipNested' => true
			],
			// Strip marker metas -- removes left over marker metas (ex: metas
			// nested in expanded tpl/extension output).
			[
				'name' => 'CleanUp-stripMarkerMetas',
				'shortcut' => 'strip-metas',
				'isTraverser' => true,
				'handlers' => [
					[
						'nodeName' => 'meta',
						'action' => [ CleanUp::class, 'stripMarkerMetas' ]
					]
				]
			],
			// Language conversion and Red link marking are done here
			// *before* we cleanup and save data-parsoid because they
			// are also used in pb2pb/html2html passes, and we want to
			// keep their input/output formats consistent.
			[
				'Processor' => LangConverter::class,
				'shortcut' => 'lang-converter',
				'skipNested' => true
			],
			[
				'Processor' => AddRedLinks::class,
				'shortcut' => 'redlinks',
				'skipNested' => true,
				'omit' => $env->noDataAccess(),
			],
			[
				'name' => 'DisplaySpace',
				'shortcut' => 'displayspace',
				'skipNested' => true,
				'isTraverser' => true,
				'handlers' => [
					[
						'nodeName' => '#text',
						'action' => [ DisplaySpace::class, 'leftHandler' ]
					],
					[
						'nodeName' => '#text',
						'action' => [ DisplaySpace::class, 'rightHandler' ]
					],
				]
			],
			[
				'Processor' => AddLinkClasses::class,
				'shortcut' => 'linkclasses',
				// Note that embedded content doesn't get these classes
				'skipNested' => true
			],
			// Add <section> wrappers around sections
			[
				'Processor' => WrapSections::class,
				'shortcut' => 'sections',
				'skipNested' => true
			],
			[
				'Processor' => ConvertOffsets::class,
				'shortcut' => 'convertoffsets',
				'skipNested' => true,
			],
			[
				'Processor' => I18n::class,
				'shortcut' => 'i18n',
				// FIXME(T214994): This should probably be `true`, since we
				// want this to be another html2html type pass, but then our
				// processor would need to handle nested content.  Redlinks,
				// displayspace, and others are ignoring that for now though,
				// so let's wait until there's a more general mechanism.
				'skipNested' => false,
			],
			[
				'name' => 'CleanUp-handleEmptyElts,CleanUp-cleanupAndSaveDataParsoid',
				'shortcut' => 'cleanup',
				'isTraverser' => true,
				'handlers' => [
					// Strip empty elements from template content
					[
						'nodeName' => null,
						'action' => [ CleanUp::class, 'handleEmptyElements' ]
					],
					// Save data.parsoid into data-parsoid html attribute.
					// Make this its own thing so that any changes to the DOM
					// don't affect other handlers that run alongside it.
					[
						'nodeName' => null,
						'action' => static function (
							$node, $env, $options, $atTopLevel, $tplInfo
						) use ( &$usedIdIndex ) {
							if ( $atTopLevel && DOMUtils::isBody( $node ) ) {
								$usedIdIndex = DOMDataUtils::usedIdIndex( $node );
							}
							return CleanUp::cleanupAndSaveDataParsoid(
								$usedIdIndex, $node, $env, $atTopLevel,
								$tplInfo
							);
						}
					]
				]
			],
		] );

		return $processors;
	}

	/**
	 * @inheritDoc
	 */
	public function setSourceOffsets( SourceRange $so ): void {
		$this->options['sourceOffsets'] = $so;
	}

	/**
	 * @inheritDoc
	 */
	public function resetState( array $options ): void {
		parent::resetState( $options );

		// $this->env->getPageConfig()->meta->displayTitle = null;
		$this->seenIds = [];
	}

	/**
	 * Create an element in the document.head with the given attrs.
	 *
	 * @param Document $document
	 * @param string $tagName
	 * @param array $attrs
	 */
	private function appendToHead( Document $document, string $tagName, array $attrs = [] ): void {
		$elt = $document->createElement( $tagName );
		DOMUtils::addAttributes( $elt, $attrs );
		( DOMCompat::getHead( $document ) )->appendChild( $elt );
	}

	/**
	 * While unnecessary for Wikimedia clients, a stylesheet url in the <head>
	 * is useful for clients like Kiwix and others who might not want to process
	 * the meta tags to construct the resourceloader url.
	 *
	 * Given that these clients will be consuming Parsoid HTML outside a MediaWiki skin,
	 * the clients are effectively responsible for their own "skin". But, once again,
	 * as a courtesy, we are hardcoding the vector skin modules for them. But, note
	 * that this may cause page elements to render differently than how they render
	 * on Wikimedia sites with the vector skin since this is probably missing a number
	 * of other modules.
	 *
	 * All that said, note that JS-generated parts of the page will still require them
	 * to have more intimate knowledge of how  to process the JS modules. Except for
	 * <graph>s, page content doesn't require JS modules at this point. So, where these
	 * clients want to invest in the necessary logic to construct a better resourceloader
	 * url, they could simply delete / ignore this stylesheet.
	 *
	 * @param Document $document
	 * @param Env $env
	 * @param string $lang
	 * @param array $styleModules
	 */
	private function addCourtesyBasicStyleSheet(
		Document $document, Env $env, string $lang, array $styleModules
	): void {
		$styleModules = array_unique( array_merge( $styleModules, [
			'mediawiki.skinning.content.parsoid',
			// Use the base styles that API output and fallback skin use.
			'mediawiki.skinning.interface',
			// Make sure to include contents of user generated styles
			// e.g. MediaWiki:Common.css / MediaWiki:Mobile.css
			'site.styles'
		] ) );

		$styleURI = $env->getSiteConfig()->getModulesLoadURI() .
			'?lang=' . $lang . '&modules=' .
			PHPUtils::encodeURIComponent( implode( '|', $styleModules ) ) .
			'&only=styles&skin=vector';
		$this->appendToHead( $document, 'link', [ 'rel' => 'stylesheet', 'href' => $styleURI ] );
	}

	/**
	 * Export used style modules via a meta tag (and via a stylesheet for now to aid some clients)
	 * @param Document $document
	 * @param Env $env
	 * @param string $lang
	 */
	private function exportStyleModules( Document $document, Env $env, string $lang ): void {
		// Styles from modules returned from preprocessor / parse requests
		$styleModules = $env->getOutputProperties()['modulestyles'] ?? [];
		if ( $styleModules ) {
			// FIXME: Maybe think about using an associative array or DS\Set
			$styleModules = array_unique( $styleModules );

			// mw:styleModules are CSS modules that are render-blocking.
			$this->appendToHead( $document, 'meta', [
				'property' => 'mw:styleModules',
				'content' => implode( '|', $styleModules )
			] );
		}

		$this->addCourtesyBasicStyleSheet( $document, $env, $lang, $styleModules );
	}

	/**
	 * Export general modules (usually JS scripts) via a meta tag
	 * @param Document $document
	 * @param Env $env
	 */
	private function exportGeneralModules( Document $document, Env $env ): void {
		// Styles from modules returned from preprocessor / parse requests
		$generalModules = $env->getOutputProperties()['modules'] ?? [];
		if ( $generalModules ) {
			// mw:generalModules can be processed via JS (and async) and are usually (but
			// not always) JS scripts.
			$this->appendToHead( $document, 'meta', [
				'property' => 'mw:generalModules',
				'content' => implode( '|', array_unique( $generalModules ) )
			] );
		}
	}

	/**
	 * Export used JS config vars via a meta tag
	 * @param Document $document
	 * @param Env $env
	 */
	private function exportJSConfigVars( Document $document, Env $env ): void {
		$vars = $env->getOutputProperties()['jsconfigvars'] ?? [];
		if ( $vars ) {
			$this->appendToHead( $document, 'meta', [
				'property' => 'mw:jsConfigVars',
				'content' => PHPUtils::jsonEncode( $vars )
			] );
		}
	}

	/**
	 * @param Element $body
	 * @param Env $env
	 */
	private function updateBodyClasslist( Element $body, Env $env ): void {
		$dir = $env->getPageConfig()->getPageLanguageDir();
		$bodyCL = DOMCompat::getClassList( $body );
		$bodyCL->add( 'mw-content-' . $dir );
		$bodyCL->add( 'sitedir-' . $dir );
		$bodyCL->add( $dir );
		$body->setAttribute( 'dir', $dir );

		// Set 'mw-body-content' directly on the body.
		// This is the designated successor for #bodyContent in core skins.
		$bodyCL->add( 'mw-body-content' );
		// Set 'parsoid-body' to add the desired layout styling from Vector.
		$bodyCL->add( 'parsoid-body' );
		// Also, add the 'mediawiki' class.
		// Some Mediawiki:Common.css seem to target this selector.
		$bodyCL->add( 'mediawiki' );
		// Set 'mw-parser-output' directly on the body.
		// Templates target this class as part of the TemplateStyles RFC
		// FIXME: This isn't expected to be found on the same element as the
		// body class above, since some css targets it as a descendant.
		// In visual diff'ing, we migrate the body contents to a wrapper div
		// with this class to reduce visual differences.  Consider getting
		// rid of it.
		$bodyCL->add( 'mw-parser-output' );
	}

	/**
	 * FIXME: consider moving to DOMUtils or Env.
	 *
	 * @param Env $env
	 * @param Document $document
	 */
	public function addMetaData( Env $env, Document $document ): void {
		// add <head> element if it was missing
		if ( !( DOMCompat::getHead( $document ) instanceof Element ) ) {
			$document->documentElement->insertBefore(
				$document->createElement( 'head' ),
				DOMCompat::getBody( $document )
			);
		}

		// add mw: and mwr: RDFa prefixes
		$prefixes = [
			'dc: http://purl.org/dc/terms/',
			'mw: http://mediawiki.org/rdf/'
		];
		$document->documentElement->setAttribute( 'prefix', implode( ' ', $prefixes ) );

		// (From wfParseUrl in core:)
		// Protocol-relative URLs are handled really badly by parse_url().
		// It's so bad that the easiest way to handle them is to just prepend
		// 'https:' and strip the protocol out later.
		$baseURI = $env->getSiteConfig()->baseURI();
		$wasRelative = substr( $baseURI, 0, 2 ) == '//';
		if ( $wasRelative ) {
			$baseURI = "https:$baseURI";
		}
		// add 'https://' to baseURI if it was missing
		$pu = parse_url( $baseURI );
		$mwrPrefix = ( !empty( $pu['scheme'] ) ? '' : 'https://' ) .
			$baseURI . 'Special:Redirect/';

		( DOMCompat::getHead( $document ) )->setAttribute( 'prefix', 'mwr: ' . $mwrPrefix );

		// add <head> content based on page meta data:

		// Set the charset first.
		$this->appendToHead( $document, 'meta', [ 'charset' => 'utf-8' ] );

		// Add page / revision metadata to the <head>
		// PORT-FIXME: We will need to do some refactoring to eliminate
		// this hardcoding. Probably even merge thi sinto metadataMap
		$pageConfig = $env->getPageConfig();
		$revProps = [
			'id' => $pageConfig->getPageId(),
			'ns' => $pageConfig->getNs(),
			'rev_parentid' => $pageConfig->getParentRevisionId(),
			'rev_revid' => $pageConfig->getRevisionId(),
			'rev_sha1' => $pageConfig->getRevisionSha1(),
			'rev_timestamp' => $pageConfig->getRevisionTimestamp()
		];
		foreach ( $revProps as $key => $value ) {
			// generate proper attributes for the <meta> or <link> tag
			if ( $value === null || $value === '' || !isset( $this->metadataMap[$key] ) ) {
				continue;
			}

			$attrs = [];
			$mdm = $this->metadataMap[$key];

			/** FIXME: The JS side has a bunch of other checks here */

			foreach ( $mdm as $k => $v ) {
				// evaluate a function, or perform sprintf-style formatting, or
				// use string directly, depending on value in metadataMap
				if ( $v instanceof Closure ) {
					$v = $v( $revProps );
				} elseif ( strpos( $v, '%' ) !== false ) {
					// @phan-suppress-next-line PhanPluginPrintfVariableFormatString
					$v = sprintf( $v, $value );
				}
				$attrs[$k] = $v;
			}

			// <link> is used if there's a resource or href attribute.
			$this->appendToHead( $document,
				isset( $attrs['resource'] ) || isset( $attrs['href'] ) ? 'link' : 'meta',
				$attrs
			);
		}

		if ( $revProps['rev_revid'] ) {
			$document->documentElement->setAttribute(
				'about', $mwrPrefix . 'revision/' . $revProps['rev_revid']
			);
		}

		// Normalize before comparison
		if (
			preg_replace( '/_/', ' ', $env->getSiteConfig()->mainpage() ) ===
			preg_replace( '/_/', ' ', $env->getPageConfig()->getTitle() )
		) {
			$this->appendToHead( $document, 'meta', [
				'property' => 'isMainPage',
				'content' => 'true' /* HTML attribute values should be strings */
			] );
		}

		// Set the parsoid content-type strings
		// FIXME: Should we be using http-equiv for this?
		$this->appendToHead( $document, 'meta', [
				'property' => 'mw:htmlVersion',
				'content' => $env->getOutputContentVersion()
			]
		);
		// Temporary backward compatibility for clients
		// This could be skipped if we support a version downgrade path
		// with a major version bump.
		$this->appendToHead( $document, 'meta', [
				'property' => 'mw:html:version',
				'content' => $env->getOutputContentVersion()
			]
		);

		$expTitle = strtr( $env->getPageConfig()->getTitle(), ' ', '_' );
		$expTitle = explode( '/', $expTitle );
		$expTitle = array_map( static function ( $comp ) {
			return PHPUtils::encodeURIComponent( $comp );
		}, $expTitle );

		$this->appendToHead( $document, 'link', [
			'rel' => 'dc:isVersionOf',
			'href' => $env->getSiteConfig()->baseURI() . implode( '/', $expTitle )
		] );

		DOMCompat::setTitle(
			$document,
			// PORT-FIXME: There isn't a place anywhere yet for displayTitle
			/* $env->getPageConfig()->meta->displayTitle || */
			$env->getPageConfig()->getTitle()
		);

		// Add base href pointing to the wiki root
		$this->appendToHead( $document, 'base', [
			'href' => $env->getSiteConfig()->baseURI()
		] );

		// Stick data attributes in the head
		if ( $env->pageBundle ) {
			DOMDataUtils::injectPageBundle( $document, DOMDataUtils::getPageBundle( $document ) );
		}

		// PageConfig guarantees language will always be non-null.
		$lang = $env->getPageConfig()->getPageLanguage();
		$body = DOMCompat::getBody( $document );
		$body->setAttribute( 'lang', Utils::bcp47n( $lang ) );
		$this->updateBodyClasslist( $body, $env );
		$this->exportJSConfigVars( $document, $env );
		$this->exportGeneralModules( $document, $env );
		$this->exportStyleModules( $document, $env, $lang );

		// Indicate whether LanguageConverter is enabled, so that downstream
		// caches can split on variant (if necessary)
		$this->appendToHead( $document, 'meta', [
				'http-equiv' => 'content-language',
				'content' => $env->htmlContentLanguage()
			]
		);
		$this->appendToHead( $document, 'meta', [
				'http-equiv' => 'vary',
				'content' => $env->htmlVary()
			]
		);

		if ( $env->profiling() ) {
			$profile = $env->getCurrentProfile();
			$body->appendChild( $body->ownerDocument->createTextNode( "\n" ) );
			$body->appendChild( $body->ownerDocument->createComment( $this->timeProfile ) );
			$body->appendChild( $body->ownerDocument->createTextNode( "\n" ) );
		}
	}

	/**
	 * @param Node $node
	 */
	public function doPostProcess( Node $node ): void {
		$env = $this->env;

		$hasDumpFlags = $env->hasDumpFlags();

		if ( $hasDumpFlags && $env->hasDumpFlag( 'dom:post-builder' ) ) {
			$opts = [];
			ContentUtils::dumpDOM( $node, 'DOM: after tree builder', $opts );
		}

		$startTime = null;
		$endTime = null;
		$prefix = null;
		$traceLevel = null;
		$resourceCategory = null;

		$profile = null;
		if ( $env->profiling() ) {
			$profile = $env->getCurrentProfile();
			if ( $this->atTopLevel ) {
				$this->timeProfile = str_repeat( "-", 85 ) . "\n";
				$prefix = 'TOP';
				// Turn off DOM pass timing tracing on non-top-level documents
				$resourceCategory = 'DOMPasses:TOP';
			} else {
				$prefix = '---';
				$resourceCategory = 'DOMPasses:NESTED';
			}
			$startTime = PHPUtils::getStartHRTime();
			$env->log( 'debug/time/dompp', $prefix . '; start=' . $startTime );
		}

		for ( $i = 0;  $i < count( $this->processors );  $i++ ) {
			$pp = $this->processors[$i];
			if ( !empty( $pp['skipNested'] ) && !$this->atTopLevel ) {
				continue;
			}

			$ppName = null;
			$ppStart = null;

			// Trace
			if ( $profile ) {
				$ppName = $pp['name'] . str_repeat(
					" ",
					( strlen( $pp['name'] ) < 30 ) ? 30 - strlen( $pp['name'] ) : 0
				);
				$ppStart = PHPUtils::getStartHRTime();
				$env->log( 'debug/time/dompp', $prefix . '; ' . $ppName . ' start' );
			}

			$opts = null;
			if ( $hasDumpFlags ) {
				$opts = [
					'env' => $env,
					'dumpFragmentMap' => $this->atTopLevel,
					'keepTmp' => true
				];

				if ( $env->hasDumpFlag( 'dom:pre-' . $pp['shortcut'] ) ) {
					ContentUtils::dumpDOM( $node, 'DOM: pre-' . $pp['shortcut'], $opts );
				}
			}

			// Excessive to do it here always, but protects against future changes
			// to how $this->frame may be updated.
			$pp['proc']( $node, [ 'frame' => $this->frame ] + $this->options, $this->atTopLevel );

			if ( $hasDumpFlags && $env->hasDumpFlag( 'dom:post-' . $pp['shortcut'] ) ) {
				ContentUtils::dumpDOM( $node, 'DOM: post-' . $pp['shortcut'], $opts );
			}

			if ( $profile ) {
				$ppElapsed = PHPUtils::getHRTimeDifferential( $ppStart );
				$env->log(
					'debug/time/dompp',
					$prefix . '; ' . $ppName . ' end; time = ' . $ppElapsed
				);
				if ( $this->atTopLevel ) {
					$this->timeProfile .= str_pad( $prefix . '; ' . $ppName, 65 ) .
						' time = ' .
						str_pad( number_format( $ppElapsed, 2 ), 10, ' ', STR_PAD_LEFT ) . "\n";
				}
				$profile->bumpTimeUse( $resourceCategory, $ppElapsed, 'DOM' );
			}
		}

		if ( $profile ) {
			$endTime = PHPUtils::getStartHRTime();
			$env->log(
				'debug/time/dompp',
				$prefix . '; end=' . number_format( $endTime, 2 ) . '; time = ' .
				number_format( PHPUtils::getHRTimeDifferential( $startTime ), 2 )
			);
		}

		// For sub-pipeline documents, we are done.
		// For the top-level document, we generate <head> and add it.
		if ( $this->atTopLevel ) {
			self::addMetaData( $env, $node->ownerDocument );
			if ( $env->hasDumpFlag( 'wt2html:limits' ) ) {
				/*
				 * PORT-FIXME: Not yet implemented
				$env->printWt2HtmlResourceUsage( [
					'HTML Size' => strlen( DOMCompat::getOuterHTML( $document->documentElement ) )
				] );
				*/
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function process( $node, array $opts = null ) {
		'@phan-var Node $node'; // @var Node $node
		$this->doPostProcess( $node );
		return $node;
	}

	/**
	 * @inheritDoc
	 */
	public function processChunkily( $input, ?array $options ): Generator {
		if ( $this->prevStage ) {
			// The previous stage will yield a DOM.
			// FIXME: Should we change the signature of that to return a DOM
			// If we do so, a pipeline stage returns either a generator or
			// concrete output (in this case, a DOM).
			$node = $this->prevStage->processChunkily( $input, $options )->current();
		} else {
			$node = $input;
		}
		$this->process( $node );
		yield $node;
	}
}
