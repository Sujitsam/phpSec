<?php
/**
      phpSec - A PHP security library
      Web:     https://github.com/xqus/phpSec

      Copyright (c) 2011 Audun Larsen <larsen@xqus.com>

  Permission is hereby granted, free of charge, to any person obtaining a copy
  of this software and associated documentation files (the "Software"), to deal
  in the Software without restriction, including without limitation the rights
  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
  copies of the Software, and to permit persons to whom the Software is
  furnished to do so, subject to the following conditions:

  The above copyright notice and this permission notice shall be included in
  all copies or substantial portions of the Software.

  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
  THE SOFTWARE.
 */

class phpsec {
  public static $_charset = 'utf-8';
  public static $_datadir = null;
  public static $_logdir  = null;
  public static $uid      = null; // User identifier.

  /* Constants. */
  const HASH_TYPE      = 'sha256';
  const SALT_INJECTION = 'before';

  /**
   * Autoload function to load required files when needed.
   */
  public static function load($class) {
    $basePath = dirname(__FILE__);
    $classes = array(
      'phpsecCache'   => 'phpsec.cache.php',
      'phpsecSession' => 'phpsec.session.php',
      'phpsecRand'    => 'phpsec.rand.php',
      'phpsecCrypt'   => 'phpsec.crypt.php',
    );

    if(isset($classes[$class])) {
      require_once $basePath.'/phpsec/'.$classes[$class];
    }
  }

  /**
   * Initialize the library.
   */
  public static function init() {
    /* First of all, register the autoloading function.
     * If we have one set from somewhere else, keep it. */
     $autoLoadFunctions   = spl_autoload_functions();
     $autoLoadFunctions[] = 'phpsec::load';
     foreach($autoLoadFunctions as $autoLoadFunction) {
       spl_autoload_register($autoLoadFunction);
     }

    /* Check write permissions to directories */
    if(!is_writeable(self::$_logdir)) {
      self::error('Log directory('.self::$_logdir.') not writeable');
    }
    if(!is_writeable(self::$_datadir)) {
      self::error('Data directory('.self::$_datadir.') not writeable');
    }

    /* Set the data dir for the cache class. */
    phpsecCache::$_datadir = self::$_datadir;

    /* Set the charset of the multibyte functions in PHP. */
    mb_internal_encoding(self::$_charset);
    mb_regex_encoding(self::$_charset);

    /* Register the custom session handler. */
    ini_set('session.save_handler', 'user');
    session_set_save_handler(
      'phpsecSession::open',
      'phpsecSession::close',
      'phpsecSession::read',
      'phpsecSession::write',
      'phpsecSession::destroy',
      'phpsecSession::gc'
    );

    /* Start a new session. */
    session_start();
    /* Regenerate the session ID and remove the old session to avoid session hijacking. */
    session_regenerate_id(true);

    /* Create a random token for each visitor and store it the users session.
       This is for example used to identify owners of cache data. */
    if(!isset($_SESSION['phpSec-uid'])) {
      self::$uid = self::genUid();
      $_SESSION['phpSec-uid'] = self::$uid;
    } else {
      self::$uid = $_SESSION['phpSec-uid'];
    }
  }

  /**
   * XSS filter. Returns a string that is safe to use on the page.
   * A string with variables is supplied to the function along with an
   * associative array defining tha values of the variables.
   * There are three types of variables:
   * %variables: HTML is stripped from the string
   * before it is inserted.
   * !variables: HTML and special characters is escaped from the string
   * before it is inserted.
   * @variables: Only HTML is escaped from the string. Special characters
   * is kept as is.
   *
   * @see https://sourceforge.net/apps/mediawiki/phpsec/index.php?title=Documentation#2.1_Using_the_XSS_filter
   *
   * @param str
   *   Base string. The string itself is not filtered in any way, but
   *   used to compose the filtered parts from the args array.
   *
   * @param args
   *   An associative array containing data to be filtered by the XSS filter.
   *   The array keys should be preceeded with %, ! or @ defining what filter
   *   to apply.
   */
  public static function f($str, $args = array()) {
    /* First, loop trough the args and apply the filters. */
    while(list($name, $data) = each($args)) {
      $safeData = false;
      $filterType = mb_substr($name, 0, 1);
      switch($filterType) {
        case '%':
          /* %variables: HTML is stripped from the string
             before it is in inserted. */
          $safeData = strip_tags($data);
          break;
        case '!':
          /* !variables: HTML and special characters is escaped from the string
             before it is in inserted. */
          $safeData = htmlentities($data, ENT_QUOTES, self::$_charset);
          break;
        case '@':
          /* @variables: Only HTML is escaped from the string. Special characters
             is kept as is. */
          $safeData = htmlspecialchars($data, ENT_NOQUOTES, self::$_charset);
          break;
        default:
          self::error('Unknown variable type', E_USER_NOTICE);
          break;
      }
      if($safeData !== false) {
        $str = str_replace($name, $safeData, $str);
      }
    }
    return $str;
  }

  /**
   * Provides the library a simple way of reporting errors to the developer using PHPs error
   * handler.
   *
   * @param string
   *   String containing the error message
   *
   * @param constant
   *   Error level (optional).
   *   If none is specified PHPSEC_E_WARN is used.
   */
  private static function error($msg, $level = E_USER_WARNING) {
    $callee = next(debug_backtrace());
    trigger_error($msg.'. (Called from <strong>'.$callee['file'].' line '.$callee['line'].'</strong>)', $level);
    /* TODO: Write error to file. */
  }

  /**
   * Write an entry to a log.
   *
   * @param type
   *   Specify the type of the logentry. This will be a part of the filname.
   *
   * @param msg
   *   The log message itself.
   *
   * @param level
   *   Error level (optional). Should be either debug, notice, warn or error.
   *   If none is specified warn is used.
   */
  public static function log($type, $msg, $level = 'warn') {
    $fileName = self::$_logdir.'/log_'.$type;
    /* TODO: Add some more information when writing log entry. */
    $line = date('c').' - '.$level.' - '.$msg;

    $fp = fopen($fileName, 'a');
    if($fp !== false) {
      if(flock($fp, LOCK_EX)) {
        fwrite($fp, $line."\n");
        flock($fp, LOCK_UN);
        fclose($fp);
      } else {
        self::error('Could not lock logfile');
      }
    }
  }

  /**
   * Returns a unique identifier in the format spsecified in
   * OpenID Authentication 2.0 protocol.
   * For example: 2005-05-15T17:11:51ZUNIQUE
   * This function is used to generate all unique tokens used by
   * phpSec.
   * @see http://openid.net/specs/openid-authentication-2_0.html
   *
   * @param length
   *   The total length of the uid. Must be between 25 and 80 characters.
   */
  public static function genUid($length = 50) {
    if($length < 25 || $length > 80) {
      self::error('Length should be between 25 and 80');
      return false;
    }
    $timeStamp = gmdate('Y-m-d\TH:i:s\Z');
    $randLength = $length-strlen($timeStamp);
    return $timeStamp.substr(hash(self::HASH_TYPE, uniqid(null, true)), 0, $randLength);
  }

  /**
   * Generate and save a one-time-token for a form. Used to protect against
   * CSRF attacks.
   *
   * @param name
   *   Name of the form to generate a token for.
   *
   * @param ttl
   *   How long the token should be valid in seconds.
   *
   * @return string
   *   The token to supply with the form data.
   */
  public static function getToken($name, $ttl = 3600) {
    $token = self::genUid();
    /* Save the token to the cahce. */
    phpsecCache::cacheSet('token-'.$name, $token, $ttl);
    return $token;
  }

  /**
   * Validate a one-time-token generated with setToken();
   * This function should be called before accepting data from a user-submitted form.
   * @see setToken();
   *
   * @param name
   *   Name of the form to validate the token for.
   *
   * @return boolean
   *   Returns true if the token is valid. Returns false otherwise.
   */
  public static function validToken($name, $token) {
    $cacheToken = phpsecCache::cacheGet('token-'.$name);
    /* Check if the provided token matches the token in the cache. */
    if($cacheToken == $token) {
      /* Remove the token from the cahche so it can't be reused. */
      phpsecCache::cacheRem('token-'.$name);
      return true;
    }
    return false;
  }

  /**
   * Create a hashed version of a password, safe for storage in a database.
   * This function return a json encodeed array that can be stored directly
   * in a database. The array has the following layout:
   * array(
   *   'hash'      => The hash created from the password and a salt.
   *   'salt'      => The salt that was used along with the password to create the hash.
   *   'algo'      => The hashing algorythm used.
   *   'injection' => How the salt was injected into the password.
   * )
   * The following injection methods exists:
   * before: The salt is placed diectly in front of the password, without using any
   * seperation characters.
   * after: The salt is placed directly after the password without any seperation
   * characters.
   *
   * @param password
   *   The password to hash.
   *
   * @return string
   *   Returns a json encoded array containing the password hash, salt and
   *   some meta data.
   */
  public static function pwHash($password) {
    $salt     = self::genUid();
    $injected = self::pwInject($password, $salt, self::SALT_INJECTION);
    $hash     = hash(self::HASH_TYPE, $injected);

    $return = array(
      'hash'      => $hash,
      'salt'      => $salt,
      'algo'      => self::HASH_TYPE,
      'injection' => self::SALT_INJECTION,
    );
    return json_encode($return);
  }

  /**
   * Validate a user-supplied  password against a stored password saved
   * using the pwHash() method.
   *
   * @param password
   *   The password supplied by the user in the login form.
   *
   * @param dbPassword
   *   The json string fetched from the database, in the exact format
   *   as created by pwHash().
   *
   * @return boolean
   *   True on password match, false otherwise.
   */
  public static function pwCheck($password, $dbPassword) {
    /**
     * Unserialize registerd password array and validate it to ensure
     * we got a valid array.
     */
    $data = json_decode($dbPassword, true);
    if(isset($data['injection']) && sizeof($data) == 4) {
      /**
       * Ok, e are pretty sure this is good stuff. Now inject the salt
       * into the user supplied password, to see if it matches the registerd
       * data from $dbPassword.
       */
      $pwInjected = self::pwInject($password, $data['salt'], $data['injection']);
      /* Create a hash and see if it matches. */
      if(hash($data['algo'], $pwInjected) == $data['hash']) {
        return true;
      }
    } else {
      /* Invalid array supplied. */
      self::error('Invalid data supplied. Expected serialized array as returned by pwHash()');
    }
    return false;
  }

  /**
   * Inject a salt into a password to create the string to be hashed.
   *
   * @param password
   *   Plain-text password.
   *
   * @param salt
   *   Well, the salt to inject into the password.
   *
   * @param injection
   *   The method used to inject the salt. @see pwHash().
   *
   * @return string
   *   Returns the salted password, ready to be hashed.
   *
   */
  private static function pwInject($password, $salt, $injection) {
    switch($injection) {
      case 'before':
        $injected = $salt.$password;
        break;
      case 'after':
        $injected = $password.$salt;
        break;
      default:
        self::error('Invalid salt injection method');
        return false;
    }
    return $injected;
  }
}
