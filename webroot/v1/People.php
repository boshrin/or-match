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
   * Check the attributes for a candidate to see if the SORID matches the SORID of the request
   *
   * @since v0.9
   * @param Array  $candidate Array of attributes
   * @param String $sor       SOR label
   * @param String $sorid     SOR ID
   * @return Boolean true if $sor and $sorid match $candidate attributes, false otherwise
   */
  
  private function checkCandidateSorId($candidate, $sor, $sorid) {
    if(!empty($candidate['sor'])
       && $candidate['sor'] == $sor
       && !empty($candidate['identifiers'])) {
      // Walk through the identifiers looking for sorid
      
      foreach($candidate['identifiers'] as $i) {
        if($i['type'] == 'sor'
           && $i['identifier'] == $sorid) {
          return true;
        }
      }
    }
    
    return false;
  }
  
  /**
   * Accept a match request
   *
   * @since v0.9
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
   */

  public function request($sor, $sorid, $sorAttributes) {
    global $log;
    
    // First, check to see if we already have a row for this sor/sorid. If so,
    // this becomes an Update Attributes Request.
    
    $Match = new Match;
    
    $rec = $Match->sorRecord($sor, $sorid);
    
    if(!empty($rec)) {
      $log->info("Update attributes request received for " . $sor . "/" . $sorid);
      $Match->update($sor, $sorid, $sorAttributes);
      
      $result = new stdClass();
      ExtendedResponder::$result = $result;
      
      return $result;
    } else {
      return $this->performRequest($sor, $sorid, $sorAttributes);
    }
  }
  
  /**
   * Accept a search-only request
   *
   * @since v0.9
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
  
  /**
   * Internal handler to perform the match or search request
   *
   * @since v0.9
   * @param string  $sor             Label for requesting system of record
   * @param string  $sorid           SOR's identifier for match subject
   * @param array   $sorAttributes   {@from body} Subject match data
   * @param boolean $searchOnly      Whether this is a search-only (read only) request
   * @return stdClass                Result
   * @throws RestException
   */
  
  private function performRequest($sor, $sorid, $sorAttributes, $searchOnly = false) {
    global $config;
    global $dbh;
    global $log;
    
    $result = new stdClass();
    $retCode = 0;
    
    $log->info(($searchOnly ? "Search only request" : "Request")
               . " received for " . $sor . "/" . $sorid);
    
    $dbh->StartTrans();
    
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
          if(!empty($config['referenceid']['responsetype'])) {
            $result->identifiers[] = array(
              'type'       => $config['referenceid']['responsetype'],
              'identifier' => $referenceId
            );
          }
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
          
          if(!empty($config['referenceid']['responsetype'])) {
            $result->identifiers[] = array(
              'type'       => $config['referenceid']['responsetype'],
              'identifier' => $candidate[0]['id']
            );
          }
          
          // If this is a match against sor+sorid (which really should have been sent as
          // an attribute update) then we promote this to be an update, not an insert.
          
          $existingSorId = false;
          
          if(!empty($candidate[0]['attributes'][0])) {
            $existingSorId = $this->checkCandidateSorId($candidate[0]['attributes'][0], $sor, $sorid);
          }
          
          if(!$searchOnly) {
            if(!$existingSorId) {
              $Match->insert($sor, $sorid, $sorAttributes, $candidate[0]['id']);
            } else {
              $log->info("Promoted insert to update due to existing record for " . $sor . "/" . $sorid);
              $Match->update($sor, $sorid, $sorAttributes);
            }
          }
        } else {
          $dofuzzy = true;
        }
      } else {
        $dofuzzy = true;
      }
      
      if($dofuzzy) {
        // Fuzzy match
        
        // Check that none of the candidates match an existing sor+sorid, since
        // that should have really been passed as an attribute update request.
        // Since this is a fuzzy scenario, we'll throw a 409 conflict.
        
        foreach($candidates as $candidate) {
          if($this->checkCandidateSorId($candidate['attributes'][0], $sor, $sorid)) {
            $log->info("Converted fuzzy match to conflict due to existing match for " . $sor . "/" . $sorid);
            $retCode = 409;
            break;
          }
        }
        
        if($retCode != 409) {
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
    }
    catch(InvalidArgumentException $e) {
      $result->error = $e->getMessage();
      
      $retCode = 400;
      $dbh->FailTrans();
    }
    catch(RuntimeException $e) {
      $result->error = $e->getMessage();
      
      $retCode = 500;
      $dbh->FailTrans();
    }
    catch(Exception $e) {
      $result->error = $e->getMessage();
      
      $retCode = 500;
      $dbh->FailTrans();
    }
    
    $dbh->CompleteTrans();
    
    $log->info("Request completed, returning " . (($retCode > 0) ? $retCode : 200));
    
    ExtendedResponder::$result = $result;
   
    if($retCode > 0) {
      throw new RestException($retCode);
    }
    
    return $result;
  }
}