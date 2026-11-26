<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$args=getopt('',array('wp-root:','public-url:','check-url:'));
$root=realpath($args['wp-root']??getcwd());
if(!$root||!is_file($root.'/wp-load.php')){fwrite(STDERR,"Instalação WordPress inválida.\n");exit(2);}
define('WP_CLI',false);define('WP_ADMIN',true);define('DISABLE_WP_CRON',true);
ob_start();require $root.'/wp-load.php';ob_end_clean();
if(!class_exists('GambWPStaticGuard\Origin_Context')){fwrite(STDERR,"Ative o Gamb WP Static Guard atualizado.\n");exit(2);}
$canonical=\GambWPStaticGuard\Origin_Context::canonical();
if(isset($args['check-url'])&&!\GambWPStaticGuard\Origin_Context::valid_alias($args['check-url'],$canonical)){fwrite(STDERR,"Origem inválida: preserve o caminho da instalação, HTTPS público e o subdomínio.\n");exit(2);}
$status=\GambWPStaticGuard\Publication_View::status();
echo json_encode(array('canonical'=>$canonical,'ready'=>$status['ready'],'mode'=>$status['mode'],'url_type'=>\GambWPStaticGuard\Options::instance()->get('destination_url_type'),'message'=>$status['message']),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
