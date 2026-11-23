<?php

declare(strict_types=1);

// «Временная ссылка» (simai-env 1.65.0): site:preview-link {domain, minutes 10-1440} is planned and confirmed as every
// action of the site; the plan says the address (never the key), the term and whether an open link is renewed; the link
// with its key comes once in the first job:status after the job (ONCE_RESULT preview_url), never in its log, and the panel
// keeps it nowhere — not in its cache, nor in site:describe's preview_link. A second call keeps the link and moves its
// deadline; site:preview-link-close closes it. The stand-in gateway (FakeFilesGateway) answers the contract on recorded
// answers, as the install check uses it.

use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Larena\Env\Gateway\CachingPanelGateway;
use Larena\Env\Gateway\FakeFilesGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Gateway\PanelCommands;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Gateway\RecordedAnswersGateway;
use Larena\Env\Operations\JobSecret;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Operations\PreviewLink;
use Larena\Env\Operations\SiteOperation;
use Larena\Env\Server\PreviewLinkState;
use Larena\Env\Server\SiteDetails;
use Larena\Env\Server\SiteDetailsReader;

require_once dirname(__DIR__).'/Support/env-test.php';

$lines = require dirname(__DIR__, 2).'/resources/lang/en/admin.php';
$text = static function (string $key) use ($lines): string {
    $value = data_get($lines, $key);
    env_assert(is_string($value), "No English text for {$key}.");

    return $value;
};

/** @param array<string, string> $replace */
function __(string $key, array $replace = []): string
{
    global $text;
    $line = $text(str_replace('larena-env::admin.', '', $key));
    foreach ($replace as $name => $value) {
        $line = str_replace(':'.$name, $value, $line);
    }

    return $line;
}

/** @param array<string, mixed> $parameters */
function route(string $name, array $parameters = [], bool $absolute = true): string
{
    return '/admin/server/'.$name.(($parameters['domain'] ?? $parameters['job'] ?? null) !== null ? '/'.implode('/', $parameters) : '');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="test">';
}

function csrf_token(): string
{
    return 'test';
}

$admin = new PanelActor('admin@example.com');
$owner = new PanelActor('staff@example.com', 'demo');

// 1. The commands: an action with 10 to 1440 minutes, its close, its status; and files-live of a restore; from 1.65.0.
env_assert(PanelCommands::kind('site:preview-link') === PanelCommands::ACTION && PanelCommands::kind('site:preview-link-close') === PanelCommands::ACTION
    && PanelCommands::kind('site:preview-link-status') === PanelCommands::READ && PanelCommands::since('site:preview-link') === '1.65.0'
    && PanelCommands::since('site:preview-link-status') === '1.65.0', 'Two actions and a read of 1.65.0.');
foreach (['10', '60', '90', '999', '1000', '1399', '1439', '1440'] as $minutes) {
    env_assert(PanelCommands::valid('site:preview-link', 'minutes', $minutes), "{$minutes} minutes are taken.");
}
foreach (['9', '0', '1441', '2000', '60 ', 'x', 'custom', '010'] as $minutes) {
    env_assert(!PanelCommands::valid('site:preview-link', 'minutes', $minutes), "{$minutes} minutes are refused before the server.");
}
env_assert(!(new PanelCapabilities('1.64.0'))->allows('site:preview-link') && (new PanelCapabilities('1.65.0'))->allows('site:preview-link-close'), 'Offered from 1.65.0 only.');
env_assert((new PanelCapabilities('1.65.0'))->takes('backup:data-restore', 'files-live') && !(new PanelCapabilities('1.64.0'))->takes('backup:data-restore', 'files-live'),
    'The files straight into the site from 1.65.0.');
env_assert(SiteOperation::PreviewLink->command() === 'site:preview-link' && SiteOperation::PreviewLink->tab() === 'domains' && SiteOperation::PreviewLink->fields() === ['minutes']
    && SiteOperation::PreviewLinkClose->asksNothing() && !SiteOperation::PreviewLink->plansAsChosen() && array_intersect(array_map(static fn (SiteOperation $one): string => $one->value, SiteOperation::SYSTEM_ONLY), ['preview-link', 'preview-link-close']) === []
    && SiteOperation::inRow([SiteOperation::PreviewLink, SiteOperation::PreviewLinkClose, SiteOperation::RedirectAdd]) === [SiteOperation::RedirectAdd],
    'On «Домены и HTTPS», from its own block, an owner\'s as an admin\'s; closing asks nothing; a typed term waits for «Показать, что будет».');
env_assert(SiteOperation::previewMinutes('60', '15') === '60' && SiteOperation::previewMinutes('1440', null) === '1440' && SiteOperation::previewMinutes('custom', ' 90 ') === '90'
    && SiteOperation::previewMinutes('custom', '') === 'custom' && SiteOperation::previewMinutes(null, '30') === null, 'An hour, a day, or a number of its own.');

// 2. The stand-in server: recorded answers with the temporary link of 1.65.0.
$root = env_temp_directory();
$fake = new FakeFilesGateway(new RecordedAnswersGateway(env_recorded_path()), $root);
$cache = new \Illuminate\Cache\Repository($store = new \Illuminate\Cache\ArrayStore());
$server = new CachingPanelGateway($fake, $cache);
$operations = env_operations($server);
$sites = new SiteDetailsReader($server);
$listed = $fake->ask(PanelRequest::read($admin, 'self:describe'))->data['gate']['commands'] ?? [];
foreach (FakeFilesGateway::PREVIEW as $command) {
    env_assert(in_array($command, $listed, true), "The stand-in lists {$command}.");
}
$site = $sites->read($admin, 'demo-php.test')->site;
env_assert($site instanceof SiteDetails && $site->previewLink === null && !SiteOperation::PreviewLinkClose->suitsSite($site) && SiteOperation::PreviewLink->suitsSite($site),
    'No link yet: only «Получить ссылку».');

// The plan: the address on the server's IP and a port, an hour, a new link.
$plan = $operations->plan($admin, SiteOperation::PreviewLink, 'demo-php.test', ['minutes' => '60']);
env_assert($plan->plan?->command === 'site:preview-link' && $plan->plan->preview === ['address' => 'http://203.0.113.10:7700/', 'minutes' => 60, 'renew' => false],
    'The plan: '.json_encode($plan->plan?->preview));
$facts = \Larena\Env\Admin\PlanAction::of($plan->plan, '/run', ['operation' => 'preview-link', 'minutes' => '60'], 'test',
    ['heading' => 'Temporary link', 'target' => 'demo-php.test', 'cancel' => '/', 'check' => null, 'warnings' => [], 'more' => ''])->facts;
$facts = array_map(static fn (array $fact): array => [$fact['label'], $fact['value']], $facts);
env_assert(in_array([$text('preview_link.plan.address'), 'http://203.0.113.10:7700/'], $facts, true) && in_array([$text('preview_link.plan.term'), $text('preview_link.terms.60')], $facts, true)
    && in_array([$text('preview_link.plan.link'), $text('preview_link.plan.new')], $facts, true), 'The plan in words: '.json_encode($facts));
env_assert(\Larena\Env\Admin\PlanAction::previewTerm(1440) === $text('preview_link.terms.1440') && \Larena\Env\Admin\PlanAction::previewTerm(90) === '90 minutes', 'A day, or its minutes.');

// The job: the link once, never in its log, never in the cache.
$started = $operations->start($admin, SiteOperation::PreviewLink, 'demo-php.test', ['minutes' => '60']);
env_assert(is_string($started->jobId), 'Confirmed, it opens: '.(string) $started->reason);
$first = $operations->view($admin, (string) $started->jobId);
$link = $first->status?->preview;
env_assert($link instanceof PreviewLink && preg_match('#\Ahttp://203\.0\.113\.10:7700/\.simai-preview/[a-f0-9]{32}\z#', $link->url) === 1 && $link->address === 'http://203.0.113.10:7700/'
    && $link->expiresAt !== null && !$link->renewed, 'The first answer after the job: the link with its key, its deadline.');
$key = substr($link->url, -32);
$kept = static function () use ($store): string {
    return serialize((fn (): array => $this->storage)->call($store));
};
env_assert(!str_contains(implode("\n", $first->log->lines ?? []), $key) && !str_contains(implode("\n", $first->log->lines ?? []), '.simai-preview'), 'The job\'s log never carries the link.');
env_assert(!str_contains($kept(), $key), 'The panel\'s cache never keeps the link.');
env_assert(!str_contains(print_r($link, true), $key) && !str_contains(print_r($first->status, true), $key),
    'The link is never printed by accident.');
// Even a kept read that carried it would not be kept: whatever answer has a value given once goes nowhere but the screen.
$carrying = new class ($link->url) implements \Larena\Env\Contracts\PanelGateway {
    public function __construct(private string $url)
    {
    }

    public function ask(PanelRequest $request): \Larena\Env\Gateway\PanelAnswer
    {
        return \Larena\Env\Gateway\PanelAnswer::fromDocument($request->requestId, ['status' => 'ok', 'kind' => 'result',
            'data' => ['schema' => 'simai-site/1', 'result' => ['preview_url' => $this->url]]]);
    }

    public function source(): string
    {
        return 'stand-in';
    }
};
$otherStore = new \Illuminate\Cache\ArrayStore();
(new CachingPanelGateway($carrying, new \Illuminate\Cache\Repository($otherStore)))->ask(PanelRequest::read($admin, 'site:describe', ['domain' => 'demo-php.test']));
env_assert(!str_contains(serialize((fn (): array => $this->storage)->call($otherStore)), $key), 'A kept read carrying the link is not kept.');
env_assert(JobSecret::in(['result' => ['preview_url' => $link->url]]) && !JobSecret::in(['result' => ['shown_once' => ['preview_url']]]), 'The link is one of the values given once.');
$again = $operations->view($admin, (string) $started->jobId);
env_assert($again->status?->preview === null && in_array('preview_url', $again->status->shownOnce, true) && !str_contains(json_encode($again->status->args) ?: '', $key),
    'Asked again, the server only says it was shown: it is shown once.');
foreach ([
    'https://203.0.113.10:7700/.simai-preview/'.$key, 'http://evil.example.com:7700/.simai-preview/'.$key, 'http://203.0.113.10:7700/.simai-db/'.$key,
    'http://203.0.113.10:7700/.simai-preview/'.$key.'"onclick=x', 'http://203.0.113.10:7700/.simai-preview/short',
] as $bad) {
    env_assert(PreviewLink::fromData(['preview_url' => $bad]) === null, "Only the server's own link is shown as one: {$bad}");
}
env_assert(PreviewLink::fromData(['preview_url' => 'http://[2001:db8::10]:7701/.simai-preview/'.$key])?->url !== null, 'An IPv6 address in brackets.');

// The card: open until when, never the key; the status says the same.
$site = $sites->read($admin, 'demo-php.test')->site;
env_assert($site?->previewLink instanceof PreviewLinkState && $site->previewLink->address === 'http://203.0.113.10:7700/' && SiteOperation::PreviewLinkClose->suitsSite($site),
    'site:describe says the link is open, and until when.');
env_assert(!str_contains($kept(), $key) && str_contains($kept(), '203.0.113.10:7700'), 'The kept description has the address and never the key.');
$status = $fake->ask(PanelRequest::read($admin, 'site:preview-link-status', ['domain' => 'demo-php.test']));
env_assert(PreviewLinkState::fromData($status->data['preview_link'] ?? null)?->address === 'http://203.0.113.10:7700/' && !str_contains(json_encode($status->data) ?: '', $key), 'The status without the key.');
env_assert(PreviewLinkState::fromData(['url' => 'http://203.0.113.10:7700/.simai-preview/'.$key]) === null && PreviewLinkState::fromData(null) === null, 'A status that carries a key is no status.');

// Asked again while open: the same link, a later deadline, shown again.
$renew = $operations->plan($admin, SiteOperation::PreviewLink, 'demo-php.test', ['minutes' => '1440']);
env_assert(($renew->plan?->preview['renew'] ?? null) === true && ($renew->plan->preview['address'] ?? null) === 'http://203.0.113.10:7700/', 'The plan says it renews the open link.');
$renewed = $operations->view($admin, (string) $operations->start($admin, SiteOperation::PreviewLink, 'demo-php.test', ['minutes' => '1440'])->jobId)->status?->preview;
env_assert($renewed instanceof PreviewLink && $renewed->renewed && $renewed->url === $link->url && strcmp((string) $renewed->expiresAt, (string) $link->expiresAt) > 0,
    'Renewed: the same port and key, the deadline moved, the link given again.');
env_assert(!str_contains($kept(), $key), 'Still nothing of the key in the cache.');
// Extending an open link is answered «Продлить», not with the action's name.
$renewAction = \Larena\Env\Admin\PlanAction::of($renew->plan, '/run', ['operation' => 'preview-link', 'minutes' => '1440'], 'test',
    ['heading' => $text('operations.preview-link'), 'target' => 'demo-php.test', 'cancel' => '/', 'check' => null, 'warnings' => [], 'more' => '']);
$openAction = \Larena\Env\Admin\PlanAction::of($plan->plan, '/run', ['operation' => 'preview-link', 'minutes' => '60'], 'test',
    ['heading' => $text('operations.preview-link'), 'target' => 'demo-php.test', 'cancel' => '/', 'check' => null, 'warnings' => [], 'more' => '']);
env_assert($renewAction->acceptText === $text('preview_link.renew') && $openAction->acceptText === $text('operations.preview-link'),
    'Renewing says «Продлить»; a new link keeps the action\'s name: '.$renewAction->acceptText.' / '.$openAction->acceptText);
// «Вернуть сайт» (1.65.0 files-live): the plan says what comes back in words, never the server's argument names: the files into the site, the database only
// where the backup holds one.
$words = static fn (bool $db): array => array_map(static fn (array $fact): array => [$fact['label'], $fact['value']], \Larena\Env\Admin\PlanAction::of(
    \Larena\Env\Operations\PanelPlan::fromData(['command' => 'backup:data-restore', 'args' => ['domain' => 'demo-static.test', 'backup' => '20261120-030000', 'files-live' => 'yes',
        'db' => $db ? 'yes' : 'no'], 'description' => 'x', 'effects' => 'write', 'lock' => 'site']),
    '/run', ['op' => 'return-site', 'step' => 'restore', 'db' => $db ? 'yes' : 'no'], 'test',
    ['heading' => 'Return', 'target' => 'demo-static.test', 'cancel' => '/', 'check' => null, 'warnings' => [], 'more' => ''])->facts);
$flat = json_encode([$words(false), $words(true)], JSON_UNESCAPED_UNICODE) ?: '';
env_assert(!str_contains($flat, 'files-live') && in_array([$text('storages.held.plan.files'), $text('storages.held.plan.files_value')], $words(false), true)
    && !in_array([$text('storages.held.plan.db'), $text('storages.held.plan.db_value')], $words(false), true)
    && in_array([$text('storages.held.plan.db'), $text('storages.held.plan.db_value')], $words(true), true), 'The plan in words: '.$flat);
// …and its end says what came back, the files and the database where the backup held one, and opens the site's card.
$returned = static fn (string $db): \Larena\Env\Operations\PanelJobStatus => new \Larena\Env\Operations\PanelJobStatus(jobId: '0123456789abcdef01234567',
    state: \Larena\Env\Operations\JobState::Succeeded, command: 'backup:data-restore', args: ['domain' => 'demo-static.test', 'backup' => '20261120-030000', 'files-live' => 'yes', 'db' => $db],
    exitCode: 0, createdAt: null, actor: null);
env_assert(\Larena\Env\Admin\JobOutcome::heading($returned('no')) === 'The site demo-static.test is back: its files are in place'
    && \Larena\Env\Admin\JobOutcome::heading($returned('yes')) === 'The site demo-static.test is back: its files and database are in place'
    && \Larena\Env\Admin\JobOutcome::next($returned('no')) === ['/admin/server/larena.env.admin.site/demo-static.test', $text('job.next.site')],
    'The end of «Вернуть сайт» in words: '.\Larena\Env\Admin\JobOutcome::heading($returned('no')).' → '.json_encode(\Larena\Env\Admin\JobOutcome::next($returned('no'))));
// One clock for a job (owner check of 0.4.7 on 1.65.0: the run's side panel said 13:36, «История» 16:36): the job's times
// are said in the panel's own time zone setting, as the panel's other times; the server's only outside the admin.
$container = \Illuminate\Container\Container::getInstance();
env_assert(\Larena\Env\Server\Moment::zone() === null || $container->bound(\Larena\Admin\Settings\AdminSiteSettings::class), 'Outside the admin no zone of the panel.');
$container->instance(\Larena\Admin\Settings\AdminSiteSettings::class, new class {
    public function timezone(): string { return 'Europe/Moscow'; }
});
env_assert(\Larena\Env\Server\Moment::zone() === 'Europe/Moscow', 'The panel\'s time zone setting is the job\'s.');
$container->forgetInstance(\Larena\Admin\Settings\AdminSiteSettings::class);
$job = (string) file_get_contents(dirname(__DIR__, 2).'/src/Http/Controllers/ServerJobController.php');
env_assert(str_contains($job, "'timezone' => \$result->status === null ? null : (\\Larena\\Env\\Server\\Moment::zone() ??"), 'The job\'s page says its times in the panel\'s zone first.');
// The link's runs in «История» and on the job's progress in the panel's words, in every language, never the server's English title.
$run = static fn (string $command): \Larena\Env\Operations\PanelJobStatus => new \Larena\Env\Operations\PanelJobStatus(jobId: '0123456789abcdef01234567',
    command: $command, args: ['domain' => 'demo-php.test'], state: \Larena\Env\Operations\JobState::Succeeded, exitCode: 0, createdAt: null, actor: null, title: 'Site preview link demo-php.test');
$history = static fn (string $command): string => \Larena\Env\Admin\EnvHistorySource::words($run($command), static fn (string $key, array $replace = []): string => __($key, $replace));
env_assert($history('site:preview-link') === 'Temporary link to demo-php.test' && $history('site:preview-link-close') === 'Temporary link to demo-php.test closed',
    'History says the link\'s runs in the panel\'s words: '.$history('site:preview-link'));
foreach (glob(dirname(__DIR__, 2).'/resources/lang/*/admin.php') ?: [] as $file) {
    $words = require $file;
    foreach (['preview_link', 'preview_link_close'] as $name) {
        $line = $words['runs']['commands']['site'][$name] ?? null;
        env_assert(is_string($line) && str_contains($line, ':domain') && !str_contains($line, 'preview link'), basename(dirname($file)).": runs.commands.site.{$name} in its own words.");
    }
}

// An owner opens a link on their own site, not on another's.
env_assert($operations->plan($owner, SiteOperation::PreviewLink, 'demo-owned.test', ['minutes' => '60'])->plan?->command === 'site:preview-link', 'An owner\'s own site.');
env_assert($operations->plan($owner, SiteOperation::PreviewLink, 'demo-php.test', ['minutes' => '60'])->plan === null, 'Not another owner\'s site.');

// What stops it: the panel knows an alias and a paused site; the server names the rest in the plan.
$described = static fn (array $over = []): SiteDetails => SiteDetails::fromDescription($over + ['schema' => 'simai-site/1', 'domain' => 'shop.example.com', 'profile' => 'generic',
    'php' => '8.3', 'ssl' => 'none']);
env_assert(PreviewLinkState::refusal($described()) === null && PreviewLinkState::refusal($described(['profile' => 'alias'])) === 'alias'
    && PreviewLinkState::refusal($described(['suspended' => true])) === 'suspended', 'An alias and a paused site, said before anyone asks.');
foreach (['no_port', 'no_address', 'no_nginx'] as $why) {
    file_put_contents($root.'/preview-refuse', $why);
    env_assert($operations->plan($admin, SiteOperation::PreviewLink, 'demo-static.test', ['minutes' => '60'])->reason === $why, "The plan refuses {$why}.");
}
unlink($root.'/preview-refuse');

// Closing it: planned, confirmed, and the card has no link any more.
$close = $operations->plan($admin, SiteOperation::PreviewLinkClose, 'demo-php.test');
env_assert($close->plan?->command === 'site:preview-link-close', 'Closing is planned too.');
$closed = $operations->view($admin, (string) $operations->start($admin, SiteOperation::PreviewLinkClose, 'demo-php.test')->jobId);
env_assert($closed->status?->command === 'site:preview-link-close' && $sites->read($admin, 'demo-php.test')->site?->previewLink === null, 'Closed.');

// 3. The screens: the job shows the link once with its copy and deadline; the card says until when, never the key.
$files = new Filesystem();
$resolver = new EngineResolver();
$compiled = env_temp_directory();
$resolver->register('blade', static fn () => new CompilerEngine(new BladeCompiler($files, $compiled), $files));
$factory = new Factory($resolver, new FileViewFinder($files, []), new Dispatcher());
$factory->addExtension('blade.php', 'blade');
$factory->addNamespace('larena-admin', [dirname(__DIR__).'/Support/admin-views', dirname(__DIR__, 2).'/vendor/larena/admin/resources/views']);
$factory->addNamespace('larena-env', dirname(__DIR__, 2).'/resources/views');
$job = static fn ($status): string => $factory->make('larena-env::admin.job', ['jobId' => (string) $status->jobId, 'source' => 'stand-in', 'status' => $status,
    'log' => null, 'refused' => false, 'reasonText' => null, 'notice' => null])->render();
$shown = $job($first->status);
env_assert(str_contains($shown, 'data-env-job-preview') && substr_count($shown, $link->url) === 3 && str_contains($shown, '<sf-button-copy value="'.$link->url.'"')
    && str_contains($shown, 'rel="noopener noreferrer"') && str_contains($shown, 'data-larena-action-keep') && str_contains($shown, 'data-env-job-preview-until')
    && str_contains($shown, htmlspecialchars($text('preview_link.once'), ENT_QUOTES)) && str_contains($shown, htmlspecialchars($text('preview_link.again'), ENT_QUOTES)),
    'The link, its copy, «действует до», said once and how to get it again.');
$later = $job($again->status);
env_assert(!str_contains($later, $key) && !str_contains($later, '.simai-preview') && str_contains($later, 'data-env-job-preview-shown'), 'Later the screen says it was shown, nothing else.');
env_assert(str_contains($job($operations->view($admin, (string) $operations->start($admin, SiteOperation::PreviewLink, 'demo-php.test', ['minutes' => '60'])->jobId)->status),
    htmlspecialchars($text('preview_link.ready'), ENT_QUOTES)), 'A new link is «готова».');

$block = static fn (array $data): string => $factory->make('larena-env::admin.partials.preview-link', $data + ['domain' => 'demo-php.test',
    'tabOperations' => [SiteOperation::PreviewLink, SiteOperation::PreviewLinkClose]])->render();
$open = $block(['previewLink' => ['state' => new PreviewLinkState('http://203.0.113.10:7700/', '2026-11-21T13:00:00Z'), 'refusal' => null]]);
env_assert(str_contains($open, 'data-env-preview-link="open"') && str_contains($open, htmlspecialchars($text('preview_link.open_until'), ENT_QUOTES))
    && str_contains($open, 'datetime="2026-11-21T13:00:00+00:00"') && str_contains($open, 'data-env-preview-link-renew') && str_contains($open, 'data-env-preview-link-close')
    && !str_contains($open, '.simai-preview'), 'Open: «Временная ссылка открыта до …», «Продлить» and «Закрыть», never the key.');
$none = $block(['previewLink' => ['state' => null, 'refusal' => null], 'tabOperations' => [SiteOperation::PreviewLink]]);
env_assert(str_contains($none, 'data-env-preview-link="closed"') && str_contains($none, 'data-env-preview-link-open') && !str_contains($none, 'data-env-preview-link-close'), 'Closed: «Получить ссылку».');
foreach (['alias', 'suspended'] as $why) {
    $refused = $block(['previewLink' => ['state' => null, 'refusal' => $why]]);
    env_assert(str_contains($refused, 'data-env-preview-link-refusal="'.$why.'"') && !str_contains($refused, 'data-env-preview-link-open'), "{$why}: said in words, no button into a refusal.");
}
foreach (['ru', 'en'] as $locale) {
    $words = require dirname(__DIR__, 2).'/resources/lang/'.$locale.'/admin.php';
    foreach (PreviewLinkState::REFUSALS as $why) {
        env_assert(is_string($words['preview_link']['refusals'][$why] ?? null), "{$locale}: the words of {$why}.");
    }
    env_assert(is_string($words['operations']['preview-link'] ?? null) && is_string($words['job']['done']['site-preview-link'] ?? null)
        && is_string($words['reasons']['preview_minutes'] ?? null), "{$locale}: the action, its end and its term.");
}

// 4. A backup's files straight into an empty site (backup:data-restore files-live, 1.65.0) on the stand-in: refused
// root_not_empty where the site has files of its own, planned where it has none.
$restore = static fn (string $domain): \Larena\Env\Gateway\PanelAnswer => $fake->ask(PanelRequest::plan($admin, 'backup:data-restore',
    ['domain' => $domain, 'backup' => '20261120-010000', 'files-live' => 'yes']));
@mkdir($root.'/sites/demo-php.test', 0o775, true);
file_put_contents($root.'/sites/demo-php.test/notes.txt', "Notes\n");
env_assert($restore('demo-php.test')->reason === 'root_not_empty', 'A site with files of its own: root_not_empty.');
env_assert($restore('back.test')->data['command'] === 'backup:data-restore', 'A site with nothing of its own: planned.');
mkdir($root.'/sites/placeholder.test/public', 0o775, true);
file_put_contents($root.'/sites/placeholder.test/public/index.html', "<!-- SIMAI placeholder -->\n");
file_put_contents($root.'/sites/placeholder.test/healthcheck.php', "<?php echo 'ok';\n");
env_assert($restore('placeholder.test')->data['command'] === 'backup:data-restore', 'What site:add wrote does not count as the site\'s own files.');

echo "PreviewLinkTest passed.\n";
