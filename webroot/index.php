<?php
/**
 * OR Match
 *
 * @package    or-match
 * @version    0.9
 * @since      0.9
 * @author     Benn Oshrin
 * @copyright  Copyright (c) 2013, University of California, Berkeley
 * @license    http://opensource.org/licenses/BSD-3-Clause BSD
 * @link       https://github.com/ucidentity/or-match
 */

// Make sure ADOdb associative fetches are always lowercased
define('ADODB_ASSOC_CASE', 0);

require_once '../vendor/adodb5/adodb-exceptions.inc.php';
require_once '../vendor/adodb5/adodb.inc.php';
require_once '../vendor/restler.php';
require_once './ExtendedResponder.php';

// We need to update the include path to include the version directory
// (which holds our API-level classes)
set_include_path(get_include_path(). PATH_SEPARATOR. 'v1/');

use Luracast\Restler\Defaults;
use Luracast\Restler\Restler;

$config = parse_ini_file("../etc/config.ini", true);
$matchConfig = array();

if(empty($config)) {
  throw new RuntimeException("Failed to parse configuration file");
}

// Rewrite the config slightly to make it easier to work with
foreach(array_keys($config) as $k) {
  $a = explode(':', $k, 2);
  
  if(preg_match('/^attribute:/', $k)) {
    $matchConfig['attributes'][ $a[1] ] = $config[$k];
    
    // Also track attributes by database field for reverse mapping
    if(empty($config[$k]['column'])) {
      throw new RuntimeException("No column set for " . $k);
    }
    
    $matchConfig['dbattributes'][ $config[$k]['column'] ] = $config[$k];
  } elseif(preg_match('/^confidence:/', $k)) {
    $matchConfig['confidences'][ $a[1] ] = $config[$k];
  }
}

// Set up logging

$log = new Logger($config['logging']);

// and record some connection information

$log->info("New connection from " . $_SERVER['REMOTE_ADDR']);

// Establish a database connection

try {
  $dbh = NewADOConnection($config['database']['type']);
  $dbh->PConnect($config['database']['host'],
                 $config['database']['user'],
                 $config['database']['password'],
                 $config['database']['database']);
  $dbh->SetFetchMode(ADODB_FETCH_ASSOC);
}
catch(Exception $e) {
  // XXX exceptions thrown here (and above) generate PHP stack traces instead of (or in addition to) HTTP 500 errors
  throw new Exception($e->getMessage());
}

Defaults::$useUrlBasedVersioning = true;

$r = new Restler();
$r->setApiVersion(1);

// Attach classes for ID Match API services
$r->addAPIClass('People', 'people');
$r->addAPIClass('ReferenceIds', 'referenceIds');
// XXX add support for these
//$r->addAPIClass('PendingMatches', 'pendingMatches');
//$r->addAPIClass('SorPeople', 'sorPeople');

// Attach auth handler
$r->addAuthenticationClass('ApiKeyAuth');

$r->handle();
