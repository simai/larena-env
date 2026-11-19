<?php

declare(strict_types=1);

namespace Larena\Env\Warnings;

use Illuminate\Http\Request;
use Larena\Admin\Alerts\AdminAlert;
use Larena\Admin\Alerts\AdminAlertLevel;
use Larena\Admin\Contracts\AdminAlertSource;
use Larena\Admin\Contracts\AdminMenuAttentionSource;

/**
 * The counts on the panel's menu rows (C384, C342): what the alert bar already holds for this person, by the row it
 * leads to — a site's problem on «Сайты», the security on «Безопасность», a new simai-env or panel on «Обновления»,
 * mail that does not leave on «Почта», the rest of the server on «Обзор». Nothing new is asked: the alert sources keep
 * what they read (the server's answer for a minute).
 */
final readonly class EnvMenuAttentionSource implements AdminMenuAttentionSource
{
    /** The «Сайты» root of the panel's product menu (kind row over env.server): a count stands on the root's own key. */
    public const string SITES = 'sites';

    public function __construct(private AdminAlertSource $alerts)
    {
    }

    public function attention(Request $request): array
    {
        $rows = [];
        foreach ($this->alerts->alerts($request) as $alert) {
            $row = self::rowOf($alert);
            $tone = match ($alert->level) {
                AdminAlertLevel::Danger => 'error',
                AdminAlertLevel::Warning => 'warning',
                AdminAlertLevel::Info => 'info',
            };
            $was = $rows[$row] ?? ['count' => 0, 'tone' => 'info'];
            $rank = ['info' => 0, 'warning' => 1, 'error' => 2];
            $rows[$row] = ['count' => $was['count'] + 1, 'tone' => $rank[$was['tone']] >= $rank[$tone] ? $was['tone'] : $tone];
        }

        return $rows;
    }

    /** The menu row an alert belongs to, by where its button leads. */
    public static function rowOf(AdminAlert $alert): string
    {
        if (str_starts_with($alert->id, 'env:update:')) {
            return 'env.update';
        }
        $path = (string) parse_url($alert->action->href ?? '', PHP_URL_PATH);

        return match (true) {
            str_starts_with($path, '/admin/server/security') => 'env.security',
            str_starts_with($path, '/admin/server/sites/') => self::SITES,
            str_starts_with($path, '/admin/server/update') => 'env.update',
            str_starts_with($path, '/admin/server/settings/mail') => 'env.mail',
            default => 'env.server.overview',
        };
    }
}
