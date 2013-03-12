<?php
namespace Moss\component\reader;

/**
 * Reader interface used for reading configurations
 *
 * @package Moss Core
 * @author  Michal Wachowski <wachowski.michal@gmail.com>
 */
interface ReaderInterface {

	/**
	 * Reads data from file and decodes it
	 *
	 * @param string $file
	 *
	 * @return array
	 */
	function read($file);
}
