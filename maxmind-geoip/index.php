<?php
require_once 'vendor/autoload.php';
use GeoIp2\Database\Reader;

// This creates the Reader object, which should be reused across
// lookups.
$reader = new Reader('databases/GeoLite2-City.mmdb');

// Replace "city" with the appropriate method for your database, e.g.,
// "country".
// $record = $reader->city('103.89.58.89'); //IN
$record = $reader->city('149.36.50.182'); //Germany
// $record = $reader->city('103.89.58.89'); //IN
// $record = $reader->city('103.89.58.89'); //IN

// print($record->country->isoCode . "\n");
// print($record->country->name . "\n");
// //print($record->country->names['zh-CN'] . "\n");

// print($record->mostSpecificSubdivision->name . "\n");
// print($record->mostSpecificSubdivision->isoCode . "\n");

// print($record->city->name . "\n");

// print($record->postal->code . "\n");

// print($record->location->latitude . "\n");
// print($record->location->longitude . "\n");

// print($record->traits->network . "\n");

echo '<pre>'; print_r($record); echo '</pre>';
