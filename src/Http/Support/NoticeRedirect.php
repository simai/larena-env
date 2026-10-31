<?php

declare(strict_types=1);

namespace Larena\Env\Http\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\Store;

/** Back to a panel screen with one sentence for it to say, kept in the person's session for the next page only. */
final class NoticeRedirect
{
    private function __construct()
    {
    }

    public static function to(Request $request, string $url, string $notice): RedirectResponse
    {
        $response = new RedirectResponse($url);
        $session = $request->hasSession() ? $request->session() : null;
        if ($session instanceof Store) {
            $response->setSession($session);
            $response->with('larena_env_notice', $notice);
        }

        return $response;
    }
}
