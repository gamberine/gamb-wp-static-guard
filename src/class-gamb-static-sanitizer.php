<?php
declare(strict_types=1);
namespace GambWPStaticGuard;
if (!defined('ABSPATH')) { exit; }

final class Gamb_Static_Sanitizer {
    public static function native_class(string $class): bool {
        return (bool)preg_match('/^(?:wp-|page-template|page-id-\d+|postid-\d+|single-|author-|error404|logged-in|admin-bar)/i', $class);
    }
    public static function style_whitelist(): array {
        $value = Options::instance()->get('sanitizer_style_whitelist', '');
        return array_values(array_filter(preg_split('/\s+/', is_string($value) ? $value : '') ?: array()));
    }
    public static function normalize_text(string $text): string {
        return str_replace(array("\u{2013}", "\u{2014}", '&#8211;', '&#8212;', '&mdash;', '&ndash;'), '-', $text);
    }
    public static function clean_css(string $css): string {
        $css = preg_replace('~/\*[#@]\s*source(?:Mapping)?URL=.*?\*/~is', '', $css);
        return (string)preg_replace_callback('~(:root\b[^{}]*\{)([^{}]*)(\})~i', static function(array $match): string {
            $declarations = preg_split('/;(?=(?:[^"\x27]*["\x27][^"\x27]*["\x27])*[^"\x27]*$)/', $match[2]) ?: array();
            $keep = array_filter($declarations, static fn(string $declaration): bool => !preg_match('/^\s*--(?:wp--preset--|wp-admin-|wp-block-)[^:]*:/i', $declaration));
            return $match[1] . implode(';', $keep) . $match[3];
        }, $css);
    }

    public static function sanitize(string $html): string {
        $guard = Gamb_Security_Suite::get_instance();
        $html = $guard->rewrite_wp_paths_to_tokens($html);
        $document = Gamb_DOM_Parser::parse($html);
        $allowed = self::style_whitelist(); $origins = array();
        $head = $document->getElementsByTagName('head')->item(0);
        foreach (Gamb_DOM_Parser::elements($document) as $element) {
            $tag = strtolower($element->tagName);
            if (('script' === $tag && 'speculationrules' === strtolower(Gamb_DOM_Parser::attribute($element, 'type'))) || ('style' === $tag && preg_match('/^(wp-|global-styles|classic-theme|core-block)/i', Gamb_DOM_Parser::attribute($element, 'id')) && !in_array(Gamb_DOM_Parser::attribute($element, 'id'), $allowed, true)) || ('meta' === $tag && 'generator' === strtolower(Gamb_DOM_Parser::attribute($element, 'name'))) || ('link' === $tag && preg_match('/(?:^|\s)(?:EditURI|wlwmanifest|shortlink)(?:\s|$)/i', Gamb_DOM_Parser::attribute($element, 'rel')))) {
                $element->parentNode?->removeChild($element); continue;
            }
            if ($element->hasAttribute('class')) {
                $classes = array_filter(preg_split('/\s+/', trim(Gamb_DOM_Parser::attribute($element, 'class'))) ?: array(), static fn(string $class): bool => !self::native_class($class));
                if ($classes) { $element->setAttribute('class', implode(' ', $classes)); } else { $element->removeAttribute('class'); }
            }
            $attributes = array();
            foreach ($element->attributes as $attribute) { $attributes[$attribute->name] = $attribute->value; }
            foreach ($attributes as $name => $value) {
                // URLs, CSS e handlers têm semântica própria; normalizar somente atributos textuais.
                if (!preg_match('/^(?:src|href|srcset|action|poster|style|on)/i', $name)) { $element->setAttribute($name, self::normalize_text($value)); }
            }
            if ('a' === $tag && '_blank' === strtolower(Gamb_DOM_Parser::attribute($element, 'target'))) {
                $rel = array_filter(preg_split('/\s+/', strtolower(Gamb_DOM_Parser::attribute($element, 'rel'))) ?: array());
                $element->setAttribute('rel', implode(' ', array_unique(array_merge($rel, array('noopener', 'noreferrer')))));
            }
            if ('style' === $tag) { Gamb_DOM_Parser::raw_text($element, self::clean_css($element->textContent)); }
            if (in_array($tag, array('img', 'source', 'video', 'audio', 'iframe', 'embed'), true)) {
                self::record_origin(Gamb_DOM_Parser::attribute($element, 'src'), $origins);
                self::record_origin(Gamb_DOM_Parser::attribute($element, 'poster'), $origins);
                $srcset = Gamb_DOM_Parser::attribute($element, 'srcset');
                if (!str_contains($srcset, 'data:')) { foreach (explode(',', $srcset) as $candidate) { self::record_origin(preg_split('/\s+/', trim($candidate))[0], $origins); } }
            }
            if ('link' === $tag && preg_match('/(?:^|\s)(?:preload|modulepreload|icon)(?:\s|$)/i', Gamb_DOM_Parser::attribute($element, 'rel'))) { self::record_origin(Gamb_DOM_Parser::attribute($element, 'href'), $origins); }
            if ('script' === $tag || ('link' === $tag && in_array('stylesheet', preg_split('/\s+/', strtolower(Gamb_DOM_Parser::attribute($element, 'rel'))) ?: array(), true))) {
                $attribute = 'script' === $tag ? 'src' : 'href';
                $url = Gamb_DOM_Parser::attribute($element, $attribute);
                if ('' !== $url) {
                    if (!Installation_Checks::external_url($url)) { $url = remove_query_arg('ver', $url); $element->setAttribute($attribute, $url); }
                    if (Installation_Checks::external_url($url)) {
                        $absolute = str_starts_with($url, '//') ? 'https:' . $url : $url;
                        $parts = wp_parse_url($absolute);
                        if (!empty($parts['host']) && strtolower($parts['scheme'] ?? '') === 'https') {
                            $origin = 'https://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : ''); $origins[$origin] = true;
                            if (!('link' === $tag && strtolower($parts['host']) === 'fonts.googleapis.com')) {
                                if (!preg_match('/^(?:sha(?:256|384|512)-[A-Za-z0-9+\/=]+\s*)+$/D', Gamb_DOM_Parser::attribute($element, 'integrity'))) { $element->setAttribute('integrity', self::remote_integrity($absolute)); }
                                $element->setAttribute('crossorigin', 'anonymous');
                            }
                        } else { throw new \RuntimeException('CRITICAL: dependência externa de script ou estilo sem HTTPS.'); }
                    } elseif ('script' === $tag && !$element->hasAttribute('defer') && !$element->hasAttribute('async')) {
                        $element->setAttribute('defer', 'defer');
                    }
                }
            }
        }
        self::clean_text_nodes($document);
        if ($head) {
            $existing = array();
            foreach ($document->getElementsByTagName('link') as $link) { if ('preconnect' === strtolower(Gamb_DOM_Parser::attribute($link, 'rel'))) { $existing[rtrim(Gamb_DOM_Parser::attribute($link, 'href'), '/')] = $link; } }
            foreach (array_keys($origins) as $origin) {
                $link = $existing[$origin] ?? $document->createElement('link');
                $link->setAttribute('rel', 'preconnect'); $link->setAttribute('href', $origin); $link->setAttribute('crossorigin', 'anonymous');
                if (!isset($existing[$origin])) { $head->appendChild($link); }
            }
        }
        $html = Gamb_DOM_Parser::html($document);
        $html = preg_replace('~/\*[#@]\s*source(?:Mapping)?URL=.*?\*/~is', '', $html);
        $guard->register_tokens_in_content((string)$html);
        return (string)$html;
    }

    private static function clean_text_nodes(object $node): void {
        $children = array(); foreach ($node->childNodes as $child) { $children[] = $child; }
        foreach ($children as $child) {
            if ($child->nodeType === 8) { $node->removeChild($child); continue; }
            if ($child->nodeType === 3 && !in_array(strtolower((string)$node->nodeName), array('script', 'style'), true)) { $child->nodeValue = self::normalize_text((string)$child->nodeValue); }
            if ($child->hasChildNodes()) { self::clean_text_nodes($child); }
        }
    }
    private static function record_origin(string $url, array &$origins): void {
        if (str_starts_with($url, '//')) { $url = 'https:' . $url; }
        if (!Installation_Checks::external_url($url)) { return; }
        $parts = wp_parse_url($url);
        if ($parts && ($parts['scheme'] ?? '') === 'https' && empty($parts['user']) && empty($parts['pass'])) {
            $origins['https://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '')] = true;
        }
    }

    public static function remote_integrity(string $url): string {
        $parts = wp_parse_url($url);
        if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https' || !empty($parts['user']) || !empty($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443) || !wp_http_validate_url($url)) { throw new \RuntimeException('CRITICAL: endereço remoto recusado pela validação de segurança.'); }
        $key = 'gamb_sri_' . get_option('gamb_wp_static_guard_sri_epoch', '0') . '_' . hash('sha256', $url);
        $cached = get_transient($key);
        if (is_string($cached) && preg_match('/^sha384-[A-Za-z0-9+\/]{64}$/D', $cached)) { return $cached; }
        $response = wp_safe_remote_get($url, array('timeout' => 15, 'redirection' => 0, 'limit_response_size' => 5242881, 'headers' => array('Accept-Encoding' => 'identity')));
        if (is_wp_error($response) || (int)wp_remote_retrieve_response_code($response) !== 200) { throw new \RuntimeException('CRITICAL: não foi possível conferir a integridade de um recurso externo.'); }
        $body = (string)wp_remote_retrieve_body($response);
        if ('' === $body || strlen($body) > 5242880) { throw new \RuntimeException('CRITICAL: recurso externo vazio ou acima do limite de 5 MiB.'); }
        $integrity = 'sha384-' . base64_encode(hash('sha384', $body, true));
        set_transient($key, $integrity, 7 * DAY_IN_SECONDS);
        return $integrity;
    }
    public static function invalidate_sri_cache(): void { update_option('gamb_wp_static_guard_sri_epoch', (string)time() . '_' . bin2hex(random_bytes(4)), false); }
}
