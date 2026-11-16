<?php
declare(strict_types=1);
namespace GambWPStaticGuard;
if (!defined('ABSPATH')) { exit; }

final class Gamb_DOM_Parser {
    public static function attribute(object $element, string $name): string { return (string)$element->getAttribute($name); }
    public static function parse(string $html): object {
        $previous = libxml_use_internal_errors(true);
        try {
            if (class_exists('\\Dom\\HTMLDocument')) {
                return \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
            }
            if (!class_exists('\\DOMDocument') || !function_exists('mb_encode_numericentity')) {
                throw new \RuntimeException('As extensões DOM e mbstring são necessárias para auditar a publicação.');
            }
            // Conteúdo raw-text não pode receber entidades HTML dentro de JavaScript ou CSS.
            $raw = array();
            $prepared = preg_replace_callback('~(<(script|style)\b(?:[^>"\x27]|"[^"]*"|\x27[^\x27]*\x27)*>)(.*?)(</\2\s*>)~is', static function(array $match) use (&$raw): string {
                $key = 'GAMB_RAW_' . hash('sha256', $match[3]) . '_' . count($raw);
                $raw[$key] = $match[3];
                return $match[1] . $key . $match[4];
            }, $html);
            $document = new \DOMDocument('1.0', 'UTF-8');
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            $encoded = mb_encode_numericentity((string)$prepared, array(0x80, 0x10ffff, 0, 0x1fffff), 'UTF-8');
            if (!$document->loadHTML($encoded, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new \RuntimeException('Não foi possível interpretar o documento HTML.');
            }
            $document->encoding = 'UTF-8';
            foreach (array('script', 'style') as $tag) {
                foreach ($document->getElementsByTagName($tag) as $element) {
                    $key = $element->textContent;
                    if (array_key_exists($key, $raw)) { self::raw_text($element, $raw[$key]); }
                }
            }
            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function html(object $document): string {
        $raw = array(); $restore = array();
        foreach (array('script', 'style') as $tag) {
            foreach ($document->getElementsByTagName($tag) as $element) {
                $text = $element->textContent;
                $key = 'GAMB_SERIAL_RAW_' . hash('sha256', $text) . '_' . count($raw);
                $raw[$key] = $text; $restore[] = array($element, $text); self::raw_text($element, $key);
            }
        }
        try { return strtr((string)$document->saveHTML(), $raw); }
        finally { foreach ($restore as [$element, $text]) { self::raw_text($element, $text); } }
    }
    public static function raw_text(object $element, string $text): void {
        if ($element instanceof \DOMElement) {
            while ($element->firstChild) { $element->removeChild($element->firstChild); }
            $element->appendChild($element->ownerDocument->createCDATASection($text));
        } else { $element->textContent = $text; }
    }
    public static function elements(object $document): array {
        $elements = array();
        foreach ($document->getElementsByTagName('*') as $element) { $elements[] = $element; }
        return $elements;
    }
}
