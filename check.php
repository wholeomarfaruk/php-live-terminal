<?php

header('Content-Type: text/plain');

echo "PHP VERSION: " . PHP_VERSION . "\n\n";

echo "exec exists: ";
var_dump(function_exists('exec'));

echo "\nexec disabled: ";
var_dump(in_array(
    'exec',
    array_map('trim', explode(',', (string) ini_get('disable_functions'))),
    true
));

echo "\ndisable_functions:\n";
echo ini_get('disable_functions');

echo "\n\nshell_exec exists: ";
var_dump(function_exists('shell_exec'));

echo "\nproc_open exists: ";
var_dump(function_exists('proc_open'));

echo "\npopen exists: ";
var_dump(function_exists('popen'));

echo "\n\nSAPI: ";
echo PHP_SAPI;

echo "\n\nLoaded php.ini:\n";
echo php_ini_loaded_file();