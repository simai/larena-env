<?php

declare(strict_types=1);

namespace Larena\Env\Area;

use Larena\Core\Enums\ScopeKind;
use Larena\Core\Scope\ScopeRef;

/**
 * The panel's main scope: the whole server (owner decision 21). It is an organization, because sites may later hang
 * under it. A panel assembly declares it as its root scope, see firstRunDeclaration().
 */
final class ServerArea
{
    public const string IDENTIFIER = 'server';
    public const string SCOPE_REF = 'organization:server';
    public const string LABEL = 'Server';

    private function __construct()
    {
    }

    public static function ref(): ScopeRef
    {
        return ScopeRef::of(ScopeKind::Organization, self::IDENTIFIER);
    }

    /**
     * What a panel assembly sets as `larena-core.first_run`: the product declares its steps and root scope (owner
     * decision 2026-10-29), so the package only offers the values and does not change a CMS it happens to be installed in.
     *
     * @return array{steps: list<string>, root_scope: string, root_scope_label: string}
     */
    public static function firstRunDeclaration(): array
    {
        return ['steps' => ['auth', 'setting'], 'root_scope' => self::SCOPE_REF, 'root_scope_label' => self::LABEL];
    }
}
