<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use DomainException;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Larena\Access\Administration\AdminUserManagementService;
use Larena\Access\Administration\AdministratorGovernanceService;
use Larena\Access\Persistence\PersistentAccessStore;
use Larena\Access\Runtime\SystemRolePresetSynchronizer;
use Larena\Admin\Assets\AdminProductAssetManifest;
use Larena\Admin\Runtime\AdminDataviewPagePresenter;
use Larena\Admin\Runtime\AdminPlannedAction;
use Larena\Auth\Runtime\RegistrationActivationBoundaryService;
use Larena\Env\Admin\AccountMoves;
use Larena\Env\Admin\ServerPeoplePage;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Http\Support\PlanReceipts;
use Larena\Env\Http\Support\PanelActorResolver;
use Larena\Env\Mail\InvitationLetter;
use Larena\Env\Mail\MailLetters;
use Larena\Env\Mail\PanelMail;
use Larena\Env\Operations\EnvOperationCatalog;
use Larena\Env\Operations\EnvOperations;
use Larena\Env\People\PanelAudit;
use Larena\Env\People\PanelPeople;
use Larena\Env\People\PersonOwners;
use Larena\Env\Server\Moment;
use Larena\Env\Server\ServerOverview;
use Larena\Env\Server\ServerOverviewReader;
use Throwable;

/**
 * «Люди» (roles study part 7, owner decision dec-03a4c39ce16b; Panel plan 2.4–2.6, owner decision 2026-10-31): one
 * screen of the panel's people at its two levels — the whole server, or their own sites through the server account they
 * are tied to. «Пригласить человека» is one form with its plan: the sign-in, the level, the server account (a new one is
 * made by the server in the same run) and whether files with passwords are open to them (q-4997ae4e93). A person's card
 * says what they may do in words, their sites, account, keys and last sign-in; blocking a person closes their sign-in
 * and, where the server can, their account's SSH and SFTP while their sites keep working. The whole server can look at
 * the panel as one of its people, marked in the audit log and in every request to the server.
 */
final readonly class PeopleController
{
    /** The role that stands for the panel's user level. */
    public const string USER_LEVEL_ROLE = SystemRolePresetSynchronizer::EDITOR;

    /** The invitation waiting for its confirmation, in the person's own session (never in the page: it holds a password). */
    public const string INVITE = 'larena_env.invite';

    /** A server account's name, as simai-env takes it. */
    private const string ACCOUNT = '/\A[a-z][a-z0-9_-]{0,31}\z/D';

    public function __construct(
        private PersonOwners $owners,
        private PersistentAccessStore $access,
        private AdministratorGovernanceService $people,
        private ServerOverviewReader $overviews,
        private EnvOperations $operations,
        private PanelAudit $audit,
        private Factory $views,
        private Translator $translator,
        private PanelPeople $directory,
        private AdminDataviewPagePresenter $dataviews,
        private ?\Larena\Env\People\SecretFileAccess $secrets = null,
        private ?AdminUserManagementService $users = null,
        private ?\Larena\Access\Runtime\AccessOperationAuthorizer $authorizer = null,
        private ?PanelMail $mail = null,
        private ?MailLetters $letters = null,
        private ?RegistrationActivationBoundaryService $invitations = null,
        private ?InvitationLetter $letter = null,
        private ?\Larena\Auth\Runtime\AdminCredentialLifecycleService $credentials = null,
        private ?\Larena\Auth\Contracts\ActivationChallengeStore $challenges = null,
        private ?\Illuminate\Database\DatabaseManager $database = null,
        private PlanReceipts $receipts = new PlanReceipts(),
    ) {
    }

    /** «Отправить приглашение на почту» is offered: the panel's mail is on and the sign-in package can invite (C335). */
    private function byMail(): bool
    {
        return $this->invitations !== null && $this->mail?->isOn() === true;
    }

    public function index(Request $request): mixed
    {
        $user = self::user($request);
        $result = $this->overviews->read(new PanelActor($user));
        $overview = $result->overview;
        $capabilities = $overview?->capabilities;
        $canOperate = $this->canOperate($request);
        $people = $this->peopleOf($user, $overview);
        // Until when each invitation sent by mail waits for its answer (C335).
        $invited = $this->letters?->invitedUntil() ?? [];
        foreach ($people as &$person) {
            if ($person['pending']) {
                $person['until'] = $invited[mb_strtolower($person['email'])] ?? null;
            }
        }
        unset($person);
        $text = $this->text();
        $byOwner = [];
        foreach ($people as $person) {
            if ($person['owner'] !== null) {
                $byOwner[$person['owner']] = $person['name'];
            }
        }
        $old = $request->hasSession() ? $request->session()->get('larena_env_invite_old') : null;

        return $this->views->make('larena-env::admin.people', [
            'peopleView' => $this->dataviews->present(ServerPeoplePage::of($people, $user, $text, $canOperate), AdminProductAssetManifest::dataviewActivation()),
            'peopleCount' => count($people),
            'canOperate' => $canOperate,
            'secretFilesKept' => $this->secrets !== null,
            'owners' => $overview->owners ?? [],
            'ownerPeople' => $byOwner,
            'ownerSites' => self::sitesByOwner($overview->sites ?? []),
            'blockedOwners' => $overview->blockedOwners ?? [],
            'ownerDisk' => $overview->ownerDiskBytes ?? [],
            // Blocking a space is offered where the server's gateway has it (simai-env 1.27.0).
            'canBlock' => $capabilities?->allows('owner:block') === true,
            // A server account without sites can be removed, and any one given a key for SSH (audit block 5, A2).
            'canRemove' => $capabilities?->allows('owner:remove') === true,
            'canAddKey' => $capabilities?->allows('owner:add-key') === true,
            'canCreateAccount' => $capabilities?->allows('owner:create') === true,
            // The sites a new account may take in the same job (simai-env 1.63.1), each with the account it is with now.
            'inviteSites' => $capabilities?->takes('owner:create', 'sites') === true ? AccountMoves::offered($overview->sites) : [],
            // The invitation form opens with the page after a refusal, with what was typed (never the password).
            'inviteOpen' => $request->query('invite') === '1',
            'inviteOld' => is_array($old) ? $old : [],
            // With the panel's mail on, the invitation can go by mail (C335, «Приглашение»).
            'inviteByMail' => $this->byMail(),
            'source' => $result->source,
            'reasonText' => $result->reason === null ? null : $this->reasonText($result->reason),
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    /** A person's card: what they may do in words, their sites, server account, keys and last sign-in (PX-79). */
    public function card(Request $request, string $person): mixed
    {
        $user = self::user($request);
        $result = $this->overviews->read(new PanelActor($user));
        $overview = $result->overview;
        $found = $this->person($person, $user, $overview);
        if ($found === null) {
            return $this->back($request, 'person_unknown');
        }
        $capabilities = $overview?->capabilities;

        return $this->views->make('larena-env::admin.people-card', [
            'person' => $found,
            'self' => $found['subject'] === $user,
            'can' => ServerPeoplePage::can($found, $this->text()),
            'canSaid' => (string) $this->translator->get('larena-env::admin.people.can_said.'.$found['level']),
            'last' => $found['last'] === null ? (string) $this->translator->get('larena-env::admin.people.never') : Moment::human($found['last']),
            'lastIso' => Moment::iso($found['last']),
            'canOperate' => $this->canOperate($request),
            'owners' => $overview->owners ?? [],
            'ownerSites' => self::sitesByOwner($overview->sites ?? []),
            'secretFilesKept' => $this->secrets !== null,
            'canAddKey' => $capabilities?->allows('owner:add-key') === true,
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    /** A person's server account and their right to files with passwords, from their card (or the old row). */
    public function assign(Request $request): RedirectResponse
    {
        $user = self::user($request);
        $subject = (string) $request->input('subject');
        $owner = (string) $request->input('owner');
        $card = self::cardOf($subject);
        if (!in_array($subject, $this->access->subjectsWithRole(self::USER_LEVEL_ROLE), true)) {
            return $this->back($request, 'not_a_user_level_person', $card);
        }
        try {
            if ($owner === '') {
                $this->owners->release($subject);
                $this->audit->record('env.person.owner_released', $user, $subject);
            } elseif ($owner !== $this->owners->ownerOf($subject)) {
                $this->owners->assign($subject, $owner, $user);
                $this->audit->record('env.person.owner_assigned', $user, $subject, ['owner' => $owner]);
            }
            // Files with passwords: off unless ticked; a change goes to «История».
            $this->secrets?->set($subject, $request->input('secret_files') === 'yes', $user);
        } catch (PanelRefused $refused) {
            return $this->back($request, $refused->reasonCode, $card);
        }

        return $this->back($request, 'space_saved', $card);
    }

    /**
     * «Пригласить человека»: the form's answers checked and kept in the session, then the plan in words — the sign-in,
     * the level, the server account (asked of the server when it is new) and the files with passwords.
     */
    public function invitePlan(Request $request): mixed
    {
        $user = self::user($request);
        $users = $this->users;
        if ($users === null) {
            return $this->back($request, 'answer_shape_invalid');
        }
        $input = [
            'name' => trim((string) $request->input('name')),
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'level' => $request->input('level') === PanelPeople::ALL ? PanelPeople::ALL : PanelPeople::OWN,
            'account' => trim((string) $request->input('account', 'new')),
            'account_name' => mb_strtolower(trim((string) $request->input('account_name'))),
            'secret' => $request->input('secret_files') === 'yes',
            'sites' => [],
            // By mail unless the first password is set by hand; by hand only while the panel's mail is off.
            'how' => $this->byMail() && $request->input('how') !== 'password' ? 'mail' : 'password',
        ];
        $password = $input['how'] === 'mail' ? '' : (string) $request->input('password');
        $overview = $this->overviews->read(new PanelActor($user))->overview;
        $owners = $overview->owners ?? [];
        $invalid = match (true) {
            $input['name'] === '' || mb_strlen($input['name']) > 120 => 'name',
            filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false => 'email',
            $input['how'] === 'password' && mb_strlen($password) < 12 => 'password',
            $input['level'] === PanelPeople::OWN && $input['account'] === 'new' && preg_match(self::ACCOUNT, $input['account_name']) !== 1 => 'account',
            $input['level'] === PanelPeople::OWN && $input['account'] === 'new' && in_array($input['account_name'], $owners, true) => 'account_taken',
            $input['level'] === PanelPeople::OWN && $input['account'] !== 'new' && !in_array($input['account'], $owners, true) => 'account',
            default => null,
        };
        // A person invited by mail who has not answered yet may be invited again: the new letter replaces the old link.
        if ($invalid === null && $this->emailTaken($input['email'], $user, $input['how'] === 'mail')) {
            $invalid = 'email_taken';
        }
        if ($invalid === null && $input['how'] === 'mail') {
            $invalid = $this->letters?->invitationRefusal($input['email']);
        }
        // The sites the new account takes in the same job: only those the server listed, never one typed in.
        if ($invalid === null && $input['level'] === PanelPeople::OWN && $input['account'] === 'new' && $overview?->capabilities?->takes('owner:create', 'sites') === true) {
            $sites = AccountMoves::chosen($request->input('sites', []), AccountMoves::offered($overview->sites));
            if ($sites === null) {
                $invalid = 'sites';
            } else {
                $input['sites'] = $sites;
            }
        }
        // More than one job takes: said as the server says it, before the request (which the command table would refuse).
        if ($invalid === null && count($input['sites']) > AccountMoves::MOST_SITES) {
            return $this->inviteAgain($request, $input, (string) AccountMoves::refusal('owner:create', 'too_many', $this->text()));
        }
        if ($invalid !== null) {
            return $this->inviteAgain($request, $input, (string) $this->translator->get('larena-env::admin.people.invite.invalid.'.$invalid));
        }
        $newAccount = $input['level'] === PanelPeople::OWN && $input['account'] === 'new' ? $input['account_name'] : null;
        $account = $input['level'] === PanelPeople::OWN ? ($newAccount ?? $input['account']) : null;
        // A new server account is the server's: asked as a plan first, so a refusal is said before anyone is made. With
        // sites, the server checks every one of them now; one it would refuse stops the whole invitation, nothing is made.
        $moves = null;
        if ($newAccount !== null) {
            $plan = $this->operations->plan(ResolvePanelActor::of($request), 'env.owner.create', self::createArgs($newAccount, $input['sites']));
            if ($plan->plan === null) {
                $reason = $plan->reason ?? 'answer_shape_invalid';

                return $this->inviteAgain($request, $input, AccountMoves::refusal('owner:create', $reason, $this->text()) ?? $this->reasonText($reason));
            }
            $moves = $plan->plan->account;
        }
        // What the confirmation makes waits in the plan's receipt (PlanReceipts), with the password typed, for half an
        // hour; the form sends back only the receipt. An invitation kept the former way, under INVITE, goes.
        $request->session()->forget(self::INVITE);
        $token = $this->receipts->issue($request, 'person:invite', [], $input + ['password' => $password, 'new' => $newAccount !== null,
            'owner' => $account], 1800);
        $text = $this->text();
        $sites = $account === null ? [] : (PanelPeople::sitesByOwner($overview)[$account] ?? []);
        $facts = [
            [$text('people.invite.fact.person'), $text('people.invite.fact.person_value', ['name' => $input['name'], 'email' => $input['email']])],
            [$text('people.invite.fact.level'), $text('people.invite.levels.'.$input['level'].'.label')],
            [$text('people.invite.fact.how_'.$input['how']), $text('people.invite.fact.how_'.$input['how'].'_value', ['email' => $input['email']])],
        ];
        if ($newAccount !== null) {
            $facts[] = [$text('people.invite.fact.account_new'), $text('people.invite.fact.account_new_value', ['account' => $newAccount])];
            // Each site with the account it leaves, how it moves, and what a failed one means — from the server's plan.
            $facts = [...$facts, ...AccountMoves::createFacts($moves ?? ['owner' => $newAccount, 'new' => true,
                'sites' => array_map(static fn (string $domain): array => ['domain' => $domain, 'from' => null], $input['sites'])], $text)];
        } elseif ($account !== null) {
            $facts[] = [$text('people.invite.fact.account_existing'), $text('people.invite.fact.account_existing_value',
                ['account' => $account, 'sites' => $sites === [] ? $text('people.invite.fact.no_sites') : implode(', ', $sites)])];
        }
        $facts[] = [$text('people.invite.fact.secret'), $text('people.invite.fact.'.($input['level'] === PanelPeople::ALL || $input['secret'] ? 'secret_yes' : 'secret_no'))];

        return $this->views->make('larena-env::admin.people-plan', [
            'heading' => $text('people.invite.heading'),
            'action' => new AdminPlannedAction(
                question: $text('people.invite.question', ['name' => $input['name']]),
                runUrl: route('larena.env.admin.people.invite.run', [], false),
                token: (string) $request->session()->token(),
                runFields: ['invite' => $token],
                acceptText: $text('people.invite.accept'),
                facts: $facts,
                cancelUrl: route('larena.env.admin.people', [], false),
                factsTitle: $text('people.invite.facts_title'),
                key: 'env-invite',
            ),
        ]);
    }

    /** The invitation confirmed: the person, their level, their server account (made by the server when new), their tick. */
    public function inviteRun(Request $request): RedirectResponse
    {
        $user = self::user($request);
        $invite = $this->receipts->take($request, 'person:invite', 'invite');
        if ($invite === null || !isset($invite['email'], $invite['name'], $invite['level']) || $this->users === null) {
            return NoticeRedirect::to($request, route('larena.env.admin.people', [], false),
                (string) $this->translator->get('larena-env::admin.people.invite.expired'));
        }
        $level = $invite['level'] === PanelPeople::ALL ? PanelPeople::ALL : PanelPeople::OWN;
        $byMail = ($invite['how'] ?? 'password') === 'mail';
        $this->freeRemovedAddress((string) $invite['email'], $user);
        if ($byMail) {
            $subject = $this->inviteByMail($request, $invite, $level, $user);
            if ($subject instanceof RedirectResponse) {
                return $subject;
            }
        } else {
            try {
                $made = $this->users->create(['email' => (string) $invite['email'], 'display_name' => (string) $invite['name'],
                    'password' => (string) $invite['password'], 'role' => PanelPeople::roleOf($level)], $user);
            } catch (DomainException|InvalidArgumentException $refused) {
                return $this->inviteAgain($request, $invite, (string) $this->translator->get('larena-env::admin.people.invite.invalid.refused',
                    ['reason' => $this->refusalText($refused->getMessage())]));
            }
            $subject = (string) $made['subject_ref'];
        }
        $owner = is_string($invite['owner'] ?? null) ? $invite['owner'] : null;
        try {
            if ($level === PanelPeople::OWN && $owner !== null) {
                $this->owners->assign($subject, $owner, $user);
                $this->audit->record('env.person.owner_assigned', $user, $subject, ['owner' => $owner]);
            }
            $this->secrets?->set($subject, $level === PanelPeople::OWN && ($invite['secret'] ?? false) === true, $user);
        } catch (PanelRefused) {
            // The person is made; their account can be given on their card.
        }
        $this->audit->record('env.person.invited', $user, $subject, ['level' => $level] + ($owner !== null ? ['owner' => $owner] : []));
        if (($invite['new'] ?? false) === true && $owner !== null) {
            // The account and its sites in one job of the server (simai-env 1.63.1): no move of the panel's own per site.
            $sites = array_values(array_filter(is_array($invite['sites'] ?? null) ? $invite['sites'] : [], 'is_string'));
            $started = $this->operations->start(ResolvePanelActor::of($request), 'env.owner.create', self::createArgs($owner, $sites));
            if ($started->jobId !== null) {
                return NoticeRedirect::to($request, route('larena.env.admin.job', ['job' => $started->jobId], false),
                    (string) $this->translator->get('larena-env::admin.people.invite.'.($byMail ? 'done_mail_account' : ($sites === [] ? 'done_account' : 'done_sites')),
                        ['name' => (string) $invite['name'], 'account' => $owner, 'count' => (string) count($sites), 'email' => (string) $invite['email']]));
            }

            return $this->back($request, $started->reason ?? 'answer_shape_invalid', self::cardOf($subject));
        }

        return NoticeRedirect::to($request, self::cardOf($subject),
            (string) $this->translator->get('larena-env::admin.people.invite.'.($byMail ? 'done_mail' : 'done'), ['name' => (string) $invite['name'], 'email' => (string) $invite['email']]));
    }

    /**
     * The invitation by mail (C335, «Приглашение»): the sign-in package makes the person, waiting for their answer, and
     * its postman sends the link at once through the panel's mail; the role is theirs once they choose a password. A
     * letter that does not leave withdraws the invitation, and the form says so.
     *
     * @param array<string, mixed> $invite
     */
    private function inviteByMail(Request $request, array $invite, string $level, string $user): string|RedirectResponse
    {
        $again = static fn (string $key): string => 'larena-env::admin.people.invite.'.$key;
        if (!$this->byMail() || $this->invitations === null) {
            return $this->inviteAgain($request, $invite, (string) $this->translator->get($again('invalid.mail_off')));
        }
        $refusal = $this->letters?->invitationRefusal((string) $invite['email']);
        if ($refusal !== null) {
            return $this->inviteAgain($request, $invite, (string) $this->translator->get($again('invalid.'.$refusal)));
        }
        $this->letter?->about($this->label($user, $user), (string) $this->translator->get($again('levels.'.$level.'.caption')));
        try {
            $result = $this->invitations->inviteUser(['email' => (string) $invite['email'], 'display_name' => (string) $invite['name'],
                'role' => PanelPeople::roleOf($level)], $user);
        } catch (DomainException|InvalidArgumentException $refused) {
            return $this->inviteAgain($request, $invite, (string) $this->translator->get($again('invalid.refused'), ['reason' => $this->refusalText($refused->getMessage())]));
        } catch (Throwable) {
            return $this->inviteAgain($request, $invite, (string) $this->translator->get($again('mail_failed')));
        }
        if (!$result->succeeded() || $result->subjectRef === null) {
            return $this->inviteAgain($request, $invite, (string) $this->translator->get($again('mail_failed')));
        }

        return $result->subjectRef;
    }

    /** «Заблокировать» / «Разблокировать» a person: the plan in words before anything changes. */
    public function blockPlan(Request $request, string $person): mixed
    {
        $user = self::user($request);
        $overview = $this->overviews->read(new PanelActor($user))->overview;
        $found = $this->person($person, $user, $overview);
        if ($found === null || $found['subject'] === $user) {
            return $this->back($request, $found === null ? 'person_unknown' : 'person_self');
        }
        $block = !$found['blocked'];
        $text = $this->text();
        $facts = [[$text('people.block.fact_sign_in'), $text('people.block.fact_sign_in_'.($block ? 'off' : 'on'))]];
        if ($found['owner'] !== null && $overview?->capabilities?->allows($block ? 'owner:block' : 'owner:unblock') === true) {
            $facts[] = [$text('people.block.fact_account', ['account' => $found['owner']]), $text('people.block.fact_account_'.($block ? 'off' : 'on'))];
        }
        if ($found['owner'] !== null) {
            $facts[] = [$text('people.block.fact_sites'), $text('people.block.fact_sites_value')];
        }

        return $this->views->make('larena-env::admin.people-plan', [
            'heading' => $text('people.block.'.($block ? 'open' : 'unopen')),
            'action' => new AdminPlannedAction(
                question: $text('people.block.'.($block ? 'question' : 'unquestion'), ['name' => $found['name']]),
                runUrl: route('larena.env.admin.people.block.run', ['person' => (string) $found['id']], false),
                token: (string) $request->session()->token(),
                runFields: ['blocked' => $block ? 'yes' : 'no',
                    PlanReceipts::FIELD => $this->receipts->issue($request, 'person:block', ['person' => $found['id'], 'blocked' => $block])],
                acceptText: $text('people.block.'.($block ? 'accept' : 'unaccept')),
                danger: $block,
                consequence: $block ? $text('people.block.consequence') : '',
                facts: $facts,
                cancelUrl: self::cardOf($found['subject']),
                key: 'env-person-block',
            ),
        ]);
    }

    public function blockRun(Request $request, string $person): RedirectResponse
    {
        $user = self::user($request);
        $overview = $this->overviews->read(new PanelActor($user))->overview;
        $found = $this->person($person, $user, $overview);
        if ($found === null || $found['subject'] === $user || $this->users === null) {
            return $this->back($request, $found === null ? 'person_unknown' : 'person_self');
        }
        $block = $request->input('blocked') === 'yes';
        if (!$this->receipts->confirms($request, 'person:block', ['person' => $found['id'], 'blocked' => $block])) {
            return $this->back($request, PlanReceipts::REFUSED, self::cardOf($found['subject']));
        }
        try {
            $this->users->setEnabled($found['id'], !$block, $user);
        } catch (DomainException|InvalidArgumentException $refused) {
            return NoticeRedirect::to($request, self::cardOf($found['subject']), $this->refusalText($refused->getMessage()));
        }
        $this->audit->record($block ? 'env.person.blocked' : 'env.person.unblocked', $user, $found['subject'],
            $found['owner'] !== null ? ['owner' => $found['owner']] : []);
        $done = (string) $this->translator->get('larena-env::admin.people.block.'.($block ? 'done' : 'undone'), ['name' => $found['name']]);
        // Their server account's SSH and SFTP, where the server can close them; their sites keep working.
        $command = $block ? 'owner:block' : 'owner:unblock';
        $accountBlocked = $found['owner'] !== null && in_array($found['owner'], $overview->blockedOwners ?? [], true);
        if ($found['owner'] !== null && $accountBlocked !== $block && $overview?->capabilities?->allows($command) === true) {
            $started = $this->operations->start(ResolvePanelActor::of($request), EnvOperationCatalog::primaryFor($command) ?? '', ['name' => $found['owner']]);
            if ($started->jobId !== null) {
                return NoticeRedirect::to($request, route('larena.env.admin.job', ['job' => $started->jobId], false), $done);
            }
        }

        return NoticeRedirect::to($request, self::cardOf($found['subject']), $done);
    }

    /**
     * «Удалить человека»: the plan in words before anything changes — their sign-in, open sessions and an invitation
     * still waiting go; the journal keeps their name; their server account (the «Свои сайты» level) stays with its
     * sites, removed apart where the server can and it has none. Never oneself, never the last of the whole server.
     */
    public function removePlan(Request $request, string $person): mixed
    {
        $user = self::user($request);
        $overview = $this->overviews->read(new PanelActor($user))->overview;
        $found = $this->person($person, $user, $overview);
        $refused = $this->removalRefused($found, $user, $overview);
        if ($refused !== null) {
            return $this->back($request, $refused, $found === null ? null : self::cardOf($found['subject']));
        }
        $text = $this->text();
        $facts = [
            [$text('people.removal.fact_gone'), $text($found['pending'] ? 'people.removal.fact_gone_invited' : 'people.removal.fact_gone_value')],
            [$text('people.removal.fact_kept'), $text('people.removal.fact_kept_value', ['name' => $found['name']])],
            [$text('people.removal.fact_address'), $text('people.removal.fact_address_value', ['email' => $found['email']])],
        ];
        if ($found['owner'] !== null) {
            $sites = count($found['sites']);
            $apart = $sites === 0 && $overview?->capabilities?->allows('owner:remove') === true;
            $facts[] = [$text('people.block.fact_account', ['account' => $found['owner']]),
                $text($sites > 0 ? 'people.removal.fact_account_sites' : ($apart ? 'people.removal.fact_account_apart' : 'people.removal.fact_account_kept'), ['count' => (string) $sites])];
        }

        return $this->views->make('larena-env::admin.people-plan', [
            'heading' => $text('people.removal.open'),
            'action' => new AdminPlannedAction(
                question: $text('people.removal.question', ['name' => $found['name']]),
                runUrl: route('larena.env.admin.people.remove.run', ['person' => (string) $found['id']], false),
                token: (string) $request->session()->token(),
                runFields: ['remove' => 'yes', PlanReceipts::FIELD => $this->receipts->issue($request, 'person:remove', ['person' => $found['id']])],
                acceptText: $text('people.removal.accept'),
                danger: true,
                consequence: $text('people.removal.consequence'),
                facts: $facts,
                cancelUrl: self::cardOf($found['subject']),
                key: 'env-person-remove',
            ),
        ]);
    }

    public function removeRun(Request $request, string $person): RedirectResponse
    {
        $user = self::user($request);
        $overview = $this->overviews->read(new PanelActor($user))->overview;
        $found = $this->person($person, $user, $overview);
        $refused = $this->removalRefused($found, $user, $overview) ?? ($request->input('remove') !== 'yes' ? 'person_remove_unconfirmed' : null);
        if ($refused !== null || $found === null) {
            return $this->back($request, $refused ?? 'person_unknown');
        }
        if (!$this->receipts->confirms($request, 'person:remove', ['person' => $found['id']])) {
            return $this->back($request, PlanReceipts::REFUSED, self::cardOf($found['subject']));
        }
        if ($this->credentials === null) {
            return $this->back($request, 'person_remove_unavailable', self::cardOf($found['subject']));
        }
        try {
            // Auth's terminal state: no sign-in, every session ended; the last administrator is refused inside it.
            $result = $this->credentials->revoke($found['email'], $user);
            if (!$result->succeeded()) {
                return NoticeRedirect::to($request, self::cardOf($found['subject']), $this->refusalText($result->status));
            }
            // An invitation still waiting: its link stops working with the sign-in.
            if ($found['pending'] && $this->challenges !== null && $this->database !== null) {
                $this->database->connection()->transaction(fn (): bool => $this->challenges->revokeOutstandingForIdentityAtomically(
                    $found['id'], \Illuminate\Support\Carbon::now(), 'identity_removed'));
            }
            // The address is free again: the person can be invited anew, as a new record; the old one keeps the name.
            $this->credentials->releaseAddress($found['email'], $user);
        } catch (DomainException|InvalidArgumentException $failure) {
            return NoticeRedirect::to($request, self::cardOf($found['subject']), $this->refusalText($failure->getMessage()));
        }
        // The panel's own records of them; the server account stays (the «Свои сайты» level's sites keep working).
        $this->owners->release($found['subject']);
        if ($found['secret'] && $found['level'] !== PanelPeople::ALL) {
            $this->secrets?->set($found['subject'], false, $user);
        }
        $this->audit->record('env.person.removed', $user, $found['subject'],
            ['name' => $found['name']] + ($found['owner'] !== null ? ['owner' => $found['owner']] : []));

        return NoticeRedirect::to($request, route('larena.env.admin.people', [], false),
            (string) $this->translator->get('larena-env::admin.people.removal.done', ['name' => $found['name']]));
    }

    /**
     * Why a person may not be removed, or null: unknown, oneself, or the last person of the whole server who works.
     *
     * @param array{id: int, subject: string, level: string, blocked: bool, pending: bool}|null $found
     */
    private function removalRefused(?array $found, string $user, ?ServerOverview $overview): ?string
    {
        if ($found === null) {
            return 'person_unknown';
        }
        if ($found['subject'] === $user) {
            return 'person_remove_self';
        }
        if ($found['level'] === PanelPeople::ALL && !$found['blocked'] && !$found['pending']) {
            foreach ($this->peopleOf($user, $overview) as $other) {
                if ($other['id'] !== $found['id'] && $other['level'] === PanelPeople::ALL && !$other['blocked'] && !$other['pending']) {
                    return null;
                }
            }

            return 'person_remove_last';
        }

        return null;
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

    /** The owner actions of this screen: a new server account, blocking one and unblocking it, removing an empty one, a key for one. */
    private const array OWNER_ACTIONS = ['create' => 'owner:create', 'block' => 'owner:block', 'unblock' => 'owner:unblock',
        'remove' => 'owner:remove', 'add-key' => 'owner:add-key'];
    /** The same actions as the panel's Core operations (EnvOperationCatalog): what the screen plans and starts. */
    private const array OWNER_OPERATIONS = ['create' => 'env.owner.create', 'block' => 'env.owner.block', 'unblock' => 'env.owner.unblock', 'remove' => 'env.owner.remove', 'add-key' => 'env.owner.add_key'];
    private const array OWNER_HEADINGS = ['create' => 'new_owner', 'block' => 'owner_block', 'unblock' => 'unblock', 'remove' => 'remove', 'add-key' => 'add_key'];

    /**
     * How many sites each server account has, so the screen offers removing only one without sites (the server refuses the rest).
     *
     * @param list<\Larena\Env\Server\SiteSummary> $sites
     *
     * @return array<string, int>
     */
    public static function sitesByOwner(array $sites): array
    {
        $count = [];
        foreach ($sites as $site) {
            if ($site->owner !== null) {
                $count[$site->owner] = ($count[$site->owner] ?? 0) + 1;
            }
        }

        return $count;
    }

    /** A new server account, blocking one, unblocking it, removing an empty one or a key for it: planned, run on confirmation. */
    public function planOwner(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $action = self::ownerAction($request);
        $input = self::ownerInput($request, $action);
        $result = $this->operations->plan($actor, self::OWNER_OPERATIONS[$action], $input);
        if ($result->plan === null) {
            return $this->back($request, $result->reason ?? 'answer_shape_invalid');
        }

        return $this->views->make('larena-env::admin.plan', [
            'heading' => (string) $this->translator->get('larena-env::admin.people.'.self::OWNER_HEADINGS[$action]),
            'eyebrow' => $input['name'] ?? '',
            'target' => $input['name'] ?? '',
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route('larena.env.admin.people.owners.run', [], false),
            'cancelUrl' => route('larena.env.admin.people', [], false),
            'hidden' => ['action' => $action] + $input
                + [PlanReceipts::FIELD => $this->receipts->issue($request, self::OWNER_ACTIONS[$action], ['action' => $action, 'args' => $input])],
        ]);
    }

    public function runOwner(Request $request): RedirectResponse
    {
        $action = self::ownerAction($request);
        $input = self::ownerInput($request, $action);
        if (!$this->receipts->confirms($request, self::OWNER_ACTIONS[$action], ['action' => $action, 'args' => $input])) {
            return $this->back($request, PlanReceipts::REFUSED);
        }
        $started = $this->operations->start(ResolvePanelActor::of($request), self::OWNER_OPERATIONS[$action], $input);
        if ($started->jobId === null) {
            return $this->back($request, $started->reason ?? 'answer_shape_invalid');
        }

        return new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    private static function ownerAction(Request $request): string
    {
        $action = (string) $request->input('action', 'create');

        return array_key_exists($action, self::OWNER_ACTIONS) ? $action : 'create';
    }

    /** @return array<string, string> */
    private static function ownerInput(Request $request, string $action = 'create'): array
    {
        $input = [];
        foreach (in_array($action, ['create', 'add-key'], true) ? ['name', 'pubkey'] : ['name'] as $field) {
            $value = $request->input($field);
            if (is_string($value) && trim($value) !== '') {
                $input[$field] = trim($value);
            }
        }

        return $input;
    }

    /**
     * owner:create's arguments: the account, and its sites joined by commas as the gateway takes them.
     *
     * @param list<string> $sites
     *
     * @return array<string, string>
     */
    public static function createArgs(string $account, array $sites): array
    {
        return ['name' => $account] + ($sites === [] ? [] : ['sites' => implode(',', $sites)]);
    }

    private static function user(Request $request): string
    {
        return (string) $request->attributes->get('larena_access_actor', '');
    }

    /** The card's address of a person by their reference. */
    private static function cardOf(string $subject): string
    {
        return preg_match('/\Auser:admin_identity:([1-9][0-9]{0,17})\z/D', $subject, $found) === 1
            ? '/admin/server/people/'.$found[1] : '/admin/server/people';
    }

    /**
     * @return list<array{id: int, subject: string, name: string, email: string, level: string, blocked: bool, owner: ?string, sites: list<string>, last: ?string, secret: bool, pending: bool, until?: ?string}>
     */
    private function peopleOf(string $user, ?ServerOverview $overview): array
    {
        try {
            return $this->directory->all($user, $overview);
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array{id: int, subject: string, name: string, email: string, level: string, blocked: bool, owner: ?string, sites: list<string>, last: ?string, secret: bool, pending: bool, until?: ?string}|null */
    private function person(string $id, string $user, ?ServerOverview $overview): ?array
    {
        if (preg_match('/\A[1-9][0-9]{0,17}\z/D', $id) !== 1) {
            return null;
        }
        try {
            return $this->directory->one((int) $id, $user, $overview);
        } catch (Throwable) {
            return null;
        }
    }

    /** Whether someone signs in with this address already; one still waiting for their invitation does not count when $invitedAgain. */
    /**
     * An address still held by a person removed before it was freed on removal: freed now, so the invitation goes on.
     * Only a removed person's (Auth refuses any other); someone working, blocked or invited keeps theirs.
     */
    private function freeRemovedAddress(string $email, string $user): void
    {
        if ($this->credentials === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        try {
            if ($this->credentials->inspect($email, $user)->diagnostics['revoked'] ?? false) {
                $this->credentials->releaseAddress($email, $user);
            }
        } catch (DomainException|InvalidArgumentException) {
            // The invitation then says why it was refused, as before.
        }
    }

    private function emailTaken(string $email, string $user, bool $invitedAgain = false): bool
    {
        foreach ($this->peopleOf($user, null) as $person) {
            if (mb_strtolower($person['email']) === $email) {
                return !($invitedAgain && $person['pending']);
            }
        }

        return false;
    }

    private function canOperate(Request $request): bool
    {
        return $this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? true;
    }

    /** Back to the form, open, with what was typed except the password, and the reason above it. @param array<string, mixed> $input */
    private function inviteAgain(Request $request, array $input, string $reason): RedirectResponse
    {
        $request->session()->flash('larena_env_invite_old', array_intersect_key($input, array_flip(['name', 'email', 'level', 'account', 'account_name', 'secret', 'sites', 'how'])));

        return NoticeRedirect::to($request, route('larena.env.admin.people', [], false).'?invite=1', $reason);
    }

    /** @return \Closure(string, array<string, string>=): string */
    private function text(): \Closure
    {
        return fn (string $key, array $replace = []): string => (string) $this->translator->get('larena-env::admin.'.$key, $replace);
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

    private function back(Request $request, string $reason, ?string $to = null): RedirectResponse
    {
        return NoticeRedirect::to($request, $to ?? route('larena.env.admin.people', [], false), $this->reasonText($reason));
    }

    /** What Access said in refusing, in words where the users' screen has them; the code otherwise. */
    private function refusalText(string $code): string
    {
        foreach (['larena-env::admin.reasons.'.$code, 'larena-auth::auth.errors.'.$code, 'larena-admin::admin.users.errors.'.$code] as $key) {
            $line = $this->translator->get($key);
            if (is_string($line) && $line !== $key) {
                return $line;
            }
        }

        return (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $code]);
    }

    private function reasonText(string $reason): string
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
    }
}
