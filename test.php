<?php

require_once 'unicode.php';

$data = file_get_contents('UTF-8-test.txt');
//$data = 'é';

$unicode = Unicode::from_utf8($data);

echo $unicode->to_utf8();