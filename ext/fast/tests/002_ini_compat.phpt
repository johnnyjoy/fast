--TEST--
fast.compat INI defaults to native (0)
--EXTENSIONS--
igbinary
--SKIPIF--
<?php
if (!extension_loaded('fast')) {
    die('skip ext-fast not loaded');
}
?>
--FILE--
<?php
var_dump(ini_get('fast.compat'));
?>
--EXPECT--
string(1) "0"
