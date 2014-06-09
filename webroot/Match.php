<?php
/**
 * This class implements the configured match logic.
 *
 * @package    or-match
 * @since      0.9
 * @author     Benn Oshrin
 * @copyright  Copyright (c) 2013-4, University of California, Berkeley
 * @license    http://opensource.org/licenses/BSD-3-Clause BSD
 * @link       https://github.com/ucidentity/or-match
 */

class Match {
  private $dbh = null;
  private $requestAttributes = null;
  
  /**
   * Perform a match and return candidates. This function should be called from within a transaction.
   *
   * @since  0.9
   * @param  string $sor         Label for requesting System of Record
   * @param  string $sorid       SoR identifier for search request
   * @param  array  $attributes  Attributes provided for searching
   * @return array  Match candidates, as an array of the following form:
   *                $r[$i]['attributes'][$attrname] = attribute value
   *                      ['confidence'] = 0 - 100 (100 = most confident; 90+ = exact match)
   *                      ['id'] = Reference ID for match candidate
   * @throws InvalidArgumentException
   * @throws RuntimeException
   */
  
  public function findCandidates($sor, $sorid, $attributes) {
    global $config;
    global $dbh;
    global $matchConfig;
    
    $candidates = array();
    
    // Make a copy of the requested attributes, and collate sor/sorid
    $this->requestAttributes = $attributes;
    $this->requestAttributes['sor'] = $sor;
    $this->requestAttributes['sorid'] = $sorid;
    
    try {
      $candidates = $this->searchDatabase('canonical');
      
      if(empty($candidates)) {
        $candidates = $this->searchDatabase('potential');
      }
    }
    catch(InvalidArgumentException $e) {
      throw new InvalidArgumentException($e->getMessage());
    }
    catch(RuntimeException $e) {
      throw new RuntimeException($e->getMessage());
    }
    catch(Exception $e) {
      throw new RuntimeException($e->getMessage());
    }
    
    return $candidates;
  }
  
  /**
   * Look through the inbound search request for the value provided for the specified attribute
   *
   * @since  0.9
   * @param  string $attr Search attribute to find value for
   * @return string Request value for $attr (including, potentially, an empty string) or null if not found
   */
  
  private function findRequestedAttrValue($attr) {
    global $matchConfig;
    global $log;
    
    // $attr is the configuration label. Map it to the wire name.
    $wireAttribute = null;
    $grouping = null;
    $value = null;
    
    if(!empty($matchConfig['attributes'][$attr]['attribute'])) {
      $wireAttribute = $matchConfig['attributes'][$attr]['attribute'];
      
      if(!empty($matchConfig['attributes'][$attr]['group'])) {
        $grouping = $matchConfig['attributes'][$attr]['group'];
      }
        
      switch($wireAttribute) {
        case 'identifier:national':
        case 'identifier:sor-affiliate':
        case 'identifier:sor-employee':
        case 'identifier:sor-student':
          $n = explode(':', $wireAttribute, 2);
          // Need to walk identifiers to find right type
          foreach($this->requestAttributes['identifiers'] as $xi) {
            if(!empty($xi['type']) && $xi['type'] == $n[1]) {
              // We've found the matching identifier type
              if(!empty($xi['identifier'])) {
                $value = $xi['identifier'];
              }
              break;
            }
          }
          break;
        case 'name:family':
        case 'name:given':
          $n = explode(':', $wireAttribute, 2);
          // Need to walk names to find right type
          foreach($this->requestAttributes['names'] as $xi) {
            if(!empty($xi['type']) && $xi['type'] == $grouping) {
              // We've found the matching name type
              if(!empty($xi[ $n[1] ])) {
                $value = $xi[ $n[1] ];
              }
              break;
            }
          }
          break;
        case 'sor':
          $value = $this->requestAttributes['sor'];
          break;
        case 'identifier:sor':
          $value = $this->requestAttributes['sorid'];
          break;
        default:
          $value = $this->requestAttributes[$wireAttribute];
          break;
      }
    }
    
    if($value) {
      // If nullequivalents and $value is only spaces, zeroes and punctuation,
      // return null
      
      if(!isset($matchConfig['attributes'][$attr]['nullequivalents'])
         || $matchConfig['attributes'][$attr]['nullequivalents']) {
        // Our actual test is that a non-zero number of any letter is in $value
        if(preg_match('/[1-9[:alpha:]]/', $value)) {
          return $value;
        } else {
          $log->info("Ignoring null equivalent value '" . $value . "' for " . $attr);
        }
      }
    }
    
    return null;
  }
  
  /**
   * Generate a version 4 UUID. Based on http://www.php.net/manual/en/function.uniqid.php#94959
   *
   * @since  0.9
   * @return string UUID
   */   
  
  protected static function generatev4uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
      
      // 32 bits for "time_low"
      mt_rand(0, 0xffff), mt_rand(0, 0xffff),
      
      // 16 bits for "time_mid"
      mt_rand(0, 0xffff),
      
      // 16 bits for "time_hi_and_version",
      // four most significant bits holds version number 4
      mt_rand(0, 0x0fff) | 0x4000,
      
      // 16 bits, 8 bits for "clk_seq_hi_res",
      // 8 bits for "clk_seq_low",
      // two most significant bits holds zero and one for variant DCE1.1
      mt_rand(0, 0x3fff) | 0x8000,
      
      // 48 bits for "node"
      mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
  }
  
  /**
   * Insert a record into the match grid and obtain a new reference identifier
   *
   * @since  0.9
   * @param  string  $sor         Label for requesting System of Record
   * @param  string  $sorid       SoR identifier for search request
   * @param  array   $attributes  Attributes provided for searching
   * @param  string  $referenceId Reference ID, or null if unknown
   * @param  boolean $assign      If no reference ID, assign a new one if true (else pending resolution)
   * @return Reference ID if $assign is true, or the row ID (for use as a match request identifier) if both $assign and $referenceId are false/null
   * @throws RuntimeException
   */
  
  public function insert($sor, $sorid, $sorAttributes, $referenceId=null, $assign=false) {
    global $dbh;
    global $config;
    global $matchConfig;
    global $log;
    
    $insertedReferenceId = $referenceId;
    $rowid = null;
    $requestTime = gmdate('Y-m-d H:i:s');
    $resolutionTime = null;
    
    // First, see if there is already an entry in matchgrid for sor+sorid
    
    $sql = "SELECT *
            FROM   matchgrid
            WHERE  sor=" . $dbh->Param('a') . "
            AND    sorid=" . $dbh->Param('b');
    
    $stmt = $dbh->Prepare($sql);
    $row = $dbh->GetRow($stmt, array($sor, $sorid));
    
    if(!empty($row['id'])) {
      // A row was returned
      
      if(!empty($row['reference_id'])) {
        // An existing record was found. This should already have been caught
        // and promoted to an update() by the calling code.
        
        throw new RuntimeException("Existing record found during insert (calling code should promote to update)");
      } else {
        // A previous request was submitted and generated a fuzzy match.
        // Replace it.
        
        $log->info("Existing unreconciled record found for " . $sor . "/"
                   . $sorid . ", replacing");
        
        $sql = "DELETE FROM matchgrid
                WHERE  sor=" . $dbh->Param('a') . "
                AND    sorid=" . $dbh->Param('b');
        
        $stmt = $dbh->Prepare($sql);
        $dbh->Execute($stmt, array($sor, $sorid));
      }
    }
    
    if($assign) {
      // Assign an identifier according to our configuration
      
      switch($config['referenceid']['method']) {
        case 'sequence':
          // Obtain the next integer
          // Note GenID will create the sequence if it doesn't already exist
          $insertedReferenceId = $dbh->GenID('reference_id_seq');
          break;
        case 'uuid':
          // Generate a UUID
          $insertedReferenceId = $this->generatev4uuid();
          break;
        default:
          throw new RuntimeException("Unknown reference id assignment method: " . $config['referenceid']['method']);
          break;
      }
    }
    
    if($referenceId || $assign) {
      // Generate a timestamp
      $resolutionTime = $requestTime;
    }
    
    $sql = "INSERT INTO matchgrid
            (sor,
             sorid,
             reference_id,
             request_time,
             resolution_time";
    
    $vals = array($sor, $sorid, $insertedReferenceId, $requestTime, $resolutionTime);
    
    // Next process the configured attributes (except sor/sorid, already done)
    
    foreach(array_keys($matchConfig['attributes']) as $a) {
      if($matchConfig['attributes'][$a]['column'] == 'sor'
         || $matchConfig['attributes'][$a]['column'] == 'sorid') {
        continue;
      }
      
      $v = $this->findRequestedAttrValue($a);
      
      if($v) {
        // Skip nulls (at least for the moment)
        $sql .= ", " . $matchConfig['attributes'][$a]['column'];
        $vals[] = $v;
      }
    }
    
    $sql .= ")
            VALUES (" . str_repeat("?,", count($vals)-1) . "?)
            RETURNING id";
    
    $stmt = $dbh->Prepare($sql);
    
    // XXX this may not work for databases other than Postgres (known not to work for Oracle)
    $rowid = $dbh->GetOne($stmt, $vals);
    
    if(!$referenceId && !$assign) {
      return $rowid;
    } else {
      return $insertedReferenceId;
    }
  }
  
  /**
   * Map the fields returned by the database to the wire(sque) representation
   *
   * @since  0.9
   * @param  array $dbFields Attributes as returned by the database
   * @param  array Non-flat array suitable for converting to wire response
   */
  
  private function mapResponseFields($dbFields) {
    global $matchConfig;
    
    $r = array();
    
    // Interim array
    $names = array();
    
    foreach(array_keys($dbFields) as $f) {
      if(!$dbFields[$f]) {
        // Skip empty values
        continue;
      }
      
      if(!empty($matchConfig['dbattributes'][$f]['attribute'])) {
        $a = $matchConfig['dbattributes'][$f]['attribute'];
        
        // If there's a colon in the attribute name, we probably need to convert
        // to a hierarchical representation
        
        if(strchr($a, ':')) {
          $n = explode(':', $a, 2);
          
          // Generally attribute name goes from singular to plural... cheat here
          $ns = $n[0] . "s";
          
          if($ns == 'names') {
            // This one is tricky since we're looking at a field at a time, and the
            // result is not keyed. We'll use an interim array, keyed on type.
            
            $g = $matchConfig['dbattributes'][$f]['group'];
            
            $names[ $g ][ $n[1] ] = $dbFields[$f];
          } else {
            $s = array(
              // Value is keyed on singular name, eg "identifier"
              $n[0]  => $dbFields[$f],
              'type' => $n[1]
            );
            
            // There can be more than one
            $r[ $ns ][] = $s;
          }
        } else {
          // Simple copy
          $r[ $a ] = $dbFields[$f];
        }
      }
      // XXX else silently fail on columns in the database that don't map...
      // we could do something for (eg) request_time
    }
    
    // Finish mapping names now that we have all the attributes
    
    foreach(array_keys($names) as $g) {
      $na = $names[$g];
      $na['type'] = $g;
      
      $r['names'][] = $na;
    }
    
    return $r;
  }
  
  /**
   * Search for match candidates in the database
   *
   * @since  0.9
   * @param  string $searchType Type of search to execute: "canonical" or "potential"
   * @return array  Match candidates
   * @throws InvalidArgumentException
   * @throws RuntimeException
   */
  
  private function searchDatabase($searchType) {
    global $config;
    global $dbh;
    global $log;
    global $matchConfig;
    $candidates = array();
    
    $log->info("Looking for " . $searchType . " match(es)");
    
    foreach(array_keys($matchConfig['confidences'][$searchType]) as $ck) {
      $log->info("Attempting match using " . $searchType . " rule '" . $ck . "'");
      
      $searchAttrs = array();
      
      if($searchType == 'canonical') {
        // Specify the search rule for each attribute, which will always be exact
        
        foreach($matchConfig['confidences'][$searchType][$ck] as $a) {
          $searchAttrs[$a] = 'exact';
        }
      } else {
        // Search rules are specified in the configuration
        $searchAttrs = $matchConfig['confidences'][$searchType][$ck];
      }
      
      // Build a query for the specified attributes
      
      $sql = "SELECT *
              FROM   matchgrid
              WHERE  reference_id IS NOT NULL
              ";
      
      $vals = array();
      
      foreach(array_keys($searchAttrs) as $attr) {
        $rule = $searchAttrs[$attr];
        $searchVal = $this->findRequestedAttrValue($attr);
        
        if(!$searchVal) {
          $log->info("No value found in request payload for attribute '" . $attr . "', skipping this set");
          continue 2;
        }
        
        // Make sure this attribute is specified for the requested search type
        
        if(!isset($matchConfig['attributes'][$attr]['search'][$rule])
           || !($matchConfig['attributes'][$attr]['search'][$rule])) {
          throw new InvalidArgumentException("Attribute '" . $attr . "' is not configured for '" . $rule . "' search");
        }
        
        $sql .= " AND ";
        
        // Assemble an appropriate clause according to the configuration, and also
        // adjust $searchVal as needed
        
        $select = "";
        
        // Case insensitive?
        if(isset($matchConfig['attributes'][$attr]['casesensitive'])
           && !$matchConfig['attributes'][$attr]['casesensitive']) {
          $select .= "lower(";
          $searchVal = strtolower($searchVal);
        }
        
        // Strip out non-alphanumeric characters?
        if(isset($matchConfig['attributes'][$attr]['alphanum'])
           && $matchConfig['attributes'][$attr]['alphanum']) {
          $select .= "regexp_replace(";
          $searchVal = preg_replace('/[^A-Za-z0-9]/', '', $searchVal);
        }
        
        // The actual column name
        $select .= $matchConfig['attributes'][$attr]['column'];
        
        // Close any statement we opened
        if(isset($matchConfig['attributes'][$attr]['alphanum'])
           && $matchConfig['attributes'][$attr]['alphanum']) {
          $select .= ", '[^A-Za-z0-9]', '', 'g')";
        }
        
        if(isset($matchConfig['attributes'][$attr]['casesensitive'])
           && !$matchConfig['attributes'][$attr]['casesensitive']) {
          $select .= ")";
        }
        
        $vals[] = $searchVal;
        
        switch($rule) {
          case 'distance':
            $sql .= "levenshtein_less_equal("
                 . $select
                 . ",?,"
                 . $matchConfig['attributes'][$attr]['search'][$rule]
                 . ") < "
                 . ($matchConfig['attributes'][$attr]['search'][$rule] + 1);
            break;
          case 'exact':
            $sql .= $select . "=?";
            break;
          case 'soundex':
            throw new RuntimeException("Not implemented (soundex)");
            break;
          case 'substr':
            // Pull out the from and for parameters
            $a = explode(",", $matchConfig['attributes'][$attr]['search'][$rule], 2);
            $sql .= "substring("
                 . $select
                 . " from "
                 . $a[0] . " for " . $a[1]
                 . ") = substring(? from "
                 . $a[0] . " for " . $a[1]
                 . ")";
            break;
          default:
            throw new InvalidArgumentException("Unknown search rule: " . $rule);
            break;
        }
      }
      
      // XXX Make sure all required attributes were defined
      
      // Try the query
      
      if(isset($config['logging']['trace']['sql']) && $config['logging']['trace']['sql']) {
        $log->info("QUERY: " . $sql . "; PARAMS: " . join(",", $vals), "sql");
      }
      
      $stmt = $dbh->Prepare($sql);
      
      $r = $dbh->Execute($stmt, $vals);
      
      while(!$r->EOF) {
        $confidence = 0;
        
        if($searchType == 'canonical') {
          $confidence = 95;
          
          // Check to see if any invalidating attribute contradicts a value in the response
          
          foreach(array_keys($matchConfig['attributes']) as $attr) {
            if(isset($matchConfig['attributes'][$attr]['invalidates'])
               && $matchConfig['attributes'][$attr]['invalidates']) {
              $requested = $this->findRequestedAttrValue($attr);
              
              if($requested // Make sure value is not null
                 && ($requested != $r->fields[ $matchConfig['attributes'][$attr]['column'] ])) {
                $log->info("Candidate reference ID " . $r->fields['reference_id'] . " downgraded to potential due to invalidating attribute " . $attr);
                $confidence = 85;
              }
            }
          }
          
          // XXX should we check that the other attributes match and if not return 409?
          // XXX perhaps make this configurable
        } else {
          $confidence = 85;
        }
        
        $log->info("Found matching candidate with reference ID " . $r->fields['reference_id']);
        
        $referenceId = $r->fields['reference_id'];
        
        if(isset($candidates[$referenceId])) {
          // Already have an entry for this person, so we're adding another set of SOR attributes.
          // However, first make sure we didn't already get this set of attributes from a previous
          // query.
          
          $skip = false;
          
          $sor = $r->fields['sor'];
          $sorid = $r->fields['sorid'];
          
          foreach($candidates[$referenceId]['attributes'] as $a) {
            if($a['sor'] == $sor) {
              // Look for the correct identifier entry
              
              foreach($a['identifiers'] as $i) {
                if($i['type'] == 'sor' && $i['identifier'] == $sorid) {
                  $skip = true;
                  break 3;
                }
              }
            }
          }
          
          if(!$skip) {
            $candidates[$referenceId]['attributes'][] = $this->mapResponseFields($r->fields);
          }
        } else {
          $c = array();
          $c['id'] = $r->fields['reference_id'];
          // Add a confidence score
          // XXX implement a better version
          /* XXX
           * first pass at confidence score-
           * (1) start with 100
           * (2) subtract 1 for each exact rule evaluated, minimum 90
           * (3) drop to 90
           * (4) subtract 1 for each fuzzy rule evaluated, minimum 80
           * (5) for each attribute that fuzzy matches subtract at least 1 point (perhaps distance score deducts 1 or 2 accordingly?)
           * XXX should there be optional match attributes that increase the match score if they match?
           */
          $c['confidence'] = $confidence;
          $c['attributes'][] = $this->mapResponseFields($r->fields);
          
          $candidates[$referenceId] = $c;
        }
        
        $r->MoveNext();
      }
    }
    
    // For each candidate, check that we've pulled all relevant SOR data. (It's plausible reference IDs
    // were linked for multiple records that wouldn't otherwise have been pulled as candidates.)
    
    foreach(array_keys($candidates) as $k) {
      $sql = "SELECT *
              FROM   matchgrid
              WHERE  reference_id=?";
      
      $stmt = $dbh->Prepare($sql);
      
      $r = $dbh->Execute($stmt, array($k));
      
      while(!$r->EOF) {
        // Basically the same logic as above
        $skip = false;
        
        $sor = $r->fields['sor'];
        $sorid = $r->fields['sorid'];
        
        foreach($candidates[$k]['attributes'] as $a) {
          if($a['sor'] == $sor) {
            // Look for the correct identifier entry
            
            foreach($a['identifiers'] as $i) {
              if($i['type'] == 'sor' && $i['identifier'] == $sorid) {
                $skip = true;
                break 2;
              }
            }
          }
        }
        
        if(!$skip) {
          $candidates[$k]['attributes'][] = $this->mapResponseFields($r->fields);
        }
        
        $r->MoveNext();
      }
    }
    
    return $candidates;
  }
  
  /**
   * Obtain the SOR Records associated with a reference identifier
   *
   * @since  0.9
   * @param  string  $referenceId Reference ID
   * @return Array   Array of SOR data (empty if no records found)
   */
  
  public function sorRecords($referenceId) {
    global $dbh;
    $ret = array();
    
    $sql = "SELECT * FROM matchgrid WHERE reference_id=?";
    
    $stmt = $dbh->Prepare($sql);
    
    $r = $dbh->Execute($stmt, array($referenceId));
      
    while(!$r->EOF) {
      $ret[] = $this->mapResponseFields($r->fields);
      
      $r->MoveNext();
    }
    
    return $ret;
  }
  
  /**
   * Update matchgrid based on a provided SOR and SORID with new attributes. This does not perform rematching.
   *
   * @since  0.9
   * @param  string  $sor         Label for requesting System of Record
   * @param  string  $sorid       SoR identifier for search request
   * @param  array   $attributes  Attributes provided for searching
   * @return string  Reference ID of record (which for now will always be what it originally was)
   * @todo   Offer a rematch option?
   */
  
  public function update($sor, $sorid, $sorAttributes) {
    global $dbh;
    global $matchConfig;
    
    $sql = "UPDATE matchgrid
            SET ";
    
    $vals = array();
    
    // Process the configured attributes (except sor/sorid, which are handled specially)
    
    $comma = false;
    
    foreach(array_keys($matchConfig['attributes']) as $a) {
      if($matchConfig['attributes'][$a]['column'] == 'sor'
         || $matchConfig['attributes'][$a]['column'] == 'sorid') {
        continue;
      }
      
      // After the first attribute we need a comma
      if($comma) { $sql .= ","; }
      
      $v = $this->findRequestedAttrValue($a);
      
      if($v) {
        $sql .= $matchConfig['attributes'][$a]['column'] . "=?";
        $vals[] = $v;
      } else {
        $sql .= $matchConfig['attributes'][$a]['column'] . "=NULL";
      }
      
      $comma = true;
    }
    
    $sql .= "
             WHERE sor=?
             AND   sorid=?
             RETURNING id";
    
    $vals[] = $sor;
    $vals[] = $sorid;
    
    $stmt = $dbh->Prepare($sql);
    
    // XXX this may not work for databases other than Postgres (known not to work for Oracle)
    $rowid = $dbh->GetOne($stmt, $vals);
    
    return $rowid;
  }
}
