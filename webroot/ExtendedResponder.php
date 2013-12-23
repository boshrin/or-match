<?php

/**
 * Extended Responder that can generate a message with a non-200 response
 *
 * @package    or-match
 * @since      0.9
 * @license    http://opensource.org/licenses/BSD-3-Clause BSD
 * @link       https://github.com/ucidentity/or-match
 * @todo       This should really be part of Restler distribution
 * 
 * reference: http://stackoverflow.com/questions/13107318/how-can-i-return-a-data-object-when-throwing-a-restexception
 */

use \Luracast\Restler\Responder;
use \Luracast\Restler\Defaults;

class ExtendedResponder extends Responder
{
  public static $result = null;

  public function formatError($statusCode, $message)
  {
    if(!empty(self::$result)) {
      return self::$result;
    } else {
      return array();
    }
  }
}

Defaults::$responderClass = 'ExtendedResponder';