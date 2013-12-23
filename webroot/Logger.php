<?php
/**
 * This class handles logging
 *
 * @package    or-match
 * @since      0.9
 * @author     Benn Oshrin
 * @copyright  Copyright (c) 2013, University of California, Berkeley
 * @license    http://opensource.org/licenses/BSD-3-Clause BSD
 * @link       https://github.com/ucidentity/or-match
 */

class Logger {
  // Logging configuration
  protected $lconfig = null;
  
  /**
   * Construct new Logger
   *
   * @since 0.9
   * @param array $config "logging" configuration array as parsed from config file
   */
  
  public function __construct($config) {
    $this->lconfig = $config;
  }
  
  /**
   * Log an informational message
   *
   * @since 0.9
   * @param string $msg Message to log
   * @throws InvalidArgumentException
   */
  
  public function info($msg) {
    $str .= getmypid() . $msg . "\n";
    
    if($this->lconfig['method'] == 'file') {
      $str = date('d M Y h:i:s') . ": " . $str;
      error_log($str, 3, $this->lconfig['logfile']);
    } elseif($this->lconfig['method'] == 'syslog') {
      openlog('ormatch', LOG_PID, LOG_DAEMON);
      syslog(LOG_INFO, $str);
      closelog();
    } else {
      throw new InvalidArgumentException("Unknown logging method: " . $this->lconfig['method']);
    }
  }
}
