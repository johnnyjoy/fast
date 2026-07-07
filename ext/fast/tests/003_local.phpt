--TEST--
Fast local mode basic get/set/count
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
$f = new Fast();
$f['x'] = 42;
var_dump($f['x']);
var_dump(count($f));
var_dump(isset($f['x']));
?>
--EXPECT--
int(42)
int(1)
bool(true)
