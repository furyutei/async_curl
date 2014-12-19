<?php
/******************************************************************************
    AsyncCurl: Asynchrounous download wrapper of cURL for PHP
    
    @author     furyu (furyutei@gmail.com)
    @copyright  Copyright (c) 2014 furyu
    @link       https://github.com/furyutei/async_curl
    @version    0.0.1.5
    @license    The MIT license
******************************************************************************/

class AsyncCurl {
    
    //{=== CONSTANTS and STATIC VARIABLES
    
    const DEFAULT_DEBUG = FALSE;
    const PARENT_LOGNAME = 'parent_debug.log';
    const CHILD_LOGNAME = 'child_debug.log';
    
    const DECODE_SAFELY = TRUE;
    const RESULT_FILENO = 3;
    const DEFAULT_BUFFER_SIZE = 64000;
    const REUSE_CHILD = FALSE; // TODO: support reuse of child process
    const USE_FD_STREAM = TRUE;
    
    // message type (parent => child)
    const TYPE_INIT   = 0x0001;
    const TYPE_END    = 0xFFFF;
    
    // message type (child => parent)
    const TYPE_RESULT = 0x8001;
    
    private static $DEFAULT_CURL_OPTIONS = array(
        CURLOPT_HEADER => FALSE,
        CURLOPT_SSL_VERIFYPEER => FALSE,
        CURLOPT_SSL_VERIFYHOST => FALSE,
        CURLOPT_FOLLOWLOCATION => TRUE,
        CURLOPT_USERAGENT => 'AsyncCurl',
        CURLOPT_CONNECTTIMEOUT => 600,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_MAXREDIRS => 10,
    );
    
    private static $FORCE_CURL_OPTIONS = array(
        CURLOPT_RETURNTRANSFER => FALSE,
        CURLOPT_FILE => STDOUT,
        CURLOPT_STDERR => STDOUT,
    );
    
    //} end of CONSTANTS and STATIC VARIABLES
    
    
    //{=== PRIVATE VARIABLES
    
    private $TYPE_SIZE = 4;
    private $LENGTH_SIZE = 8;
    private $PHP_CLI = NULL;
    
    private $debug = FALSE;
    private $is_child = FALSE;
    
    private $process = NULL;
    private $fp_stdin = NULL;
    private $fp_stdout = NULL;
    private $fp_stderr = NULL;
    private $fp_result = NULL;
    
    private $buffer_size = 0;
    
    private $fp_log = NULL;
    
    //} end of PRIVATE VARIABLES
    
    
    //{=== PRIVATE FUNCTIONS
    
    private function    log($text) {
        if (!$this->fp_log) return;
        $write_string = is_string($text) ? $text : print_r($text, TRUE);
        fwrite($this->fp_log, date("Y-m-d H:i:s") . " {$write_string}\n");
    }   //  end of log()
    
    private function    array_update($array1, $array2) {
        if (function_exists('array_replace')) return array_replace($array1, $array2);
        foreach ($array2 as $key => $value) $array1[$key] = $value;
        return $array1;
    }   //  end of array_update()
    
    private function    encode_number($number, $size) {
        $number_string = sprintf("%0{$size}x", $number);
        return (strlen($number_string) == $size) ? $number_string : FALSE;
    }   //  end of encode_number()
    
    private function    decode_number($number_string, $size) {
        if (strlen($number_string) != $size) return FALSE;
        $number = hexdec($number_string);
        return $number;
    }   //  end of decode_number()
    
    private function    encode_value($value) {
        $encoded_value = (self::DECODE_SAFELY) ? json_encode($value) : serialize($value);
        return $encoded_value;
    }   //  end of encode_value()
    
    private function    decode_value($encoded_value) {
        $value = (self::DECODE_SAFELY) ? json_decode($encoded_value, TRUE) : serialize($encoded_value);
        return $value;
    }   //  end of encode_value()
    
    private function    write_header($fp, $type, $length) {
        $result = FALSE;
        for (;;) {
            if (($type_string = $this->encode_number($type, $this->TYPE_SIZE)) === FALSE) break;
            if (($length_string = $this->encode_number($length, $this->LENGTH_SIZE)) === FALSE) break;
            
            $size = $this->TYPE_SIZE + $this->LENGTH_SIZE;
            $write_size = fwrite($fp, $type_string . $length_string, $size);
            if ($write_size === FALSE || $write_size != $size) break;
            
            $result = TRUE;
            break;
        }
        return $result;
    }   //  end of write_header()
    
    private function    write_value($fp, $type, $value) {
        $result = FALSE;
        for (;;) {
            try {
                $encoded_value = $this->encode_value($value);
                $length = strlen($encoded_value);
            } catch (Exception $exp) {
                break;
            }
            if (!$this->write_header($fp, $type, $length)) break;
            
            $write_length = fwrite($fp, $encoded_value, $length);
            if ($write_length === FALSE || $write_length != $length) break;
            $result = TRUE;
            break;
        }
        return $result;
    }   //  end of write_value()
    
    private function    read_header($fp, &$type, &$length) {
        $result = FALSE;
        for (;;) {
            $type_string = fread($fp, $this->TYPE_SIZE);
            if ($type_string === FALSE || ($type = $this->decode_number($type_string, $this->TYPE_SIZE)) === FALSE) break;
            
            $length_string = fread($fp, $this->LENGTH_SIZE);
            if ($length_string === FALSE || ($length = $this->decode_number($length_string, $this->LENGTH_SIZE)) === FALSE) break;
            
            $result = TRUE;
            break;
        }
        return $result;
    }   //  end of read_header()
    
    private function    read_value($fp, &$type, &$value) {
        $result = FALSE;
        for (;;) {
            if (!$this->read_header($fp, $type, $length)) break;
            
            $encoded_value = fread($fp, $length);
            if ($encoded_value === FALSE || strlen($encoded_value) != $length) break;
            try {
                $value = $this->decode_value($encoded_value);
            } catch (Exception $exp) {
                break;
            }
            $result = TRUE;
            break;
        }
        return $result;
    }   //  end of read_value()
    
    private function    read_stream($fp, &$contents) {
        $result = FALSE;
        for (;;) {
            $contents = stream_get_contents($fp);
            if ($contents === FALSE) break;
            $result = TRUE;
            break;
        }
        return $result;
    }   //  end of read_stream()
    
    private function    close_all_fd() {
        $fp_name_list = array('fp_stdin', 'fp_stdout', 'fp_stderr', 'fp_result');
        foreach ($fp_name_list as $fp_name) {
            if ($this->{$fp_name}) {
                fclose($this->{$fp_name});
                $this->{$fp_name} = NULL;
            }
        }
        if ($this->process) {
            proc_close($this->process);
            $this->process = NULL;
        }
    }   //  end of close_all_fd()
    
    private function    get_php_client_path() {
        if ($this->PHP_CLI) return $this->PHP_CLI;
        $PHP_CLI = '/usr/bin/php';
        $OPT_FILE = dirname(__FILE__) . '/async_curl_options.php';
        if (is_file($OPT_FILE)) require($OPT_FILE);
        $this->PHP_CLI = $PHP_CLI;
        return $PHP_CLI;
    }   //  end of get_php_client_path()
    
    private function    check_open_fd() {
        $result = FALSE;
        for (;;) {
            if (!self::USE_FD_STREAM) break;
            if ($this->is_child) {
                $fp = @fopen('php://fd/1', 'wb');
                if (!$fp) break;
                fclose($fp);
            }
            else {
                $php_cli = $this->get_php_client_path();
                $command = sprintf('%s "%s" "0" "1" 2>&1', $php_cli, __FILE__);
                exec($command, $output, $return_var);
                if ($return_var !== 0) break;
            }
            $result = TRUE;
            break;
        }
        $this->log('check_open_fd(): ' . ($result?'TRUE':'FALSE'));
        return $result;
    }   //  end of check_open_fd()
    
    private function    start_child($debug=NULL) {
        if ($debug) $this->fp_log = @fopen(self::CHILD_LOGNAME, 'ab+');
        $this->log('*** start_child() ***');
        
        $this->buffer_size = self::DEFAULT_BUFFER_SIZE;
        
        for (;;) {
            if (!$this->fp_result) {
                $ready_to_open_fd = $this->check_open_fd();
                $this->fp_result = ($ready_to_open_fd) ? @fopen('php://fd/' . self::RESULT_FILENO, 'wb') : STDERR;
                if (!$this->fp_result) {
                    $this->log('Error: fopen(php://fd/' . self::RESULT_FILENO . ')');
                    break;
                }
                $this->fp_stdin = STDIN;
                $this->fp_stdout = STDOUT;
                //$this->fp_stderr = STDERR;
                if ($ready_to_open_fd) @fclose(STDERR);
                $this->fp_stderr = NULL; // disused
            }
            if (!@$this->read_value($this->fp_stdin, $type, $init_parameters)) {
                $this->log("Error: read_value(fp_stdin)");
                break;
            }
            $this->log(sprintf("TYPE: 0x%0{$this->TYPE_SIZE}x", $type));
            $this->log('PARAMETERS:');
            $this->log($init_parameters);
            
            $is_end = FALSE;
            $curl_pointer = NULL;
            switch ($type) {
                case    self::TYPE_INIT :
                    $curl_pointer = @curl_init($init_parameters['url']);
                    if ($curl_pointer === FALSE) {
                        $this->log("Error: curl_init()");
                        $is_end = TRUE;
                        break;
                    }
                    $curl_options_to_set = self::$DEFAULT_CURL_OPTIONS;
                    $curl_options_to_set = $this->array_update($curl_options_to_set, $init_parameters['curl_options']);
                    $curl_options_to_set = $this->array_update($curl_options_to_set, self::$FORCE_CURL_OPTIONS);
                    
                    $this->log('cURL OPTIONS:');
                    $this->log($curl_options_to_set);
                    
                    @curl_setopt_array($curl_pointer, $curl_options_to_set);
                    
                    if (@curl_exec($curl_pointer) === FALSE) {
                        $this->log("Error: curl_exec()");
                        $is_end = TRUE;
                        break;
                    }
                    $curl_result = array(
                        'info' => @curl_getinfo($curl_pointer),
                        'errno' => @curl_errno($curl_pointer),
                        'error' => @curl_error($curl_pointer),
                    );
                    if (!$this->write_value($this->fp_result, self::TYPE_RESULT, $curl_result)) {
                        $this->log(sprintf("Error: write_value(TYPE_RESULT(0x%0{$this->TYPE_SIZE}x))", self::TYPE_RESULT));
                        $is_end = TRUE;
                        break;
                    }
                    if (!self::REUSE_CHILD) {
                        $is_end = TRUE;
                        break;
                    }
                    // TODO: would like to tell parent about transmission completion ...
                    break;
                case    self::TYPE_END  :
                    $is_end = TRUE;
                    break;
            }
            if ($curl_pointer) @curl_close($curl_pointer);
            if ($is_end) break;
        }
        @$this->close_all_fd();
        
    }   //  end of start_child()
    
    //} end of PRIVATE FUNCTIONS
    
    
    //{=== PUBLIC FUNCTIONS
    
    public  function    __construct($url=NULL, $curl_options=NULL, &$contents_pointer=NULL, $debug=NULL, $is_child=FALSE, $check_only=FALSE) {
        $this->LENGTH_SIZE = strlen(sprintf("%x", PHP_INT_MAX));
        if ($debug === NULL) $debug = self::DEFAULT_DEBUG;
        
        $this->is_child = $is_child;
        if ($is_child) {
            if ($check_only) {
                $ready_to_open_fd = $this->check_open_fd();
                echo ($ready_to_open_fd ? 'OK' : 'NG') . "\n";
                exit($ready_to_open_fd ? 0 : 1);
            }
            else {
                $this->start_child($debug);
            }
        }
        else {
            if ($url) $contents_pointer = $this->init($url, $curl_options, $debug);
        }
    }   //  end of __construct()
    
    public  function    __destruct() {
        $this->close_all_fd();
    }   //  end of __destruct()
    
    public  function    init($url, $curl_options=NULL, $debug=NULL) {
        $php_cli = $this->get_php_client_path();
        
        if ($debug) $this->fp_log = fopen(self::PARENT_LOGNAME, 'ab+');
        $this->log('*** init() ***');
        
        $result = FALSE;
        for (;;) {
            if (!self::REUSE_CHILD) $this->close_all_fd();
            
            if (!$this->process) {
                $descriptorspec = array(
                    0 => array('pipe', 'r'), // stdin : child will read from
                    1 => array('pipe', 'w'), // stdout: child will write to
                    2 => array('pipe', 'w'), // stderr: child will write to
                    self::RESULT_FILENO => array('pipe', 'w'), // result: child will write to
                );
                $ready_to_open_fd = $this->check_open_fd();
                $command = sprintf('%s "%s" "%d" ' . (($ready_to_open_fd) ? '2>&1' : ''), $php_cli, __FILE__, ($debug) ? 1 : 0);
                $this->process = proc_open($command, $descriptorspec, $pipes);
                if (!$this->process) {
                    $this->log("Error: proc_open({$command})");
                    break;
                }
                $this->fp_stdin = $pipes[0];
                $this->fp_stdout = $pipes[1];
                //$this->fp_stderr = $pipes[2];
                if ($ready_to_open_fd) fclose($pipes[2]);
                $this->fp_stderr = NULL; // disused
                $this->fp_result = ($ready_to_open_fd) ? $pipes[self::RESULT_FILENO] : $pipes[2];
            }
            if (!is_array($curl_options)) $curl_options = array();
            $this->buffer_size = isset($curl_options['CURLOPT_BUFFERSIZE']) ? $curl_options['CURLOPT_BUFFERSIZE'] : self::DEFAULT_BUFFER_SIZE;
            
            $init_parameters = array(
                'url' => $url,
                'curl_options' => $curl_options,
            );
            
            $this->log('PARAMETERS:');
            $this->log($init_parameters);
            
            if (!$this->write_value($this->fp_stdin, self::TYPE_INIT, $init_parameters)) {
                $this->log(sprintf("Error: write_value(TYPE_INIT(0x%0{$this->TYPE_SIZE}x))", self::TYPE_INIT));
                break;
            }
            $result = TRUE;
            break;
        }
        if (!$result) $this->close_all_fd();
        $this->log('=> ' . (($result === FALSE) ? 'ERROR' : 'OK'));
        
        return $this->get_contents_pointer();
    }   //  end of init()
    
    public  function    get_contents_pointer() {
        $this->log('*** get_contents_pointer() ***');
        $this->log($this->fp_stdout);
        return $this->fp_stdout;
    }   //  end of get_contents_pointer()
    
    public  function    get_contents() {
        $contents = FALSE;
        $this->log('*** get_contents() ***');
        for (;;) {
            $this->log($this->fp_stdout);
            if (!$this->fp_stdout) break;
            $result = $this->read_stream($this->fp_stdout, $contents);
            //$this->log($contents);
            if (!$result) {
                $contents = FALSE;
                break;
            }
            break;
        }
        $this->log('=> ' . (($contents === FALSE) ? 'ERROR' : 'OK'));
        return $contents;
    }   //  end of get_contents()
    
    public  function    get_curl_result() {
        $curl_result = FALSE;
        $this->log('*** get_curl_result() ***');
        for (;;) {
            if (!$this->process || !$this->fp_result) break;
            $result = $this->read_value($this->fp_result, $type, $curl_result);
            if (!$result || $type != self::TYPE_RESULT) $curl_result = FALSE;
            break;
        }
        if (!self::REUSE_CHILD) $this->close_all_fd();
        
        $this->log('=> ' . (($curl_result === FALSE) ? 'ERROR' : 'OK'));
        
        return $curl_result;
    }   //  end of get_curl_result()
    
    //} end of PUBLIC FUNCTIONS

}   //  end of class AsyncCurl()


if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) new AsyncCurl(NULL, NULL, $contents_pointer, (isset($argv[1]) && $argv[1] == '1'), TRUE, (isset($argv[2]) && $argv[2] == '1'));


// ■ end of file
