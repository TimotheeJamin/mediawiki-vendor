<?php
/**
 * Copyright (C) 2011-2020 Wikimedia Foundation and others.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

namespace MWParsoid\Config;

use ContentHandler;
use File;
use LinkBatch;
use Linker;
use MediaTransformError;
use MediaWiki\BadFileLookup;
use MediaWiki\Content\Transform\ContentTransformer;
use MediaWiki\HookContainer\HookContainer;
use Parser;
use ParserFactory;
use RepoGroup;
use Title;
use Wikimedia\Parsoid\Config\DataAccess as IDataAccess;
use Wikimedia\Parsoid\Config\PageConfig as IPageConfig;
use Wikimedia\Parsoid\Config\PageContent as IPageContent;

class DataAccess implements IDataAccess {

	/** @var RepoGroup */
	private $repoGroup;

	/** @var BadFileLookup */
	private $badFileLookup;

	/** @var HookContainer */
	private $hookContainer;

	/** @var ContentTransformer */
	private $contentTransformer;

	/** @var Parser */
	private $parser;

	/** @var ?PageConfig */
	private $previousPageConfig;

	/**
	 * @param RepoGroup $repoGroup
	 * @param BadFileLookup $badFileLookup
	 * @param HookContainer $hookContainer
	 * @param ContentTransformer $contentTransformer
	 * @param ParserFactory $parserFactory A legacy parser factory,
	 *   for PST/preprocessing/extension handling
	 */
	public function __construct(
		RepoGroup $repoGroup,
		BadFileLookup $badFileLookup,
		HookContainer $hookContainer,
		ContentTransformer $contentTransformer,
		ParserFactory $parserFactory
	) {
		$this->repoGroup = $repoGroup;
		$this->badFileLookup = $badFileLookup;
		$this->hookContainer = $hookContainer;
		$this->contentTransformer = $contentTransformer;

		// Use the same legacy parser object for all calls to extension tag
		// processing, for greater compatibility.
		$this->parser = $parserFactory->create();
		$this->previousPageConfig = null; // ensure we initialize parser options
	}

	/**
	 * @param File $file
	 * @param array $hp
	 * @return array
	 */
	private function makeTransformOptions( $file, array $hp ): array {
		// Validate the input parameters like Parser::makeImage()
		$handler = $file->getHandler();
		if ( !$handler ) {
			return []; // will get iconThumb()
		}
		foreach ( $hp as $name => $value ) {
			if ( !$handler->validateParam( $name, $value ) ) {
				unset( $hp[$name] );
			}
		}

		// This part is similar to Linker::makeImageLink(). If there is no width,
		// set one based on the source file size.
		$page = $hp['page'] ?? 0;
		if ( !isset( $hp['width'] ) ) {
			if ( isset( $hp['height'] ) && $file->isVectorized() ) {
				// If it's a vector image, and user only specifies height
				// we don't want it to be limited by its "normal" width.
				global $wgSVGMaxSize;
				$hp['width'] = $wgSVGMaxSize;
			} else {
				$hp['width'] = $file->getWidth( $page );
			}

			// We don't need to fill in a default thumbnail width here, since
			// that is done by Parsoid. Parsoid always sets the width parameter
			// for thumbnails.
		}

		return $hp;
	}

	/** @inheritDoc */
	public function getPageInfo( IPageConfig $pageConfig, array $titles ): array {
		$titleObjs = [];
		$pagemap = [];
		$classes = [];
		$ret = [];
		foreach ( $titles as $name ) {
			$t = Title::newFromText( $name );
			// Filter out invalid titles. Title::newFromText in core (not our bespoke
			// version in src/Utils/Title.php) can return null for invalid titles.
			if ( !$t ) {
				// FIXME: This is a bandaid to patch up the fact that Env::makeTitle treats
				// this as a valid title, but Title::newFromText treats it as invalid.
				// T237535
				// This matches what ApiQuery::outputGeneralPageInfo() would
				// return for an invalid title.
				$ret[$name] = [
					'pageId' => -1,
					'revId' => -1,
					'invalid' => true,
					'invalidreason' => 'The requested page title is invalid',
				];
			} else {
				$titleObjs[$name] = $t;
				$pdbk = $t->getPrefixedDBkey();
				$pagemap[$t->getArticleID()] = $pdbk;
				$classes[$pdbk] = $t->isRedirect() ? 'mw-redirect' : '';
			}
		}
		$linkBatch = new LinkBatch( $titleObjs );
		$linkBatch->execute();

		$context_title = Title::newFromText( $pageConfig->getTitle() );
		$this->hookContainer->run(
			'GetLinkColours',
			[ $pagemap, &$classes, $context_title ]
		);

		foreach ( $titleObjs as $name => $obj ) {
			/** @var Title $obj */
			$pdbk = $obj->getPrefixedDBkey();
			$c = preg_split(
				'/\s+/', $classes[$pdbk] ?? '', -1, PREG_SPLIT_NO_EMPTY
			);
			$ret[$name] = [
				'pageId' => $obj->getArticleID(),
				'revId' => $obj->getLatestRevID(),
				'missing' => !$obj->exists(),
				'known' => $obj->isKnown(),
				'redirect' => $obj->isRedirect(),
				'linkclasses' => $c, # See ApiQueryInfo::getLinkClasses() in core
			];
		}
		return $ret;
	}

	/** @inheritDoc */
	public function getFileInfo( IPageConfig $pageConfig, array $files ): array {
		$page = Title::newFromText( $pageConfig->getTitle() );
		$fileObjs = $this->repoGroup->findFiles( array_keys( $files ) );
		$ret = [];
		foreach ( $files as $filename => $dims ) {
			/** @var File $file */
			$file = $fileObjs[$filename] ?? null;
			if ( !$file ) {
				$ret[$filename] = null;
				continue;
			}
			// See Linker::makeImageLink; 'page' is a key in $handlerParams
			// core uses 'false' as the default then casts to (int) => 0
			$pageNum = $dims['page'] ?? 0;

			$result = [
				'width' => $file->getWidth( $pageNum ),
				'height' => $file->getHeight( $pageNum ),
				'size' => $file->getSize(),
				'mediatype' => $file->getMediaType(),
				'mime' => $file->getMimeType(),
				'url' => $file->getFullUrl(),
				'mustRender' => $file->mustRender(),
				'badFile' => $this->badFileLookup->isBadFile( $filename, $page ?: false ),
			];

			$length = $file->getLength();
			if ( $length ) {
				$result['duration'] = (float)$length;
			}

			if ( isset( $dims['seek'] ) ) {
				$dims['thumbtime'] = $dims['seek'];
			}

			$txopts = $this->makeTransformOptions( $file, $dims );
			$mto = $file->transform( $txopts );
			if ( $mto ) {
				if ( $mto->isError() && $mto instanceof MediaTransformError ) {
					$result['thumberror'] = $mto->toText();
				} else {
					if ( $txopts ) {
						// Do srcset scaling
						Linker::processResponsiveImages( $file, $mto, $txopts );
						if ( count( $mto->responsiveUrls ) ) {
							$result['responsiveUrls'] = [];
							foreach ( $mto->responsiveUrls as $density => $url ) {
								$result['responsiveUrls'][$density] = $url;
							}
						}
					}

					// Proposed MediaTransformOutput serialization method for T51896 etc.
					// Note that getAPIData(['fullurl']) would return
					// wfExpandUrl(), which wouldn't respect the wiki's
					// protocol preferences -- instead it would use the
					// protocol used for the API request.
					if ( is_callable( [ $mto, 'getAPIData' ] ) ) {
						$result['thumbdata'] = $mto->getAPIData( [ 'withhash' ] );
					}

					$result['thumburl'] = $mto->getUrl();
					$result['thumbwidth'] = $mto->getWidth();
					$result['thumbheight'] = $mto->getHeight();
				}
			} else {
				$result['thumberror'] = "Presumably, invalid parameters, despite validation.";
			}

			$ret[$filename] = $result;
		}

		return $ret;
	}

	/**
	 * Prepare MediaWiki's parser for preprocessing or extension tag parsing,
	 * clearing its state if necessary.
	 *
	 * @param IPageConfig $pageConfig
	 * @param int $outputType
	 * @return Parser
	 */
	private function prepareParser( IPageConfig $pageConfig, int $outputType ) {
		'@phan-var PageConfig $pageConfig'; // @var PageConfig $pageConfig
		// Clear the state only when the PageConfig changes, so that Parser's internal caches can
		// be retained. This should also provide better compatibility with extension tags.
		$clearState = $this->previousPageConfig !== $pageConfig;
		$this->previousPageConfig = $pageConfig;
		$this->parser->startExternalParse(
			Title::newFromText( $pageConfig->getTitle() ), $pageConfig->getParserOptions(),
			$outputType, $clearState, $pageConfig->getRevisionId() );
		$this->parser->resetOutput();
		return $this->parser;
	}

	/** @inheritDoc */
	public function doPst( IPageConfig $pageConfig, string $wikitext ): string {
		'@phan-var PageConfig $pageConfig'; // @var PageConfig $pageConfig
		// This could use prepareParser(), but it's only called once per page,
		// so it's not essential.
		$titleObj = Title::newFromText( $pageConfig->getTitle() );
		$user = $pageConfig->getParserOptions()->getUserIdentity();
		$content = ContentHandler::makeContent( $wikitext, $titleObj, CONTENT_MODEL_WIKITEXT );
		return $this->contentTransformer->preSaveTransform(
			$content,
			$titleObj,
			$user,
			$pageConfig->getParserOptions()
		)->serialize();
	}

	/** @inheritDoc */
	public function parseWikitext( IPageConfig $pageConfig, string $wikitext ): array {
		$parser = $this->prepareParser( $pageConfig, Parser::OT_HTML );
		$html = $parser->parseExtensionTagAsTopLevelDoc( $wikitext );
		$out = $parser->getOutput();
		$out->setText( $html );
		return [
			'html' => $out->getText( [ 'unwrap' => true ] ),
			'modules' => array_values( array_unique( $out->getModules() ) ),
			'modulestyles' => array_values( array_unique( $out->getModuleStyles() ) ),
			'jsconfigvars' => $out->getJsConfigVars(),
			'categories' => $out->getCategories(),
		];
	}

	/** @inheritDoc */
	public function preprocessWikitext( IPageConfig $pageConfig, string $wikitext ): array {
		$parser = $this->prepareParser( $pageConfig, Parser::OT_PREPROCESS );
		$out = $parser->getOutput();
		$wikitext = $parser->replaceVariables( $wikitext );
		$wikitext = $parser->getStripState()->unstripBoth( $wikitext );
		return [
			'wikitext' => $wikitext,
			'modules' => array_values( array_unique( $out->getModules() ) ),
			'modulestyles' => array_values( array_unique( $out->getModuleStyles() ) ),
			'jsconfigvars' => $out->getJsConfigVars(),
			'categories' => $out->getCategories(),
			'properties' => $out->getProperties()
		];
	}

	/** @inheritDoc */
	public function fetchTemplateSource(
		IPageConfig $pageConfig, string $title
	): ?IPageContent {
		'@phan-var PageConfig $pageConfig'; // @var PageConfig $pageConfig
		$titleObj = Title::newFromText( $title );

		// Use the PageConfig to take advantage of custom template
		// fetch hooks like FlaggedRevisions, etc.
		$revRecord = $pageConfig->fetchRevisionRecordOfTemplate( $titleObj );

		return $revRecord ? new PageContent( $revRecord ) : null;
	}

	/** @inheritDoc */
	public function fetchTemplateData( IPageConfig $pageConfig, string $title ): ?array {
		$ret = [];
		// @todo: Document this hook in MediaWiki / Extension:TemplateData
		$this->hookContainer->run(
			'ParserFetchTemplateData', [ [ $title ], &$ret ]
		);

		// Cast value to array since the hook returns this as a stdclass
		$tplData = $ret[$title] ?? null;
		if ( $tplData ) {
			// Deep convert to associative array
			$tplData = json_decode( json_encode( $tplData ), true );
		}
		return $tplData;
	}

	/** @inheritDoc */
	public function logLinterData( IPageConfig $pageConfig, array $lints ): void {
		global $wgReadOnly;
		if ( $wgReadOnly ) {
			return;
		}

		$revId = $pageConfig->getRevisionId();
		$title = $pageConfig->getTitle();
		$pageInfo = $this->getPageInfo( $pageConfig, [ $title ] );
		$latest = $pageInfo[$title]['revId'];

		// Only send the request if it the latest revision
		if ( $revId !== null && $revId === $latest ) {
			// @todo: Document this hook in MediaWiki / Extension:Linter
			$this->hookContainer->run(
				'ParserLogLinterData', [ $title, $revId, $lints ]
			);
		}
	}

}
