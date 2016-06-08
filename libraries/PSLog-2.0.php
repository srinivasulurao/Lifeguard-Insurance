<?php
/**
 * Main Logger Library. Version 2.0 adds new features that are API incompatible with PSLog v1+.
 * Version 2.0 may be used simultaneously on the same site, but scripts should only leverage one
 * version of the logger.
 *
 * This library allows for logging to the RightNow database, log files, or both.  Please review
 * the index.html file in the API folder for complete PSLog api and configuration documentation.
 *
 * Project Location: http://src.rightnow.com/spaces/logging
 * Blob Hash: $Id: 49599e18306f32174fb49be02b6d7c361ecf4de0 $
 *
 * @link http://http://src.rightnow.com/spaces/logging
 * @author Ben Johns PSLog 1.0
 * @author Scott Harwell <scott.harwell@oracle.com> PSLog 1.1 and higher
 * @version 2.0.6
 */

namespace PS\Log\v2;

if (!defined('BASEPATH') && !defined('CUSTOM_SCRIPT')) {
  exit('No direct script access allowed');
}

require_once get_cfg_var("doc_root") . "/ConnectPHP/Connect_init.php";

/**
 * Extend the base exception class for clearer logging.
 */
class LogException extends \Exception
{

  /**
   * Create an automagic __toString method.
   * @return string
   */
  public function __toString() {
    return "{$this->getFile()} - Line ({$this->getLine()}) Code ({$this->getCode()}) - {$this->getMessage()}\n{$this->getTraceAsString()}";
  }

}

/**
 * Base class for common logger object properties and methods
 */
abstract class LogBase {
  /**
   * Holds the connected namespace used by ConnectPHP
   * @var string
   */
  public static $connectNamespace;

  /**
   * Method to autoset the connect namespace.
   * @throws \PS\Log\v2\LogException
   */
  public static function autoSetNamespace() {
    //Try to set the connect namespace automatically if namespace not provided
    if (!class_exists((static::$connectNamespace) . "\\ConnectAPI")) {
      if (class_exists("RightNow\\Connect\\v1_2\\ConnectAPI")) {
        static::$connectNamespace = "RightNow\\Connect\\v1_2";
      }
      else if (class_exists("RightNow\\Connect\\v1_1\\ConnectAPI")) {
          static::$connectNamespace = "RightNow\\Connect\\v1_1";
        }
      else if (class_exists("RightNow\\Connect\\v1\\ConnectAPI")) {
          static::$connectNamespace = "RightNow\\Connect\\v1";
        }
    }

    if (!class_exists((static::$connectNamespace) . "\\ConnectAPI")) {
      throw new LogException("Unable to set connect namespace.");
    }
  }

}

/**
 * A class that menu items can inherit from.
 * This is used in place of a trait that would be more appropriate for horizontal inheritance
 * until Rnt moves to PHP 5.4
 */
abstract class MenuItem extends LogBase
{

  /**
   * Try to init this object based on DB values
   */
  public static function init() {
    try {
      static::autoSetNamespace();

      //Try to map DB values to constant values
      $typesClass = static::$connectNamespace . static::$cphpObj;
      if (class_exists($typesClass)) {
        $types = $typesClass::find("ID > 0");

        if (!empty($types)) {
          foreach ($types as $t) {
            if (($labelKey = array_search($t->LookupName, static::$labels))) {
              static::$dbValMap[$labelKey] = $t->ID;
            }
          }
        }
        static::$initComplete = true;
      }
      else {
        throw new LogException(sprintf("%s: %s does not exist!", __METHOD__, $typesClass));
      }
    }
    catch (LogException $e) {
      echo $e;
    }
  }

}

/**
 * Class that defines the types of logs.
 * This class mimics the order of the log types stored in the console object export.
 * If changes are made to the database types, then a change must be made to this class.
 */
abstract class Type extends MenuItem
{
  const AddIn = 1;
  const CP = 2;
  const Cron = 3;
  const CustomAPI = 4;
  const Export = 5;
  const ExternalEvent = 6;
  const Import = 7;
  const CPM = 8;
  /**
   * Map the hard-coded constants in this class to DB label values.
   * This is used to assist with issues arising from updates to the custom
   * menu item in the console.
   * @var array
   */
  public static $labels = array (
    self::AddIn => 'Add-In',
    self::CP => 'CP',
    self::CPM => 'CPM',
    self::Cron => 'Cron',
    self::CustomAPI => 'Custom API',
    self::Export => 'Export',
    self::ExternalEvent => 'External Event',
    self::Import => 'Import'
  );
  /**
   * The CPHP object to check and map values
   * @var string
   */
  public static $cphpObj = "\\PSLog\\Type";
  /**
   * Set whether init has been called
   * @var bool
   */
  public static $initComplete = false;
  /**
   * Store DB values for the Severity
   * This param should be moved to a trait in PHP 5.4
   * @var array
   */
  public static $dbValMap = array ();
}

/**
 * Class that defines the Severity of logs.
 * This class mimics the order of the log Severity stored in the console object export.
 * If changes are made to the database types, then a change must be made to this class.
 */
abstract class Severity extends MenuItem
{
  const Fatal = 1;
  const Error = 2;
  const Warning = 3;
  const Notice = 4;
  const Info = 5;
  const Debug = 6;
  /**
   * Map the hard-coded constants in this class to DB label values.
   * This is used to assist with issues arising from updates to the custom
   * menu item in the console.
   * @var array
   */
  public static $labels = array (
    self::Fatal => 'Fatal',
    self::Error => 'Error',
    self::Warning => 'Warning',
    self::Notice => 'Notice',
    self::Info => 'Info',
    self::Debug => 'Debug'
  );
  /**
   * The CPHP object to check and map values
   * @var string
   */
  public static $cphpObj = "\\PSLog\\Severity";
  /**
   * Set whether init has been called
   * @var bool
   */
  public static $initComplete = false;
  /**
   * Store DB values for the Severity
   * This param should be moved to a trait in PHP 5.4
   * @var array
   */
  public static $dbValMap = array ();
}

/**
 * Main Logger class.  Logger class for writing logs to files and the RightNow Databases.
 * Logger can instantiated mutitple objects of the logger to perform various logging tasks.
 *
 * @uses \PS\Log\v2\Severity Log Severity
 * @uses \PS\Log\v2\Type Log Type
 * @uses \PS\LogLogException Logger base exception class
 */
class Log extends LogBase
{
  /**
   * Whether to log entries to a file in the '/tmp/PSLog' directory, rather
   * than the PSLog CBO table.
   * @var bool
   */
  public $logToFile = false;
  /**
   * Log entries to the PSLog CBO.
   * If $logToFile and $logToDb are set to false, then $stdOutputThreshold will be
   * assigned a value for output to stdOut.
   * @var bool
   */
  public $logToDb = true;
  /**
   * Default LogType to use for succeeding log entries.
   * @var int
   */
  public static $type = Type::CustomAPI;
  /**
   * Default subtype to use.
   * @var string
   */
  public static $subtype = null;
  /**
   * Default LogSeverity to use.
   * @var int
   */
  public $severity = null;
  /**
   * Override the default generated source string with a custom string.
   * @var string
   */
  public $source = null;
  /**
   * Sets the threshold where a stack trace is generated automatically.
   * @var int PS\Log\v2\Severity
   */
  public $traceThreshold = Severity::Warning;
  /**
   * Sets the severity threshold for when an entry is actually logged.
   * @var int PS\Log\v2\Severity
   */
  public $logThreshold = Severity::Error;
  /**
   * Sets the severity for which logs are sent to stdOut.
   * A null value means that this script will not send output to stdOut.
   * This should be used as a debugging tool only and should be set to null in production.
   * @var int PS\Log\v2\Severity
   */
  public $stdOutputThreshold = null;
  /**
   * Whether to automatically log the source of each log entry (typically
   * this is the filename, or the web request URI). This will be ignored
   * if the source is manually supplied.
   * @var bool
   */
  public $logSource = true;
  /**
   * RightNow object or array of RightNow objects that should be associated with every log.
   * You will probably need to update the Log schema for your project to associate the
   * constant objects. An example is using a reference to the ImportStats object
   * from the import framework.
   * @var RNObject|Array
   */
  public static $constantReferences = array ();
  /**
   * Log the output of memory_get_usage() to each log that is outputted.
   * This is used for debugging scripts that may run into the RightNow memory leak issues.
   * @var bool
   */
  public $logMemoryUsage = false;
  /**
   * Set if connected to the application
   * @var bool
   */
  protected $_connected = false;
  /**
   * Default path for file logging.
   * @var string
   */
  protected static $_logFileDir = "/tmp/PSLog";
  /**
   * Default severity for logging when no severity is provided to the class.
   * @var int PS\Log\v2\Severity
   */
  protected static $_defaultSeverity = Severity::Error;
  /**
   * Log file for messages generated by this class
   * @var string
   */
  protected static $_coreLogFile = null;
  /**
   * Varibale used for caching logs
   * @var mixed
   */
  protected $_logFileCache = null;
  /**
   * Array that contains overall schema and data used by this class.
   * @var array
   */
  protected $_logSchema = array (
    'package' => "PSLog",
    'objects' => array (
      'log' => "Log",
      'severity' => "Severity",
      'type' => "Type"
    )
  );
  /**
   * Array of RightNow Configuration object "Name" values that should be checked for values.
   * This array should map the RightNow config variable to the PSLog variable that should be set.
   * You may append additional configs in the constructor, but these three three will always be
   * checked for values.
   * CUSTOM_CFG_ is omitted because adding that somehow causes the array keys to be overwritten
   * by the config IDs, which breaks the assignment.  The standard CUSTOM_CFG_ is added dynamically.
   * If passing other options to the logger, you should omit that as well.
   * @var array
   */
  protected $_rntConfigs = array (
    "PSLOG_LOG_TO_DB" => 'logToDb', //On by default in PSLog -- THIS NEEDS TO BE THE FIRST ITEM IN THE ARRAY!
    "PSLOG_LOG_TO_FILE" => 'logToFile', //Off by default in PSLog
    "PSLOG_LOG_THRESHOLD" => 'logThreshold', //Debug is default threshold.  Allow UI-level management.
    "PSLOG_LOG_MEMORY_USAGE" => 'logMemoryUsage', //Will log the memory usage of PHP while this script is running
  );

  /**
   * Create static array of the vars that can be pulled from the database so that the query for them does not happen each time this object is initiated.
   *
   * (default value: array())
   *
   * @var array
   * @access protected
   * @static
   */
  protected static $_rntConfigVals = array();

  /**
   * RNCPHP Classes used for logging
   * @var array
   */
  protected $_logClasses = null;

  /**
   * Each PSLog instance must now be instantiated.  This constructor allows a developer to override default properties and/or pass RightNow configuration parameters that should be read in as options.
   *
   * @param \PS\Log\v2\Type|array $type May be either a reference to a PS\Log\v2\Type constant or an array of parameters
   * @param string $subtype (optional) Default subtype to use.
   * @param int $severity (optional) Default LogSeverity to use.
   * @param array $rntConfigs Array of RightNow configs to PSLog vars that will be loaded in addition to the configs set by this class.
   * @param bool $logToFile (optional) Whether to log entries to file, rather than the PSLog CBO table.
   * @param bool $logToDb (optional) Whether to log entries to the PSLog CBO table.
   * @param array $credentials (optional) Username and password array for CPHP connection
   * @param string $source (optional) Override the default generated source string with a custom string.
   * @param bool $logSource (optional) Whether to automatically log the source of each log entry
   * @param string $connectNamespace (optional) Change the default namespace used by the logger to something other than cphp v1.2
   * @param int $logThreshold (optional) PS\Log\v2\Severity Set the threshold that logs will be recorded
   * @param int $stdOutputThreshold (optional) PS\Log\v2\Severity Set the threshold in which logs will be sent to stdOut
   */
  public function __construct($type = null, $subtype = null, $severity = null, $rntConfigs = null, $logToFile = false, $logToDb = true, array $credentials = null, $source = null, $logSource = true, $connectNamespace = null, $logThreshold = null, $stdOutputThreshold = null) {
    // Initialize core log file for CPHP and CBO errors
    static::$_coreLogFile = static::_initLogFile(static::$_logFileDir);

    //If an array is passed as the first variable, then use extract to get the param values
    if (\is_array($type)) {
      //extract array values as vars
      \extract($type, EXTR_OVERWRITE);
    }

    try {
      if (!empty($type))
        static::$type = $type;
      if (!empty($subtype))
        static::$subtype = $subtype;
      if (!empty($severity))
        $this->severity = $severity;
      if ($logToFile !== null)
        $this->logToFile = $logToFile;
      if ($logToDb !== null)
        $this->logToDb = $logToDb;
      if (!empty($source))
        $this->source = $source;
      if ($logSource !== null)
        $this->logSource = $logSource;
      if ($logThreshold !== null)
        $this->logThreshold = $logThreshold;
      if ($stdOutputThreshold !== null)
        $this->stdOutputThreshold = $stdOutputThreshold;
      if (!empty($connectNamespace))
        $this->connectNamespace = $connectNamespace;

      //Connect to CPHP API if credentials are provided
      //This is mostly useful when accessing PSLog through customer portal
      if ($credentials !== null) {
        if (!is_array($credentials)) {
          $e = new LogException("\$credentials parameter must be an array of username and password.");
          $this->_addCoreFileEntry($e, Severity::Error);
          throw $e;
        }
        elseif (isset($credentials[0]) && isset($credentials[1])) {
          $this->connect($credentials[0], $credentials[1]);
        }
      }

      //Try to set the connect namespace automatically if namespace not provided
      if (empty($connectNamespace) && !\class_exists((static::$connectNamespace) . "\\ConnectAPI")) {
        $this->autoSetNamespace();
      }

      //Load the Rnt configs after connecting/namespace setup.
      if (\is_array($rntConfigs)) {
        $this->_rntConfigs = array_merge($this->_rntConfigs, $rntConfigs);
      }
      $this->_loadCustomConfigs();

      //The log type must be set in order to proceed.
      if (empty(static::$type) || !is_numeric(static::$type)) {
        throw new LogException("Log type not set or set to invalid value: {static::$type}.");
      }
    }
    catch (\Exception $e) {
      $this->addCoreFileEntry($e, Severity::Fatal);
    }
  }

  /**
   * Creates a single generic log entry.
   *
   * @param string|\Exception $message Log message (up to 255 characters allowed) or an Exception object.
   * @param int $severity (optional) LogSeverity to use for this entry.
   * @param mixed $references (optional) Either a single standard CPHP object, or an array of CPHP objects to associate with this log entry.
   * @param string $note (optional) Long note (up to 1MB of text allowed).
   * @param string $subtype (optional) Subtype to use for this entry.
   * @param int $type (optional) Overwrite the default LogType for this entry.
   * @param string $source (optional) Overwrite the default generated source string for this entry.
   * @return boolean Returns false if log entry creation failed. Failures will be logged in the core logfile in /tmp/PSLog by default.
   */
  public function create($message, $severity = null, $references = null, $note = null, $subtype = null, $type = null, $source = null) {
    try {
      //Output to stdOut
      if ($this->stdOutputThreshold !== null && $severity <= $this->stdOutputThreshold) {
        echo sprintf("%s PSLog (%s) - %s\n", date('m/d/Y H:i:s'), Severity::$labels[$severity], $message);
      }

      // Only log if less than or equal to the log threshold
      if (!empty($this->logThreshold) && $severity > $this->logThreshold) {
        return true;
      }

      if ($this->logToFile) {
        // Get log directory and file to write to
        $dir = $this->_getLogDir($type, $subtype);
        if (isset($this->_logFileCache[$dir])) {
          $filename = $this->_logFileCache[$dir];
        }
        else {
          $filename = $this->_initLogFile(static::$_logFileDir . "/" . $dir);
          $this->_logFileCache[$dir] = $filename;
        }

        $res = $this->_addFileEntry($filename, $message, $severity, $note, $subtype, $type, $source);

        //Only return if we are not logging to the database too
        if (!$this->logToDb) {
          return $res;
        }
      }

      if ($this->logToDb) {
        //connect to CCOM if not connected
        if (!$this->_connected) {
          $this->connect();
        }

        //init types to get db values
        if (Type::$initComplete == false)
          Type::init();

        //init types to get db values
        if (Severity::$initComplete == false)
          Severity::init();

        //Map the Severity and types to db values
        if (sizeof(Severity::$dbValMap) > 0 && !empty(Severity::$dbValMap[$severity]))
          $severity = Severity::$dbValMap[$severity];
        if (sizeof(Type::$dbValMap) > 0 && !empty(Type::$dbValMap[$type]))
          $type = Type::$dbValMap[$type];

        if ($this->_logClasses === null) {
          $this->_generateClasses($this->_logSchema);
        }
        return $this->_addEntry($message, $severity, $references, $note, $subtype, $type, $source);
      }
    }
    catch (\Exception $e) {
      $this->_addCoreFileEntry($e, Severity::Error);
      return false;
    }

    return $this;
  }

  /**
   * Magic method to allow severity create methods and chained public parameter settings.
   * Uses include calling the logger like: $log->error("Your error message");
   * Also will assist with property method chaining.
   * $log->type(Type::Cron)->error('Error message.');
   *
   * @param string $name The name of the method to call
   * @param array $arguments The arguments to pass to the method being called.
   */
  public function __call($name, $arguments) {
    //First, check to see if we are trying to call a "severity helper" method
    $sevName = ucfirst(strtolower($name));

    //If the method name matches a Severity, then call the create() method
    if (in_array($sevName, Severity::$labels)) {
      //If $arguments is not an array, convert to an array so we can pass to call_user_func_array
      if (!is_array($arguments)) {
        $arguments = array ($arguments);
      }

      //Insert the Severity into the args array. Otherwise, method call would have arguments in same order.
      array_splice($arguments, 1, 0, constant("PS\\Log\\v2\\Severity::{$sevName}"));
      //Call the create method
      return call_user_func_array(array ($this, "create"), $arguments);
    }
    //Next, check to see if we are trying to set a property through method chaining
    else if (property_exists($this, $name)) {
        //Create a reflection class so that we can examine the methods and control chaining
        $refClass = new \ReflectionClass($this);
        $properties = $refClass->getProperties(\ReflectionProperty::IS_PUBLIC);

        $vars = array ();
        array_walk($properties, function(\ReflectionProperty $prop, $index) use (&$vars) {
            $vars[] = $prop->name;
          });

        if ($name[0] != "_" && in_array($name, $vars)) {
          $refProp = new \ReflectionProperty(__CLASS__, $name);
          if ($refProp->isStatic()) {
            static::${$name} = $arguments[0];
          }
          else {
            $this->{$name} = $arguments[0];
          }
        }
        else {
          throw new LogException("You may not set private methods ({$name}) via method chaining.");
        }
      }

    return $this; //return the object instance for chaining
  }

  /**
   * Magic method to allow severity create methods accessed through static method.
   * This allows the developer to call the logger without having to instantiate it,
   * similar to PSLog 1.x.
   *
   * @param string $name The name of the method to call
   * @param array $arguments The arguments to pass to the method being called.
   */
  public static function __callStatic($name, $arguments) {
    //First, check to see if we are trying to call a "severity helper" method
    $sevName = ucfirst(strtolower($name));

    //Use an object instance for logging and reflection
    $log = new self();

    //If the method name matches a Severity, then call the create() method
    if (in_array($sevName, Severity::$labels)) {
      //If $arguments is not an array, convert to an array so we can pass to call_user_func_array
      if (!is_array($arguments))
        $arguments = array (
          $arguments
        );

      //Insert the Severity into the args array. Otherwise, method call would have arguments in same order.
      array_splice($arguments, 1, 0, constant("PS\\Log\\v2\\Severity::{$sevName}"));
      //Call the create method
      return call_user_func_array(array ($log, "create"), $arguments);
    }
    //Next, check to see if we are trying to set a property through method chaining
    else if (property_exists($log, $name)) {
        //Create a reflection class so that we can examine the methods and control chaining
        $refClass = new \ReflectionClass($log);
        $staticProperties = $refClass->getStaticProperties();
        $staticVars = array_keys($staticProperties);

        if ($name[0] != "_" && in_array($name, $staticVars)) {
          static::${$name} = $arguments[0];
        }
        else {
          throw new LogException("You may not set private static methods ({$name}) via method chaining.");
        }
      }

    //Assist with garbage cleanup
    $log = null;
  }

  /**
   * Helper method to quickly add a costant reference to a log.
   * This method will take a RightNow object and either add to the constantReference array
   * or convert the constantReference variable to an array if it is assigned only one object.
   *
   * @param \RNCPHP\RNObject $obj
   */
  public function addConstantReference(&$obj) {
    if (\is_array(self::$constantReferences)) {
      self::$constantReferences[] = $obj;
    }
    else if (\is_object(self::$constantReferences)) {
        $arr = array (self::$constantReferences, $obj);
        self::$constantReferences = $arr;
      }
    else {
      self::$constantReferences = array ($obj);
    }
  }

  /**
   * Removes a CPHP object from being a constant reference.
   * This will only remove the same object passed in as the constant reference to begin with.
   *
   * @param \RNCPHP\RNObject $obj
   * @return bool
   */
  public function removeConstantReference(&$obj) {
    if (\is_object($obj)) {
      if (\is_object(self::$constantReferences)) {
        self::$constantReferences = null;
        return true;
      }
      else if (\is_array(self::$constantReferences)) {
          foreach (self::$constantReferences as $key => $cr) {
            if ($obj === $cr && $obj->ID === $cr->ID) {
              unset(self::$constantReferences[$key]);
              return true;
            }
          }
        }
    }
    return false;
  }

  /**
   * Manually connect to Connect for PHP API with optional login credentials.
   *
   * @param string $username Valid account username with which to connect
   * @param string $password Valid account password with which to connect
   */
  public function connect($username = null, $password = null) {
    try {
      // Load ConnectPHP init file
      $connectFilepath = get_cfg_var("doc_root") . "/ConnectPHP/Connect_init.php";
      if (!file_exists($connectFilepath)) {
        throw new LogException(sprintf("ConnectPHP initialization file '%s' could not be found (or could not be read).", $connectFilepath));
      }
      require_once $connectFilepath;

      // Optionally use login credentials
      if ($username !== null && $password !== null) {
        initConnectAPI(strval($username), strval($password));
      }
      else {
        initConnectAPI();
      }
      $this->_connected = true;
    }
    catch (RNCPHP\ConnectAPIError $e) {
      $this->addCoreFileEntry($e, Severity::Fatal);
      return false;
    }
    catch (\Exception $e) {
      $this->addCoreFileEntry($e, Severity::Fatal);
      return false;
    }
    return true;
  }

  /**
   * Creates log directory if needed, and sets logFile property.
   *
   * @static
   * @param string $logDir Log directory to initialize.
   * @param bool $clearLog Whether to clear logfile.
   * @return string Path to current log file.
   */
  protected static function _initLogFile($logDir, $clearLog = false) {
    // Create log directory if it doesn't exist
    if (!is_dir($logDir)) {
      $oldumask = umask(0);
      mkdir($logDir, 0775, true);
      umask($oldumask);
      if (!is_dir($logDir)) {
        throw new LogException(sprintf("Log directory could not be created: %s", $logDir));
      }
    }

    // Generate log filename based on date
    $logFile = sprintf("%s/%s.log", $logDir, date('Y-m-d'));
    if ($clearLog) {
      file_put_contents($logFile, "");
    }
    return $logFile;
  }

  /**
   * Creates an entry for a log file.
   *
   * @param string $filename
   * @param string $message
   * @param PS\Log\v2\LogSeverity $severity
   * @param string $note
   * @param PS\Log\v2\LogType $subtype
   * @param PS\Log\v2\LogType $type
   * @param string $source
   */
  protected function _addFileEntry($filename, $message, $severity, $note, $subtype, $type, $source) {
    // Sanity checks
    if (empty($severity)) {
      $severity = $this->severity;
    }
    if (empty($source) && $this->logSource) {
      if (!empty($this->source)) {
        $source = $this->source;
      } else {
        $source = $this->_getSource();
      }
    }

    list($trace, $messageStr) = $this->_getTraceAndMessage($message, $severity);

    // Set log object properties
    if ($note !== null) {
      $note = sprintf("\nNote: %s", print_r($note, true));
    }
    if ($source !== null) {
      $source = sprintf("\nSource: %s", $source);
    }
    if ($trace !== null) {
      $trace = sprintf("\nTrace: %s", $trace);
    }

    $memory = "";
    if ($this->logMemoryUsage) {
      $memory .= sprintf("(%fMB used) ", round((memory_get_usage() / 1024 / 1024), 2));
    }

    $output = sprintf("%s (%s)%s: %s%s%s%s\n\n", date('c'), Severity::$labels[$severity], $memory, $messageStr, $source, $note, $trace);
    return \file_put_contents($filename, $output, FILE_APPEND);
  }

  /**
   * Get and trace messages.
   *
   * @param string $message
   * @param PS\Log\v2\Severity $severity
   * @return array
   */
  protected function _getTraceAndMessage($message, $severity) {
    if (\is_a($message, 'Exception')) {
      // Dump entire exception as trace
      if ($severity <= $this->traceThreshold) {
        $trace = $message->getTraceAsString();
      }
      else {
        $trace = strval($message);
      }
      $messageStr = $message->getMessage();
    }
    else {
      // Generate trace if severity is higher than trace threshold
      if ($severity <= $this->traceThreshold) {
        $e = new \Exception();
        $trace = sprintf("Auto-generated by PSLog:\n%s", $e->getTraceAsString());
      }
      else {
        $trace = null;
      }
      $messageStr = strval($message);
    }
    return array (
      $trace,
      $messageStr
    );
  }

  /**
   * Get the directory for the log file.
   *
   * @param PS\Log\v2\LogType $type
   * @param PS\Log\v2\LogType $subtype
   * @return PS\Log\v2\LogType
   * @throws PS\LogLogException
   */
  protected function _getLogDir($type, $subtype) {
    // Organize log files in directories named subtype_type
    $dir = null;

    // Add type string to directory name
    if ($type == null) {
      if (static::$type == null) {
        throw new LogException("Log type not defined. Must call \PS\Log\v2\Log::init() or explicitly set type when calling \PS\Log\v2\Log::create().");
      }
      else {
        $type = static::$type;
      }
    }
    if (isset(Type::$labels[$type])) {
      $typeLabel = Type::$labels[$type];
    }
    else {
      $typeLabel = strval($type);
    }

    // Add subtype string to directory name
    if ($subtype == null) {
      $subtype = static::$subtype;
    }

    // Create directory name
    if (is_string($subtype)) {
      $dir = sprintf("%s_%s", $this->alphaNumStr($subtype), $this->alphaNumStr($typeLabel));
    }
    else {
      $dir = $typeLabel;
    }

    // Check for valid log directory
    if ($dir == null || $dir == "") {
      throw new LogException("Could not generate custom log directory.");
    }

    return $dir;
  }

  /**
   * Get an alphanumeric string.
   *
   * @static
   * @param string $str
   * @return string
   */
  public static function alphaNumStr($str) {
    if (is_string($str) || $str !== "") {
      return preg_replace("/[^a-zA-Z0-9]/", "", $str);
    }
  }

  /**
   * Add a core file entry.
   *
   * @static
   * @param string $e
   * @param PS\Log\v2\LogType $severity
   */
  protected static function _addCoreFileEntry($e, $severity = null) {
    if ($severity == null) {
      $severity = static::$_defaultSeverity;
    }
    if (static::$_coreLogFile === null) {
      static::$_coreLogFile = static::_initLogFile(static::$_logFileDir);
    }

    $message = strval($e);

    if (is_object($e) && $severity > Severity::Warning) {
      $message = $e->getTraceAsString();
    }

    $output = sprintf("%s (%s): %s\n\n", date('c'), Severity::$labels[$severity], $message);
    file_put_contents(static::$_coreLogFile, $output, FILE_APPEND);
  }

  /**
   * Method to allow logging to the core file from external sources.
   * This method is used by PSLog during a PHP exit() or total failure which would cause a log to be written out of
   * this class' scope.  Devs should not use this method.
   *
   * @param string $error
   * @param PS\Log\v2\LogType $severity
   */
  public static function logCoreFailure($error, $severity = Severity::Fatal) {
    static::_addCoreFileEntry($error, $severity);
  }

  /**
   * Generate namespace/class string from object names.
   *
   * @param array $schema
   * @throws PS\Log\v2\LogException
   */
  protected function _generateClasses($schema) {
    // Sanity checks
    if (!is_array($schema)) {
      throw new LogException("Log schema must be an array.");
    }
    if (!isset($schema['package'])) {
      throw new LogException("Package name must exist in log schema.");
    }
    if (!isset($schema['objects']) || !is_array($schema['objects'])) {
      throw new LogException("Log schema must contain an array of object classnames.");
    }

    // Generate namespace/class string from object names
    $this->_logClasses = array ();
    foreach ($schema['objects'] as $desc => $object) {
      $class = sprintf("%s\%s\%s", static::$connectNamespace, $schema['package'], strval($object));
      if (!class_exists($class)) {
        throw new LogException(sprintf("Class '%s' does not exist.", $class));
      }
      $this->_logClasses[$desc] = sprintf("\%s", $class);
    }
  }

  /**
   * Add a log entry
   *
   * @param string $message
   * @param PS\Log\v2\Severity $severity
   * @param string $references
   * @param string $note
   * @param PS\Log\v2\Type $subtype
   * @param PS\Log\v2\Type $type
   * @param string $source
   * @return bool
   * @throws PS\LogLogException
   */
  protected function _addEntry($message, $severity, $references, $note, $subtype, $type, $source) {
    // Sanity checks
    if (!isset($this->_logClasses['log']) && !isset($this->_logClasses['severity']) && !isset(
        $this->_logClasses['type'])) {
      throw new LogException("Log classes were not instantiated from schema correctly.");
    }
    if ($type == null) {
      if (static::$type == null) {
        throw new LogException("Log type not defined. You must set a log type prior to calling a log method.");
      }
      else {
        $type = static::$type;
      }
    }
    if (isset(Type::$labels[$type])) {
      $typeLabel = Type::$labels[$type];
    }
    else {
      $typeLabel = strval($type);
    }

    if ($subtype == null) {
      $subtype = static::$subtype;
    }
    if ($severity == null) {
      $severity = $this->severity;
    }
    if (empty($source) && $this->logSource) {
      if (!empty($this->source)) {
        $source = $this->source;
      } else {
        $source = $this->_getSource();
      }
    }

    // Instantiate log object
    $logClass = $this->_logClasses['log'];
    $entryObj = new $logClass();

    // Instantiate type object
    $typeClass = $this->_logClasses['type'];
    $typeObj = $typeClass::fetch($typeLabel);

    // Set default severity and instantiate object
    if ($severity === null) {
      $severity = static::$_defaultSeverity;
    }
    $severityClass = $this->_logClasses['severity'];
    $severityObj = $severityClass::fetch($severity);

    list($trace, $messageStr) = $this->_getTraceAndMessage($message, $severity);

    // Set log object properties
    $entryObj->Type = $typeObj;
    $entryObj->Message = substr($messageStr, 0, 255);
    $entryObj->Severity = $severityObj;
    if ($subtype !== null && $subtype !== "") {
      $entryObj->SubType = strval($subtype);
    }
    if ($note !== null && $note !== "" && !(is_array($note) && empty($note))) {
      $entryObj->Note = print_r($note, true);
    }
    if ($source !== null && $source !== "") {
      $entryObj->Source = strval($source);
    }
    if ($trace !== null && $trace !== "") {
      $entryObj->StackTrace = strval($trace);
    }

    //If the memory capture column exist and the log memory flag is checked, then add current mem usage
    if (\property_exists($logClass, 'Memory') && $this->logMemoryUsage) {
      $entryObj->Memory = memory_get_usage();
    }

    //Assign object references to the log object.
    if ($references !== null || $this->constantReferences !== null) {
      $refs = array ();
      //Merge constant references into refs
      if (!empty($this->constantReferences) && !\is_array($this->constantReferences)) {
        $refs[] = $this->constantReferences;
      }
      else if (is_array($this->constantReferences)) {
          $refs = \array_merge($refs, $this->constantReferences);
        }

      //Merge references into refs
      if (!empty($references) && !\is_array($references)) {
        $refs[] = $references;
      }
      else if (is_array($references)) {
          $refs = \array_merge($refs, $references);
        }

      $this->_addReferences($entryObj, $refs);
    }
    $result = $entryObj->save();

    return $result;
  }

  /**
   * Attempt to get the source of the file calling the logger.
   * @return string
   */
  protected function _getSource() {
    // Check if we're in CP or a custom script
    if (defined("BASEPATH")) {
      // CP
      if (isset($_SERVER['HTTP_HOST']) && isset($_SERVER['REQUEST_URI'])) {
        $source = sprintf("%s%s", $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']);
      }
      else {
        $source = "Unknown CP Page Request";
      }
    }
    else {
      // Custom script
      foreach (debug_backtrace(false) as $item) {
        if (isset($item['file']) && $item['file'] !== __FILE__) {
          $source = $item['file'];
          break;
        }
      }

      //If source is empty now, then the source is a closure within this script
      $source = __FILE__;
    }

    if (\is_string($source) && !empty($source)) {
      return substr($source, -255);
    }
  }

  /**
   * Add log references to RNCPHP objects.
   *
   * @param RNCPHP\Log $entryObj
   * @param array|RNCPHP\RNObject $references
   */
  protected function _addReferences(&$entryObj, $references) {
    // Add array of refrerences, or single reference object
    if (is_array($references)) {
      foreach ($references as $reference) {
        try {
          if (!empty($reference)) {
            $this->_addReferenceObj($entryObj, $reference);
          }
        }
        catch (\Exception $e) {
          list($trace, $msgStr) = $this->_getTraceAndMessage($e, Severity::Error);
          $this->_addCoreFileEntry($e, Severity::Error);
          $this->_addCoreFileEntry($trace, Severity::Error);
        }
      }
    }
    else {
      try {
        $this->_addReferenceObj($entryObj, $references);
      }
      catch (\Exception $e) {
        list($trace, $msgStr) = $this->_getTraceAndMessage($e, Severity::Error);
        $this->_addCoreFileEntry($e, Severity::Error);
        $this->_addCoreFileEntry($trace, Severity::Error);
      }
    }
  }

  /**
   * Add refernces to logging object
   *
   * @param RNCPHP\Log $entryObj
   * @param array|RNCPHP\RNObject $reference
   * @throws LogException
   */
  protected function _addReferenceObj(&$entryObj, $reference) {
    if (!is_object($reference)) {
      throw new LogException(sprintf("Reference is not an object: %s", print_r($reference, true)));
    }
    $refClass = get_class($reference);
    $meta = $entryObj::getMetaData();
    $found = false;
    if (!empty($meta)) {
      foreach ($meta as $property) {
        if (is_object($property) && $refClass == $property->type_name && $property->name != "UpdatedByAccount" && $property->name != "CreatedByAccount") {
          $propName = $property->name;
          $entryObj->$propName = $reference;
          $found = true;
        }
      }
    }
    if (!$found) {
      throw new LogException(sprintf("Reference could not be found on log object: %s", print_r($reference, true)));
    }
  }

  /**
   * Returns an array of all object properties (excluding '_metadata' property).
   * Useful for inspecting objects returned from ROQL queries and Connect
   * for PHP methods. This WILL trigger the lazy loading of lookup objects and
   * traverse them when $recursive is true.
   * @throws RightNow\Connect\v1\ConnectAPIErrorBase
   * @param mixed $object Any object
   * @param bool $recursive Optional: recursively get properties from nested objects
   * @return mixed Array of object properties, or single property
   */
  public function dump($object, $recursive = false) {
    // If property is object, recursively get sub properties
    if (is_object($object)) {
      $outputArray = array ();
      // Use reflection to get public and dynamic properties
      $reflect = new \ReflectionObject($object);
      $props = $reflect->getProperties(\ReflectionProperty::IS_PUBLIC);
      // Loop through property list
      foreach ($props as $prop) {
        $propName = $prop->getName();
        // Exclude metadata from properties list
        if ($propName != "_metadata") {
          if ($recursive) {
            $outputArray[$propName] = $this->dump($object->$propName, $recursive);
          }
          else {
            $outputArray[$propName] = $object->$propName;
          }
        }
      }
      return $outputArray;
    }
    // If property is array, recursively get properties of elements
    elseif (is_array($object)) {
      $subArray = array ();
      foreach ($object as $idx => $propElem) {
        $subArray[$idx] = $this->dump($propElem, $recursive);
      }
      return $subArray;
    }
    // Non-object or non-array property
    else {
      return $object;
    }
  }

  /**
   * This will scan the configuration object for certain PSLog-specific configs and load them
   * if they exist.  This method will only work on 13.8+ sites.  Sites that do not have this
   * object will ignore this method.
   *
   * @return void
   */
  protected function _loadCustomConfigs() {
    $configClass = static::$connectNamespace . "\\Configuration";

    if (\class_exists($configClass)) {
      //loop through the configs found in the DB and then create a key/value array
      foreach ($this->_rntConfigs as $rntConfig => $psLogVar) {
        //Make sure that the array maps a config and a PSLog variable
        if (!empty($rntConfig) && !empty($psLogVar)) {
          if (!empty(static::$_rntConfigVals[$rntConfig])) {
            $this->$psLogVar = static::$_rntConfigVals[$rntConfig];
          } else {
            $cfgName = "CUSTOM_CFG_{$rntConfig}";
            $config = $configClass::first("Name = '{$cfgName}'");
            if (!empty($config)) {
              //Only apply a config if there is a value.
              //Values from the DB can be empty/null/zero, so do not perform any data check.
              //Assign both the class var and to the static array so we
              static::$_rntConfigVals[$rntConfig] = $this->$psLogVar = ($config->Value === '') ? null : $config->Value;
            } else {
              $this->debug("Unable to find config: {$rntConfig}");
            }
            //empty the config var for the next item in the loop
            $config = null;
          }
        }
        else {
          $this->debug("Value in RntConfig array was empty.", null, print_r($this->_rntConfigs, true));
        }
      }
    }
  }

  /**
   * Method for removing logs from the PSLog database table.
   * This is a destructive method and should be used with cation.
   * @param string|int|null $startDate Pass a strtotime compatible string to set a date to start the pruning.  If this is not provided, then the prune will simply start at the oldest record in the table.
   * @param string|int|null $endDate Pass a strtotime compatible string to set a date to end the pruning.  This will be the current time if null.
   * @param int|null $limitRecordsRemoved Will remove up to this number of logs. Null removes logs until all conditions are met.
   * @param int|null $removeSeverityLimit Will only remove logs up to this severity.  By default, remove debug, info, notice, and warning logs.
   */
  public static function pruneDatabaseLogs($startDate = null, $endDate = null, $limitRecordsRemoved = null, $removeSeverityLimit = Severity::Warning) {
    static::_addCoreFileEntry("Prune DB Logs Called!", Severity::Notice);
    if (!empty($endDate) && !\is_numeric($endDate)) {
      $endDate = \strtotime($endDate);
    }

    if (!empty($startDate) && !\is_numeric($startDate)) {
      $startDate = \strtotime($startDate);
    }

    $recordsRemoved = 0;
    $batch = 1;
    $queryString = !empty($endDate) ? "CreatedTime <= {$endDate} " : " ";
    $queryString .=!empty($startDate) ? "CreatedTime >= {$startDate} " : " ";
    $queryString .= "Severity >= {$removeSeverityLimit} ";
    $queryString .= "ORDER BY CreatedTime ASC"; //Will start by deleting the oldest records first

    static::_addCoreFileEntry("Log remove conditions: " . $queryString, Severity::Notice);

    try {
      do {
        static::autoSetNamespace();
        $object = static::$connectNamespace . "\\PSLog\\Log";
        $logs = $object::find($queryString);

        foreach ($logs as $log) {
          $log->destroy();
          $recordsRemoved++;

          if (!empty($limitRecordsRemoved) && $recordsRemoved >= $limitRecordsRemoved) {
            break;
          }
        }

        //On the last loop, logs will be empty which will show the enf of the process.
        //This log is confusing if it shows a batch with 0 items, so only show if there is data.
        if (!empty($logs)) {
          static::_addCoreFileEntry(sprintf("Batch (%d) - %d of %d removed.", $batch, sizeof($logs), $recordsRemoved), Severity::Notice);
        }

        $batch++;
      }
      while (!empty($logs) && (empty($limitRecordsRemoved) || $recordsRemoved < $limitRecordsRemoved));
    }
    catch (\Exception $e) {
      static::_addCoreFileEntry("Error when pruning DB logs: " . $e->getMessage(), Severity::Error);
    }

    static::_addCoreFileEntry("Prune DB Logs Ended!", Severity::Notice);
  }

}

/**
 * PROCEDURAL LOGGER METHODS
 * These methods will be added to any page in which the logger file is added.
 * This will assist in catching any exceptions that are not caught by a developer's code
 */
//Register a default exception handler so that we can log an error if the developer has not done so.
set_exception_handler(
  function ($exception) {
    $message = sprintf("Exception thrown in %s (Line:%d): %s", $exception->getFile(), $exception->getLine(), $exception->getMessage());
    $message = substr($message, 0, 1332); //Message should be a max of 1333 chars

    try {
      $log = new log();
      $log->logToFile(true)->logToDb(true)->stdOutputThreshold(Severity::Error)->error($message);
    }
    //Sometimes, CPHP will throw errors preventing a DB log.  If this script catches
    //an exception when logging to the DB, then force a file log.
    catch (\Exception $e) {
      Log::logCoreFailure($e, Severity::Fatal);
    }
  }
);

//Register shutdown and exception functions to attempt to log to the db in the event
//of something very bad happening.
register_shutdown_function(
  function () {
    //If a fatal error has occurred, then try to log it.
    //The exception handler should log this to a file if DB loggin fails
    $err = error_get_last();
    if ($err['type'] === E_ERROR || $err['type'] === E_USER_ERROR) {
      Log::logCoreFailure("Fatal Error: {$err['message']} in {$err['file']} on line {$err['line']}");
    }
  }
);
