<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\Translation\Translator;
use Larena\Admin\Record\AdminRecordLayoutStore;
use Larena\Admin\Runtime\PropertyViewPort;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelCommands;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Http\Controllers\NewSiteController;
use Larena\Env\Server\SiteProfile;
use Larena\Property\Contracts\PropertyField;

/**
 * A new site as a record of the Framework property view (simai.property-view-port), in create mode: collection
 * «env.new_site.<kind>», whose fields are those the kind takes (Panel plan 2.2, 5.5): the domain, PHP and a database,
 * the target of a redirect, the local port of an application, the 1C-Bitrix installer, the daily backup and, for the
 * whole server, whose site it is. record.create makes no site: it plans site:add exactly as the form does
 * (NewSiteController::planFor), keeps the values in this person's session as a draft and answers the draft, whose
 * address is the plan screen. The site is made only when that plan is confirmed, as any action is.
 */
final class NewSitePropertyViewPort extends PropertyViewPort
{
    public const string SCOPE = 'scope:server';
    public const string COLLECTION = 'env.new_site.';
    /** A field of the property view and the site:add argument it fills. */
    private const array ARGUMENTS = ['domain' => 'domain', 'owner' => 'owner', 'php' => 'php', 'create_db' => 'create-db', 'db_engine' => 'db-engine',
        'redirect_to' => 'redirect-to', 'upstream_port' => 'upstream-port', 'upstream_path' => 'upstream-path', 'websockets' => 'websockets',
        'bitrix_files' => 'bitrix-files', 'daily_backup' => 'daily-backup'];
    private const array BOOLEANS = ['create_db', 'websockets', 'daily_backup'];
    /** The groups the fields stand in, in the order a person fills them. */
    private const array GROUPS = ['address' => ['domain', 'redirect_to', 'owner'], 'run' => ['php', 'create_db', 'db_engine'],
        'app' => ['upstream_port', 'upstream_path', 'websockets'], 'bitrix' => ['bitrix_files'], 'backup' => ['daily_backup']];

    private ?PanelActor $panelActor = null;
    private ?Session $session = null;

    public function __construct(
        private NewSiteController $sites,
        private Translator $translator,
        AdminRecordLayoutStore $layouts,
    ) {
        parent::__construct($layouts);
    }

    /** This port for one request: the panel's person, whose server reads it makes, and the session drafts are kept in. */
    public function for(PanelActor $actor, Session $session): self
    {
        $port = clone $this;
        $port->panelActor = $actor;
        $port->session = $session;

        return $port;
    }

    protected function collectionPattern(): string
    {
        return '/^env\.new_site\.[a-z][a-z0-9-]{0,31}$/';
    }

    protected function recordPattern(): string
    {
        return '/^draft-[0-9a-f]{16}$/';
    }

    protected function conflictReason(): string
    {
        return 'new_site_conflict';
    }

    protected function readsGroups(): bool
    {
        return false;
    }

    protected function mainGroupLabel(): string
    {
        return $this->text('new_site.groups.address', 'Address');
    }

    /** The plan screen of the draft: where a filled new site leads. */
    protected function relatedHref(string $collection, string $recordId): ?string
    {
        return preg_match($this->recordPattern(), $recordId) === 1 ? route('larena.env.admin.sites.plan.draft', ['draft' => $recordId], false) : null;
    }

    /** The empty site of a kind this person may make here, each field's default filled. */
    protected function readCollection(string $actor, string $scope, string $collection): array
    {
        $kind = $this->kindOf($collection);
        if ($kind === null) {
            return ['answer' => 'refused', 'reason' => 'record_invalid'];
        }

        return [$this->collectionOf($collection, $kind['name'], $kind['offer'], false), ['creatable' => true]];
    }

    /** A draft read back: what was planned, read only (the plan screen shows the rest). */
    protected function readObject(string $actor, string $scope, string $collection, string $recordId): array
    {
        $kind = $this->kindOf($collection);
        $raw = $this->session?->get(NewSiteController::DRAFTS.'.'.$recordId);
        if ($kind === null || !is_array($raw)) {
            return ['answer' => 'refused', 'reason' => 'record_unknown'];
        }
        $values = [];
        foreach (self::ARGUMENTS as $field => $argument) {
            if (!is_string($raw[$argument] ?? null)) {
                continue;
            }
            $values[$field] = in_array($field, self::BOOLEANS, true) ? $raw[$argument] === 'yes' : ($field === 'upstream_port' ? (int) $raw[$argument] : $raw[$argument]);
        }

        return [$this->collectionOf($collection, $kind['name'], $kind['offer'], true),
            ['record_id' => $recordId, 'revision' => 'd'.substr(hash('sha256', $recordId), 0, 12), 'editable' => false, 'values' => $values]];
    }

    /**
     * Plans the site as the form does; a value the gateway would refuse is said under its field before anything is
     * asked of the server.
     */
    protected function createObject(string $actor, string $scope, string $collection, array $values): array
    {
        $kind = $this->kindOf($collection);
        if ($kind === null || $this->panelActor === null || $this->session === null) {
            return ['status' => 'rejected', 'reason_code' => 'record_invalid'];
        }
        $raw = ['profile' => $kind['name']];
        foreach (self::ARGUMENTS as $field => $argument) {
            $value = $values[$field] ?? null;
            if (is_bool($value)) {
                $raw[$argument] = $value ? 'yes' : 'no';
            } elseif (is_int($value) || is_float($value)) {
                $raw[$argument] = (string) (int) $value;
            } elseif (is_string($value) && trim($value) !== '') {
                $raw[$argument] = trim($value);
            }
        }
        $wrong = self::unofferedField($this->collectionOf($collection, $kind['name'], $kind['offer'], false), $values) ?? self::wrongField($raw);
        if ($wrong !== null) {
            return ['status' => 'rejected', 'reason_code' => 'invalid_argument', 'field' => $wrong, 'field_reason' => $wrong];
        }
        [$result] = $this->sites->planFor($this->panelActor, $raw);
        if ($result->plan === null) {
            return ['status' => $result->refused ? 'rejected' : 'unavailable', 'reason_code' => $result->reason ?? 'answer_shape_invalid'];
        }
        $draft = 'draft-'.bin2hex(random_bytes(8));
        $this->session->put(NewSiteController::DRAFTS.'.'.$draft, $raw);

        return ['status' => 'ok', 'redirect' => ['record_id' => $draft]];
    }

    protected function writeObject(string $actor, string $scope, string $collection, string $recordId, int|string $revision, array $values): array
    {
        return ['status' => 'rejected', 'reason_code' => 'new_site_planned'];
    }

    /** @param list<array<string,mixed>> $fields */
    protected function defaultLayout(array $fields): array
    {
        $layout = parent::defaultLayout($fields);
        $layout['groups'] = [];
        $layout['fields'] = [];
        $present = [];
        foreach ($fields as $field) {
            if (is_string($field['key'] ?? null)) {
                $present[$field['key']] = $field;
            }
        }
        $order = 10;
        foreach (self::GROUPS as $group => $keys) {
            $mine = array_values(array_filter($keys, static fn (string $key): bool => isset($present[$key])));
            if ($mine === []) {
                continue;
            }
            $layout['groups'][$group] = ['label' => $this->text('new_site.groups.'.$group, ucfirst($group)), 'order' => $order, 'section' => 'main', 'columns' => 2];
            foreach ($mine as $position => $key) {
                $hint = $present[$key]['hint'] ?? null;
                $layout['fields'][AdminRecordLayoutStore::part($key)] = ['group' => $group, 'order' => ($position + 1) * 10,
                    'width' => in_array($key, ['domain', 'redirect_to'], true) ? 'full' : 'half'] + (is_string($hint) && $hint !== '' ? ['hint' => $hint] : []);
            }
            $order += 10;
        }

        return $layout;
    }

    /** The panel's words for a refusal the gateway or the panel names. */
    protected function reasonMessage(string $reason): ?string
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = $this->translator->get($key);

        return is_string($line) && $line !== $key ? $line : null;
    }

    /** Why one field's value cannot be sent, in its own words. */
    protected function fieldReasonMessage(string $reason): ?string
    {
        $key = 'larena-env::admin.new_site.invalid.'.$reason;
        $line = $this->translator->get($key);

        return is_string($line) && $line !== $key ? $line : null;
    }

    /**
     * The first field whose value the gateway's own table refuses, or null. Each is checked beside a valid domain, so
     * the refusal is about that field alone.
     *
     * @param array<string, string> $raw
     */
    private static function wrongField(array $raw): ?string
    {
        foreach (self::ARGUMENTS as $field => $argument) {
            if (!isset($raw[$argument]) || $argument === 'daily-backup') {
                continue;
            }
            try {
                PanelCommands::check('site:add', ['domain' => $argument === 'domain' ? $raw[$argument] : 'example.com', $argument => $raw[$argument]]);
            } catch (PanelRefused) {
                return $field;
            }
            if ($argument === 'upstream-port' && in_array((int) $raw[$argument], [80, 443], true)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The first choice whose value is not one the field offers (an owner, a PHP version the server does not have), or null.
     *
     * @param array<string, mixed> $collection
     * @param array<string, mixed> $values
     */
    private static function unofferedField(array $collection, array $values): ?string
    {
        foreach ($collection['fields'] as $field) {
            $key = $field['key'] ?? null;
            $value = is_string($key) ? ($values[$key] ?? null) : null;
            if (($field['type'] ?? null) !== 'choice' || !is_string($value) || $value === '') {
                continue;
            }
            $offered = array_column(is_array($field['constraints']['options'] ?? null) ? $field['constraints']['options'] : (is_array($field['settings']['options'] ?? null) ? $field['settings']['options'] : []), 'value');
            if (!in_array($value, $offered, true)) {
                return (string) $key;
            }
        }

        return null;
    }

    /** @return array{name: string, offer: array<string, mixed>}|null the kind a collection names, when this person may make it */
    private function kindOf(string $collection): ?array
    {
        if ($this->panelActor === null || preg_match($this->collectionPattern(), $collection) !== 1) {
            return null;
        }
        $name = substr($collection, strlen(self::COLLECTION));
        $offer = $this->sites->offer($this->panelActor);

        return in_array($name, $offer['profiles'], true) ? ['name' => $name, 'offer' => $offer] : null;
    }

    /**
     * The fields a kind asks, as Property describes them.
     *
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    private function collectionOf(string $collection, string $kind, array $offer, bool $readonly): array
    {
        /** @var array<string, SiteProfile>|null $server */
        $server = $offer['server'];
        $profile = $server[$kind] ?? null;
        $groups = NewSiteController::groupsOf($kind, $server);
        $overview = $offer['overview'];
        $choices = fn (array $values, ?string $prefix = null): array => array_map(fn (string $value): array => ['value' => $value,
            'label' => $prefix === null ? $value : $this->text($prefix.$value, $value)], $values);
        $fields = [
            new PropertyField('domain', 'string', 1, $this->text('new_site.domain', 'Domain'), ['max_length' => 253, 'placeholder' => 'example.com'],
                required: true, readonly: $readonly, hint: $this->hint('new_site.domain_hint')),
        ];
        if (in_array('redirect', $groups, true)) {
            $fields[] = new PropertyField('redirect_to', 'string', 1, $this->text('new_site.redirect_to', 'Redirect to'),
                ['max_length' => 300, 'placeholder' => 'https://example.org'], required: true, readonly: $readonly);
        }
        if ($this->panelActor?->owner === null && is_array($overview?->owners) && $overview->owners !== []) {
            $fields[] = new PropertyField('owner', 'choice', 1, $this->text('new_site.owner', 'Owner'), ['options' => $choices($overview->owners)],
                readonly: $readonly, hint: $this->hint('new_site.owner_hint'));
        }
        if (in_array('php', $groups, true)) {
            $versions = $profile?->values('php') ?? (is_array($overview?->phpVersions) ? $overview->phpVersions : []);
            $default = $profile?->args['php']['default'] ?? null;
            $fields[] = new PropertyField('php', 'choice', 1, $this->text('new_site.php', 'PHP version'), ['options' => $choices($versions)],
                readonly: $readonly, hint: $this->hint('new_site.php_default_hint'), default: is_string($default) && in_array($default, $versions, true) ? $default : null);
        }
        if (in_array('database', $groups, true)) {
            $wanted = $profile?->db === 'required' || in_array($kind, ['laravel', 'wordpress', 'bitrix'], true);
            $fields[] = new PropertyField('create_db', 'boolean', 1, $this->text('new_site.database', 'Create a database'), readonly: $readonly,
                hint: $this->hint('new_site.database_hint'), default: $wanted);
            $engines = $profile?->values('db-engine') ?? ['mysql', 'pgsql'];
            $fields[] = new PropertyField('db_engine', 'choice', 1, $this->text('new_site.engine', 'Database'), ['options' => $choices($engines, 'new_site.engines.')],
                readonly: $readonly, default: $engines[0] ?? 'mysql',
                attributes: ['when' => ['visible' => ['field' => 'create_db', 'op' => 'eq', 'value' => true]]]);
        }
        if (in_array('proxy', $groups, true)) {
            $fields[] = new PropertyField('upstream_port', 'integer', 1, $this->text('new_site.upstream_port', 'Port'), ['min' => 1, 'max' => 65535],
                required: true, readonly: $readonly, hint: $this->hint('new_site.upstream_port_hint'));
            $fields[] = new PropertyField('upstream_path', 'string', 1, $this->text('new_site.upstream_path', 'Path'), ['max_length' => 200, 'placeholder' => '/'],
                readonly: $readonly);
            $fields[] = new PropertyField('websockets', 'boolean', 1, $this->text('new_site.websockets', 'WebSocket'), readonly: $readonly, default: false);
        }
        if (in_array('bitrix', $groups, true)) {
            $fields[] = new PropertyField('bitrix_files', 'choice', 1, $this->text('new_site.bitrix_files', 'Installer'),
                ['options' => $choices($profile?->values('bitrix-files') ?? NewSiteController::BITRIX_FILES, 'new_site.bitrix_files_choices.')],
                readonly: $readonly, default: 'none');
        }
        if (($offer['schedules'] ?? false) === true && NewSiteController::backsUp($kind, $server)) {
            $fields[] = new PropertyField('daily_backup', 'boolean', 1, $this->text('new_site.daily_backup', 'Daily backup'), readonly: $readonly,
                hint: $this->hint('new_site.daily_backup_hint'), default: true);
        }

        return ['structure_id' => $collection, 'label' => $this->kindLabel($kind, $profile),
            'fields' => array_map(static fn (PropertyField $field): array => $field->toArray(), $fields)];
    }

    private function kindLabel(string $kind, ?SiteProfile $profile): string
    {
        $known = $this->text('new_site.profiles.'.$kind, '');

        return $known !== '' ? $known : ($profile !== null && $profile->title !== null ? $profile->title : $kind);
    }

    private function hint(string $key): ?string
    {
        $line = $this->text($key, '');

        return $line !== '' ? $line : null;
    }

    private function text(string $key, string $fallback): string
    {
        $line = $this->translator->get('larena-env::admin.'.$key);

        return is_string($line) && $line !== 'larena-env::admin.'.$key ? $line : $fallback;
    }
}
