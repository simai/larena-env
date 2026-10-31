<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Access\Administration\AdministratorGovernanceService;
use Larena\Access\Persistence\PersistentAccessStore;
use Larena\Access\Runtime\SystemRolePresetSynchronizer;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Http\Support\PanelActorResolver;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\People\PanelAudit;
use Larena\Env\People\PersonOwners;
use Larena\Env\Server\ServerOverviewReader;
use Throwable;

/**
 * The people of the panel's user level and the spaces they work in (Panel plan 2.4–2.6, owner decision 2026-10-31):
 * the whole server gives each one an owner of the server, makes a new owner with a plan and a confirmation, and can
 * look at the panel as one of them, marked in the audit log and in every request to the server. A person's level is
 * their role (the users' screen): the administrator is the whole server, the editor works in a space.
 * Blocking and unblocking a person is the users' screen too.
 */
final readonly class PeopleController
{
    /** The role that stands for the panel's user level. */
    public const string USER_LEVEL_ROLE = SystemRolePresetSynchronizer::EDITOR;

    public function __construct(
        private PersonOwners $owners,
        private PersistentAccessStore $access,
        private AdministratorGovernanceService $people,
        private ServerOverviewReader $overviews,
        private PanelOperations $operations,
        private PanelAudit $audit,
        private Factory $views,
        private Translator $translator,
    ) {
    }

    public function index(Request $request): mixed
    {
        $user = self::user($request);
        $assigned = $this->owners->all();
        $rows = [];
        foreach ($this->access->subjectsWithRole(self::USER_LEVEL_ROLE) as $subject) {
            $rows[] = ['subject' => $subject, 'label' => $this->label($subject, $user), 'owner' => $assigned[$subject] ?? null];
        }
        $result = $this->overviews->read(new PanelActor($user));

        return $this->views->make('larena-env::admin.people', [
            'people' => $rows,
            'owners' => $result->overview->owners ?? [],
            'source' => $result->source,
            'reasonText' => $result->reason === null ? null : $this->reasonText($result->reason),
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    public function assign(Request $request): RedirectResponse
    {
        $user = self::user($request);
        $subject = (string) $request->input('subject');
        $owner = (string) $request->input('owner');
        if (!in_array($subject, $this->access->subjectsWithRole(self::USER_LEVEL_ROLE), true)) {
            return $this->back($request, 'not_a_user_level_person');
        }
        try {
            if ($owner === '') {
                $this->owners->release($subject);
                $this->audit->record('env.person.owner_released', $user, $subject);
            } else {
                $this->owners->assign($subject, $owner, $user);
                $this->audit->record('env.person.owner_assigned', $user, $subject, ['owner' => $owner]);
            }
        } catch (PanelRefused $refused) {
            return $this->back($request, $refused->reasonCode);
        }

        return $this->back($request, 'space_saved');
    }

    /** The panel as one of its people sees it: their space, asked as them and marked as acted for. */
    public function actAs(Request $request): RedirectResponse
    {
        $user = self::user($request);
        $subject = (string) $request->input('subject');
        $owner = $this->owners->ownerOf($subject);
        if ($owner === null || $subject === $user) {
            return $this->back($request, 'owner_unmapped');
        }
        $request->session()->put(PanelActorResolver::ACTING_AS, $subject);
        $request->session()->put(PanelActorResolver::ACTING_AS_LABEL, $this->label($subject, $user));
        $this->audit->record('env.acting_as.started', $user, $subject, ['owner' => $owner]);

        return new RedirectResponse(route('larena.env.admin.index', [], false));
    }

    public function stop(Request $request): RedirectResponse
    {
        $user = self::user($request);
        $subject = $request->session()->pull(PanelActorResolver::ACTING_AS);
        $request->session()->forget(PanelActorResolver::ACTING_AS_LABEL);
        if (is_string($subject) && $subject !== '') {
            $this->audit->record('env.acting_as.stopped', $user, $subject);
        }

        return new RedirectResponse(route('larena.env.admin.people', [], false));
    }

    /** A new owner of the server, a space for people to work in: planned and created on confirmation. */
    public function planOwner(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $input = self::ownerInput($request);
        $result = $this->operations->planCommand($actor, 'owner:create', $input);
        if ($result->plan === null) {
            return $this->back($request, $result->reason ?? 'answer_shape_invalid');
        }

        return $this->views->make('larena-env::admin.plan', [
            'heading' => (string) $this->translator->get('larena-env::admin.people.new_owner'),
            'eyebrow' => $input['name'] ?? '',
            'target' => $input['name'] ?? '',
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route('larena.env.admin.people.owners.run', [], false),
            'cancelUrl' => route('larena.env.admin.people', [], false),
            'hidden' => $input,
        ]);
    }

    public function runOwner(Request $request): RedirectResponse
    {
        $started = $this->operations->startCommand(ResolvePanelActor::of($request), 'owner:create', self::ownerInput($request));
        if ($started->jobId === null) {
            return $this->back($request, $started->reason ?? 'answer_shape_invalid');
        }

        return new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    /** @return array<string, string> */
    private static function ownerInput(Request $request): array
    {
        $input = [];
        foreach (['name', 'pubkey'] as $field) {
            $value = $request->input($field);
            if (is_string($value) && trim($value) !== '') {
                $input[$field] = trim($value);
            }
        }

        return $input;
    }

    private static function user(Request $request): string
    {
        return (string) $request->attributes->get('larena_access_actor', '');
    }

    /** A person's name as the users' screen shows it; their reference when it cannot be read. */
    private function label(string $subject, string $user): string
    {
        try {
            return $this->people->administratorDisplayLabel($subject, $user) ?? $subject;
        } catch (Throwable) {
            return $subject;
        }
    }

    private function back(Request $request, string $reason): RedirectResponse
    {
        return NoticeRedirect::to($request, route('larena.env.admin.people', [], false), $this->reasonText($reason));
    }

    private function reasonText(string $reason): string
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
    }
}
