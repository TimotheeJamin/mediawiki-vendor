<?php
declare( strict_types = 1 );

namespace Wikimedia\Parsoid\Wt2Html\PP\Processors;

use Wikimedia\Parsoid\Config\Env;
use Wikimedia\Parsoid\DOM\DocumentFragment;
use Wikimedia\Parsoid\DOM\Element;
use Wikimedia\Parsoid\DOM\Node;
use Wikimedia\Parsoid\Utils\DOMCompat;
use Wikimedia\Parsoid\Utils\PHPUtils;
use Wikimedia\Parsoid\Wt2Html\Wt2HtmlDOMProcessor;

class AddRedLinks implements Wt2HtmlDOMProcessor {
	/**
	 * Add red links to a document.
	 *
	 * @inheritDoc
	 */
	public function run(
		Env $env, Node $root, array $options = [], bool $atTopLevel = false
	): void {
		'@phan-var Element|DocumentFragment $root';  // @var Element|DocumentFragment $root
		$wikiLinks = DOMCompat::querySelectorAll( $root, 'a[rel~="mw:WikiLink"]' );

		$titles = array_reduce(
			PHPUtils::iterable_to_array( $wikiLinks ),
			static function ( array $s, Element $a ): array {
				// Magic links, at least, don't have titles
				if ( $a->hasAttribute( 'title' ) ) {
					$s[] = $a->getAttribute( 'title' );
				}
				return $s;
			},
			[]
		);

		if ( !$titles ) {
			return;
		}

		$start = PHPUtils::getStartHRTime();
		$titleMap = $env->getDataAccess()->getPageInfo( $env->getPageConfig(), $titles );
		if ( $env->profiling() ) {
			$profile = $env->getCurrentProfile();
			$profile->bumpMWTime( "RedLinks", PHPUtils::getHRTimeDifferential( $start ), "api" );
			$profile->bumpCount( "RedLinks" );
		}

		foreach ( $wikiLinks as $a ) {
			if ( !$a->hasAttribute( 'title' ) ) {
				continue;
			}
			$k = $a->getAttribute( 'title' );
			if ( empty( $titleMap[$k] ) ) {
				// Likely a consequence of T237535; can be removed once
				// that is fixed.
				$env->log( 'warn', 'We should have data for the title: ' . $k );
				continue;
			}
			$data = $titleMap[$k];
			$a->removeAttribute( 'class' ); // Clear all
			if ( !empty( $data['missing'] ) && empty( $data['known'] ) ) {
				DOMCompat::getClassList( $a )->add( 'new' );
			}
			if ( !empty( $data['redirect'] ) ) {
				DOMCompat::getClassList( $a )->add( 'mw-redirect' );
			}
			foreach ( $data['linkclasses'] ?? [] as $extraClass ) {
				DOMCompat::getClassList( $a )->add( $extraClass );
			}
		}
	}
}
