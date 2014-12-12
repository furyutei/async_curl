AsyncCurl
=========
非同期ダウンロード用 cURL(PHP) wrapper  
- License: The MIT license  
- Copyright (c) 2014 風柳(furyu)  

概要
----
データをダウンロードしつつ逐次処理を行うための [PHP 用 cURL](http://php.net/manual/ja/ref.curl.php) wrapper。  


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
ページ内容を逐次取得しつつ、ファイルに書き込み＆読み込んだサイズを画面に表示していく。
```php
<?php
require_once('async_curl/async_curl.php');

$curl_options = array(
    CURLOPT_HEADER => FALSE,
    CURLOPT_FOLLOWLOCATION => TRUE,
    CURLOPT_USERAGENT => 'AsyncCurl',
);

$async_curl = new AsyncCurl('http://d.hatena.ne.jp/furyu-tei', $curl_options, $contents_pointer);

$max_fragment_size = 100;
$total_size = 0;
$fp = fopen('test.bin', 'w');
while (!feof($contents_pointer)) {
    $fragment = fread($contents_pointer, $max_fragment_size);
    $size = strlen($fragment);
    fwrite($fp, $fragment, $size);
    $total_size += $size;
    echo "\rSize:{$total_size}";
    echo $status . str_repeat(' ', 80 - strlen($status));
}
echo("\n");
$curl_result = $async_curl->get_curl_result();
var_dump($curl_result);
?>
```
※ 使い方については、[test/test_async_curl.php](https://github.com/furyutei/async_curl/blob/master/test/test_async_curl.php) も参照。  


関連記事
--------
- [ダウンロードしつつ逐次処理できるcURL wrapperを試作 - 風柳メモ](http://d.hatena.ne.jp/furyu-tei/20141213/1418397266)
