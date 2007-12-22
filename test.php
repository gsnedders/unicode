<?php

require_once 'unicode.php';

$data = file_get_contents('UTF-8-test.txt');
//$data = "\xC3\xA9";
//$data = "\xC0\xE0\x80\xF0\x80\x80\xF8\x80\x80\x80\xFC\x80\x80\x80\x80\xDF\xEF\xBF\xF7\xBF\xBF\xFB\xBF\xBF\xBF\xFD\xBF\xBF\xBF\xBF";
//$data = "\xF0\x90\x91\xBE";
//$data = "\xE0\x81\x81";

$utf16 = Unicode::from_utf8($data)->to_utf16();
$utf32 = Unicode::from_utf16($utf16)->to_utf32();
$utf8 = Unicode::from_utf32($utf32)->to_utf8();