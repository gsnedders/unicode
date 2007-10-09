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
 * @version 0.1-dev
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
	 * @var string binary string on PHP < 6, and unicode string on PHP >= 6
	 */
	private $data;
	
	private function __construct()
	{
	}
	
	public function __sleep()
	{
		if (version_compare(phpversion(), '6', '>=') && is_unicode($this->data))
		{
			$this->data = self::call_unicode_func('unicode_encode', $this->data, 'UTF-32BE');
		}
		return array('data');
	}
	
	public function __wakeup()
	{
		if (!is_string($this->data))
		{
			trigger_error('Unicode::__wakeup() expects Unicode::$data to be string, ' . get_type($this->data) . ' given', E_USER_WARNING);
			$this->data = '';
		}
		elseif (version_compare(phpversion(), '6', '>=') && is_binary($this->data))
		{
			$this->data = self::call_unicode_func('unicode_decode', $this->data, 'UTF-32BE');
		}
		elseif (version_compare(phpversion(), '6', '<') && ($len = strlen($this->data)) % 4)
		{
			$this->data = substr($this->data, 0, floor($len / 4)) . "\x00\x00\xFF\xFD";
		}
	}
	
	private static function call_unicode_func($function)
	{
		$param_arr = func_get_args();
		unset($param_arr[0]);
		return self::call_unicode_func_array($function, $param_arr);
	}
	
	private static function call_unicode_func_array($function, $param_arr)
	{
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
		$substr_char = unicode_get_subst_char();
		$from_mode = unicode_get_error_mode(FROM_UNICODE);
		$to_mode = unicode_get_error_mode(TO_UNICODE);
		unicode_set_subst_char($replacement_character);
		unicode_set_error_mode(FROM_UNICODE, U_CONV_ERROR_SUBST);
		unicode_set_error_mode(TO_UNICODE, U_CONV_ERROR_SUBST);
		$return = call_user_func_array($function, $param_arr);
		unicode_set_subst_char($substr_char);
		unicode_set_error_mode(FROM_UNICODE, $from_mode);
		unicode_set_error_mode(TO_UNICODE, $to_mode);
		return $return;
	}
	
	public static function from_utf8($string)
	{
		if (!is_string($string))
		{
			trigger_error('Unicode::from_utf8() expects parameter 1 to be string, ' . get_type($string) . ' given', E_USER_WARNING);
			return false;
		}
		
		$unicode = new Unicode;
		
		if (version_compare(phpversion(), '6', '>='))
		{
			if (is_unicode($string))
			{
				$unicode->data = $string;
			}
			else
			{
				$this->data = self::call_unicode_func('unicode_decode', $string, 'UTF-8');
			}
		}
		else
		{
			$unicode->data = '';
			$remaining = 0;
			
			$len = strlen($string);
			for ($i = 0; $i < $len; $i++)
			{
				$value = ord($string[$i]);
				
				if (!$remaining)
				{
					if ($value <= 0x7F)
					{
						$character = $value;
						$length = 1;
					}
					elseif (($value & 0xE0) === 0xC0)
					{
						$character = ($value & 0x1F) << 6;
						$length = 2;
						$remaining = 1;
					}
					elseif (($value & 0xF0) === 0xE0)
					{
						$character = ($value & 0x0F) << 12;
						$length = 3;
						$remaining = 2;
					}
					elseif (($value & 0xF8) === 0xF0)
					{
						$character = ($value & 0x07) << 18;
						$length = 4;
						$remaining = 3;
					}
					else
					{
						$character = 0xFFFD;
						$length = 3;
						$remaining = 0;
					}
				}
				else
				{
					if (($value & 0xC0) === 0x80)
					{
						$remaining--;
						$character |= ($value & 0x3F) << ($remaining * 6);
					}
					else
					{
						$character = 0xFFFD;
						$length = 3;
						$remaining = 0;
						// Reprocess byte as a start of character
						$i--;
					}
				}
				
				if (!$remaining)
				{
					// "Non-shortest form" sequences
					if ($length > 1 && $character <= 0x7F
						|| $length > 2 && $character <= 0x7FF
						|| $length > 3 && $character <= 0xFFFF
						// Outside of Unicode codespace (the former should never occur, but we better check for it in case of a security hole)
						|| $character < 0
						|| $character > 0x10FFFF
						// UTF-16 Surrogates
						|| $character >= 0xD800 && $character <= 0xDFFF
						// Noncharacters
						|| ($character & 0xFFFE) === 0xFFFE
						|| $character >= 0xFDD0 && $character <= 0xFDEF)
					{
						$character = 0xFFFD;
					}
					
					$unicode->data .= pack('N', $character);
				}
			}
			
			if (!empty($remaining))
			{
				$unicode->data .= "\x00\x00\xFF\xFD";
			}
		}
		return $unicode;
	}
	
	public function to_utf8()
	{
		if (version_compare(phpversion(), '6', '>=') && is_unicode($this->data))
		{
			return self::call_unicode_func('unicode_encode', $this->data, 'UTF-8');
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
			$data = unpack('N*', $this->data);
			$return = '';
			foreach ($data as $codepoint)
			{
				$return .= self::codepoint_to_utf8($codepoint);
			}
			return $return;
		}
	}
	
	private static function codepoint_to_utf8($codepoint)
	{
		static $cache;
		if (!is_int($codepoint))
		{
			trigger_error('Unicode::codepoint_to_utf8() expects parameter 1 to be long, ' . get_type($codepoint) . ' given', E_USER_WARNING);
			return false;
		}
		elseif (!isset($cache[$codepoint]))
		{
			if ($codepoint >= 0x00 && $codepoint <= 0x7F)
			{
				$cache[$codepoint] = chr($codepoint);
			}
			elseif ($codepoint <= 0x7FF)
			{
				$cache[$codepoint] = chr(0xC0 | ($codepoint >> 6)) . chr(0x80 | ($codepoint & 0x3F));
			}
			elseif ($codepoint < 0xD800 || $codepoint > 0xDFFF && $codepoint <= 0xFFFF)
			{
				$cache[$codepoint] = chr(0xE0 | ($codepoint >> 12)) . chr(0x80 | (($codepoint >> 6) & 0x3F)) . chr(0x80 | ($codepoint & 0x3F));
			}
			elseif ($codepoint <= 0x10FFFF)
			{
				$cache[$codepoint] = chr(0xF0 | ($codepoint >> 18)) . chr(0x80 | (($codepoint >> 12) & 0x3F)) . chr(0x80 | (($codepoint >> 6) & 0x3F)) . chr(0x80 | ($codepoint & 0x3F));
			}
			else
			{
				$cache[$codepoint] = "\xEF\xBF\xBD";
			}
		}
		return $cache[$codepoint];
	}
}