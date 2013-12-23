<?php
/**
 * This class accepts ID Match requests
 *
 * @package    or-match
 * @since      0.9
 * @author     Benn Oshrin
 * @copyright  Copyright (c) 2013, University of California, Berkeley
 * @license    http://opensource.org/licenses/BSD-3-Clause BSD
 * @link       https://github.com/ucidentity/or-match
 */

class People {
  /**
   * Accept a match request
   *
   * @since v0.1
   * @param string $sor             Label for requesting system of record
   * @param string $sorid           SOR's identifier for match subject
   * @param array $sorAttributes    {@from body} Subject match data
   * @return stdClass               Result
   * @throws RestException
   *
   * Adjust routing to map to request
   * @url PUT {sor}/{sorid}
   * All functions in this class require auth
   * @access protected
   * @todo XXX resolve fuzzy match
   * @todo XXX update match attributes
   */

  public function request($sor, $sorid, $sorAttributes) {
    return $this->performRequest($sor, $sorid, $sorAttributes);
  }
  
  /**
   * Accept a search-only request
   *
   * @since v0.1
   * @param string $sor             Label for requesting system of record
   * @param string $sorid           SOR's identifier for match subject
   * @param array $sorAttributes    {@from body} Subject match data
   * @return stdClass               Result
   * @throws RestException
   *
   * Adjust routing to map to request
   * @url POST {sor}/{sorid}
   * All functions in this class require auth
   * @access protected
   */
  
  public function search($sor, $sorid, $sorAttributes) {
    return $this->performRequest($sor, $sorid, $sorAttributes, true);
  }
  
  private function performRequest($sor, $sorid, $sorAttributes, $searchOnly = false) {
    global $config;
    global $log;
    
    $result = new stdClass();
    $retCode = 0;
    
    $log->info("Request received for " . $sor . "/" . $sorid);
    
    try {
      $Match = new Match;
      $dofuzzy = false;
      
      $candidates = $Match->findCandidates($sor, $sorid, $sorAttributes);
      
      if(empty($candidates)) {
        // No match
        
        if($searchOnly) {
          $retCode = 404;
        } else {
          $referenceId = $Match->insert($sor, $sorid, $sorAttributes, null, true);
          
          $result->referenceId = $referenceId;
          $retCode = 201;
        }
      } elseif(count($candidates) == 1) {
        // Take a look at the confidence level to determine the result code
        
        $candidate = array_values($candidates);
        
        if($candidate[0]['confidence'] >= 90) {
          // Exact match
          
          $result->referenceId = $candidate[0]['id'];
//        $result->confidence = $candidate[0]['confidence'];
//        $result->attributes = $candidate[0]['attributes'];
          
          // XXX If this is a match against sor+sorid (which really should have been
          // sent as an attribute update) then we should do an update, not an insert.
          
          if(!$searchOnly) {
            $Match->insert($sor, $sorid, $sorAttributes, $candidate[0]['id']);
          }
        } else {
          $dofuzzy = true;
        }
      } else {
        $dofuzzy = true;
      }
      
      if($dofuzzy) {
        // Fuzzy match
        
        if($searchOnly
           || (isset($config['sors'][$sor]['resolution'])
               && $config['sors'][$sor]['resolution'] == 'interactive')) {
          // $candidates is keyed on reference ID, but we don't need that for the wire
          $result->candidates = array_values($candidates);
          
          $retCode = 300;
        } else {
          // Use the newly inserted row ID as the match request ID
          
          $result->matchRequest = $Match->insert($sor, $sorid, $sorAttributes);
          $retCode = 202;
        }
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