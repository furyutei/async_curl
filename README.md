AsyncCurl
=========
非同期ダウンロード用 cURL(PHP) wrapper  
　License: The MIT license  
　Copyright (c) 2014 風柳(furyu)  

概要
----
データをダウンロードしつつ逐次処理を行うための PHP 用 cURL wrapper。  


使い方
------
### 準備
1. async_curl_options.php.sample を async_curl_options.php にリネームし、ファイル中の  
```php
$PHP_CLI = '/usr/local/bin/php';
```
を、自環境のCLI版PHPのあるPATHに変更。  

2. async_curl.php 及び async_curl_options.php を PHPのパスが通っている場所に async_curl ディレクトリを作成してその下にコピー。  


### 使用例
```php
require_once('async_curl/async_curl.php');

$curl_options = array(
    CURLOPT_HEADER => FALSE,
    CURLOPT_FOLLOWLOCATION => TRUE,
    CURLOPT_USERAGENT => 'AsyncCurl',
);

$async_curl = new AsyncCurl('http://www.google.co.jp/', $curl_options);

$fp_contents_pointer = $async_curl->get_contents_pointer();

$total_size = 0;
$fp = fopen('test.bin', 'w');
while (!feof($fp_contents_pointer)) {
    $fragment = fread($fp_contents_pointer, 8192);
    $size = strlen($fragment);
    fwrite($fp, $fragment, $size);
    $total_size += $size;
    echo "\rSize:{$total_size}";
    echo $status . str_repeat(' ', 80 - strlen($status));
}
echo("\n");
$curl_result = $async_curl->get_curl_result();
var_dump($curl_result);

```
※ 使い方については、test/test_async_curl.php も参照。  