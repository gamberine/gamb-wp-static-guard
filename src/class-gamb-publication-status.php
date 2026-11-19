<?php
declare(strict_types=1);
namespace GambWPStaticGuard;
if (!defined('ABSPATH')) { exit; }

/** Diferencia entrega concluída de mudanças posteriores na origem. */
class Publication_Status {
    const OPTION='gamb_static_guard_publication';
    const REVISION='gamb_static_guard_source_revision';
    public static function register(): void {
        add_action('save_post',array(__CLASS__,'post_changed'),99,2);
        add_action('before_delete_post',array(__CLASS__,'post_changed'),20,2);
        foreach (array('added_post_meta','updated_post_meta','deleted_post_meta') as $hook) { add_action($hook,array(__CLASS__,'meta_changed'),99,4); }
        foreach (array('wp_update_nav_menu','edited_term','created_term','delete_term','switch_theme','customize_save_after','upgrader_process_complete') as $hook) { add_action($hook,array(__CLASS__,'changed')); }
        add_action('acf/save_post',array(__CLASS__,'acf_saved'),99);
        foreach (array('updated_option','added_option','deleted_option') as $hook) { add_action($hook,array(__CLASS__,'option_changed')); }
    }
    public static function acf_saved($id): void { if (is_numeric($id)) { self::post_changed((int)$id); } else { self::changed(); } }
    public static function option_changed($name): void {
        if (str_starts_with((string)$name,'options_') || str_starts_with((string)$name,'theme_mods_') || in_array($name,array('blogname','blogdescription','page_on_front','page_for_posts','show_on_front','permalink_structure','home','siteurl','stylesheet','template'),true)) { self::changed(); }
    }
    public static function post_changed($id,$post=null): void {
        $post=is_object($post) ? $post : get_post($id);
        if (!$post || wp_is_post_revision($id) || wp_is_post_autosave($id) || (!in_array($post->post_status,array('publish','trash'),true) && !($post->post_type==='attachment' && $post->post_status==='inherit'))) { return; }
        $type=get_post_type_object($post->post_type);
        if ($type && $type->public) { self::changed(); }
    }
    public static function meta_changed($meta_id,$post_id,$key,$value=null): void { if (!in_array($key,array('_edit_lock','_edit_last'),true)) { self::post_changed($post_id); } }
    public static function changed(): void { update_option(self::REVISION,wp_generate_uuid4(),false); }
    public static function revision(): string { return (string)get_option(self::REVISION,''); }
    public static function destination(): string {
        $options=Options::instance(); $values=array();
        foreach (array('delivery_method','local_dir','destination_url_type','relative_path','destination_host') as $key) { $values[$key]=$options->get($key,''); }
        return hash('sha256',wp_json_encode($values));
    }
    public static function complete(string $revision): void {
        $previous=get_option(self::OPTION,array());
        $count=(int)($previous['count'] ?? (Options::instance()->get('first_full_export_completed',false) ? 1 : 0));
        update_option(self::OPTION,array('revision'=>$revision,'destination'=>self::destination(),'at'=>current_time('mysql'),'count'=>$count+1),false);
    }
    public static function snapshot(): array {
        $record=get_option(self::OPTION,array()); $options=Options::instance();
        $published=!empty($record['at']) || $options->get('first_full_export_completed',false);
        $dirty=$published && (self::revision() !== (string)($record['revision'] ?? '') || (isset($record['destination']) && $record['destination']!==self::destination()));
        $state=!$published ? 'unpublished' : ($dirty ? 'outdated' : ((int)($record['count'] ?? 1)>1 ? 'updated' : 'published'));
        $labels=array('unpublished'=>'Não publicado','outdated'=>'Desatualizado','updated'=>'Atualizado','published'=>'Publicado');
        return array('state'=>$state,'label'=>$labels[$state],'tone'=>$state==='outdated' ? 'warning' : ($published ? 'success' : 'neutral'),'published_at'=>$record['at'] ?? $options->get('last_export_time',''),'message'=>$dirty ? 'Há alterações posteriores na origem. Execute a publicação completa.' : ($published ? 'Há uma publicação completa registrada.' : 'Execute a primeira publicação completa.'),'first_full_export_completed'=>(bool)$options->get('first_full_export_completed',false));
    }
}
