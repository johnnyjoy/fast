--TEST--
Fast class is registered by the extension
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
var_dump(class_exists('Fast', false));
var_dump(in_array('ArrayAccess', class_implements('Fast'), true));
?>
--EXPECT--
bool(true)
bool(true)
