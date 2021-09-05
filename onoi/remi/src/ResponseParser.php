<?php

namespace Onoi\Remi;

/**
 * @license GNU GPL v2+
 * @since 0.1
 *
 * @author mwjames
 */
interface ResponseParser {

	/**
	 * @since 0.1
	 *
	 * @return FilteredRecord
	 */
	public function getFilteredRecord();

	/**
	 * @since 0.1
	 *
	 * @return array
	 */
	public function getMessages();

	/**
	 * @since 0.1
	 *
	 * @return boolean
	 */
	//public function isFromCache();
	public function usesCache();

	/**
	 * @since 0.1
	 *
	 * @param string $id
	 */
	//public function doFilterResponseById( $id );
	public function doFilterResponseFor( $id );

	/**
	 * @since 0.1
	 *
	 * @param string $id
	 *
	 * @return string
	 */
	//public function getRawResponseById( $id );
	public function getRawResponse( $id );

}
