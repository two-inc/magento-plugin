<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Ui;

/**
 * Reduces buyer-facing copy to text plus links: an `<a>` with an http(s) href
 * survives, every other tag is dropped and its text kept, and all other markup
 * is escaped. Copy whose contract is plain text goes through escapeTextOnly()
 * instead, which keeps no tag at all - a dropped tag there would hide the
 * translator's typo rather than show it.
 *
 * Surviving anchors are rebuilt from their allowed attributes, so no attribute
 * this module does not itself emit can reach the page. The href itself is only
 * checked for scheme and userinfo, not vouched for - whoever writes the copy
 * chooses where an http(s) link points. `target` and `rel` are matched
 * case-insensitively, as browsers treat those keywords; `rel` is read as a
 * token set, and a kept `target="_blank"` always carries `rel="noopener"`.
 */
class AnchorOnlyHtmlEscaper
{
    private const ALLOWED_TARGET = '_blank';
    private const ALLOWED_REL = 'noopener';

    /** @param mixed $html */
    public function escape($html): string
    {
        // Only a name-like tag opens markup; a stray '<' stays text rather than
        // swallowing the copy up to the next '>'.
        $parts = preg_split(
            '/(<\/?[a-zA-Z][^>]*>)/',
            $this->stripControlCharacters((string) $html),
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );
        if ($parts === false) {
            return '';
        }

        $result = '';
        $openAnchors = 0;
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $result .= $this->escapeText($part);
                continue;
            }

            if (preg_match('/^<\/a\s*>$/i', $part)) {
                if ($openAnchors > 0) {
                    $result .= '</a>';
                    $openAnchors--;
                }
                continue;
            }

            // A nested anchor is invalid HTML the browser would unnest anyway;
            // its text is kept, its tag is not.
            if ($openAnchors === 0 && preg_match('/^<a\s[^>]*>$/i', $part)) {
                $anchor = $this->rebuildAnchor($part);
                if ($anchor !== '') {
                    $result .= $anchor;
                    $openAnchors++;
                }
            }
        }

        return $result . str_repeat('</a>', $openAnchors);
    }

    /**
     * Whether escaping leaves the value's content alone - the admin
     * accept/reject boundary, so it is the render boundary.
     * Entity encoding is not a change; only markup this class drops or
     * rewrites fails.
     *
     * @param mixed $html
     */
    public function rendersUnchanged($html): bool
    {
        $html = (string) $html;

        return html_entity_decode($this->escape($html), ENT_QUOTES, 'UTF-8')
            === html_entity_decode($html, ENT_QUOTES, 'UTF-8');
    }

    /** Plain-text copy: even a valid anchor is markup here, not a link. */
    public function escapeTextOnly(string $text): string
    {
        return $this->escapeText($this->stripControlCharacters($text));
    }

    private function escapeText(string $text): string
    {
        // ENT_SUBSTITUTE: without it one malformed byte blanks the whole run.
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }

    private function stripControlCharacters(string $text): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    }

    private function rebuildAnchor(string $tag): string
    {
        $attributes = $this->attributes($tag);
        $href = html_entity_decode(trim($attributes['href'] ?? ''), ENT_QUOTES, 'UTF-8');
        if (!preg_match('/^https?:\/\//i', $href)) {
            return '';
        }
        // Userinfo is the classic spoof: everything before the '@' reads as the host.
        if (preg_match('/^https?:\/\/[^\/?#]*@/i', $href)) {
            return '';
        }

        $opensNewTab = strtolower(trim($attributes['target'] ?? '')) === self::ALLOWED_TARGET;
        $relTokens = preg_split('/\s+/', strtolower(trim($attributes['rel'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);

        $anchor = '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        if ($opensNewTab) {
            $anchor .= ' target="' . self::ALLOWED_TARGET . '"';
        }
        // A new tab without noopener hands the opener over, so the pair is not the copy's to split.
        if ($opensNewTab || in_array(self::ALLOWED_REL, $relTokens, true)) {
            $anchor .= ' rel="' . self::ALLOWED_REL . '"';
        }

        return $anchor . '>';
    }

    /** @return array<string, string> */
    private function attributes(string $tag): array
    {
        preg_match_all(
            '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/',
            $tag,
            $matches,
            PREG_SET_ORDER
        );

        $attributes = [];
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            if (!isset($attributes[$name])) {
                // PREG_SET_ORDER truncates each set at the last participating
                // group, so an empty quoted value leaves later groups absent.
                $attributes[$name] = $match[2] !== ''
                    ? $match[2]
                    : (($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? ''));
            }
        }

        return $attributes;
    }
}
