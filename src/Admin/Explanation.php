<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

/**
 * A screen's explanation in one line of sense, the rest folded under «Подробнее» (the panel's UX audit, COPY-01): the
 * paragraphs about how the server works stay a click away instead of filling every tab.
 */
final class Explanation
{
    /** Shorter than this, the whole text is the line. */
    private const int SHORT = 110;

    private function __construct()
    {
    }

    /**
     * The line shown and the rest folded; the rest is null when the text is short or one sentence.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function split(string $text): array
    {
        $text = trim($text);
        if (mb_strlen($text) < self::SHORT) {
            return [$text, null];
        }
        // The first sentence that is a sentence: ends in . ! or ? before a space and a capital, and says something.
        if (preg_match('/\A(.{25,}?[.!?])\s+(\p{Lu}.*)\z/su', $text, $parts) !== 1) {
            return [$text, null];
        }

        return [$parts[1], $parts[2]];
    }
}
