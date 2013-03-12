<?php
namespace Moss\component\reader;

use \Moss\component\reader\ReaderInterface,
	\Moss\component\cache\CacheInterface;

/**
 * YAML Reader implementation
 *
 * @package Moss Core
 * @author  Michal Wachowski <wachowski.michal@gmail.com>
 */
class YAMLReader implements ReaderInterface {
	const REGEX_QUOTED_STRING = '(?:"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|\'([^\']*(?:\'\'[^\']*)*)\')';
	const REGEX_TIMESTAMP = '/^(?P<year>[0-9][0-9][0-9][0-9])-(?P<month>[0-9][0-9]?)-(?P<day>[0-9][0-9]?)(?:(?:[Tt]|[ \t]+)(?P<hour>[0-9][0-9]?):(?P<minute>[0-9][0-9]):(?P<second>[0-9][0-9])(?:\.(?P<fraction>[0-9]*))?(?:[ \t]*(?P<tz>Z|(?P<tz_sign>[-+])(?P<tz_hour>[0-9][0-9]?)(?::(?P<tz_minute>[0-9][0-9]))?))?)?$/xi';

	private $trueValues = array('true', 'on', '+', 'yes', 'y');
	private $falseValues = array('false', 'off', '-', 'no', 'n');

	protected $offset = 0;
	protected $lines = array();
	protected $currentLineNb = -1;
	protected $currentLine = '';
	protected $refs = array();

	/** @var CacheInterface */
	protected $Cache;

	/**
	 * Constructor
	 *
	 * @param CacheInterface $Cache
	 * @param int            $offset
	 */
	public function __construct(CacheInterface $Cache = null, $offset = 0) {
		$this->offset = $offset;
		$this->Cache = & $Cache;
	}

	/**
	 * Reads data from file and decodes it
	 *
	 * @param string $file
	 * @param bool   $import
	 *
	 * @return array
	 * @throws \InvalidArgumentException
	 */
	public function read($file, $import = true) {
		if($result = $this->readCache($file)) {
			return $result;
		}

		if(!is_file($file)) {
			throw new \InvalidArgumentException(sprintf('File %s does not exists', $file));
		}

		if(!$content = file_get_contents($file)) {
			throw new \InvalidArgumentException(sprintf('File %s can not be read', $file));
		}

		$result = $this->parse($content);
		$this->writeCache($file, $result, 0);

		return $result;
	}

	/**
	 * Returns cached value if value is still fresh
	 * Otherwise returns null
	 *
	 * @param string $file
	 *
	 * @return mixed|null
	 */
	protected function readCache($file) {
		if(!$this->Cache) {
			return null;
		}

		if($this->Cache->time($file) < filemtime($file)) {
			return null;
		}

		if(!$result = $this->Cache->fetch($file)) {
			return null;
		}

		return $result;
	}

	/**
	 * Adds entry to cache
	 *
	 * @param string $file
	 * @param mixed  $content
	 * @param int    $ttl
	 */
	protected function writeCache($file, $content, $ttl = 0) {
		if(!$this->Cache) {
			return;
		}

		$this->Cache->store($file, $content, $ttl);
	}

	/**
	 * Parses YAML string and returns its content
	 *
	 * @param string $value
	 *
	 * @return mixed
	 * @throws \InvalidArgumentException
	 */
	public function parse($value) {
		$this->currentLineNb = -1;
		$this->currentLine = '';

		$this->lines = explode("\n", $this->cleanup($value));

		$data = array();
		$multipart = array();
		while($this->moveToNextLine()) {
			if($this->isCurrentLineEmpty()) {
				continue;
			}

			// tab?
			if(preg_match('/^\t+/i', $this->currentLine)) {
				throw new \InvalidArgumentException(sprintf('A YAML file cannot contain tabs as indentation at line %d (%s).', $this->getRealCurrentLineNb() + 1, $this->currentLine));
			}

			$isRef = $isInPlace = $isProcessed = false;
			if(preg_match('/^[-]{3}\s*/ui', $this->currentLine)) {
				$multipart[] = $data;
				$data = array();
			}
			elseif(preg_match('/^[.]{3}\s*/ui', $this->currentLine)) {
				// NOP
			}
			elseif(preg_match('/^\-((?P<leadspaces>\s+)(?P<value>.+?))?\s*$/ui', $this->currentLine, $values)) {
				if(isset($values['value']) && preg_match('/^&(?P<ref>[^ ]+) *(?P<value>.*)/ui', $values['value'], $matches)) {
					$isRef = $matches['ref'];
					$values['value'] = $matches['value'];
				}

				// array
				if(!isset($values['value']) || trim($values['value'], ' ') == '' || strpos(ltrim($values['value'], ' '), '#') === 0) {
					$c = $this->getRealCurrentLineNb() + 1;
					$Reader = new self(null, $c);
					$Reader->refs = & $this->refs;
					$data[] = $Reader->parse($this->getNextEmbedBlock());
				}
				else {
					// this is a compact notation element, add to next block and parse
					if(isset($values['leadspaces']) && $values['leadspaces'] == ' ' && preg_match('/^(?P<key>' . self::REGEX_QUOTED_STRING . '|[^ \'"\{].*?) *\:(\s+(?P<value>.+?))?\s*$/ui', $values['value'], $matches)) {
						$c = $this->getRealCurrentLineNb();
						$Reader = new self(null, $c);
						$Reader->refs = & $this->refs;

						$block = $values['value'];
						if(!$this->isNextLineIndented()) {
							$block .= "\n" . $this->getNextEmbedBlock($this->getCurrentLineIndentation() + 2);
						}

						$data[] = $Reader->parse($block);
					}
					else {
						$data[] = $this->parseValue($values['value']);
					}
				}
			}
			elseif(preg_match('/^(?P<key>' . self::REGEX_QUOTED_STRING . '|[^ \'"].*?) *\:(\s+(?P<value>.+?))?\s*$/ui', $this->currentLine, $values)) {
				$key = $this->parseScalar($values['key']);

				if($key === '<<') {
					if(isset($values['value']) && substr($values['value'], 0, 1) === '*') {
						$isInPlace = substr($values['value'], 1);
						if(!array_key_exists($isInPlace, $this->refs)) {
							throw new \InvalidArgumentException(sprintf('Reference "%s" does not exist at line %s (%s).', $isInPlace, $this->getRealCurrentLineNb() + 1, $this->currentLine));
						}
					}
					else {
						$value = isset($values['value']) && $values['value'] !== '' ? $values['value'] : $this->getNextEmbedBlock();

						$c = $this->getRealCurrentLineNb() + 1;
						$Reader = new self(null, $c);
						$Reader->refs = & $this->refs;
						$parsed = $Reader->parse($value);

						$merged = array();
						if(!is_array($parsed)) {
							throw new \InvalidArgumentException(sprintf("YAML merge keys used with a scalar value instead of an array at line %s (%s)", $this->getRealCurrentLineNb() + 1, $this->currentLine));
						}
						elseif(isset($parsed[0])) {
							// Numeric array, merge individual elements
							foreach(array_reverse($parsed) as $parsedItem) {
								if(!is_array($parsedItem)) {
									throw new \InvalidArgumentException(sprintf("Merge items must be arrays at line %s (%s).", $this->getRealCurrentLineNb() + 1, $parsedItem));
								}
								$merged = array_merge($parsedItem, $merged);
							}
						}
						else {
							// Associative array, merge
							$merged = array_merge($merged, $parsed);
						}

						$isProcessed = $merged;
					}
				}
				elseif(isset($values['value']) && preg_match('/^&(?P<ref>[^ ]+) *(?P<value>.*)/ui', $values['value'], $matches)) {
					$isRef = $matches['ref'];
					$values['value'] = $matches['value'];
				}

				if($isProcessed) {
					// Merge keys
					$data = $isProcessed;
				}
				// hash
				elseif(!isset($values['value']) || trim($values['value'], ' ') == '' || strpos(ltrim($values['value'], ' '), '#') === 0) {
					// if next line is less indented or equal, then it means that the current value is null
					if($this->isNextLineIndented()) {
						$data[$key] = null;
					}
					else {
						$c = $this->getRealCurrentLineNb() + 1;
						$Reader = new self(null, $c);
						$Reader->refs = & $this->refs;
						$data[$key] = $Reader->parse($this->getNextEmbedBlock());
					}
				}
				else {
					if($isInPlace) {
						$data = $this->refs[$isInPlace];
					}
					else {
						$data[$key] = $this->parseValue($values['value']);
					}
				}
			}
			else {
				// if more than one line - this is multi line string
				$glue = preg_match('/-+ \|.*\s/im', $value) ? "\n" : ' ';
				$lCount = count($this->lines);
				if($lCount > 1 && empty($this->lines[$lCount - 1])) {
					$value = array_slice($this->lines, 0, $lCount - 1);
					array_walk($value, function (&$v) {
						$v = trim($v);
					});
					$value = implode($glue, $value);
					$value = $this->loadInline($value);

					if(is_array($value)) {
						$first = reset($value);
						if(substr($first, 0, 1) === '*') {
							$data = array();
							foreach($value as $alias) {
								$data[] = $this->refs[substr($alias, 1)];
							}
							$value = $data;
						}
					}

					return $value;
				}

				switch(preg_last_error()) {
					case PREG_INTERNAL_ERROR:
						$error = 'Internal PCRE error on line';
						break;
					case PREG_BACKTRACK_LIMIT_ERROR:
						$error = 'pcre.backtrack_limit reached on line';
						break;
					case PREG_RECURSION_LIMIT_ERROR:
						$error = 'pcre.recursion_limit reached on line';
						break;
					case PREG_BAD_UTF8_ERROR:
						$error = 'Malformed UTF-8 data on line';
						break;
					case PREG_BAD_UTF8_OFFSET_ERROR:
						$error = 'Offset doesn\'t correspond to the begin of a valid UTF-8 code point on line';
						break;
					default:
						$error = 'Unable to parse line';
				}

				throw new \InvalidArgumentException(sprintf('%s %d (%s).', $error, $this->getRealCurrentLineNb() + 1, $this->currentLine));
			}

			if($isRef) {
				$this->refs[$isRef] = end($data);
			}
		}

		if($multipart) {
			$multipart[] = $data;
			$data = $multipart;
		}

		return empty($data) ? null : $data;
	}

	/**
	 * Returns the current line number (takes the offset into account).
	 *
	 * @return integer The current line number
	 */
	protected function getRealCurrentLineNb() {
		return $this->currentLineNb + $this->offset;
	}

	/**
	 * Returns the current line indentation.
	 *
	 * @return integer The current line indentation
	 */
	protected function getCurrentLineIndentation() {
		return strlen($this->currentLine) - strlen(ltrim($this->currentLine, ' '));
	}

	/**
	 * Returns the next embed block of YAML.
	 *
	 * @param integer $indentation The indent level at which the block is to be read, or null for default
	 *
	 * @return string A YAML string
	 * @throws \InvalidArgumentException
	 */
	protected function getNextEmbedBlock($indentation = null) {
		$this->moveToNextLine();

		if($indentation === null) {
			$newIndent = $this->getCurrentLineIndentation();

			if(!$this->isCurrentLineEmpty() && $newIndent == 0) {
				throw new \InvalidArgumentException(sprintf('Indentation problem at line %d (%s)', $this->getRealCurrentLineNb() + 1, $this->currentLine));
			}
		}
		else {
			$newIndent = $indentation;
		}

		$data = array(substr($this->currentLine, $newIndent));

		while($this->moveToNextLine()) {
			if($this->isCurrentLineEmpty()) {
				if($this->isCurrentLineBlank()) {
					$data[] = substr($this->currentLine, $newIndent);
				}

				continue;
			}

			$indent = $this->getCurrentLineIndentation();

			if(preg_match('/^(?P<text> *)$/i', $this->currentLine, $match)) {
				$data[] = $match['text'];
			}
			elseif($indent >= $newIndent) {
				$data[] = substr($this->currentLine, $newIndent);
			}
			elseif($indent == 0) {
				$this->moveToPreviousLine();
				break;
			}
			else {
				throw new \InvalidArgumentException(sprintf('Indentation problem at line %d (%s)', $this->getRealCurrentLineNb() + 1, $this->currentLine));
			}
		}

		return implode("\n", $data);
	}

	/**
	 * Moves the parser to the next line.
	 */
	protected function moveToNextLine() {
		if($this->currentLineNb >= count($this->lines) - 1) {
			return false;
		}

		$this->currentLine = $this->lines[++$this->currentLineNb];

		return true;
	}

	/**
	 * Moves the parser to the previous line.
	 */
	protected function moveToPreviousLine() {
		$this->currentLine = $this->lines[--$this->currentLineNb];
	}

	/**
	 * Parses a YAML value.
	 *
	 * @param  string $value A YAML value
	 *
	 * @return mixed  A PHP value
	 * @throws \InvalidArgumentException
	 */
	protected function parseValue($value) {
		if(substr($value, 0, 1) === '*') {
			$value = ($pos = strpos($value, '#')) !== false ? substr($value, 1, $pos - 2) : $value = substr($value, 1);

			if(!array_key_exists($value, $this->refs)) {
				throw new \InvalidArgumentException(sprintf('Reference "%s" does not exist (%s).', $value, $this->currentLine));
			}

			return $this->refs[$value];
		}

		if(preg_match('/^(?P<separator>\||>)(?P<modifiers>\+|\-|\d+|\+\d+|\-\d+|\d+\+|\d+\-)?(?P<comments> +#.*)?$/i', $value, $matches)) {
			$modifiers = isset($matches['modifiers']) ? $matches['modifiers'] : '';

			return $this->parseFoldedScalar($matches['separator'], preg_replace('#\d+#i', '', $modifiers), intval(abs($modifiers)));
		}
		else {
			return $this->loadInline($value);
		}
	}

	/**
	 * Parses a folded scalar.
	 *
	 * @param  string  $separator   The separator that was used to begin this folded scalar (| or >)
	 * @param  string  $indicator   The indicator that was used to begin this folded scalar (+ or -)
	 * @param  integer $indentation The indentation that was used to begin this folded scalar
	 *
	 * @return string  The text value
	 */
	protected function parseFoldedScalar($separator, $indicator = '', $indentation = 0) {
		$separator = '|' == $separator ? "\n" : ' ';
		$text = '';

		$notEOF = $this->moveToNextLine();

		while($notEOF && $this->isCurrentLineBlank()) {
			$text .= "\n";
			$notEOF = $this->moveToNextLine();
		}

		if(!$notEOF) {
			return '';
		}

		if(!preg_match('/^(?P<indent>' . ($indentation ? str_repeat(' ', $indentation) : ' +') . ')(?P<text>.*)$/ui', $this->currentLine, $matches)) {
			$this->moveToPreviousLine();
			return '';
		}

		$textIndent = $matches['indent'];
		$previousIndent = 0;

		$text .= $matches['text'] . $separator;
		while($this->currentLineNb + 1 < count($this->lines)) {
			$this->moveToNextLine();

			if(preg_match('/^(?P<indent> {' . strlen($textIndent) . ',})(?P<text>.+)$/ui', $this->currentLine, $matches)) {
				if(' ' == $separator && $previousIndent != $matches['indent']) {
					$text = substr($text, 0, -1) . "\n";
				}

				$previousIndent = $matches['indent'];
				$text .= str_repeat(' ', $diff = strlen($matches['indent']) - strlen($textIndent)) . $matches['text'] . ($diff ? "\n" : $separator);
			}
			elseif(preg_match('/^(?P<text> *)$/i', $this->currentLine, $matches)) {
				$text .= preg_replace('/^ {1,' . strlen($textIndent) . '}/i', '', $matches['text']) . "\n";
			}
			else {
				$this->moveToPreviousLine();
				break;
			}
		}

		if($separator == ' ') {
			// replace last separator by a newline
			$text = preg_replace('/ (\n*)$/i', "\n$1", $text);
		}

		switch($indicator) {
			case '':
				$text = preg_replace('/\n+$/si', "\n", $text);
				break;
			case '+':
				break;
			case '-':
				$text = preg_replace('/\n+$/si', '', $text);
				break;
		}

		return $text;
	}

	/**
	 * Returns true if the next line is indented.
	 *
	 * @return Boolean Returns true if the next line is indented, false otherwise
	 */
	protected function isNextLineIndented() {
		$currentIndentation = $this->getCurrentLineIndentation();
		$notEOF = $this->moveToNextLine();

		while($notEOF && $this->isCurrentLineEmpty()) {
			$notEOF = $this->moveToNextLine();
		}

		if($notEOF === false) {
			return false;
		}

		$ret = false;
		if($this->getCurrentLineIndentation() <= $currentIndentation) {
			$ret = true;
		}

		$this->moveToPreviousLine();

		return $ret;
	}

	/**
	 * Returns true if the current line is blank or if it is a comment line.
	 *
	 * @return Boolean Returns true if the current line is empty or if it is a comment line, false otherwise
	 */
	protected function isCurrentLineEmpty() {
		return $this->isCurrentLineBlank() || $this->isCurrentLineComment();
	}

	/**
	 * Returns true if the current line is blank.
	 *
	 * @return Boolean Returns true if the current line is blank, false otherwise
	 */
	protected function isCurrentLineBlank() {
		return trim($this->currentLine, ' ') == '';
	}

	/**
	 * Returns true if the current line is a comment line.
	 *
	 * @return Boolean Returns true if the current line is a comment line, false otherwise
	 */
	protected function isCurrentLineComment() {
		//checking explicitly the first char of the trim is faster than loops or strpos
		$ltrimmedLine = ltrim($this->currentLine, ' ');
		return $ltrimmedLine[0] === '#';
	}

	/**
	 * Cleanups a YAML string to be parsed.
	 *
	 * @param  string $value The input YAML string
	 *
	 * @return string A cleaned up YAML string
	 */
	protected function cleanup($value) {
		$value = str_replace(array("\r\n", "\r"), "\n", $value);

		if(!preg_match("/\n$/i", $value)) {
			$value .= "\n";
		}

		// strip YAML header
		$count = 0;
		$value = preg_replace('/^\%YAML[: ][\d\.]+.*\n/siu', '', $value, -1, $count);
		$this->offset += $count;

		// remove leading comments
		$trimmedValue = preg_replace('/^(\#.*?\n)+/si', '', $value, -1, $count);
		if($count == 1) {
			// items have been removed, update the offset
			$this->offset += substr_count($value, "\n") - substr_count($trimmedValue, "\n");
			$value = $trimmedValue;
		}

		// remove start of the document marker (---)
		$trimmedValue = preg_replace('/^\-\-\-.*?\n/si', '', $value, -1, $count);
		if($count == 1) {
			// items have been removed, update the offset
			$this->offset += substr_count($value, "\n") - substr_count($trimmedValue, "\n");
			$value = $trimmedValue;

			// remove end of the document marker (...)
			$value = preg_replace('/\.\.\.\s*$/si', '', $value);
		}

		return $value;
	}

	/**
	 * Parses inline value
	 *
	 * @param string $value
	 *
	 * @return mixed
	 */
	protected function loadInline($value) {
		$value = trim($value);

		if(strlen($value) == 0) {
			return '';
		}

		switch($value[0]) {
			case '[':
				$result = $this->parseSequence($value);
				break;
			case '{':
				$result = $this->parseMapping($value);
				break;
			default:
				$result = self::parseScalar($value);
		}

		return $result;
	}

	/**
	 * Parses inline sequence
	 *
	 * @param string $sequence
	 * @param int    $i
	 *
	 * @return array
	 * @throws \InvalidArgumentException
	 */
	protected function parseSequence($sequence, &$i = 0) {
		$output = array();
		$len = strlen($sequence);
		$i += 1;

		// [foo, bar, ...]
		while($i < $len) {
			switch($sequence[$i]) {
				case '[':
					// nested sequence
					$output[] = $this->parseSequence($sequence, $i);
					break;
				case '{':
					// nested mapping
					$output[] = $this->parseMapping($sequence, $i);
					break;
				case ']':
					return $output;
				case ',':
				case ' ':
					break;
				default:
					$isQuoted = in_array($sequence[$i], array('"', "'"));
					$value = $this->parseScalar($sequence, array(',', ']'), array('"', "'"), $i);

					if(!$isQuoted && false !== strpos($value, ': ')) {
						// embedded mapping?
						try {
							$value = $this->parseMapping('{' . $value . '}');
						}
						catch(\InvalidArgumentException $e) {
							// no, it's not
						}
					}

					$output[] = $value;
					--$i;
			}
			++$i;
		}

		throw new \InvalidArgumentException(sprintf('Malformed inline YAML string %s', $sequence));
	}

	/**
	 * Parses inline mapping
	 *
	 * @param string $mapping
	 * @param int    $i
	 *
	 * @return array
	 * @throws \InvalidArgumentException
	 */
	protected function parseMapping($mapping, &$i = 0) {
		$output = array();
		$len = strlen($mapping);
		$i += 1;

		// {foo: bar, bar:foo, ...}
		while($i < $len) {
			switch($mapping[$i]) {
				case ' ':
				case ',':
					++$i;
					continue 2;
				case '}':
					return $output;
			}

			// key
			$key = self::parseScalar($mapping, array(':', ' '), array('"', "'"), $i, false);

			// value
			$done = false;
			while($i < $len) {
				switch($mapping[$i]) {
					case '[':
						// nested sequence
						$output[$key] = $this->parseSequence($mapping, $i);
						$done = true;
						break;
					case '{':
						// nested mapping
						$output[$key] = $this->parseMapping($mapping, $i);
						$done = true;
						break;
					case ':':
					case ' ':
						break;
					default:
						$output[$key] = $this->parseScalar($mapping, array(',', '}'), array('"', "'"), $i);
						$done = true;
						--$i;
				}

				++$i;

				if($done) {
					continue 2;
				}
			}
		}

		throw new \InvalidArgumentException(sprintf('Malformed inline YAML string %s', $mapping));
	}

	/**
	 * Parses inline scalar
	 *
	 * @param string $scalar
	 * @param array  $delimiters
	 * @param array  $stringDelimiters
	 * @param int    $i
	 * @param bool   $evaluate
	 *
	 * @return bool|float|int|mixed|null|number|string
	 * @throws \InvalidArgumentException
	 */
	protected function parseScalar($scalar, $delimiters = array(), $stringDelimiters = array('"', "'"), &$i = 0, $evaluate = true) {
		if(in_array($scalar[$i], $stringDelimiters)) {
			// quoted scalar
			$output = $this->parseQuotedScalar($scalar, $i);
		}
		else {
			// "normal" string
			if(!$delimiters) {
				$output = substr($scalar, $i);
				$i += strlen($output);

				// remove comments
				if(($strpos = strpos($output, ' #')) !== false) {
					$output = rtrim(substr($output, 0, $strpos));
				}
			}
			elseif(preg_match('/^(.+?)(' . implode('|', $delimiters) . ')/i', substr($scalar, $i), $match)) {
				$output = $match[1];
				$i += strlen($output);
			}
			else {
				throw new \InvalidArgumentException(sprintf('Malformed inline YAML string (%s).', $scalar));
			}

			$output = $evaluate ? $this->evaluateScalar($output) : $output;
		}

		return $output;
	}

	/**
	 * Parses quoted scalar
	 *
	 * @param string $scalar
	 * @param int    $i
	 *
	 * @return mixed
	 * @throws \InvalidArgumentException
	 */
	protected function parseQuotedScalar($scalar, &$i) {
		if(!preg_match('/' . self::REGEX_QUOTED_STRING . '/Aui', substr($scalar, $i), $match)) {
			throw new \InvalidArgumentException(sprintf('Malformed inline YAML string (%s).', substr($scalar, $i)));
		}

		$output = substr($match[0], 1, strlen($match[0]) - 2);
		$output = $scalar[$i] == '"' ? str_replace(array('\\"', '\\n', '\\r'), array('"', "\n", "\r"), $output) : str_replace('\'\'', '\'', $output);
		$i += strlen($match[0]);

		return $output;
	}

	/**
	 * Evaluates scalar value
	 *
	 * @param string $scalar
	 *
	 * @return mixed
	 */
	protected function evaluateScalar($scalar) {
		$scalar = trim($scalar);

		switch(true) {
			case strtolower($scalar) == 'null':
			case $scalar == '':
			case $scalar == '~':
				return null;
			case strpos($scalar, '!str') === 0:
				return (string) substr($scalar, 5);
			case strpos($scalar, '! ') === 0:
				return intval(self::parseScalar(substr($scalar, 2)));
			case ctype_digit($scalar):
				$raw = $scalar;
				$cast = intval($scalar);
				return $scalar[0] == '0' ? octdec($scalar) : (((string) $raw == (string) $cast) ? $cast : $raw);
			case in_array(strtolower($scalar), $this->trueValues):
				return true;
			case in_array(strtolower($scalar), $this->falseValues):
				return false;
			case is_numeric($scalar):
				return $scalar[0] == '0x' . $scalar[1] ? hexdec($scalar) : floatval($scalar);
			case strcasecmp($scalar, '.inf') == 0:
			case strcasecmp($scalar, '.NaN') == 0:
				return -log(0);
			case strcasecmp($scalar, '-.inf') == 0:
				return log(0);
			case preg_match('/^(-|\+)?[0-9,]+(\.[0-9]+)?$/i', $scalar):
				return floatval(str_replace(',', '', $scalar));
			case preg_match(self::REGEX_TIMESTAMP, $scalar):
				return strtotime($scalar);
			case strpos($scalar, '!!php/object:') === 0:
				return unserialize(substr($scalar, 13));
			default:
				return (string) $scalar;
		}
	}
}
