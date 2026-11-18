<?php

declare(strict_types=1);

// Sites moving between server accounts (roles study, second wave), on the recorded answers of simai-env 1.63.1 and a
// stand-in gateway for the refusals the recording does not hold: «Пригласить человека» with «Свои сайты» passes the
// chosen sites to owner:create as one job and says per site from whom it moves, every refusal in words, and that a site
// that failed stays with its previous account while «Повторить» continues; «Передать сайт…» on a site's card is the whole
// server's alone, offers only another account (never none), and its plan says the backup first, no break, the previous
// account's lost access, the database left as it is and the way back.

use Larena\Env\Admin\AccountMoves;
use Larena\Env\Admin\EnvHistorySource;
use Larena\Env\Admin\PlanAction;
use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Gateway\PanelCommands;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Gateway\RecordedAnswersGateway;
use Larena\Env\Http\Controllers\PeopleController;
use Larena\Env\Operations\PanelJobStatus;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Operations\PanelPlan;
use Larena\Env\Operations\SiteOperation;
use Larena\Env\Server\SiteDetails;
use Larena\Env\Server\SiteSummary;

require_once dirname(__DIR__).'/Support/env-test.php';

$lines = require dirname(__DIR__, 2).'/resources/lang/ru/admin.php';
$english = require dirname(__DIR__, 2).'/resources/lang/en/admin.php';

/** @param array<string, string> $replace */
function __(string $key, array $replace = []): string
{
    global $lines;
    $line = str_starts_with($key, 'larena-env::admin.') ? data_get($lines, substr($key, strlen('larena-env::admin.'))) : null;
    $line = is_string($line) ? $line : $key;
    foreach ($replace as $name => $value) {
        $line = str_replace(':'.$name, (string) $value, $line);
    }

    return $line;
}

$text = static fn (string $key, array $replace = []): string => __('larena-env::admin.'.$key, $replace);
$admin = new PanelActor('admin@stand.test');

// 1. The command table: owner:create takes up to fifty sites joined by commas (1.63.1); site:transfer a site and an account.
PanelCommands::check('owner:create', ['name' => 'staff', 'sites' => 'demo-php.test,demo-owned.test']);
$fiftyOne = implode(',', array_map(static fn (int $n): string => "s{$n}.test", range(1, 51)));
foreach ([['owner:create', ['name' => 'staff', 'sites' => $fiftyOne]], ['owner:create', ['name' => 'staff', 'sites' => 'a.test;rm']],
    ['site:transfer', ['domain' => 'demo-php.test']], ['site:transfer', ['domain' => 'demo-php.test', 'owner' => 'Bad Name']]] as [$command, $args]) {
    try {
        PanelCommands::check($command, $args);
        env_assert(false, $command.' '.json_encode($args).' is refused before it leaves.');
    } catch (PanelRefused) {
    }
}
PanelCommands::check('site:transfer', ['domain' => 'demo-php.test', 'owner' => 'demo']);
env_assert(PanelCommands::kind('site:transfer') === PanelCommands::ACTION, 'A transfer is an action: planned, then confirmed.');
$on = static fn (string $version): PanelCapabilities => PanelCapabilities::fromHostDescription(['simai_env' => ['version' => $version]]);
env_assert($on('1.63.1')->takes('owner:create', 'sites') && !$on('1.63.0')->takes('owner:create', 'sites') && $on('1.63.0')->allows('owner:create'),
    'The sites of a new account are asked only of a server that takes them (1.63.1).');
env_assert($on('1.57.0')->allows('site:transfer') && !$on('1.56.2')->allows('site:transfer'), 'A transfer from simai-env 1.57.0.');
env_assert(PeopleController::createArgs('staff', ['demo-php.test', 'demo-owned.test']) === ['name' => 'staff', 'sites' => 'demo-php.test,demo-owned.test']
    && PeopleController::createArgs('staff', []) === ['name' => 'staff'], 'One job: the account and its sites in one request, no sites argument without sites.');

// 2. «Пригласить человека»: the sites offered (never an alias, the panel's own is left out by the overview) and those chosen.
$summary = static fn (string $domain, ?string $owner, string $profile = 'generic'): SiteSummary => new SiteSummary($domain, $profile, '8.3', 'none', $owner, null, null, null);
$offered = AccountMoves::offered([$summary('demo-php.test', null), $summary('demo-owned.test', 'demo'), $summary('www.demo.test', null, 'alias')]);
env_assert($offered === [['domain' => 'demo-php.test', 'owner' => null], ['domain' => 'demo-owned.test', 'owner' => 'demo']], 'An alias moves with its site, never offered.');
env_assert(AccountMoves::chosen(['demo-owned.test', 'demo-php.test', 'demo-php.test'], $offered) === ['demo-php.test', 'demo-owned.test']
    && AccountMoves::chosen(null, $offered) === [] && AccountMoves::chosen(['evil.test'], $offered) === null
    && AccountMoves::chosen('demo-owned.test, demo-php.test', $offered) === ['demo-php.test', 'demo-owned.test'] && AccountMoves::chosen('', $offered) === [],
    'The chosen sites in the server\'s order, once each; one the server did not list sends the person back to choose.');

// 3. The plan of a new account with sites, as simai-env 1.63.1 answers it (recorded on the stand).
$recorded = new PanelOperations(new RecordedAnswersGateway(env_recorded_path()));
$planned = $recorded->planCommand($admin, 'owner:create', PeopleController::createArgs('staff', ['demo-php.test', 'demo-owned.test']));
env_assert($planned->plan?->account === ['owner' => 'staff', 'new' => true, 'sites' => [['domain' => 'demo-php.test', 'from' => null], ['domain' => 'demo-owned.test', 'from' => 'demo']]],
    'Each site with the account it leaves: '.json_encode($planned->plan?->account));
env_assert(PanelPlan::fromAccount('owner demo') === 'demo' && PanelPlan::fromAccount('the shared user simai (no owner)') === null
    && PanelPlan::fromAccount('its own user site-demo-php-test (no owner)') === null, 'The server\'s words of the previous account.');
$facts = AccountMoves::createFacts($planned->plan->account, $text);
env_assert($facts[0] === ['demo-php.test', $text('people.invite.fact.site_from_none')] && $facts[1] === ['demo-owned.test', 'перейдёт от аккаунта demo']
    && str_contains($facts[2][1], 'резервная копия') && str_contains($facts[2][1], 'продолжает работать') && str_contains($facts[2][1], 'теряет доступ')
    && $facts[3][0] === 'Если сайт не перейдёт' && str_contains($facts[3][1], 'останется у прежнего аккаунта') && str_contains($facts[3][1], '«Повторить»'),
    'Per site from whom; how a site moves; a failed one stays with its previous account and repeating continues: '.json_encode($facts, JSON_UNESCAPED_UNICODE));
// The same words where the server's plan is shown as it is (the job's «Повторить»).
$part = (string) PlanAction::part(['plan' => $planned->plan, 'runUrl' => '/run', 'heading' => 'Новый аккаунт на сервере staff', 'cancelUrl' => '/cancel'], 'token');
env_assert(str_contains($part, 'перейдёт от аккаунта demo') && str_contains($part, 'появится новый аккаунт staff') && !str_contains($part, 'demo-php.test,demo-owned.test'),
    'The repeated plan says the moves in words, not the server\'s comma list.');
// The refusals the recording holds: a site the server does not have, the panel's own site.
foreach (['gone.test' => 'site_missing', 'panel.localhost' => 'panel_site'] as $site => $reason) {
    $refused = $recorded->planCommand($admin, 'owner:create', ['name' => 'staff', 'sites' => 'demo-php.test,'.$site]);
    env_assert($refused->plan === null && $refused->reason === $reason, $site.' is refused as '.$reason.': '.json_encode($refused->reason));
}

// 4. Every refusal of a new account's sites, in words, with nothing made (a stand-in answers the ones not recorded).
$refusing = new class () implements PanelGateway {
    public string $reason = '';
    /** @var list<PanelRequest> */
    public array $asked = [];

    public function ask(PanelRequest $request): PanelAnswer
    {
        $this->asked[] = $request;

        return PanelAnswer::fromDocument($request->requestId, ['schema' => 'simai-panel-answer/1', 'request_id' => $request->requestId, 'status' => 'refused',
            'reason' => $this->reason, 'data' => ['checks' => ['blockers' => ['server words'], 'notes' => []]]]);
    }

    public function source(): string
    {
        return 'stand-in';
    }
};
$standIn = new PanelOperations($refusing);
foreach (AccountMoves::CREATE_REFUSALS as $reason) {
    $refusing->reason = $reason;
    $result = $standIn->planCommand($admin, 'owner:create', ['name' => 'staff', 'sites' => 'demo-php.test']);
    $said = AccountMoves::refusal('owner:create', (string) $result->reason, $text);
    env_assert($result->plan === null && $said !== null && !str_contains($said, 'people.invite') && str_contains($said, 'аккаунт не создан')
        && is_string($english['people']['invite']['refusals'][$reason] ?? null), $reason.' is said in words, nothing made: '.json_encode($said, JSON_UNESCAPED_UNICODE));
}
env_assert(str_contains((string) AccountMoves::refusal('owner:create', 'too_many', $text), '50'), 'At most fifty sites, said with the number.');
env_assert(AccountMoves::refusal('owner:create', 'not_enough_disk', $text) === null, 'Another refusal keeps the panel\'s general words.');
env_assert(array_filter($refusing->asked, static fn (PanelRequest $one): bool => $one->confirm) === [], 'A refused plan never starts anything.');

// 5. «Передать сайт…»: the whole server's action, another account only, never none.
$site = static fn (array $more = []): SiteDetails => SiteDetails::fromDescription($more + ['schema' => 'simai-site/1', 'domain' => 'demo-php.test', 'profile' => 'generic', 'php' => '8.3', 'ssl' => 'none']);
env_assert(SiteOperation::SiteTransfer->command() === 'site:transfer' && SiteOperation::SiteTransfer->tab() === 'overview'
    && SiteOperation::SiteTransfer->fields() === ['owner']
    && !SiteOperation::SiteTransfer->destroys() && SiteOperation::SiteTransfer->plansAsChosen($on('1.63.1')),
    'On the card\'s overview, the whole server\'s alone, the account chosen from a list and the plan shown as it is chosen.');
env_assert(SiteOperation::SiteTransfer->arguments('demo-php.test', ['owner' => 'demo']) === ['domain' => 'demo-php.test', 'owner' => 'demo'], 'The site and the account.');
env_assert(SiteOperation::transferChoices('demo', ['demo', 'tpetrov', 'old'], ['old']) === ['tpetrov'] && SiteOperation::transferChoices(null, ['demo'], []) === ['demo']
    && SiteOperation::transferChoices('demo', ['demo'], []) === [], 'Every other account that is not blocked; nothing to offer without one.');
env_assert(in_array(SiteOperation::SiteTransfer, SiteOperation::offeredFor($site(), $on('1.63.1')), true) && !SiteOperation::SiteTransfer->suitsSite($site(['profile' => 'alias']))
    && !in_array(SiteOperation::SiteTransfer, SiteOperation::offeredFor($site(), $on('1.56.2')), true), 'Offered where the server has it; an alias goes with its site.');

// 6. Its plan, as simai-env answers it (recorded on the stand): from whom, to whom, the backup, no break, the database.
$transfer = $recorded->plan($admin, SiteOperation::SiteTransfer, 'demo-php.test', ['owner' => 'demo']);
env_assert($transfer->plan?->transfer === ['domain' => 'demo-php.test', 'from' => null, 'to' => 'demo', 'backup' => true, 'serving' => true, 'database' => true],
    'The transfer plan is read: '.json_encode($transfer->plan?->transfer));
$said = AccountMoves::transferFacts($transfer->plan->transfer, $text);
$words = json_encode($said, JSON_UNESCAPED_UNICODE);
env_assert(str_contains($words, 'ни к какому аккаунту не привязан') && str_contains($words, 'к аккаунту demo') && str_contains($words, 'резервная копия базы и файлов')
    && str_contains($words, 'нет: сайт продолжит открываться') && str_contains($words, 'не меняется') && str_contains($words, '«без аккаунта» сайт уже не станет')
    && !str_contains($words, 'потеряет доступ'), 'A site of no account: backup, no break, database unchanged, no way back to none: '.$words);
$fromDemo = AccountMoves::transferFacts(['domain' => 'demo-owned.test', 'from' => 'demo', 'to' => 'tpetrov', 'backup' => true, 'serving' => true, 'database' => false], $text);
$fromWords = json_encode($fromDemo, JSON_UNESCAPED_UNICODE);
env_assert(str_contains($fromWords, 'Аккаунт demo') && str_contains($fromWords, 'потеряет доступ к файлам сайта по SFTP и SSH') && str_contains($fromWords, 'выбрав аккаунт demo')
    && !str_contains($fromWords, 'База данных'), 'From an account: it loses access, and the way back names it: '.$fromWords);
$transferPart = (string) PlanAction::part(['plan' => $transfer->plan, 'runUrl' => '/run', 'heading' => __('larena-env::admin.transfer.heading', ['domain' => 'demo-php.test', 'account' => 'demo']),
    'cancelUrl' => '/cancel', 'hidden' => ['operation' => 'site-transfer', 'owner' => 'demo']], 'token');
env_assert(str_contains($transferPart, 'Передать demo-php.test аккаунту demo?') && str_contains($transferPart, 'резервная копия базы и файлов')
    && !str_contains($transferPart, 'Владелец'), 'The question names the site and the account; the facts are the panel\'s, not the server\'s «Владелец».');
// Its refusals: the recorded one (a site handed to the account it has), then each the server names.
$same = $recorded->plan($admin, SiteOperation::SiteTransfer, 'demo-owned.test', ['owner' => 'demo']);
env_assert($same->plan === null && $same->reason === 'same_owner' && AccountMoves::refusal('site:transfer', 'same_owner', $text) === 'Сайт уже у этого аккаунта.',
    'Handed to its own account: refused and said.');
foreach (AccountMoves::TRANSFER_REFUSALS as $reason) {
    $refusing->reason = $reason;
    $result = $standIn->plan($admin, SiteOperation::SiteTransfer, 'demo-php.test', ['owner' => 'demo']);
    $said = AccountMoves::refusal('site:transfer', (string) $result->reason, $text);
    env_assert($result->plan === null && $said !== null && !str_contains($said, 'transfer.refusals') && is_string($english['transfer']['refusals'][$reason] ?? null),
        $reason.' in words: '.json_encode($said, JSON_UNESCAPED_UNICODE));
}
// A started transfer is named in the panel's words in «История» and on the job's page.
$job = PanelJobStatus::fromData(['job_id' => str_repeat('a', 24), 'command' => 'site:transfer', 'args' => ['domain' => 'demo-php.test', 'owner' => 'demo'],
    'state' => 'running', 'started_at' => '2026-11-18T10:00:00Z']);
env_assert(EnvHistorySource::words($job, static fn (string $key, array $replace = []): string => __($key, $replace)) === 'Передача сайта demo-php.test аккаунту demo',
    'The job\'s heading: '.EnvHistorySource::words($job, static fn (string $key, array $replace = []): string => __($key, $replace)));

// 7. The lines both languages carry.
foreach ([$lines, $english] as $language) {
    foreach (['operations.site-transfer', 'fields.owner', 'fields.owner_choose', 'transfer.heading', 'transfer.note', 'transfer.note_from', 'transfer.fact.back_none',
        'people.invite.sites', 'people.invite.sites_hint', 'people.invite.sites_none', 'people.invite.site_now_account', 'people.invite.site_now_none', 'people.invite.done_sites',
        'people.invite.invalid.sites', 'people.invite.fact.failure_value', 'people.invite.refusals.nothing_made', 'runs.commands.site.transfer'] as $key) {
        env_assert(is_string(data_get($language, $key)), 'line '.$key);
    }
}

echo "AccountMovesTest passed.\n";
