<?php
/**
 * Class for manipulating Unicode data
 *
 * The MIT License
 *
 * Copyright (c) 2007 Geoffrey Sneddon
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package Unicode
 * @version 0.2-dev
 * @copyright 2007 Geoffrey Sneddon
 * @author Geoffrey Sneddon
 * @license http://www.opensource.org/licenses/mit-license.php The MIT License
*/

/**
 * Unicode
 *
 * @package Unicode
 */
class Unicode
{
	/**
	 * Contains the raw unicode data that we're working from
	 *
	 * @var string UTF-32BE binary string on PHP < 6, otherwise a unicode string
	 */
	private $data;
	
	/**
	 * Object should be created with some Unicode::from_*() method, therefore
	 * this is private
	 */
	private function __construct()
	{
	}
	
	/**
	 * Prepare the object for serialisation
	 *
	 * If we're on PHP6, convert the Unicode::$data to a UTF-32BE binary string
	 * before serialising the object to allow for the object to be unserialised
	 * on older PHP versions without affecting functionality
	 */
	public function __sleep()
	{
		if (version_compare(phpversion(), '6', '>=') && is_unicode($this->data))
		{
			$this->data = unicode_encode($this->data, 'UTF-32BE');
		}
		return array('data');
	}
	
	/**
	 * Check the object is valid when being unserialised
	 *
	 * To prepare the object for use after being unserialised, we need to check
	 * that it is valid, and to convert Unicode::$data to a unicode string on
	 * PHP6. If Unicode::$data is not a string, a warning will be thrown. The
	 * validity of the UTF-32BE Unicode::$data is also checked, and the string
	 * is corrected if it is invalid.
	 */
	public function __wakeup()
	{
		if (!isset($this->data))
		{
			trigger_error('Unicode::__wakeup() expects the serialised object to have a $data property, none exists', E_USER_WARNING);
			$this->data = '';
		}
		elseif (!is_string($this->data))
		{
			trigger_error('Unicode::__wakeup() expects Unicode::$data to be string, ' . get_type($this->data) . ' given', E_USER_WARNING);
			$this->data = '';
		}
		elseif (version_compare(phpversion(), '6', '>=') && is_binary($this->data))
		{
			$this->data = self::call_unicode_func('unicode_decode', $this->data, 'UTF-32BE');
		}
		elseif (version_compare(phpversion(), '6', '<'))
		{
			$this->data = Unicode::from_utf32be($this->data)->to_utf32be();
		}
	}
	
	/**
	 * Call a function given by the first parameter in our own unicode setup
	 *
	 * @see call_user_func()
	 * @see Unicode::call_unicode_func_array()
	 * @param callback $function
	 * @param mixed $parameter,...
	 * @return mixed
	 */
	private static function call_unicode_func($function)
	{
		$param_arr = func_get_args();
		unset($param_arr[0]);
		return self::call_unicode_func_array($function, $param_arr);
	}
	
	/**
	 * Call a function given by the first parameter with an array of parameters
	 * in our own unicode setup
	 *
	 * @see call_user_func_array()
	 * @see Unicode::call_unicode_func()
	 * @param callback $function
	 * @param array $param_arr
	 * @return mixed
	 */
	private static function call_unicode_func_array($function, $param_arr)
	{
		// Get U+FFFD as a unicode string (which is slightly hard with unicode_semantics=off)
		static $replacement_character;
		if (!$replacement_character)
		{
			if (unicode_semantics())
			{
				$replacement_character = "\uFFFD";
			}
			else
			{
				$replacement_character = unicode_decode("\x00\x00\xFF\xFD", 'UTF-32BE');
			}
		}
		
		// Save the current unicode enviroment settings
		$substr_char = unicode_get_subst_char();
		$from_mode = unicode_get_error_mode(FROM_UNICODE);
		$to_mode = unicode_get_error_mode(TO_UNICODE);
		
		// Set our own unicode enviroment settings
		unicode_set_subst_char($replacement_character);
		unicode_set_error_mode(FROM_UNICODE, U_CONV_ERROR_SUBST);
		unicode_set_error_mode(TO_UNICODE, U_CONV_ERROR_SUBST);
		
		// Actually call the function
		$return = call_user_func_array($function, $param_arr);
		
		// Return everything to its prior state
		unicode_set_subst_char($substr_char);
		unicode_set_error_mode(FROM_UNICODE, $from_mode);
		unicode_set_error_mode(TO_UNICODE, $to_mode);
		
		// Finally return what the function returned
		return $return;
	}
	
	/**
	 * Check the given codepoint is a valid character
	 *
	 * @param int $codepoint
	 * @return bool
	 */
	private static function valid_unicode_codepoint($codepoint)
	{
		// Outside of Unicode codespace
		if ($codepoint < 0
			|| $codepoint > 0x10FFFF
			// UTF-16 Surrogates
			|| $codepoint >= 0xD800 && $codepoint <= 0xDFFF
			// Noncharacters
			|| ($codepoint & 0xFFFE) === 0xFFFE
			|| $codepoint >= 0xFDD0 && $codepoint <= 0xFDEF)
		{
			return false;
		}
		else
		{
			return true;
		}
	}
	
	/**
	 * Create a new Unicode object from an array of codepoints
	 *
	 * @param array $array
	 * @return Unicode
	 */
	public static function from_codepoint_array($array)
	{
		// Check given parameter is an array
		if (!is_array($string))
		{
			trigger_error('Unicode::from_codepoint_array() expects parameter 1 to be array, ' . get_type($string) . ' given', E_USER_WARNING);
			return false;
		}
		
		// Get U+FFFD as a binary string (which is slightly hard with unicode_semantics=off)
		static $replacement_character;
		if (!$replacement_character)
		{
			if (version_compare(phpversion(), '6', '>=') && unicode_semantics())
			{
				$replacement_character = unicode_encode("\uFFFD", 'UTF-32');
			}
			else
			{
				$replacement_character = "\x00\x00\xFF\xFD";
			}
		}
		
		// Create new object
		$unicode = new Unicode;
		
		// Strip any leading BOM (as otherwise we chage the meaing of the new sequence, which is illegal)
		if (isset($array[0]) && $array[0] === 0xFFFD)
		{
			array_splice($array, 1);
		}
		
		// Iterate through each and every codepoint
		foreach ($array as $codepoint)
		{
			// If the codepoint is an invalid character replace it with a U+FFFD REPLACEMENT CHARACTER
			if (!self::valid_unicode_codepoint($codepoint))
			{
				$unicode->data .= $replacement_character;
			}
			// Otherwise, append it to Unicode::$data
			else
			{
				$unicode->data .= pack('N', $codepoint);
			}
		}
		
		// If we're on PHP6, convert it to a unicode string and store that
		if (version_compare(phpversion(), '6', '>='))
		{
			$unicode->data = unicode_decode($string, 'UTF-32BE');
		}
		
		return $unicode;
	}
	
	/**
	 * Create an array of codepoints from the object
	 *
	 * @return string
	 */
	public function to_codepoint_array()
	{
		if (version_compare(phpversion(), '6', '>=') && is_unicode($this->data))
		{
			$data = unicode_encode($this->data, 'UTF-32BE');
		}
		else
		{
			$data = $this->data;
		}
		return unpack('N*', $data);
	}
	
	/**
	 * Create a new Unicode object from a UTF-8 encoded string
	 *
	 * @param string $string
	 * @return Unicode
	 */
	public static function from_utf8($string)
	{
		// Check given parameter is a string
		if (!is_string($string))
		{
			trigger_error('Unicode::from_utf8() expects parameter 1 to be string, ' . get_type($string) . ' given', E_USER_WARNING);
			return false;
		}
		
		// Create new object
		$unicode = new Unicode;
		
		// If we're on PHP6, we'll just get a unicode string and store that
		if (version_compare(phpversion(), '6', '>='))
		{
			if (is_unicode($string))
			{
				$unicode->data = $string;
			}
			else
			{
				$unicode->data = self::call_unicode_func('unicode_decode', $string, 'UTF-8');
			}
		}
		// Otherwise, we need to decode the UTF-8 string
		else
		{
			// Set the data to an empty string, and remaining bytes in the current sequence to zero
			$unicode->data = '';
			$remaining = 0;
			
			// Iterate through each and every byte
			for ($i = 0, $len = strlen($string); $i < $len; $i++)
			{
				$value = ord($string[$i]);
				
				// If we're the first byte of sequence:
				if (!$remaining)
				{
					// One byte sequence:
					if ($value <= 0x7F)
					{
						$character = $value;
						$length = 1;
					}
					// Two byte sequence:
					elseif (($value & 0xE0) === 0xC0)
					{
						$character = ($value & 0x1F) << 6;
						$length = 2;
						$remaining = 1;
					}
					// Three byte sequence:
					elseif (($value & 0xF0) === 0xE0)
					{
						$character = ($value & 0x0F) << 12;
						$length = 3;
						$remaining = 2;
					}
					// Four byte sequence:
					elseif (($value & 0xF8) === 0xF0)
					{
						$character = ($value & 0x07) << 18;
						$length = 4;
						$remaining = 3;
					}
					// Invalid byte:
					else
					{
						$character = 0xFFFD;
						$length = 3;
						$remaining = 0;
					}
				}
				// Continuation byte:
				else
				{
					// Check that the byte is valid, then add it to the character:
					if (($value & 0xC0) === 0x80)
					{
						$remaining--;
						$character |= ($value & 0x3F) << ($remaining * 6);
					}
					// If it is invalid, count the sequence as invalid and reprocess the current byte as the start of a sequence:
					else
					{
						$character = 0xFFFD;
						$length = 3;
						$remaining = 0;
						$i--;
					}
				}
				
				// If we've reached the end of the current byte sequence, append it to Unicode::$data
				if (!$remaining)
				{
					// If the character is illegal replace it with U+FFFD REPLACEMENT CHARACTER
					if ($length > 1 && $character <= 0x7F
						|| $length > 2 && $character <= 0x7FF
						|| $length > 3 && $character <= 0xFFFF
						|| !self::valid_unicode_codepoint($character))
					{
						$character = 0xFFFD;
					}
					
					$unicode->data .= pack('N', $character);
				}
			}
			
			// Strip any U+FEFF BYTE ORDER MARK (as otherwise we chage the meaing of the new sequence, which is illegal)
			if (substr($unicode->data, 0, 4) === "\x00\x00\xFE\xFF")
			{
				$unicode->data = substr($unicode->data, 4);
			}
			
			// If we've reached the end of the string but not the end of a character sequence, append a U+FFFD REPLACEMENT CHARACTE
			if ($remaining > 0)
			{
				$unicode->data .= "\x00\x00\xFF\xFD";
			}
		}
		return $unicode;
	}
	
	/**
	 * Create a UTF-8 binary string from the object
	 *
	 * @return string
	 */
	public function to_utf8()
	{
		if (version_compare(phpversion(), '6', '>=') && is_unicode($this->data))
		{
			return unicode_encode($this->data, 'UTF-8');
		}
		elseif (extension_loaded('mbstring') && ($return = @mb_convert_encoding($this->data, 'UTF-8', 'UTF-32BE')))
		{
			return $return;
		}
		elseif (extension_loaded('iconv') && ($return = @iconv('UTF-32BE', 'UTF-8', $this->data)))
		{
			return $return;
		}
		else
		{
			$codepoints = unpack('N*', $this->data);
			$return = '';
			foreach ($codepoints as $codepoint)
			{
				$return .= self::codepoint_to_utf8($codepoint);
			}
			return $return;
		}
	}
	
	/**
	 * Convert a unicode codepoint to a UTF-8 character sequence
	 *
	 * @param int $codepoint
	 * @return string
	 */
	private static function codepoint_to_utf8($codepoint)
	{
		// Keep a cache of all the codepoints we have already converted (this is actually quicker even with such simple code)
		static $cache;
		
		// If we haven't already got it cached, go cache it
		if (!isset($cache[$codepoint]))
		{
			// On PHP6, we can use its own unicode support
			if (version_compare(phpversion(), '6', '>=') && unicode_semantics())
			{
				$cache[$codepoint] = unicode_encode(self::call_unicode_func('chr', $codepoint), 'UTF-8');
			}
			// If the codepoint is invalid, just store it as U+FFFD REPLACEMENT CHARACTER
			elseif (!self::valid_unicode_codepoint($codepoint))
			{
				$cache[$codepoint] = "\xEF\xBF\xBD";
			}
			// One byte sequence:
			elseif ($codepoint <= 0x7F)
			{
				$cache[$codepoint] = chr($codepoint);
			}
			// Two byte sequence:
			elseif ($codepoint <= 0x7FF)
			{
				$cache[$codepoint] = chr(0xC0 | ($codepoint >> 6)) . chr(0x80 | ($codepoint & 0x3F));
			}
			// Three byte sequence:
			elseif ($codepoint <= 0xFFFF)
			{
				$cache[$codepoint] = chr(0xE0 | ($codepoint >> 12)) . chr(0x80 | (($codepoint >> 6) & 0x3F)) . chr(0x80 | ($codepoint & 0x3F));
			}
			// Four byte sequence:
			else
			{
				$cache[$codepoint] = chr(0xF0 | ($codepoint >> 18)) . chr(0x80 | (($codepoint >> 12) & 0x3F)) . chr(0x80 | (($codepoint >> 6) & 0x3F)) . chr(0x80 | ($codepoint & 0x3F));
			}
		}
		return $cache[$codepoint];
	}
	
	/**
	 * Create a new Unicode object from a UTF-16 encoded string
	 *
	 * @param string $string
	 * @return Unicode
	 */
	public static function from_utf16($string)
	{
		// Check given parameter is a string
		if (!is_string($string))
		{
			trigger_error('Unicode::from_utf8() expects parameter 1 to be string, ' . get_type($string) . ' given', E_USER_WARNING);
			return false;
		}
		
		// Create new object
		$unicode = new Unicode;
		
		// If we're on PHP6, we'll just get a unicode string and store that
		if (version_compare(phpversion(), '6', '>='))
		{
			if (is_unicode($string))
			{
				$unicode->data = $string;
			}
			else
			{
				$unicode->data = self::call_unicode_func('unicode_decode', $string, 'UTF-16');
			}
		}
		// Otherwise, we need to decode the UTF-16 string
		else
		{
			// Set the data to an empty string and surrogate to false
			$unicode->data = '';
			$surrogate = false;
			
			// See if the string is of a valid length (as UTF-16 is in two byte sequences, it must be divisible by two)
			$valid_length = (($len = strlen($string)) % 2) ? false : true;
			
			// If it is of an invalid length, trim all the invalid bytes at the end (we'll replace them with a U+FFFD REPLACEMENT CHARACTER later)
			if (!$valid_length)
			{
				$string = substr($string, 0, floor($len / 2) * 2);
			}
			
			// If the string starts with a UTF-16LE BOM, it is UTF-16LE, so decode it as such
			if (substr($string, 0, 2) === "\xFF\xFE")
			{
				$words = unpack('v*', $string);
			}
			// Otherwise, it is UTF-16BE, so decode it as such
			else
			{
				$words = unpack('n*', $string);
			}
			
			// Iterate through each and every word
			for ($i = 0, $word_count = count($words); $i < $word_count; $i++)
			{
				// If we're the first word of sequence:
				if (!$surrogate)
				{
					// One word sequence:
					if (self::valid_unicode_codepoint($words[$i]))
					{
						$unicode->data .= pack('N', $words[$i]);
					}
					// Two word sequence:
					elseif ($value >= 0xD800 && $words[$i] <= 0xDFFF)
					{
						$character = ($words[$i] & 0x3FF) << 10;
						$surrogate = true;
					}
					// Invalid word:
					else
					{
						$unicode->data .= pack('N', 0xFFFD);
					}
				}
				// Second word:
				else
				{
					// Surrogates are only ever two words, so we can say we've reached the end with certainty
					$surrogate = false;
					
					// Check that the word is valid, then add it to the character:
					if ($value >= 0xDC00 && $words[$i] <= 0xDFFF)
					{
						$character |= $words[$i] & 0x3FF;
						if (self::valid_unicode_codepoint($character))
						{
							$unicode->data .= pack('N', $character);
						}
						else
						{
							$unicode->data .= pack('N', 0xFFFD);
						}
					}
					// If it is invalid, count the sequence as invalid and reprocess the current word as a first word:
					else
					{
						$unicode->data .= pack('N', 0xFFFD);
						$i--;
					}
				}
			}
			
			// If we've reached the end of the string but not the end of a surrogate pair, append a U+FFFD REPLACEMENT CHARACTER
			if ($surrogate)
			{
				$unicode->data .= "\x00\x00\xFF\xFD";
			}
			
			// If it was of an invalid length, append a U+FFFD REPLACEMENT CHARACTER
			if (!$valid_length)
			{
				$unicode->data .= "\x00\x00\xFF\xFD";
			}
			
			// Strip any U+FEFF BYTE ORDER MARK (as otherwise we chage the meaing of the new sequence, which is illegal)
			if (substr($unicode->data, 0, 4) === "\x00\x00\xFE\xFF")
			{
				$unicode->data = substr($unicode->data, 4);
			}
		}
		return $unicode;
	}
	
	/**
	 * Create a new Unicode object from a UTF-16BE encoded string
	 *
	 * @param string $string
	 * @return Unicode
	 */
	public static function from_utf16be($string)
	{
		// Check given parameter is a string
		if (!is_string($string))
		{
			trigger_error('Unicode::from_utf16be() expects parameter 1 to be string, ' . get_type($string) . ' given', E_USER_WARNING);
			return false;
		}
		
		// Add BOM before calling Unicode::from_utf16() if it doesn't already exist
		if ((version_compare(phpversion(), '6', '<') || is_binary($string)) && substr($string, 0, 2) !== "\xFE\xFF")
		{
			// Get U+FEFF as a binary string (which is slightly hard with unicode_semantics=off)
			static $bom;
			if (!$bom)
			{
				if (version_compare(phpversion(), '6', '>=') && unicode_semantics())
				{
					$bom = unicode_encode("\uFEFF", 'UTF-16BE');
				}
				else
				{
					$bom = "\xFE\xFF";
				}
			}
			$string = $bom . $string;
		}
		return self::from_utf16($string);
	}
	
	/**
	 * Create a new Unicode object from a UTF-16LE encoded string
	 *
	 * @param string $string
	 * @return Unicode
	 */
	public static function from_utf16le($string)
	{
		// Check given parameter is a string
		if (!is_string($string))
		{
			trigger_error('Unicode::from_utf16le() expects parameter 1 to be string, ' . get_type($string) . ' given', E_USER_WARNING);
			return false;
		}
		
		// Add BOM before calling Unicode::from_utf16() if it doesn't already exist
		
		if ((version_compare(phpversion(), '6', '<') || is_binary($string)) && substr($string, 0, 2) !== "\xFF\xFE")
		{
			// Get U+FEFF as a binary string (which is slightly hard with unicode_semantics=off)
			static $bom;
			if (!$bom)
			{
				if (version_compare(phpversion(), '6', '>=') && unicode_semantics())
				{
					$bom = unicode_encode("\uFEFF", 'UTF-16LE');
				}
				else
				{
					$bom = "\xFF\xFE";
				}
			}
			$string = $bom . $string;
		}
		return self::from_utf16($string);
	}
	
	/**
	 * Create a UTF-16 binary string from the object
	 *
	 * @return string
	 */
	public function to_utf16()
	{
		if (version_compare(phpversion(), '6', '>=') && unicode_semantics())
		{
			return unicode_encode("\uFEFF", 'UTF-16BE') . $this->to_utf16be();
		}
		else
		{
			return "\xFE\xFF" . $this->to_utf16be();
		}
	}
	
	/**
	 * Create a UTF-16BE binary string from the object
	 *
	 * @return string
	 */
	public function to_utf16be()
	{
		if (version_compare(phpversion(), '6', '>=') && is_unicode($this->data))
		{
			return unicode_encode($this->data, 'UTF-16BE');
		}
		elseif (extension_loaded('mbstring') && ($return = @mb_convert_encoding($this->data, 'UTF-16BE', 'UTF-32BE')))
		{
			return $return;
		}
		elseif (extension_loaded('iconv') && ($return = @iconv('UTF-32BE', 'UTF-16BE', $this->data)))
		{
			return $return;
		}
		else
		{
			$codepoints = unpack('N*', $this->data);
			$return = '';
			foreach ($codepoints as $codepoint)
			{
				$return .= self::codepoint_to_utf16be($codepoint);
			}
			return $return;
		}
	}
	
	/**
	 * Create a UTF-16LE binary string from the object
	 *
	 * @return string
	 */
	public function to_utf16le()
	{
		if (version_compare(phpversion(), '6', '>=') && is_unicode($this->data))
		{
			return unicode_encode($this->data, 'UTF-16LE');
		}
		elseif (extension_loaded('mbstring') && ($return = @mb_convert_encoding($this->data, 'UTF-16LE', 'UTF-32BE')))
		{
			return $return;
		}
		elseif (extension_loaded('iconv') && ($return = @iconv('UTF-32BE', 'UTF-16LE', $this->data)))
		{
			return $return;
		}
		else
		{
			$codepoints = unpack('N*', $this->data);
			$return = '';
			foreach ($codepoints as $codepoint)
			{
				$return .= self::codepoint_to_utf16le($codepoint);
			}
			return $return;
		}
	}
	
	/**
	 * Convert a unicode codepoint to a UTF-16 character sequence
	 *
	 * @param int $codepoint
	 * @return string
	 */
	private static function codepoint_to_utf16($codepoint)
	{
		return self::codepoint_to_utf16be($codepoint);
	}
	
	/**
	 * Convert a unicode codepoint to a UTF-16BE character sequence
	 *
	 * @param int $codepoint
	 * @return string
	 */
	private static function codepoint_to_utf16be($codepoint)
	{
		// Keep a cache of all the codepoints we have already converted (this is actually quicker even with such simple code)
		static $cache;
		
		// If we haven't already got it cached, go cache it
		if (!isset($cache[$codepoint]))
		{
			// On PHP6, we can use its own unicode support
			if (version_compare(phpversion(), '6', '>=') && unicode_semantics())
			{
				$cache[$codepoint] = unicode_encode(self::call_unicode_func('chr', $codepoint), 'UTF-16BE');
			}
			// If the codepoint is invalid, just store it as U+FFFD REPLACEMENT CHARACTER
			elseif (!self::valid_unicode_codepoint($codepoint))
			{
				$cache[$codepoint] = "\xFF\xFD";
			}
			// Without a surrogate:
			elseif ($codepoint < 0x10000)
			{
				$cache[$codepoint] = pack('n', $codepoint);
			}
			// With a surrogate
			else
			{
				$surrogate_code_point = $codepoint - 0x10000;
				$cache[$codepoint] = pack('n*', ($codepoint >> 10) | 0xD800, ($codepoint & 0x03FF) | 0xDC00);
			}
		}
		return $cache[$codepoint];
	}
	
	/**
	 * Convert a unicode codepoint to a UTF-16LE character sequence
	 *
	 * @param int $codepoint
	 * @return string
	 */
	private static function codepoint_to_utf16le($codepoint)
	{
		// Keep a cache of all the codepoints we have already converted (this is actually quicker even with such simple code)
		static $cache;
		
		// If we haven't already got it cached, go cache it
		if (!isset($cache[$codepoint]))
		{
			// On PHP6, we can use its own unicode support
			if (version_compare(phpversion(), '6', '>=') && unicode_semantics())
			{
				$cache[$codepoint] = unicode_encode(self::call_unicode_func('chr', $codepoint), 'UTF-16LE');
			}
			// If the codepoint is invalid, just store it as U+FFFD REPLACEMENT CHARACTER
			elseif (!self::valid_unicode_codepoint($codepoint))
			{
				$cache[$codepoint] = "\xFD\xFF";
			}
			// Without a surrogate:
			elseif ($codepoint < 0x10000)
			{
				$cache[$codepoint] = pack('v', $codepoint);
			}
			// With a surrogate
			else
			{
				$surrogate_code_point = $codepoint - 0x10000;
				$cache[$codepoint] = pack('v*', ($codepoint >> 10) | 0xD800, ($codepoint & 0x03FF) | 0xDC00);
			}
		}
		return $cache[$codepoint];
	}
	
	/**
	 * Create a new Unicode object from a UTF-32 encoded string
	 *
	 * @param string $string
	 * @return Unicode
	 */
	public static function from_utf32($string)
	{
		// Check given parameter is a string
		if (!is_string($string))
		{
			trigger_error('Unicode::from_utf32() expects parameter 1 to be string, ' . get_type($string) . ' given', E_USER_WARNING);
			return false;
		}
		
		// Create new object
		$unicode = new Unicode;
		
		// If we're on PHP6, we'll just get a unicode string and store that
		if (version_compare(phpversion(), '6', '>='))
		{
			if (is_unicode($string))
			{
				$unicode->data = $string;
			}
			else
			{
				$unicode->data = self::call_unicode_func('unicode_decode', $string, 'UTF-32');
			}
		}
		// Otherwise, we need to decode the UTF-32 string
		else
		{
			// Set the data to an empty string
			$unicode->data = '';
			
			// See if the string is of a valid length (as UTF-32 is in four byte sequences, it must be divisible by four)
			$valid_length = (($len = strlen($string)) % 4) ? false : true;
			
			// If it is of an invalid length, trim all the invalid bytes at the end (we'll replace them with a U+FFFD REPLACEMENT CHARACTER later)
			if (!$valid_length)
			{
				$string = substr($string, 0, floor($len / 4) * 4);
			}
			
			// If the string starts with a UTF-32LE BOM, it is UTF-32LE, so decode it as such
			if (substr($string, 0, 4) === "\xFF\xFE\x00\x00")
			{
				$codepoints = unpack('V*', $string);
			}
			// Otherwise, it is UTF-32BE, so decode it as such
			else
			{
				$codepoints = unpack('N*', $string);
			}
			
			// Iterate through each and every codepoint
			foreach ($codepoints as $codepoint)
			{
				// If the codepoint is an invalid character replace it with a U+FFFD REPLACEMENT CHARACTER
				if (!self::valid_unicode_codepoint($codepoint))
				{
					$unicode->data .= "\x00\x00\xFF\xFD";
				}
				// Otherwise, append it to Unicode::$data
				else
				{
					$unicode->data .= pack('N', $codepoint);
				}
			}
			
			// If it was of an invalid length, append a U+FFFD REPLACEMENT CHARACTER
			if (!$valid_length)
			{
				$unicode->data .= "\x00\x00\xFF\xFD";
			}
			
			// Strip any leading BOM (as otherwise we chage the meaing of the new sequence, which is illegal)
			if (substr($unicode->data, 0, 4) === "\x00\x00\xFE\xFF")
			{
				$unicode->data = substr($unicode->data, 4);
			}
		}
		
		return $unicode;
	}
	
	/**
	 * Create a new Unicode object from a UTF-32BE encoded string
	 *
	 * @param string $string
	 * @return Unicode
	 */
	public static function from_utf32be($string)
	{
		// Check given parameter is a string
		if (!is_string($string))
		{
			trigger_error('Unicode::from_utf32be() expects parameter 1 to be string, ' . get_type($string) . ' given', E_USER_WARNING);
			return false;
		}
		
		// Add BOM before calling Unicode::from_utf32() if it doesn't already exist
		if ((version_compare(phpversion(), '6', '<') || is_binary($string)) && substr($string, 0, 4) !== "\x00\x00\xFE\xFF")
		{
			// Get U+FEFF as a binary string (which is slightly hard with unicode_semantics=off)
			static $bom;
			if (!$bom)
			{
				if (version_compare(phpversion(), '6', '>=') && unicode_semantics())
				{
					$bom = unicode_encode("\uFEFF", 'UTF-32BE');
				}
				else
				{
					$bom = "\x00\x00\xFE\xFF";
				}
			}
			$string = $bom . $string;
		}
		return self::from_utf32($string);
	}
	
	/**
	 * Create a new Unicode object from a UTF-32LE encoded string
	 *
	 * @param string $string
	 * @return Unicode
	 */
	public static function from_utf32le($string)
	{
		// Check given parameter is a string
		if (!is_string($string))
		{
			trigger_error('Unicode::from_utf32le() expects parameter 1 to be string, ' . get_type($string) . ' given', E_USER_WARNING);
			return false;
		}
		
		// Add BOM before calling Unicode::from_utf32() if it doesn't already exist
		if ((version_compare(phpversion(), '6', '<') || is_binary($string)) && substr($string, 0, 4) !== "\xFF\xFE\x00\x00")
		{
			// Get U+FEFF as a binary string (which is slightly hard with unicode_semantics=off)
			static $bom;
			if (!$bom)
			{
				if (version_compare(phpversion(), '6', '>=') && unicode_semantics())
				{
					$bom = unicode_encode("\uFEFF", 'UTF-32LE');
				}
				else
				{
					$bom = "\xFF\xFE\x00\x00";
				}
			}
			$string = $bom . $string;
		}
		return self::from_utf32($string);
	}
	
	/**
	 * Create a UTF-32 binary string from the object
	 *
	 * @return string
	 */
	public function to_utf32()
	{
		if (version_compare(phpversion(), '6', '>=') && unicode_semantics())
		{
			return unicode_encode("\uFEFF", 'UTF-32BE') . $this->to_utf32be();
		}
		else
		{
			return "\x00\x00\xFE\xFF" . $this->to_utf32be();
		}
	}
	
	/**
	 * Create a UTF-32BE binary string from the object
	 *
	 * @return string
	 */
	public function to_utf32be()
	{
		if (version_compare(phpversion(), '6', '>=') && is_unicode($this->data))
		{
			return unicode_encode($this->data, 'UTF-32BE');
		}
		else
		{
			return $this->data;
		}
	}
	
	/**
	 * Create a UTF-32LE binary string from the object
	 *
	 * @return string
	 */
	public function to_utf32le()
	{
		if (version_compare(phpversion(), '6', '>=') && is_unicode($this->data))
		{
			return unicode_encode($this->data, 'UTF-32LE');
		}
		elseif (extension_loaded('mbstring') && ($return = @mb_convert_encoding($this->data, 'UTF-32LE', 'UTF-32BE')))
		{
			return $return;
		}
		elseif (extension_loaded('iconv') && ($return = @iconv('UTF-32BE', 'UTF-32LE', $this->data)))
		{
			return $return;
		}
		else
		{
			return call_user_func_array('pack', array_merge(array('V*'), unpack('N*', $this->data)));
		}
	}
	
	/**
	 * Convert a unicode codepoint to a UTF-32 character sequence
	 *
	 * @param int $codepoint
	 * @return string
	 */
	private static function codepoint_to_utf32($codepoint)
	{
		return self::codepoint_to_utf32be($codepoint);
	}
	
	/**
	 * Convert a unicode codepoint to a UTF-32BE character sequence
	 *
	 * @param int $codepoint
	 * @return string
	 */
	private static function codepoint_to_utf32be($codepoint)
	{
		if (self::valid_unicode_codepoint($codepoint))
		{
			return pack('N', $codepoint);
		}
		elseif (version_compare(phpversion(), '6', '>=') && unicode_semantics())
		{
			return unicode_encode("\uFFFD", 'UTF-32BE');
		}
		else
		{
			return "\x00\x00\xFF\xFD";
		}
	}
	
	/**
	 * Convert a unicode codepoint to a UTF-32LE character sequence
	 *
	 * @param int $codepoint
	 * @return string
	 */
	private static function codepoint_to_utf32le($codepoint)
	{
		if (self::valid_unicode_codepoint($codepoint))
		{
			return pack('V', $codepoint);
		}
		elseif (version_compare(phpversion(), '6', '>=') && unicode_semantics())
		{
			return unicode_encode("\uFFFD", 'UTF-32LE');
		}
		else
		{
			return "\xFD\xFF\x00\x00";
		}
	}
}