<?php
/**
 * This class accepts Reference ID management requests
 *
 * @package    or-match
 * @since      0.9
 * @author     Benn Oshrin
 * @copyright  Copyright (c) 2013, University of California, Berkeley
 * @license    http://opensource.org/licenses/BSD-3-Clause BSD
 * @link       https://github.com/ucidentity/or-match
 */

class ReferenceIds {
  /**
   * Obtain SOR Records request
   *
   * @since v0.9
   * @param string $referenceId     Reference ID
   * @return stdClass               Result
   * @throws RestException
   *
   * Adjust routing to map to request
   * @url GET {referenceId}
   * All functions in this class require auth
   * @access protected
   */

  public function request($referenceId) {
    global $config;
    global $log;
    
    $result = new stdClass();
    $retCode = 0;
    
    $log->info("Request SOR Records request received for " . $referenceId);
    
    try {
      $Match = new Match;
      
      $sorRecords = $Match->sorRecords($referenceId);
      
      if(count($sorRecords) == 0) {
        $retCode = 404;
      } else {
        $result->sorPeople = $sorRecords;
      }
    }
    catch(InvalidArgumentException $e) {
      $result->error = $e->getMessage();
      
      $retCode = 400;
    }
    catch(RuntimeException $e) {
      $result->error = $e->getMessage();
      
      $retCode = 500;
    }
    catch(Exception $e) {
      $result->error = $e->getMessage();
      
      $retCode = 500;
    }
    
    ExtendedResponder::$result = $result;
   
    if($retCode > 0) {
      throw new RestException($retCode);
    }
    
    return $result;
  }
}