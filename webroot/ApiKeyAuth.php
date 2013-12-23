<?php
use Luracast\Restler\iAuthenticate;

/**
 * Simple basic auth user/key authentication
 *
 * @package    or-match
 * @since      0.9
 * @author     Benn Oshrin
 * @copyright  Copyright (c) 2013, University of California, Berkeley
 * @license    http://opensource.org/licenses/BSD-3-Clause BSD
 * @link       https://github.com/ucidentity/or-match
 */

class ApiKeyAuth implements iAuthenticate {
  /**
   * Authenticate/authorize a request (Restler expected method)
   *
   * @since 0.9
   */
  
  function __isAllowed() {
    global $dbh;
    global $log;
    global $r;
    
    // We must have a username and password to continue
    
    if(empty($_SERVER['PHP_AUTH_USER']) || empty($_SERVER['PHP_AUTH_PW'])) {
      $log->info("Authentication failed: Username or API Key not provided");
      return false;
    }
    
    $sor = (!empty($r->apiMethodInfo->arguments[0]) ? $r->apiMethodInfo->arguments[0] : "*");
    
    // Verify user/key
    
    $sql = "SELECT COUNT(*)
            FROM   matchauth
            WHERE  apiuser=" . $dbh->Param('a') . "
            AND    apikey=" . $dbh->Param('b') . "
            AND    sor IN (" . $dbh->Param('c') . ",'*')";
    
    try {
      $stmt = $dbh->Prepare($sql);
      
      $count = $dbh->GetOne($stmt, array($_SERVER['PHP_AUTH_USER'],
                                         $_SERVER['PHP_AUTH_PW'],
                                         $sor));
    }
    catch(Exception $e) {
      $log->info("Authentication failed: " . $e->getMessage());
      return false;
    }
    
    if($count != 1) {
      $log->info("Authentication denied");
      return false;
    }
    
    // If we made it this far, permission granted (and set the user name)
    $this->authUser = $_SERVER['PHP_AUTH_USER'];
    
    $log->info("Authenticated '" . $this->authUser . "' via apikey");
    
    return true;
  }
}
