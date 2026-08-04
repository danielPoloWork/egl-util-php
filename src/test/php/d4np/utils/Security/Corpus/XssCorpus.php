<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Security\Corpus;

/**
 * Payloads from the OWASP XSS Filter Evasion cheat sheet, and the mutation-XSS family.
 *
 * Kept as data in one place because two suites consume it for different purposes: the snapshot
 * suite records what {@see \D4np\Utils\Security\Escaper} *does* with each payload, and the
 * invariant assertions check what must be *true* of that output. One corpus, two questions.
 *
 * Every entry is named for the technique it uses rather than the tag it happens to contain,
 * because the technique is what a reader needs in order to judge whether the corpus still covers
 * the threat after it is edited.
 */
final class XssCorpus
{
    private function __construct()
    {
        // Static-only: no instances.
    }

    /**
     * Payloads aimed at HTML, attribute, JavaScript and URL contexts.
     *
     * @return array<string, string>
     */
    public static function escaperPayloads(): array
    {
        return [
            // --- the canonical opener ---------------------------------------------------------
            'script-alert' => '<script>alert(1)</script>',
            'script-src' => '<script src=https://evil.example/x.js></script>',

            // --- tag-less: no `<` needed, which is what defeats "strip angle brackets" ---------
            'img-onerror' => '<img src=x onerror=alert(1)>',
            'img-onerror-no-quotes' => '<img src=x onerror=alert(String.fromCharCode(88))>',
            'svg-onload' => '<svg/onload=alert(1)>',
            'body-onload' => '<body onload=alert(1)>',
            'input-onfocus-autofocus' => '<input onfocus=alert(1) autofocus>',
            'iframe-srcdoc' => '<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;">',
            'details-ontoggle' => '<details open ontoggle=alert(1)>',

            // --- attribute break-out: the case `html()` cannot cover -------------------------
            'attr-breakout-double' => '" onfocus=alert(1) autofocus="',
            'attr-breakout-single' => "' onfocus=alert(1) autofocus='",
            'attr-breakout-unquoted' => 'x onmouseover=alert(1)',
            'attr-breakout-backtick' => '` onmouseover=alert(1) `',
            'attr-breakout-newline' => "x\nonmouseover=alert(1)",
            'attr-breakout-tab' => "x\tonmouseover=alert(1)",
            'attr-breakout-formfeed' => "x\x0conmouseover=alert(1)",
            'attr-breakout-slash' => 'x/onmouseover=alert(1)',

            // --- javascript-context break-out ------------------------------------------------
            'js-string-breakout' => "';alert(1);//",
            'js-string-breakout-double' => '";alert(1);//',
            'js-script-close' => '</script><script>alert(1)</script>',
            'js-backslash' => "\\';alert(1);//",
            'js-template-literal' => '`${alert(1)}`',
            'js-line-separator' => "';\u{2028}alert(1);//",
            'js-paragraph-separator' => "';\u{2029}alert(1);//",

            // --- URL context -----------------------------------------------------------------
            'url-javascript-scheme' => 'javascript:alert(1)',
            'url-javascript-mixed-case' => 'JaVaScRiPt:alert(1)',
            'url-data-html' => 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
            'url-vbscript' => 'vbscript:msgbox(1)',
            'url-javascript-with-entity' => 'java&#115;cript:alert(1)',
            'url-javascript-with-newline' => "java\nscript:alert(1)",

            // --- encoding and parser confusion -----------------------------------------------
            'entity-encoded-script' => '&lt;script&gt;alert(1)&lt;/script&gt;',
            'decimal-entities' => '&#60;script&#62;alert(1)&#60;/script&#62;',
            'hex-entities' => '&#x3C;script&#x3E;alert(1)&#x3C;/script&#x3E;',
            'null-byte-in-tag' => "<scr\0ipt>alert(1)</scr\0ipt>",
            'html-comment-wrapped' => '<!--<script>alert(1)</script>-->',
            'conditional-comment' => '<!--[if IE]><script>alert(1)</script><![endif]-->',
            'overlong-utf8-lt' => "\xC0\xBCscript\xC0\xBEalert(1)",
            'unicode-lookalike-quote' => "admin\u{2019} onmouseover=alert(1)",
            'bidi-override' => "\u{202E}tpircs<>(1)trela",

            // --- shapes that are legitimate text and must survive intact ---------------------
            'plain-text' => 'Grace Hopper',
            'text-with-ampersand' => 'Tom & Jerry',
            'text-multibyte' => 'héllo 漢 🙂',
            'empty' => '',
        ];
    }

    /**
     * Mutation-XSS payloads for {@see \D4np\Utils\Security\Sanitizer::richText()}.
     *
     * These are the family a sanitizer gets wrong even when it correctly removes every dangerous
     * element it can see. They exploit the fact that a browser's parse of a *re-serialised*
     * document can differ from its parse of the original: markup that is inert after one pass
     * becomes executable after the next, typically by escaping a foreign-content or raw-text
     * context (`<svg>`, `<math>`, `<noscript>`, `<style>`, `<xmp>`) that changes how the parser
     * treats everything after it.
     *
     * They are the reason RFC-0001 requires the work be delegated to a real parser rather than a
     * tag stripper: no regex has the context to see any of this.
     *
     * @return array<string, string>
     */
    public static function domBypassPayloads(): array
    {
        return [
            'mxss-noscript-title' => '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
            'mxss-svg-style' => '<svg></p><style><a id="</style><img src=1 onerror=alert(1)>">',
            'mxss-math-mglyph' => '<math><mtext><table><mglyph><style><!--</style><img src=x onerror=alert(1)>',
            'mxss-xmp' => '<xmp><p title="</xmp><img src=x onerror=alert(1)>">',
            'mxss-listing' => '<listing><p title="</listing><img src=x onerror=alert(1)>">',
            'mxss-noembed' => '<noembed><p title="</noembed><img src=x onerror=alert(1)>">',
            'mxss-iframe-srcdoc' => '<iframe srcdoc="&lt;img src=x onerror=alert(1)&gt;"></iframe>',
            'mxss-svg-foreignobject' => '<svg><foreignObject><div><script>alert(1)</script></div></foreignObject></svg>',
            'mxss-template' => '<template><script>alert(1)</script></template>',
            'mxss-form-nested' => '<form><math><mtext></form><form><mglyph><style></math><img src onerror=alert(1)>',
            'mxss-select-noembed' => '<select><noembed></select><img src=x onerror=alert(1)>',
            'mxss-table-caption' => '<table><caption><svg></caption><img src=x onerror=alert(1)>',
            'mxss-textarea-close' => '<textarea></textarea><img src=x onerror=alert(1)>',
            'mxss-title-close' => '<title></title><img src=x onerror=alert(1)>',
            'mxss-style-comment' => '<style><!--</style><img src=x onerror=alert(1)>',
            'mxss-entity-in-attribute' => '<a href="&#106;avascript:alert(1)">x</a>',
            'mxss-nested-anchors' => '<a href="https://ok.example"><a href="javascript:alert(1)">x</a></a>',
            'mxss-base-tag' => '<base href="javascript:alert(1)//">',

            // Legitimate rich text that must survive: a sanitizer that destroys everything is
            // trivially "safe" and useless.
            'legitimate-formatting' => '<p>Hello <strong>world</strong> and <em>others</em></p>',
            'legitimate-link' => '<p>See <a href="https://example.com/docs">the docs</a>.</p>',
            'legitimate-list' => '<ul><li>one</li><li>two</li></ul>',
        ];
    }
}
