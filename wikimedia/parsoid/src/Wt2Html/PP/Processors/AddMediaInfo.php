<?php
declare( strict_types = 1 );

namespace Wikimedia\Parsoid\Wt2Html\PP\Processors;

use stdClass;
use Wikimedia\Assert\Assert;
use Wikimedia\Parsoid\Config\Env;
use Wikimedia\Parsoid\Config\WikitextConstants as Consts;
use Wikimedia\Parsoid\Core\Sanitizer;
use Wikimedia\Parsoid\DOM\DocumentFragment;
use Wikimedia\Parsoid\DOM\Element;
use Wikimedia\Parsoid\DOM\Node;
use Wikimedia\Parsoid\Html2Wt\WTSUtils;
use Wikimedia\Parsoid\Utils\DOMCompat;
use Wikimedia\Parsoid\Utils\DOMDataUtils;
use Wikimedia\Parsoid\Utils\DOMUtils;
use Wikimedia\Parsoid\Utils\PHPUtils;
use Wikimedia\Parsoid\Utils\WTUtils;
use Wikimedia\Parsoid\Wt2Html\PegTokenizer;
use Wikimedia\Parsoid\Wt2Html\Wt2HtmlDOMProcessor;

class AddMediaInfo implements Wt2HtmlDOMProcessor {
	/**
	 * Extract the dimensions for media.
	 *
	 * @param Env $env
	 * @param array $attrs
	 * @param array $info
	 * @phan-param array{size:array{height?:int,width?:int},format:string} $attrs
	 * @return array
	 */
	private static function handleSize( Env $env, array $attrs, array $info ): array {
		$height = $info['height'];
		$width = $info['width'];

		Assert::invariant(
			is_numeric( $height ) && $height !== NAN,
			'Expected $height as a valid number'
		);
		Assert::invariant(
			is_numeric( $width ) && $width !== NAN,
			'Expected $width as a valid number'
		);

		if ( !empty( $info['thumburl'] ) && !empty( $info['thumbheight'] ) ) {
			$height = $info['thumbheight'];
		}

		// @phan-suppress-next-line PhanRedundantCondition
		if ( !empty( $info['thumburl'] ) && !empty( $info['thumbwidth'] ) ) {
			$width = $info['thumbwidth'];
		}

		// Audio files don't have dimensions, so we fallback to these arbitrary
		// defaults, and the "mw-default-audio-height" class is added.
		if ( $info['mediatype'] === 'AUDIO' ) {
			$height = /* height || */32; // Arguably, audio should respect a defined height
			$width = $width ?: $env->getSiteConfig()->widthOption();
		}

		// Handle client-side upscaling (including 'border')

		$mustRender = $info['mustRender'] ?? $info['mediatype'] !== 'BITMAP';

		// Calculate the scaling ratio from the user-specified width and height
		$ratio = null;
		if ( !empty( $attrs['size']['height'] ) && !empty( $info['height'] ) ) {
			$ratio = $attrs['size']['height'] / $info['height'];
		}
		if ( !empty( $attrs['size']['width'] ) && !empty( $info['width'] ) ) {
			$r = $attrs['size']['width'] / $info['width'];
			$ratio = ( $ratio === null || $r < $ratio ) ? $r : $ratio;
		}

		// If the user requested upscaling, then this is denied in the thumbnail
		// and frameless format, except for files with mustRender.
		if (
			$ratio !== null && $ratio > 1 && !$mustRender &&
			( $attrs['format'] === 'Thumb' || $attrs['format'] === 'Frameless' )
		) {
			// Upscaling denied
			$height = $info['height'];
			$width = $info['width'];
		}

		return [ 'height' => $height, 'width' => $width ];
	}

	/**
	 * This is a port of TMH's parseTimeString()
	 *
	 * @param string $timeString
	 * @param int|float|null $length
	 * @return int|float|null
	 */
	private static function parseTimeString(
		string $timeString, $length = null
	) {
		$parts = explode( ':', $timeString );
		$time = 0;
		$countParts = count( $parts );
		if ( $countParts > 3 ) {
			return null;
		}
		for ( $i = 0;  $i < $countParts;  $i++ ) {
			if ( !is_numeric( $parts[$i] ) ) {
				return null;
			}
			$time += floatval( $parts[$i] ) * pow( 60, $countParts - 1 - $i );
		}
		if ( $time < 0 ) {
			$time = 0;
		} elseif ( $length !== null ) {
			if ( $time > $length ) {
				$time = $length - 1;
			}
		}
		return $time;
	}

	/**
	 * Handle media fragments
	 * https://www.w3.org/TR/media-frags/
	 *
	 * @param array $info
	 * @param stdClass $dataMw
	 * @return string
	 */
	private static function parseFrag( array $info, stdClass $dataMw ): string {
		$frag = '';
		$starttime = WTSUtils::getAttrFromDataMw( $dataMw, 'starttime', true );
		$endtime = WTSUtils::getAttrFromDataMw( $dataMw, 'endtime', true );
		if ( $starttime || $endtime ) {
			$frag .= '#t=';
			if ( $starttime ) {
				$time = self::parseTimeString( $starttime[1]->txt, $info['duration'] ?? null );
				if ( $time !== null ) {
					$frag .= $time;
				}
			}
			if ( $endtime ) {
				$time = self::parseTimeString( $endtime[1]->txt, $info['duration'] ?? null );
				if ( $time !== null ) {
					$frag .= ',' . $time;
				}
			}
		}
		return $frag;
	}

	/**
	 * @param Element $elt
	 * @param array $info
	 * @param stdClass $dataMw
	 * @param bool $hasDimension
	 */
	private static function addSources(
		Element $elt, array $info, stdClass $dataMw, bool $hasDimension
	): void {
		$doc = $elt->ownerDocument;
		$frag = self::parseFrag( $info, $dataMw );

		$dataFromTMH = true;
		if ( is_array( $info['thumbdata']['derivatives'] ?? null ) ) {
			// BatchAPI's `getAPIData`
			$derivatives = $info['thumbdata']['derivatives'];
		} elseif ( is_array( $info['derivatives'] ?? null ) ) {
			// "videoinfo" prop
			$derivatives = $info['derivatives'];
		} else {
			$derivatives = [
				[
					'src' => $info['url'],
					'type' => $info['mime'],
					'width' => (string)$info['width'],
					'height' => (string)$info['height'],
				],
			];
			$dataFromTMH = false;
		}

		foreach ( $derivatives as $o ) {
			$source = $doc->createElement( 'source' );
			$source->setAttribute( 'src', $o['src'] . $frag );
			$source->setAttribute( 'type', $o['type'] );
			$fromFile = isset( $o['transcodekey'] ) ? '' : '-file';
			if ( $hasDimension ) {
				$source->setAttribute( 'data' . $fromFile . '-width', (string)$o['width'] );
				$source->setAttribute( 'data' . $fromFile . '-height', (string)$o['height'] );
			}
			if ( $dataFromTMH ) {
				$source->setAttribute( 'data-title', $o['title'] );
				$source->setAttribute( 'data-shorttitle', $o['shorttitle'] );
			}
			$elt->appendChild( $source );
		}
	}

	/**
	 * @param Element $elt
	 * @param array $info
	 */
	private static function addTracks( Element $elt, array $info ): void {
		$doc = $elt->ownerDocument;
		if ( is_array( $info['thumbdata']['timedtext'] ?? null ) ) {
			// BatchAPI's `getAPIData`
			$timedtext = $info['thumbdata']['timedtext'];
		} elseif ( is_array( $info['timedtext'] ?? null ) ) {
			// "videoinfo" prop
			$timedtext = $info['timedtext'];
		} else {
			$timedtext = [];
		}
		foreach ( $timedtext as $o ) {
			$track = $doc->createElement( 'track' );
			$track->setAttribute( 'kind', $o['kind'] ?? '' );
			$track->setAttribute( 'type', $o['type'] ?? '' );
			$track->setAttribute( 'src', $o['src'] ?? '' );
			$track->setAttribute( 'srclang', $o['srclang'] ?? '' );
			$track->setAttribute( 'label', $o['label'] ?? '' );
			$track->setAttribute( 'data-mwtitle', $o['title'] ?? '' );
			$track->setAttribute( 'data-dir', $o['dir'] ?? '' );
			$elt->appendChild( $track );
		}
	}

	/**
	 * Abstract way to get the path for an image given an info object.
	 *
	 * @param array $info
	 * @return string
	 */
	private static function getPath( array $info ) {
		$path = '';
		if ( !empty( $info['thumburl'] ) ) {
			$path = $info['thumburl'];
		} elseif ( !empty( $info['url'] ) ) {
			$path = $info['url'];
		}
		return $path;
	}

	/**
	 * @param Env $env
	 * @param Element $span
	 * @param array $attrs
	 * @param array $info
	 * @param ?array $manualinfo
	 * @param stdClass $dataMw
	 * @param Element $container
	 * @return array
	 */
	private static function handleAudio(
		Env $env, Element $span, array $attrs, array $info, ?array $manualinfo,
		stdClass $dataMw, Element $container
	): array {
		$doc = $span->ownerDocument;
		$audio = $doc->createElement( 'audio' );

		$audio->setAttribute( 'controls', '' );
		$audio->setAttribute( 'preload', 'none' );

		$size = self::handleSize( $env, $attrs, $info );
		DOMDataUtils::addNormalizedAttribute( $audio, 'height', (string)$size['height'], null, true );
		DOMDataUtils::addNormalizedAttribute( $audio, 'width', (string)$size['width'], null, true );

		// Hardcoded until defined heights are respected.
		// See `AddMediaInfo.handleSize`
		DOMCompat::getClassList( $container )->add( 'mw-default-audio-height' );

		self::copyOverAttribute( $audio, $span, 'resource' );

		if ( $span->hasAttribute( 'lang' ) ) {
			self::copyOverAttribute( $audio, $span, 'lang' );
		}

		self::addSources( $audio, $info, $dataMw, false );
		self::addTracks( $audio, $info );

		return [ 'rdfaType' => 'mw:Audio', 'elt' => $audio ];
	}

	/**
	 * @param Env $env
	 * @param Element $span
	 * @param array $attrs
	 * @param array $info
	 * @param ?array $manualinfo
	 * @param stdClass $dataMw
	 * @return array
	 */
	private static function handleVideo(
		Env $env, Element $span, array $attrs, array $info, ?array $manualinfo,
		stdClass $dataMw
	): array {
		$doc = $span->ownerDocument;
		$video = $doc->createElement( 'video' );

		if ( $manualinfo || !empty( $info['thumburl'] ) ) {
			$video->setAttribute( 'poster', self::getPath( $manualinfo ?: $info ) );
		}

		$video->setAttribute( 'controls', '' );
		$video->setAttribute( 'preload', 'none' );

		$size = self::handleSize( $env, $attrs, $info );
		DOMDataUtils::addNormalizedAttribute( $video, 'height', (string)$size['height'], null, true );
		DOMDataUtils::addNormalizedAttribute( $video, 'width', (string)$size['width'], null, true );

		self::copyOverAttribute( $video, $span, 'resource' );

		if ( $span->hasAttribute( 'lang' ) ) {
			self::copyOverAttribute( $video, $span, 'lang' );
		}

		self::addSources( $video, $info, $dataMw, true );
		self::addTracks( $video, $info );

		return [ 'rdfaType' => 'mw:Video', 'elt' => $video ];
	}

	/**
	 * Set up the actual image structure, attributes, etc.
	 *
	 * @param Env $env
	 * @param Element $span
	 * @param array $attrs
	 * @param array $info
	 * @param ?array $manualinfo
	 * @param stdClass $dataMw
	 * @return array
	 */
	private static function handleImage(
		Env $env, Element $span, array $attrs, array $info, ?array $manualinfo,
		stdClass $dataMw
	): array {
		$doc = $span->ownerDocument;
		$img = $doc->createElement( 'img' );

		self::addAttributeFromDataMw( $img, $dataMw, 'alt' );

		if ( $manualinfo ) {
			$info = $manualinfo;
		}

		self::copyOverAttribute( $img, $span, 'resource' );

		$img->setAttribute( 'src', self::getPath( $info ) );

		if ( $span->hasAttribute( 'lang' ) ) {
			self::copyOverAttribute( $img, $span, 'lang' );
		}

		// Add (read-only) information about original file size (T64881)
		$img->setAttribute( 'data-file-width', (string)$info['width'] );
		$img->setAttribute( 'data-file-height', (string)$info['height'] );
		$img->setAttribute( 'data-file-type', strtolower( $info['mediatype'] ?? '' ) );

		$size = self::handleSize( $env, $attrs, $info );
		DOMDataUtils::addNormalizedAttribute( $img, 'height', (string)$size['height'], null, true );
		DOMDataUtils::addNormalizedAttribute( $img, 'width', (string)$size['width'], null, true );

		// Handle "responsive" images, i.e. srcset
		if ( !empty( $info['responsiveUrls'] ) ) {
			$candidates = [];
			foreach ( $info['responsiveUrls'] as $density => $url ) {
				$candidates[] = $url . ' ' . $density . 'x';
			}
			if ( $candidates ) {
				$img->setAttribute( 'srcset', implode( ', ', $candidates ) );
			}
		}

		return [ 'rdfaType' => 'mw:Image', 'elt' => $img ];
	}

	/**
	 * Use sane defaults
	 *
	 * @param Env $env
	 * @param string $key
	 * @param array $dims
	 * @return array
	 */
	private static function errorInfo( Env $env, string $key, array $dims ): array {
		$widthOption = $env->getSiteConfig()->widthOption();
		return [
			'url' => './Special:FilePath/' . Sanitizer::sanitizeTitleURI( $key, false ),
			// Preserve width and height from the wikitext options
			// even if the image is non-existent.
			'width' => $dims['width'] ?? $widthOption,
			'height' => $dims['height'] ?? $dims['width'] ?? $widthOption,
		];
	}

	/**
	 * @param string $key
	 * @param string $message
	 * @param ?array $params
	 * @return array
	 */
	private static function makeErr(
		string $key, string $message, ?array $params = null
	): array {
		$e = [ 'key' => $key, 'message' => $message ];
		// Additional error info for clients that could fix the error.
		if ( $params !== null ) {
			$e['params'] = $params;
		}
		return $e;
	}

	/**
	 * @param Env $env
	 * @param string $key
	 * @param array $dims
	 * @return array
	 */
	public static function requestInfo( Env $env, string $key, array $dims ): array {
		$err = null;
		$start = PHPUtils::getStartHRTime();
		$info = $env->getDataAccess()->getFileInfo(
			$env->getPageConfig(),
			[ $key => $dims ]
		)[$key] ?? null;
		if ( $env->profiling() ) {
			$profile = $env->getCurrentProfile();
			$profile->bumpMWTime( "Media", PHPUtils::getHRTimeDifferential( $start ), "api" );
			$profile->bumpCount( "Media" );
		}
		if ( !$info ) {
			$info = self::errorInfo( $env, $key, $dims );
			$err = self::makeErr( 'apierror-filedoesnotexist', 'This image does not exist.' );
		} elseif ( isset( $info['thumberror'] ) ) {
			$err = self::makeErr( 'apierror-unknownerror', $info['thumberror'] );
		}
		return [ 'err' => $err, 'info' => $info ];
	}

	/**
	 * @param Element $container
	 * @param array $errs
	 * @param stdClass $dataMw
	 */
	private static function addErrors( Element $container, array $errs, stdClass $dataMw ): void {
		if ( !DOMUtils::hasTypeOf( $container, 'mw:Error' ) ) {
			$typeOf = $container->getAttribute( 'typeof' ) ?? '';
			$typeOf = 'mw:Error' . ( $typeOf ? ' ' . $typeOf : '' );
			$container->setAttribute( 'typeof', $typeOf );
		}
		if ( is_array( $dataMw->errors ?? null ) ) {
			$errs = array_merge( $dataMw->errors, $errs );
		}
		$dataMw->errors = $errs;
	}

	/**
	 * @param Element $elt
	 * @param Element $span
	 * @param string $attribute
	 */
	private static function copyOverAttribute(
		Element $elt, Element $span, string $attribute
	): void {
		DOMDataUtils::addNormalizedAttribute(
			$elt,
			$attribute,
			$span->getAttribute( $attribute ) ?? '',
			WTSUtils::getAttributeShadowInfo( $span, $attribute )['value']
		);
	}

	/**
	 * If this is a manual thumbnail, fetch the info for that as well
	 *
	 * @param Env $env
	 * @param array $attrs
	 * @param array $dims
	 * @param stdClass $dataMw
	 * @return array
	 */
	private static function manualInfo(
		Env $env, array $attrs, array $dims, stdClass $dataMw
	): array {
		$attr = WTSUtils::getAttrFromDataMw( $dataMw, 'manualthumb', true );
		if ( $attr === null ) {
			return [ 'err' => null, 'info' => null ];
		}

		$val = $attr[1]->txt;
		$title = $env->makeTitleFromText( $val, $attrs['title']->getNamespace(), true );
		if ( $title === null ) {
			return [
				'info' => self::errorInfo( $env, $attrs['title']->getKey(), $dims ),
				'err' => self::makeErr(
					'apierror-invalidtitle',
					'Invalid thumbnail title.',
					[ 'name' => $val ]
				),
			];
		}

		return self::requestInfo( $env, $title->getKey(), $dims );
	}

	/**
	 * @param Element $elt
	 * @param stdClass $dataMw
	 * @param string $key
	 */
	private static function addAttributeFromDataMw(
		Element $elt, stdClass $dataMw, string $key
	): void {
		$attr = WTSUtils::getAttrFromDataMw( $dataMw, $key, false );
		if ( $attr === null ) {
			return;
		}

		$elt->setAttribute( $key, $attr[1]->txt );
	}

	/**
	 * @param Env $env
	 * @param PegTokenizer $urlParser
	 * @param Element $oldAnchor
	 * @param array $attrs
	 * @param stdClass $dataMw
	 * @param bool $isImage
	 * @param int $page
	 * @return Element
	 */
	private static function replaceAnchor(
		Env $env, PegTokenizer $urlParser, Element $oldAnchor, array $attrs,
		stdClass $dataMw, bool $isImage, int $page
	): Element {
		$doc = $oldAnchor->ownerDocument;
		$attr = WTSUtils::getAttrFromDataMw( $dataMw, 'link', true );

		$anchor = $doc->createElement( 'a' );
		if ( $isImage ) {
			if ( $attr !== null ) {
				$discard = true;
				$val = $attr[1]->txt;
				if ( $val === '' ) {
					// No href if link= was specified
					$anchor = $doc->createElement( 'span' );
				} elseif ( $urlParser->tokenizesAsURL( $val ) ) {
					// an external link!
					$anchor->setAttribute( 'href', $val );
				} else {
					$link = $env->makeTitleFromText( $val, null, true );
					if ( $link !== null ) {
						$anchor->setAttribute( 'href', $env->makeLink( $link ) );
					} else {
						// Treat same as if link weren't present
						$anchor->setAttribute( 'href', $env->makeLink( $attrs['title'] ) );
						// but preserve for roundtripping
						$discard = false;
					}
				}
				if ( $discard ) {
					WTSUtils::getAttrFromDataMw( $dataMw, 'link', /* keep */false );
				}
			} else {
				$href = $env->makeLink( $attrs['title'] );
				if ( $page > 0 ) {
					$href .= "?page=$page";
				}
				$anchor->setAttribute( 'href', $href );
			}
		} else {
			$anchor = $doc->createElement( 'span' );
		}

		if ( DOMCompat::nodeName( $anchor ) === 'a' ) {
			$href = Sanitizer::cleanUrl(
				$env->getSiteConfig(), $anchor->getAttribute( 'href' ) ?? '', 'external'
			);
			$anchor->setAttribute( 'href', $href );
		}

		$oldAnchor->parentNode->replaceChild( $anchor, $oldAnchor );

		return $anchor;
	}

	/**
	 * @inheritDoc
	 */
	public function run(
		Env $env, Node $root, array $options = [], bool $atTopLevel = false
	): void {
		'@phan-var Element|DocumentFragment $root';  // @var Element|DocumentFragment $root
		$urlParser = new PegTokenizer( $env );

		// Since we haven't fetched info yet, they were all assumed to be mw:Image
		// See WikiLinkHandler::renderFile()
		$containers = DOMCompat::querySelectorAll( $root, '[typeof*="mw:Image"]' );

		foreach ( $containers as $container ) {
			// DOMFragmentWrappers assume the element name of their outermost
			// content so, depending how the above query is written, we're
			// protecting against getting a figure of the wrong type.  However,
			// since we're currently using typeof, it shouldn't be a problem.
			// Also note that info for the media nested in the fragment has
			// already been added in their respective pipeline.
			Assert::invariant(
				!WTUtils::isDOMFragmentWrapper( $container ),
				'Media info for fragment was already added'
			);

			$dataMw = DOMDataUtils::getDataMw( $container );

			// We expect this structure to be predictable based on how it's
			// emitted in the TT/WikiLinkHandler but treebuilding may have
			// messed that up for us.
			$anchor = $container->firstChild;
			$anchorNodeName = DOMCompat::nodeName( $anchor );
			if (
				$anchor instanceof Element && $anchorNodeName !== 'a' &&
				isset( Consts::$HTML['FormattingTags'][$anchorNodeName] )
			) {
				// An active formatting element may have been reopened inside
				// the wrapper if a content model violation was encountered
				// during treebuiling.  Try to be a little lenient about that
				// instead of bailing out
				$anchor = $anchor->firstChild;
				$anchorNodeName = DOMCompat::nodeName( $anchor );
			}
			if ( !( $anchor instanceof Element && $anchorNodeName === 'a' ) ) {
				$env->log( 'error', 'Unexpected structure when adding media info.' );
				continue;
			}
			$span = $anchor->firstChild;
			if ( !( $span instanceof Element && DOMCompat::nodeName( $span ) === 'span' ) ) {
				$env->log( 'error', 'Unexpected structure when adding media info.' );
				continue;
			}

			$attrs = [
				'size' => [
					'width' => (int)$span->getAttribute( 'data-width' ) ?: null,
					'height' => (int)$span->getAttribute( 'data-height' ) ?: null,
				],
				'format' => WTSUtils::getMediaFormat( $container ),
				'title' => $env->makeTitleFromText( $span->textContent ),
			];

			$dims = $attrs['size'];

			if ( $env->noDataAccess() ) {
				$errs = [ self::makeErr(
					'apierror-unknownerror',
					'Fetch of image info disabled.'
				) ];
				self::addErrors( $container, $errs, $dataMw );
				continue;
			}

			$page = WTSUtils::getAttrFromDataMw( $dataMw, 'page', true );
			if ( $page ) {
				$dims['page'] = $page[1]->txt;
			}

			// "starttime" should be used if "thumbtime" isn't present,
			// but only for rendering.
			// "starttime" should be used if "thumbtime" isn't present,
			// but only for rendering.
			$thumbtime = WTSUtils::getAttrFromDataMw( $dataMw, 'thumbtime', true );
			$starttime = WTSUtils::getAttrFromDataMw( $dataMw, 'starttime', true );
			if ( $thumbtime || $starttime ) {
				$seek = isset( $thumbtime[1] )
					? $thumbtime[1]->txt
					: ( isset( $starttime[1] ) ? $starttime[1]->txt : '' );
				$seek = self::parseTimeString( $seek );
				if ( $seek !== null ) {
					$dims['seek'] = $seek;
				}
			}

			$i = self::requestInfo( $env, $attrs['title']->getKey(), $dims );
			$m = self::manualInfo( $env, $attrs, $dims, $dataMw );

			$errs = [];
			if ( $i['err'] !== null ) {
				$errs[] = $i['err'];
			}
			if ( $m['err'] !== null ) {
				$errs[] = $m['err'];
			}

			// Add mw:Error to the RDFa type.
			if ( $errs ) {
				self::addErrors( $container, $errs, $dataMw );
				continue;
			}

			$info = $i['info'];
			$manualinfo = $m['info'];

			// T110692: The batching API seems to return these as strings.
			// Till that is fixed, let us make sure these are numbers.
			// (This was fixed in Sep 2015, FWIW.)
			$info['height'] = (int)$info['height'];
			$info['width'] = (int)$info['width'];

			$isImage = false;
			switch ( $info['mediatype'] ) {
				case 'AUDIO':
					$o = self::handleAudio( $env, $span, $attrs, $info, $manualinfo, $dataMw, $container );
					break;
				case 'VIDEO':
					$o = self::handleVideo( $env, $span, $attrs, $info, $manualinfo, $dataMw );
					break;
				default:
					$isImage = true;
					$o = self::handleImage( $env, $span, $attrs, $info, $manualinfo, $dataMw );
			}
			$rdfaType = $o['rdfaType'];
			$elt = $o['elt'];

			$anchor = self::replaceAnchor(
				$env, $urlParser, $anchor, $attrs, $dataMw, $isImage,
				(int)( $dims['page'] ?? 0 )
			);
			$anchor->appendChild( $elt );

			$typeOf = $container->getAttribute( 'typeof' ) ?? '';
			$typeOf = preg_replace( '#\bmw:(Image)(/\w*)?\b#', "$rdfaType$2", $typeOf, 1 );
			$container->setAttribute( 'typeof', $typeOf );

			if ( isset( $dataMw->attribs ) && count( $dataMw->attribs ) === 0 ) {
				unset( $dataMw->attribs );
			}
		}
	}
}
