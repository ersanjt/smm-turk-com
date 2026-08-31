<?php
/**
 * Allowlist HTML sanitizer for admin-authored blog bodies.
 * Strips scripts, event handlers, and dangerous URLs while keeping basic formatting.
 */
class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p' => true, 'br' => true, 'strong' => true, 'b' => true, 'em' => true, 'i' => true,
        'u' => true, 's' => true, 'a' => true, 'ul' => true, 'ol' => true, 'li' => true,
        'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true, 'blockquote' => true,
        'code' => true, 'pre' => true, 'img' => true, 'span' => true, 'hr' => true,
        'table' => true, 'thead' => true, 'tbody' => true, 'tr' => true, 'th' => true, 'td' => true,
        'figure' => true, 'figcaption' => true, 'div' => true,
    ];

    private const ALLOWED_ATTR = [
        'a' => ['href' => true, 'title' => true, 'rel' => true, 'target' => true],
        'img' => ['src' => true, 'alt' => true, 'width' => true, 'height' => true, 'loading' => true],
        'td' => ['colspan' => true, 'rowspan' => true],
        'th' => ['colspan' => true, 'rowspan' => true],
        'code' => ['class' => true],
        'pre' => ['class' => true],
        'div' => ['class' => true],
        'span' => ['class' => true],
        'blockquote' => ['cite' => true],
    ];

    public static function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<div id="smm-sanitize-root">' . $html . '</div>';
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $root = $doc->getElementById('smm-sanitize-root');
        if (!$root) {
            $xpath = new DOMXPath($doc);
            $root = $xpath->query('//*[@id="smm-sanitize-root"]')->item(0);
        }
        if (!$root) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        self::cleanNode($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    private static function cleanNode(DOMNode $node): void
    {
        $toRemove = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMComment) {
                $toRemove[] = $child;
                continue;
            }
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (!isset(self::ALLOWED_TAGS[$tag])) {
                    $toRemove[] = $child;
                    continue;
                }
                self::cleanAttributes($child, $tag);
                self::cleanNode($child);
            }
        }
        foreach ($toRemove as $dead) {
            $parent = $dead->parentNode;
            if ($parent) {
                $parent->removeChild($dead);
            }
        }
    }

    private static function cleanAttributes(DOMElement $el, string $tag): void
    {
        $allowed = self::ALLOWED_ATTR[$tag] ?? [];
        $remove = [];
        foreach ($el->attributes ?? [] as $attr) {
            $name = strtolower($attr->name);
            if (str_starts_with($name, 'on') || !isset($allowed[$name])) {
                $remove[] = $attr->name;
                continue;
            }
            $value = trim($attr->value);
            if (in_array($name, ['href', 'src', 'cite'], true) && !self::isSafeUrl($value, $name === 'src')) {
                $remove[] = $attr->name;
            }
        }
        foreach ($remove as $name) {
            $el->removeAttribute($name);
        }
        if ($tag === 'a') {
            $el->setAttribute('rel', 'noopener noreferrer nofollow');
            if ($el->getAttribute('target') === '_blank') {
                $el->setAttribute('target', '_blank');
            } else {
                $el->removeAttribute('target');
            }
        }
    }

    private static function isSafeUrl(string $url, bool $allowRelative): bool
    {
        if ($url === '' || str_contains($url, "\0")) {
            return false;
        }
        $lower = strtolower($url);
        if (preg_match('#^\s*(javascript|data|vbscript|file):#i', $lower)) {
            return false;
        }
        if (preg_match('#^https?://#i', $url)) {
            return (bool) filter_var($url, FILTER_VALIDATE_URL);
        }
        if ($allowRelative && (str_starts_with($url, '/') || str_starts_with($url, 'assets/') || str_starts_with($url, 'uploads/'))) {
            return !str_contains($url, '..');
        }
        return false;
    }
}
