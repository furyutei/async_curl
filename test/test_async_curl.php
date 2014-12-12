<?php

//{ parameters
$debug = true;
$buffer_size = 8192;
$lump_threshold = 5 * 1024 * 1024;
$output_filename = './download.bin';
//}

require_once(dirname(__FILE__) . '/../async_curl.php');

$url = isset($argv[1]) ? $argv[1] : 'http://www.google.com/';

$async_curl = new AsyncCurl();

echo "[HEAD] {$url}\n";
$curl_options = array(
    CURLOPT_NOBODY => TRUE, // HTTP HEAD Request
    CURLOPT_HEADER => TRUE, // OUTPUT HEADER
);

$async_curl->init($url, $curl_options, $debug);

$header = $async_curl->get_contents();
echo("<HTTP RESPONSE HEADER(S)>\n");
echo "{$header}\n";

$curl_result = $async_curl->get_curl_result();
echo("<Result>\n");
var_dump($curl_result);

$final_url = (isset($curl_result['info']['url'])) ? $curl_result['info']['url'] : $url;
$content_length = (isset($curl_result['info']['download_content_length'])) ? $curl_result['info']['download_content_length'] : -1;
echo "{$final_url}\n";
echo "Content Length: {$content_length}\n";
echo str_repeat('=', 80) . "\n";

if ($content_length <= $lump_threshold) {
    echo "[GET (lump)] {$final_url}\n";
    $curl_options[CURLOPT_NOBODY] = FALSE;
    $curl_options[CURLOPT_HEADER] = FALSE;
    
    $async_curl->init($final_url, $curl_options, $debug);
    
    $contents = $async_curl->get_contents();
    $total_size = strlen($contents);
    echo "Size:{$total_size}\n";
    
    $curl_result = $async_curl->get_curl_result();
    echo("<Result>\n");
    var_dump($curl_result);
    
    $final_url = (isset($curl_result['info']['url'])) ? $curl_result['info']['url'] : $url;
    $content_length = (isset($curl_result['info']['download_content_length'])) ? $curl_result['info']['download_content_length'] : -1;
    echo "{$final_url}\n";
    echo "Content Length: {$content_length}\n";
    echo str_repeat('=', 80) . "\n";
}

echo "[GET (streaming)]  {$final_url}\n";
$curl_options[CURLOPT_NOBODY] = FALSE;
$curl_options[CURLOPT_HEADER] = FALSE;

$async_curl->init($final_url, $curl_options, $debug);
$fp_contents_pointer = $async_curl->get_contents_pointer();

$fp = fopen($output_filename, 'wb');

$total_size = 0;
$max_fragment_size = 0;
while (!feof($fp_contents_pointer)) {
    $fragment = fread($fp_contents_pointer, $buffer_size);
    if ($fragment === FALSE) {
        echo "*** Error: fread()\n";
        break;
    }
    $size = strlen($fragment);
    if (fwrite($fp, $fragment, $size) === FALSE) {
        echo "*** Error: fwrite()\n";
        break;
    }
    $total_size += $size;
    if ($max_fragment_size < $size) $max_fragment_size = $size;
    $status = "\rSize:{$total_size} (fragment:{$size} max:{$max_fragment_size})";
    echo $status . str_repeat(' ', 80 - strlen($status));
}
fclose($fp);

echo "\n";
$curl_result = $async_curl->get_curl_result();
echo("<Result>\n");
var_dump($curl_result);

$final_url = (isset($curl_result['info']['url'])) ? $curl_result['info']['url'] : $url;
$content_length = (isset($curl_result['info']['download_content_length'])) ? $curl_result['info']['download_content_length'] : -1;
echo "{$final_url}\n";
echo "Content-Length: {$content_length}\n";
echo str_repeat("=", 80) . "\n";

// ■ end of file
