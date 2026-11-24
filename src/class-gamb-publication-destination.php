<?php
declare(strict_types=1);
namespace GambWPStaticGuard;
if (!defined('ABSPATH')) { exit; }

/** Uma única validação para o formulário, criação explícita e entrega da exportação. */
final class Publication_Destination {
    public static function inspect($value, bool $create = false, bool $audit_read_only = false) {
        if($audit_read_only&&$create){return new \WP_Error('readonly_destination','A inspeção de auditoria não cria pastas.');}
        if (!is_string($value)) { return new \WP_Error('invalid_local','Informe um caminho absoluto.'); }
        $path=wp_normalize_path(trim($value));
        if (preg_match('~:~',preg_replace('~^[A-Za-z]:~','',$path)) || preg_match('~(?:^|/)(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|/|$)~i',$path)) { return new \WP_Error('invalid_local','O caminho contém um nome reservado ou um fluxo alternativo de arquivo.'); }
        if (''===$path || preg_match('~[\x00-\x1f<>"|?*]|(?:^|/)\.{1,2}(?:/|$)|[. ](?:/|$)~',$path) || str_starts_with($path,'//') || !preg_match('~^(?:[A-Za-z]:/|/)~',$path)) { return new \WP_Error('invalid_local','Use um caminho absoluto, sem atalhos, segmentos de navegação, links de rede ou caracteres de controle.'); }
        $path=rtrim($path,'/'); $ancestor=$path; $missing=array();
        while (!file_exists($ancestor) && !is_link($ancestor)) {
            $parent=dirname($ancestor); if ($parent===$ancestor || '.'===$parent) { return new \WP_Error('invalid_local','O caminho não possui uma pasta existente acessível.'); }
            array_unshift($missing,basename($ancestor)); $ancestor=$parent;
        }
        $real=realpath($ancestor);
        if (!$real || !is_dir($real)) { return new \WP_Error('invalid_local','Existe um arquivo ou uma pasta inacessível no caminho informado.'); }
        if (strtolower(rtrim(wp_normalize_path($real),'/'))!==strtolower(rtrim(wp_normalize_path($ancestor),'/'))) { return new \WP_Error('unsafe_local','Selecione a pasta real, sem links simbólicos ou junções.'); }
        for ($part=$ancestor; ; $part=dirname($part)) { if (is_link($part)) { return new \WP_Error('unsafe_local','Links simbólicos não são aceitos para publicação.'); } if (dirname($part)===$part) { break; } }
        $resolved=rtrim(wp_normalize_path($real),'/').($missing?'/'.implode('/',$missing):'');
        $target=strtolower(trailingslashit($resolved)); $wp=strtolower(trailingslashit(wp_normalize_path(realpath(ABSPATH) ?: ABSPATH)));
        if (str_starts_with($wp,$target) || preg_match('~^(?:[A-Za-z]:)?/$~',$target)) { return new \WP_Error('unsafe_local','A raiz do WordPress, suas pastas ancestrais e a raiz do disco são protegidas.'); }
        foreach (array(WP_CONTENT_DIR,ABSPATH.'wp-admin',ABSPATH.(defined('WPINC')?WPINC:'wp-includes'),ABSPATH.'.codex-artifacts') as $protected) {
            if (str_starts_with($target,strtolower(trailingslashit(wp_normalize_path($protected))))) { return new \WP_Error('unsafe_local','A publicação deve ficar fora das pastas internas do WordPress.'); }
        }
        if(!$audit_read_only){foreach (array('wp-config.php','wp-load.php','.git','.env') as $marker) { if (file_exists($resolved.'/'.$marker)) { return new \WP_Error('unsafe_local','Esta pasta contém uma aplicação. Selecione uma pasta exclusiva para o site estático.'); } }}
        $exists=!$missing; $writable=is_writable($real);
        if ($create && !$exists) {
            if (!$writable || realpath($ancestor)!==$real || !wp_mkdir_p($resolved)) { return new \WP_Error('local_create_failed','Não foi possível criar a pasta. Confira as permissões do servidor.'); }
            clearstatcache(); $checked=self::inspect($resolved);
            if (is_wp_error($checked)) { return $checked; }
            $checked['created']=true; $checked['message']='Pasta criada e validada. Salve as configurações para usá-la.'; return $checked;
        }
        return array('path'=>$resolved,'exists'=>$exists,'writable'=>$writable,'can_create'=>!$exists && $writable,'created'=>false,'message'=>$exists?($writable?'Pasta existente, gravável e fora das áreas protegidas.':'Pasta existente, sem permissão de gravação.'):($writable?'Pasta inexistente. Ela pode ser criada pelo botão Criar pasta.':'A pasta não existe e o diretório superior não é gravável.'));
    }
    public static function validate($path, bool $audit_read_only = false) {
        $result=self::inspect($path,false,$audit_read_only);
        if (is_wp_error($result)) { return $result; }
        return $result['exists'] && ($audit_read_only || $result['writable']) ? $result['path'] : new \WP_Error('invalid_local',$result['message']);
    }
}
