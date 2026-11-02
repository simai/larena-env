<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

/**
 * Whether a site works, in one word the sites' list and the card share: a 404 is the site's own answer, so only a server
 * error or no answer at all is the site failing.
 */
final class SiteState
{
    /** Each state and the colour it is drawn in. */
    public const array COLORS = ['works' => 'success', 'error' => 'danger', 'down' => 'danger', 'unchecked' => 'neutral'];

    private function __construct()
    {
    }

    public static function of(bool $checked, ?int $answers): string
    {
        return match (true) {
            !$checked => 'unchecked',
            $answers === null => 'down',
            $answers >= 500 => 'error',
            default => 'works',
        };
    }

    /** The status word of the Framework's badge for a state. */
    public static function status(string $state): string
    {
        return ['works' => 'success', 'unchecked' => 'info'][$state] ?? 'error';
    }
}
