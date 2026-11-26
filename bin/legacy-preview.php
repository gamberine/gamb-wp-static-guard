<?php
/** Copie para avaliacao-temporaria/index.php ao substituir o antigo proxy anônimo. */
header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow, noarchive');header('X-Content-Type-Options: nosniff');
$path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)?:'';
// Apenas um redirecionamento relativo: nunca captura WordPress, cookies ou credenciais.
$marker='/avaliacao-temporaria/estatico/';$offset=strpos($path,$marker);
if($offset!==false&&in_array($_SERVER['REQUEST_METHOD']??'GET',array('GET','HEAD'),true)){
 $prefix=substr($path,0,$offset);$suffix=substr($path,$offset+strlen($marker));
 if(!preg_match('~[\\\\\x00-\x20]|%(?:2e|2f|5c)|(?:^|/)\.{1,2}(?:/|$)~i',$prefix.'/'.$suffix)){
  header('Location: '.$prefix.'/'.$suffix,true,302);exit;
 }
}
http_response_code(410);header('Content-Type: text/plain; charset=UTF-8');
echo 'A prévia anônima foi encerrada. A URL principal serve o estático. Para validar o original, entre no admin e ative o acesso WP privado.';
