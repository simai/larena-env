<?php

declare(strict_types=1);

// Files with passwords are a right of their own (owner decision 2026-11-18, q-4997ae4e93): the whole server has it; a
// person of the user level («свои сайты») only when it is ticked for them. Without it the file is listed and marked
// «секреты», and opening, downloading and changing it is refused on the server with one plain line, whatever address is
// asked; with it every open, download and save is written to «История» without the file's text. The site's files are
// the stand-in for the server's folders (FakeFilesGateway) on recorded answers.

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Larena\Audit\Contracts\AuditSink;
use Larena\Audit\Runtime\InMemoryAuditSink;
use Larena\Core\Process\RecordingProcessExecutor;
use Larena\Env\Admin\SiteFilesPage;
use Larena\Env\Contracts\PanelFileGateway;
use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Files\FileEntry;
use Larena\Env\Files\SiteFiles;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelDownload;
use Larena\Env\Gateway\PanelGatewayFactory;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Http\Controllers\SiteAdminController;
use Larena\Env\Http\Controllers\SiteFilesController;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Operations\PanelJobStatus;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\People\PanelAudit;
use Larena\Env\People\SecretFileAccess;
use Larena\Env\People\SecretFileAuditNames;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteDetailsReader;
use Larena\Env\Server\SiteExtrasReader;
use Symfony\Component\HttpFoundation\StreamedResponse;

require_once dirname(__DIR__).'/Support/env-test.php';
require_once dirname(__DIR__).'/Support/AdminPresenceHost.php';

$lines = require dirname(__DIR__, 2).'/resources/lang/en/admin.php';
$russian = require dirname(__DIR__, 2).'/resources/lang/ru/admin.php';

/** @param array<string, string> $replace */
function __(string $key, array $replace = []): string
{
    global $lines;
    $line = data_get($lines, str_replace('larena-env::admin.', '', $key));
    if (!is_string($line)) {
        return $key;
    }
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

/** The stand-in for the server, remembering what the panel asked of it. */
final class WatchedGateway implements PanelGateway, PanelFileGateway
{
    /** @var list<array{0: string, 1: ?string, 2: bool}> */
    public array $asked = [];

    public function __construct(private readonly PanelGateway&PanelFileGateway $inner)
    {
    }

    public function source(): string
    {
        return $this->inner->source();
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        $this->asked[] = [$request->command, $request->args['path'] ?? null, $request->confirm];

        return $this->inner->ask($request);
    }

    public function download(PanelRequest $request, string $into): PanelDownload
    {
        $this->asked[] = [$request->command, $request->args['path'] ?? null, true];

        return $this->inner->download($request, $into);
    }

    /** Whether the panel asked the server anything about this file (beyond stat, which says only its name and size). */
    public function touched(string $path): bool
    {
        foreach ($this->asked as [$command, $asked]) {
            if ($asked === $path && $command !== 'file:stat' && $command !== 'file:list') {
                return true;
            }
        }

        return false;
    }
}

/** A server of simai-env 1.62.2 (it packs without the files with passwords), remembering every argument it was sent. */
final class NewerGateway implements PanelGateway, PanelFileGateway
{
    /** @var list<array{0: string, 1: array<string, mixed>, 2: bool}> */
    public array $sent = [];

    public function __construct(private readonly PanelGateway&PanelFileGateway $inner)
    {
    }

    public function source(): string
    {
        return $this->inner->source();
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        $this->sent[] = [$request->command, $request->args, $request->confirm];
        $answer = $this->inner->ask($request);
        if ($request->command !== 'self:describe' || !is_array($answer->data['simai_env'] ?? null)) {
            return $answer;
        }
        $data = $answer->data;
        $data['simai_env']['version'] = '1.62.2';

        return PanelAnswer::fromDocument($request->requestId, ['status' => 'ok', 'kind' => 'result', 'data' => $data, 'command_status' => 'success']);
    }

    public function download(PanelRequest $request, string $into): PanelDownload
    {
        return $this->inner->download($request, $into);
    }
}

$directory = env_temp_directory();
$host = new AdminPresenceHost();
$host->storage = $directory.'/storage';
Container::setInstance($host);
$sink = new InMemoryAuditSink();
$host->instance(AuditSink::class, $sink);
$audit = new PanelAudit($host);

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$connection = $capsule->getConnection();
// The table as its migration makes it.
$connection->getSchemaBuilder()->create(SecretFileAccess::TABLE, static function ($table): void {
    $table->string('subject_ref', 191)->primary();
    $table->string('granted_by', 191);
    $table->timestamp('granted_at');
});
$secrets = new SecretFileAccess($connection, $audit);

$root = $directory.'/fake';
$fake = PanelGatewayFactory::make(['driver' => 'recorded', 'recorded' => ['path' => env_recorded_path(), 'files' => $root]], true, static fn () => new RecordingProcessExecutor());
if (!$fake instanceof \Larena\Env\Gateway\FakeFilesGateway) {
    throw new RuntimeException('The stand-in for the site\'s folders.');
}
$gateway = new WatchedGateway($fake);
$files = new SiteFiles($gateway);
$domain = 'demo-owned.test';
$admin = new PanelActor('admin@stand.test');
$staff = new PanelActor('staff@stand.test', 'demo');
$trusted = new PanelActor('trusted@stand.test', 'demo');
// The site's folder exists once the stand-in is first asked; then the site gets its .env.
env_assert(is_array($files->folder($admin, $domain, '.')), 'The owner\'s site has its folder.');
$password = 'DB_PASSWORD=hunter2-very-secret';
file_put_contents($root.'/sites/'.$domain.'/.env', $password."\n");
file_put_contents($root.'/sites/'.$domain.'/notes.txt', "plain\n");

// The one list of files with passwords is the one that marks «секреты» (FileEntry::secret).
foreach (['.env', 'public/.env.local', 'wp-config.php', 'bitrix/.settings.php', 'bitrix/php_interface/dbconn.php', 'id_rsa', 'ssl/site.key'] as $name) {
    env_assert(SecretFileAccess::secret($name) && FileEntry::secret(basename($name)), "{$name} is a file with passwords.");
}
env_assert(!SecretFileAccess::secret('.env.example') && !SecretFileAccess::secret('notes.txt'), 'An example and a note are ordinary files.');

// 1–2. The right: the whole server always; the user level off until ticked, on for that person only.
env_assert($secrets->allows($admin) && !$secrets->allows($staff) && !$secrets->allows($trusted), 'Server level has it; «свои сайты» does not by default.');
$secrets->set('trusted@stand.test', true, 'admin@stand.test');
env_assert($secrets->allows($trusted) && !$secrets->allows($staff) && $secrets->all() === ['trusted@stand.test' => true], 'Ticked for one person: that person only.');
env_assert(!$secrets->allows(new PanelActor('staff@stand.test', 'demo', 'admin@stand.test')), 'Looking as a person: the person\'s right, not the administrator\'s.');
$granted = $sink->events();
env_assert(count($granted) === 1 && $granted[0]->type === 'env.person.secret_files_allowed' && $granted[0]->actor === 'admin@stand.test' && $granted[0]->subject === 'trusted@stand.test',
    'Switching it on is in «История»: who gave it to whom.');
$secrets->set('trusted@stand.test', true, 'admin@stand.test');
env_assert(count($sink->events()) === 1, 'Saving the row unchanged writes nothing.');
$noStore = new SecretFileAccess();
env_assert($noStore->allows($admin) && !$noStore->allows($trusted), 'Without its table the right stays the server level\'s only.');

// The screens.
$filesystem = new Filesystem();
$resolver = new EngineResolver();
$resolver->register('blade', static fn () => new CompilerEngine(new BladeCompiler($filesystem, env_temp_directory()), $filesystem));
$factory = new Factory($resolver, new FileViewFinder($filesystem, []), new Dispatcher());
$factory->addExtension('blade.php', 'blade');
$factory->addNamespace('larena-admin', dirname(__DIR__).'/Support/admin-views');
$factory->addNamespace('larena-env', dirname(__DIR__, 2).'/resources/views');
$translator = new class ($lines) implements \Illuminate\Contracts\Translation\Translator {
    /** @param array<string, mixed> $lines */
    public function __construct(private array $lines)
    {
    }

    public function get($key, array $replace = [], $locale = null): mixed
    {
        $value = data_get($this->lines, substr($key, strlen('larena-env::admin.')));
        foreach (is_string($value) ? $replace : [] as $name => $one) {
            $value = str_replace(':'.$name, (string) $one, $value);
        }

        return is_string($value) || is_array($value) ? $value : $key;
    }

    public function choice($key, $number, array $replace = [], $locale = null): string
    {
        return (string) $this->get($key, $replace);
    }

    public function getLocale(): string
    {
        return 'en';
    }

    public function setLocale($locale): void
    {
    }
};
$refusedLine = $lines['files']['reasons']['secret_files_not_allowed'];
env_assert($russian['files']['reasons']['secret_files_not_allowed'] === 'Нет права смотреть файлы с паролями, попросите администратора сервера.', 'The refusal in the owner\'s words.');
$operations = new PanelOperations($gateway);
$overviews = new ServerOverviewReader($gateway);
$card = new SiteAdminController(new SiteDetailsReader($gateway), $overviews, new SiteExtrasReader($gateway), $operations, $factory, $translator, files: $files, secrets: $secrets);
$filesController = new SiteFilesController($operations, $overviews, $files, $factory, $translator, $host, $secrets);
$asking = static function (PanelActor $actor, string $uri, string $method = 'GET', array $input = [], ?Store $session = null): Request {
    $request = Request::create($uri, $method, $input);
    $request->attributes->set(ResolvePanelActor::ATTRIBUTE, $actor);
    $request->setLaravelSession($session ?? new Store('panel', new ArraySessionHandler(10)));

    return $request;
};
// A confirmation as the plan's page sends it: with the receipt of a plan of exactly these values (PlanReceipts). The
// rights to files with passwords are asked again at the confirmation, receipt or not.
$confirming = static function (PanelActor $actor, string $uri, string $method = 'GET', array $input = [], ?Store $session = null) use ($asking): Request {
    $request = $asking($actor, $uri, $method, $input, $session);
    $request->merge(['receipt' => (new \Larena\Env\Http\Support\PlanReceipts())->issue($request, SiteFilesController::FILE_OPERATIONS[$input['op']],
        ['op' => $input['op'], 'args' => $input['args'] ?? [], 'staged' => $input['staged'] ?? null])]);

    return $request;
};
$site = '/admin/server/sites/'.$domain;
$filesTab = static function (PanelActor $actor, string $query) use ($card, $asking, $site, $domain): array {
    $view = $card->show($asking($actor, $site.'?tab=files&'.$query), $domain);

    return $view->getData()['files'];
};
$notice = static fn (RedirectResponse $response): string => (string) $response->getSession()?->get('larena_env_notice');
$sha = hash('sha256', $password."\n");
$last = static fn (array $list): mixed => $list[array_key_last($list)];
$history = static fn (): array => array_values(array_filter($sink->events(), static fn ($event): bool => str_starts_with($event->type, 'env.secret_file.')));

// 3. The whole server: the file opens when asked, its open is in «История» with who, the site and the file, never its text.
$opened = $filesTab($admin, 'view=.env&reveal=1');
env_assert(is_array($opened['text']) && $opened['text']['content'] === $password."\n" && $opened['secretRefused'] === null, 'Server level: the .env opens.');
$events = $history();
env_assert(count($events) === 1 && $events[0]->type === 'env.secret_file.opened' && $events[0]->actor === 'admin@stand.test'
    && $events[0]->payload === ['domain' => $domain, 'path' => '.env'] && $events[0]->subject === 'env.file:'.$domain.'/.env', 'The open is in «История»: who, which site, which file.');
$asked = $filesTab($admin, 'view=.env');
env_assert($asked['text'] === null && $asked['secret'] && count($history()) === 1, 'Not asked to show: the question first, nothing read, nothing written.');
$download = $filesController->download($asking($admin, $site.'/files/download?path=.env&confirm=secret'), $domain);
env_assert($download instanceof StreamedResponse && $download->getStatusCode() === 200, 'Server level: downloaded after the question.');
ob_start();
$download->sendContent();
env_assert(ob_get_clean() === $password."\n", 'The file itself is handed over.');
env_assert($last($history())->type === 'env.secret_file.downloaded', 'The download is in «История».');
$save = ['op' => 'write', 'path' => '.env', 'content' => "DB_PASSWORD=changed\n", 'sha256' => $sha];
env_assert($filesController->plan($asking($admin, $site.'/files/plan', 'POST', $save), $domain) instanceof \Illuminate\Contracts\View\View, 'Server level: the save is planned.');
$ran = $filesController->run($confirming($admin, $site.'/files/run', 'POST', ['op' => 'write', 'args' => ['domain' => $domain, 'path' => '.env', 'content' => "DB_PASSWORD=changed\n", 'expect-sha256' => $sha]]), $domain);
env_assert(str_contains($ran->getTargetUrl(), '/admin/server/larena.env.admin.job/') && file_get_contents($root.'/sites/'.$domain.'/.env') === "DB_PASSWORD=changed\n", 'Confirmed, saved.');
$saved = $history();
env_assert(end($saved)->type === 'env.secret_file.saved' && end($saved)->payload === ['domain' => $domain, 'path' => '.env'], 'The save is in «История».');
$sha = hash('sha256', "DB_PASSWORD=changed\n");

// The plan's receipt (PlanReceipts): the confirmation sends back what the plan's page holds, and only that runs, once,
// within 15 minutes. The same text written again here, so the file stays as the steps below expect it.
$filesClock = time();
$timedFiles = new SiteFilesController($operations, $overviews, $files, $factory, $translator, $host, $secrets, receipts: env_plan_receipts($filesClock));
$filesSession = new Store('panel', new ArraySessionHandler(10));
$planned = static function () use ($timedFiles, $asking, $admin, $site, $domain, $sha, $filesSession): array {
    $view = $timedFiles->plan($asking($admin, $site.'/files/plan', 'POST', ['op' => 'write', 'path' => '.env', 'content' => "DB_PASSWORD=changed\n", 'sha256' => $sha], $filesSession), $domain);
    env_assert($view instanceof \Illuminate\Contracts\View\View && strlen((string) ($view->getData()['hidden']['receipt'] ?? '')) === 32, 'The save is planned with its receipt.');
    // The page's hidden fields as the browser sends them back: args[path] becomes args.path.
    parse_str(http_build_query($view->getData()['hidden']), $sent);

    return $sent;
};
$confirmWith = static fn (array $sent): RedirectResponse => $timedFiles->run($asking($admin, $site.'/files/run', 'POST', $sent, $filesSession), $domain);
$savedCount = static fn (): int => count(array_filter($sink->events(), static fn ($event): bool => $event->type === 'env.secret_file.saved'));
$savedBefore = $savedCount();
$sent = $planned();
env_assert($notice($confirmWith(array_diff_key($sent, ['receipt' => true]))) === $lines['reasons']['plan_receipt'] && $savedCount() === $savedBefore,
    'Without the receipt: refused in words, nothing written.');
$changedSent = $sent;
$changedSent['args']['content'] = "DB_PASSWORD=swapped\n";
env_assert($notice($confirmWith($changedSent)) === $lines['reasons']['plan_receipt'] && file_get_contents($root.'/sites/'.$domain.'/.env') === "DB_PASSWORD=changed\n",
    'A text changed after the plan is refused, the file untouched.');
$sent = $planned();
$filesClock += 901;
env_assert($notice($confirmWith($sent)) === $lines['reasons']['plan_receipt'] && $savedCount() === $savedBefore, 'A receipt older than 15 minutes is refused.');
$sent = $planned();
env_assert(str_contains($confirmWith($sent)->getTargetUrl(), 'larena.env.admin.job') && $savedCount() === $savedBefore + 1, 'The receipt of the plan: saved.');
env_assert($notice($confirmWith($sent)) === $lines['reasons']['plan_receipt'] && $savedCount() === $savedBefore + 1, 'A receipt saves once.');

// 4. «Свои сайты» without the tick: the file is listed and marked, everything else refused on the server.
$before = count($sink->events());
$gateway->asked = [];
foreach (['view=.env&reveal=1', 'open=.env', 'view=.env'] as $query) {
    $closed = $filesTab($staff, $query);
    env_assert($closed['text'] === null && $closed['secret'] && !$closed['revealed'] && $closed['secretRefused'] === $refusedLine, "Without the right ({$query}): the plain refusal, no text.");
}
$rendered = (string) $factory->make('larena-env::admin.partials.files', ['files' => $closed, 'domain' => $domain])->render();
env_assert(str_contains($rendered, 'data-env-files-secret-refused') && str_contains($rendered, e($refusedLine)) && !str_contains($rendered, 'data-env-files-reveal')
    && !str_contains($rendered, 'data-env-files-secret-download') && !str_contains($rendered, 'data-env-files-editor') && !str_contains($rendered, 'hunter2'), 'The page says the line and offers nothing.');
$direct = $filesController->download($asking($staff, $site.'/files/download?path=.env&confirm=secret'), $domain);
env_assert($direct instanceof RedirectResponse && $notice($direct) === $refusedLine, 'The download address is refused with the line.');
$preview = $filesController->preview($asking($staff, $site.'/files/preview?path=ssl/site.key'), $domain);
env_assert($preview->getStatusCode() === 403 && !str_contains((string) $preview->getContent(), 'BEGIN'), 'The preview address is refused.');
$changes = [
    'save' => ['op' => 'write', 'path' => '.env', 'content' => 'DB_PASSWORD=mine', 'sha256' => $sha],
    'rename away' => ['op' => 'rename', 'path' => '.env', 'name' => 'readable.txt'],
    'pack' => ['op' => 'archive', 'path' => '.env', 'name' => 'env', 'format' => 'zip'],
    'make one' => ['op' => 'create', 'folder' => '.', 'name' => '.env.local'],
    'rename into' => ['op' => 'rename', 'path' => 'notes.txt', 'name' => 'wp-config.php'],
];
foreach ($changes as $what => $input) {
    $refused = $filesController->plan($asking($staff, $site.'/files/plan', 'POST', $input), $domain);
    env_assert($refused instanceof RedirectResponse && $notice($refused) === $refusedLine, "Without the right, {$what} is refused before the server is asked.");
}
// The confirmation's own address, sent straight without a plan: refused too.
$straight = $filesController->run($confirming($staff, $site.'/files/run', 'POST', ['op' => 'write', 'args' => ['domain' => $domain, 'path' => '.env', 'content' => 'DB_PASSWORD=mine', 'expect-sha256' => $sha]]), $domain);
env_assert($notice($straight) === $refusedLine && file_get_contents($root.'/sites/'.$domain.'/.env') === "DB_PASSWORD=changed\n", 'The run address is refused, the file untouched.');
$copied = $filesController->run($confirming($staff, $site.'/files/run', 'POST', ['op' => 'copy', 'args' => ['domain' => $domain, 'path' => '.env', 'to' => 'copy.txt']]), $domain);
// (The stand-in has no file:copy, so it is refused as not offered there; a real server is refused by the right, as above.)
env_assert($copied->getTargetUrl() !== '' && !file_exists($root.'/sites/'.$domain.'/copy.txt'), 'No copy out of a secret by the run address.');
env_assert(!$gateway->touched('.env') && !$gateway->touched('wp-config.php') && !$gateway->touched('.env.local') && !$gateway->touched('ssl/site.key'),
    'The server was never asked to read, hand over or change a secret: '.json_encode($gateway->asked));
env_assert(count($sink->events()) === $before, 'Nothing was opened, so nothing is written to «История».');
// Looking at the panel as that person: refused the same.
$acting = $filesTab(new PanelActor('staff@stand.test', 'demo', 'admin@stand.test'), 'view=.env&reveal=1');
env_assert($acting['text'] === null && $acting['secretRefused'] === $refusedLine, 'Looking as the person: the person\'s refusal.');
// An ordinary file of theirs still opens.
$plain = $filesTab($staff, 'view=notes.txt');
env_assert(is_array($plain['text']) && $plain['text']['content'] === "plain\n" && $plain['secretRefused'] === null, 'Their ordinary files open as before.');

// The list: marked «секреты», the name opens the refusal, no other action in the row.
$entry = static fn (array $data): FileEntry => FileEntry::fromData($data);
$text = static fn (string $key, array $replace = []): string => (string) data_get($lines, $key, $key);
$folder = [$entry(['name' => '.env', 'type' => 'file', 'size' => 20]), $entry(['name' => 'notes.txt', 'type' => 'file', 'size' => 6])];
$allows = array_fill_keys(['rename', 'copy', 'remove', 'archive', 'download', 'read'], true);
$closedRows = array_column(SiteFilesPage::folder($domain, '.', $folder, $allows + ['secrets' => false], $text, 'tok', true)->rows, null, 'id');
env_assert($closedRows['.env']['mark']['label'] === $lines['files']['secret'] && $closedRows['.env']['mark']['tip'] === $refusedLine && $closedRows['.env']['_actions'] === ['view']
    && in_array('download', $closedRows['notes.txt']['_actions'], true), 'Without the right: listed, marked, nothing but its name.');
$openRows = array_column(SiteFilesPage::folder($domain, '.', $folder, $allows + ['secrets' => true], $text, 'tok', true)->rows, null, 'id');
env_assert(in_array('look', $openRows['.env']['_actions'], true) && in_array('rename', $openRows['.env']['_actions'], true) && !in_array('download', $openRows['.env']['_actions'], true),
    'With the right: looked at and changed; still downloaded from its page only, asked first.');

// 5. «Свои сайты» with the tick: opens, downloads and saves, each in «История».
$withRight = $filesTab($trusted, 'view=.env&reveal=1');
env_assert(is_array($withRight['text']) && $withRight['text']['content'] === "DB_PASSWORD=changed\n" && $withRight['secretRefused'] === null, 'Ticked: the .env opens.');
$trustedOpen = $history();
env_assert(end($trustedOpen)->type === 'env.secret_file.opened' && end($trustedOpen)->actor === 'trusted@stand.test', 'Their open is in «История» under their name.');
$trustedDownload = $filesController->download($asking($trusted, $site.'/files/download?path=.env&confirm=secret'), $domain);
env_assert($trustedDownload instanceof StreamedResponse, 'Ticked: downloaded after the question.');
ob_start();
$trustedDownload->sendContent();
ob_end_clean();
$trustedRun = $filesController->run($confirming($trusted, $site.'/files/run', 'POST', ['op' => 'write', 'args' => ['domain' => $domain, 'path' => '.env', 'content' => "DB_PASSWORD=third\n", 'expect-sha256' => $sha]]), $domain);
env_assert(file_get_contents($root.'/sites/'.$domain.'/.env') === "DB_PASSWORD=third\n" && str_contains($trustedRun->getTargetUrl(), 'job'), 'Ticked: saved.');
$trustedEvents = array_map(static fn ($event): string => $event->type.' '.$event->actor, array_slice($history(), -3));
env_assert($trustedEvents === ['env.secret_file.opened trusted@stand.test', 'env.secret_file.downloaded trusted@stand.test', 'env.secret_file.saved trusted@stand.test'],
    'Open, download and save, each in «История»: '.json_encode($trustedEvents));
// Looking as someone: the history names who was at the keyboard and for whom.
$filesTab(new PanelActor('trusted@stand.test', 'demo', 'admin@stand.test'), 'view=.env&reveal=1');
$actingEvent = $last($history());
env_assert($actingEvent->actor === 'admin@stand.test' && $actingEvent->payload['acting_as'] === 'trusted@stand.test', 'Looking as a person: written as the administrator, for that person.');

// «История» never holds a file's text: not the payload, not the subject, not anywhere.
$all = json_encode(array_map(static fn ($event): array => [$event->type, $event->actor, $event->subject, $event->payload], $sink->events()), JSON_THROW_ON_ERROR);
env_assert(!str_contains($all, 'hunter2') && !str_contains($all, 'DB_PASSWORD') && !str_contains($all, 'changed') && !str_contains($all, 'third'), 'No file text in «История»: '.$all);
foreach ($sink->events() as $event) {
    env_assert(array_diff(array_keys($event->payload), ['domain', 'path', 'acting_as']) === [], 'Only the site, the file and for whom: '.$event->type);
}
// In words: the event's sentence and the file by name and site.
foreach (['env_secret_file_opened', 'env_secret_file_downloaded', 'env_secret_file_saved', 'env_person_secret_files_allowed', 'env_person_secret_files_disallowed'] as $key) {
    env_assert(is_string($russian['people']['history'][$key] ?? null) && is_string($lines['people']['history'][$key] ?? null), "{$key} is said in words.");
}
$names = (new SecretFileAuditNames($translator))->names(['env.file:demo-owned.test/public/.env', 'user:1', 'env.file:broken']);
env_assert($names === ['env.file:demo-owned.test/public/.env' => 'public/.env on demo-owned.test'], 'The file is named with its site: '.json_encode($names));
$audit = (string) file_get_contents(dirname(__DIR__, 2).'/audit.yaml');
foreach (['env.secret_file.opened', 'env.secret_file.downloaded', 'env.secret_file.saved', 'env.person.secret_files_allowed', 'env.person.secret_files_disallowed'] as $type) {
    env_assert(str_contains($audit, 'type: '.$type), "{$type} is declared.");
}

// «Люди» (roles study part 7): the tick is asked in «Пригласить человека» and on the person's card, on for whom it is on.
$invite = (string) $factory->make('larena-env::admin.people', ['secretFilesKept' => true, 'canOperate' => true, 'owners' => ['demo'],
    'canCreateAccount' => true, 'source' => 'recorded', 'reasonText' => null, 'notice' => null])->render();
env_assert(substr_count($invite, 'name="secret_files" value="yes"') === 1 && !str_contains($invite, 'data-env-invite-secret checked')
    && str_contains($invite, e($lines['people']['secret_files'])) && str_contains($invite, 'data-env-invite-form'), 'The invitation asks the tick, off.');
$card = static fn (bool $secret): string => (string) $factory->make('larena-env::admin.people-card', ['person' => ['id' => 7, 'subject' => 'user:admin_identity:7',
    'name' => 'Trusted', 'email' => 'trusted@stand.test', 'level' => 'own', 'blocked' => false, 'owner' => 'demo', 'sites' => ['demo-owned.test'], 'last' => null, 'secret' => $secret],
    'self' => false, 'can' => 'Own sites: 1', 'canSaid' => 'x', 'last' => 'never', 'lastIso' => null, 'canOperate' => true, 'owners' => ['demo'], 'ownerSites' => ['demo' => 1],
    'secretFilesKept' => true, 'canAddKey' => false, 'notice' => null])->render();
env_assert(preg_match('/name="secret_files" value="yes"[^>]*checked/', $card(true)) === 1 && preg_match('/name="secret_files" value="yes"[^>]*checked/', $card(false)) === 0
    && str_contains($card(true), 'name="subject" value="user:admin_identity:7"'), 'The card has the tick, checked for the one it is on for.');
$secrets->set('trusted@stand.test', false, 'admin@stand.test');
env_assert(!$secrets->allows($trusted) && $last($sink->events())->type === 'env.person.secret_files_disallowed', 'Unticked: off again, and said in «История».');

// 6. Packing and unpacking (simai-env 1.62.2, `without-secrets`): a person without the right packs a folder and unpacks an
// archive, and the server leaves the files with passwords out; the job's page names them. The panel adds it, never the form.
mkdir($root.'/sites/'.$domain.'/conf', 0o775, true);
file_put_contents($root.'/sites/'.$domain.'/conf/.env', "APP_KEY=base64:kept-out\n");
file_put_contents($root.'/sites/'.$domain.'/conf/app.php', "<?php return [];\n");
if (class_exists(ZipArchive::class)) {
    $zip = new ZipArchive();
    $zip->open($root.'/sites/'.$domain.'/upload.zip', ZipArchive::CREATE);
    $zip->addFromString('.env', "DB_PASSWORD=from-archive\n");
    $zip->addFromString('public/index.php', "<?php echo 1;\n");
    $zip->addFromString('keys/id_rsa', "-----BEGIN-----\n");
    $zip->close();
}
$planRequest = static fn (PanelActor $actor, array $input) => $asking($actor, $site.'/files/plan', 'POST', $input);
$pack = ['op' => 'archive', 'path' => 'conf', 'name' => 'conf', 'format' => 'zip'];
// An older server (the recorded one, 1.49.0) cannot leave them out: refused to such a person, as before, in plain words.
$old = $filesController->plan($planRequest($staff, $pack), $domain);
env_assert($old instanceof RedirectResponse && $notice($old) === $lines['files']['reasons'][SiteFilesController::SECRETS_KEPT], 'An older server: packing refused with its line.');
env_assert($filesController->plan($planRequest($admin, $pack), $domain) instanceof \Illuminate\Contracts\View\View, 'The server level packs on any server.');

$newer = new NewerGateway($fake);
$newerFiles = new SiteFiles($newer);
$newerOperations = new PanelOperations($newer);
$newerController = new SiteFilesController($newerOperations, new ServerOverviewReader($newer), $newerFiles, $factory, $translator, $host, $secrets);
$planned = $newerController->plan($planRequest($staff, $pack), $domain);
env_assert($planned instanceof \Illuminate\Contracts\View\View && ($planned->getData()['hidden']['args[without-secrets]'] ?? null) === 'yes', 'Without the right: planned, without the files with passwords.');
$planSent = $last(array_values(array_filter($newer->sent, static fn (array $one): bool => $one[0] === 'file:archive')));
env_assert(($planSent[1]['without-secrets'] ?? null) === 'yes' && !$planSent[2], 'The plan asked the server without them.');
$planPage = (string) $factory->make('larena-env::admin.plan', $planned->getData())->render();
env_assert(str_contains($planPage, e($lines['plan']['args']['without-secrets'])), 'The plan names it in words.');
// The confirmation sent straight with the argument taken out (or said `no`): the panel puts it back.
$ranPack = $newerController->run($confirming($staff, $site.'/files/run', 'POST', ['op' => 'archive', 'args' => ['domain' => $domain, 'path' => 'conf', 'to' => 'conf.zip', 'without-secrets' => 'no']]), $domain);
$runSent = $last(array_values(array_filter($newer->sent, static fn (array $one): bool => $one[0] === 'file:archive')));
env_assert(str_contains($ranPack->getTargetUrl(), 'job') && ($runSent[1]['without-secrets'] ?? null) === 'yes' && $runSent[2], 'Confirmed: sent without them, whatever the form said.');
$jobId = basename($ranPack->getTargetUrl());
$jobStatus = $newerOperations->view($staff, $jobId)->status ?? null;
env_assert($jobStatus !== null && $jobStatus->secretSkipped === ['paths' => ['conf/.env'], 'total' => 1], 'The job names what it left out: '.json_encode($jobStatus?->secretSkipped));
$jobPage = (string) $factory->make('larena-env::admin.job', ['jobId' => $jobId, 'source' => 'stand-in', 'status' => $jobStatus, 'log' => null, 'refused' => false,
    'reasonText' => null, 'notice' => null])->render();
env_assert(str_contains($jobPage, 'data-env-job-secret-skipped="1"') && str_contains($jobPage, '>conf/.env<') && str_contains($jobPage, e($lines['job']['secret_skipped_archive']))
    && !str_contains($jobPage, 'kept-out'), 'The job page says it in words and names the file, never its text.');
// The server level packs with them (nothing added).
$newerController->run($confirming($admin, $site.'/files/run', 'POST', ['op' => 'archive', 'args' => ['domain' => $domain, 'path' => 'conf', 'to' => 'conf-all.zip']]), $domain);
$adminSent = $last(array_values(array_filter($newer->sent, static fn (array $one): bool => $one[0] === 'file:archive')));
env_assert(!isset($adminSent[1]['without-secrets']), 'The server level packs everything.');
// Packing the .env itself stays refused before the server is asked.
$self = $newerController->plan($planRequest($staff, ['op' => 'archive', 'path' => 'conf/.env', 'name' => 'env', 'format' => 'zip']), $domain);
env_assert($self instanceof RedirectResponse && $notice($self) === $refusedLine, 'The .env itself is not packed by such a person.');
if (class_exists(ZipArchive::class)) {
    $unpack = $newerController->run($confirming($staff, $site.'/files/run', 'POST', ['op' => 'extract', 'args' => ['domain' => $domain, 'path' => 'upload.zip', 'to' => '.', 'overwrite' => 'yes']]), $domain);
    $unpacked = $newerOperations->view($staff, basename($unpack->getTargetUrl()))->status ?? null;
    env_assert($unpacked !== null && $unpacked->secretSkipped === ['paths' => ['.env', 'keys/id_rsa'], 'total' => 2], 'Unpacked without them: '.json_encode($unpacked?->secretSkipped));
    $unpackPage = (string) $factory->make('larena-env::admin.job', ['jobId' => 'x', 'source' => 'stand-in', 'status' => $unpacked, 'log' => null, 'refused' => false,
        'reasonText' => null, 'notice' => null])->render();
    env_assert(str_contains($unpackPage, e($lines['job']['secret_skipped_extract'])) && str_contains($unpackPage, '>keys/id_rsa<'), 'Said for unpacking.');
}
// The side panel's form says it before anything is asked.
$formFiles = $filesTab($staff, 'path=.&op=archive&entry=conf');
$form = (string) $factory->make('larena-env::admin.partials.files', ['files' => $formFiles, 'domain' => $domain])->render();
env_assert(str_contains($form, 'data-env-files-without-secrets') && str_contains($form, e($lines['files']['without_secrets_archive'])), 'The pack form says the files with passwords stay out.');
$adminForm = (string) $factory->make('larena-env::admin.partials.files', ['files' => $filesTab($admin, 'path=.&op=archive&entry=conf'), 'domain' => $domain])->render();
env_assert(!str_contains($adminForm, 'data-env-files-without-secrets'), 'Not said to the server level.');
// More than the server names: the rest is a count.
$many = PanelJobStatus::fromData(['job_id' => 'c00000000000000000000001', 'state' => 'succeeded', 'command' => 'file:extract',
    'result' => ['secret_skipped' => array_map(static fn (int $n): string => "site{$n}/.env", range(1, 30)), 'secret_skipped_total' => 42]]);
env_assert($many->secretSkipped['total'] === 42 && count($many->secretSkipped['paths']) === 30, 'Thirty named, forty-two in all.');
$manyPage = (string) $factory->make('larena-env::admin.job', ['jobId' => 'y', 'source' => 'stand-in', 'status' => $many, 'log' => null, 'refused' => false, 'reasonText' => null, 'notice' => null])->render();
env_assert(str_contains($manyPage, 'data-env-job-secret-skipped-more') && str_contains($manyPage, '12'), 'And twelve more, said.');

// The server's own mark: taken when given, the panel's list where it is not; a file either names is one.
env_assert(FileEntry::fromData(['name' => 'custom.secret', 'type' => 'file', 'secret' => true])->isSecret(), 'Marked by the server: a file with passwords.');
env_assert(FileEntry::fromData(['name' => '.env', 'type' => 'file'])->isSecret() && !FileEntry::fromData(['name' => 'notes.txt', 'type' => 'file', 'secret' => false])->isSecret(),
    'No mark: the panel\'s list.');
env_assert(FileEntry::secret('.my.cnf') && FileEntry::secret('.pgpass') && FileEntry::secret('.netrc') && !FileEntry::secret('.env.template'), 'The list is the server\'s (1.62.2).');
$listed = $newerFiles->folder($staff, $domain, 'conf');
$marks = is_array($listed) ? array_map(static fn (FileEntry $one): array => [$one->name, $one->marked, $one->isSecret()], $listed['entries']) : [];
env_assert($marks === [['.env', true, true], ['app.php', false, false]], 'The list carries the server\'s mark: '.json_encode($marks));

// The trash: a removed .env is marked, and put back only by a person with the right, never elsewhere.
$newerController->run($confirming($admin, $site.'/files/run', 'POST', ['op' => 'remove', 'args' => ['domain' => $domain, 'path' => 'conf/.env']]), $domain);
$trashed = $newerFiles->trash($admin, $domain);
$secretEntry = is_array($trashed) ? $trashed['entries'][0] : null;
env_assert($secretEntry !== null && $secretEntry->secret && $secretEntry->name === '.env', 'The trash marks the .env.');
$trashRows = SiteFilesPage::trash($domain, $trashed['entries'], true, true, $text, 'tok', null, false)->rows;
env_assert($trashRows[0]['mark']['label'] === $lines['files']['secret'] && $trashRows[0]['_actions'] === ['purge'], 'Without the right: marked, not offered back.');
$adminTrash = SiteFilesPage::trash($domain, $trashed['entries'], true, true, $text, 'tok')->rows;
env_assert(in_array('restore', $adminTrash[0]['_actions'], true), 'With the right: offered back.');
$restoreRefused = $newerController->run($confirming($staff, $site.'/files/run', 'POST', ['op' => 'trash-restore', 'args' => ['domain' => $domain, 'id' => $secretEntry->id, 'to' => 'public/readme.txt']]), $domain);
env_assert($notice($restoreRefused) === $refusedLine && !file_exists($root.'/sites/'.$domain.'/public/readme.txt'), 'A restore elsewhere by such a person is refused.');
$restoredByAdmin = $newerController->run($confirming($admin, $site.'/files/run', 'POST', ['op' => 'trash-restore', 'args' => ['domain' => $domain, 'id' => $secretEntry->id, 'to' => 'public/readme.txt']]), $domain);
$restoreSent = $last(array_values(array_filter($newer->sent, static fn (array $one): bool => $one[0] === 'file:trash-restore')));
env_assert(!isset($restoreSent[1]['to']) && file_exists($root.'/sites/'.$domain.'/conf/.env') && !file_exists($root.'/sites/'.$domain.'/public/readme.txt'),
    'Even the server level puts it back only where it was: the panel never sends `to`.');

// The site card (FASTPANEL look §5.3): the site's size with when it was measured, and its «История» at the bottom: the
// five latest runs of this site, filled a moment after the card (one job:list), with «Вся история» narrowed to the site.
$jobsListed = new class ($fake) implements PanelGateway, PanelFileGateway {
    public int $lists = 0;

    public function __construct(private readonly PanelGateway&PanelFileGateway $inner)
    {
    }

    public function source(): string
    {
        return $this->inner->source();
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        if ($request->command !== 'job:list') {
            return $this->inner->ask($request);
        }
        $this->lists++;
        $job = static fn (int $n, string $command, array $args, string $state, string $kind = 'person'): array => ['job_id' => sprintf('%024x', $n), 'state' => $state,
            'command' => $command, 'args' => $args, 'created_at' => sprintf('2026-11-18T%02d:00:00Z', 23 - $n), 'started_at' => sprintf('2026-11-18T%02d:00:00Z', 23 - $n),
            'actor' => ['user' => 'admin@stand.test', 'kind' => $kind], 'exit_code' => $state === 'failed' ? 1 : 0];
        $jobs = [$job(1, 'backup:data', ['domain' => 'demo-owned.test'], 'succeeded', 'schedule'), $job(2, 'site:set-php', ['domain' => 'other.test', 'php' => '8.3'], 'succeeded'),
            $job(3, 'ssl:letsencrypt', ['domain' => 'demo-owned.test'], 'failed'), $job(4, 'site:cache-clear', ['domain' => 'demo-owned.test'], 'succeeded'),
            $job(5, 'self:update', ['target' => 'env'], 'succeeded', 'server'), $job(6, 'backup:data', ['domain' => 'demo-owned.test'], 'succeeded'),
            $job(7, 'site:suspend', ['domain' => 'demo-owned.test'], 'succeeded'), $job(8, 'site:resume', ['domain' => 'demo-owned.test'], 'succeeded')];

        return PanelAnswer::fromDocument($request->requestId, ['status' => 'ok', 'kind' => 'result', 'data' => ['schema' => 'simai-job-list/1', 'jobs' => $jobs, 'more' => false]]);
    }

    public function download(PanelRequest $request, string $into): PanelDownload
    {
        return $this->inner->download($request, $into);
    }
};
$historyCard = new SiteAdminController(new SiteDetailsReader($jobsListed), new ServerOverviewReader($jobsListed), new SiteExtrasReader($jobsListed),
    new PanelOperations($jobsListed), $factory, $translator, files: new SiteFiles($jobsListed));
$cardFirst = $historyCard->show($asking($admin, $site), $domain);
$firstData = $cardFirst->getData();
env_assert(($firstData['siteHistory']['pending'] ?? null) === (class_exists(\Larena\Admin\Runtime\AdminLiveRegion::class)) && $jobsListed->lists === (class_exists(\Larena\Admin\Runtime\AdminLiveRegion::class) ? 0 : 1),
    'The card is drawn without the server\'s list; the part fills itself.');
$firstPage = (string) $cardFirst->render();
env_assert(str_contains($firstPage, 'data-env-disk-measured') && str_contains($firstPage, 'title="'.e(\Larena\Env\Server\Moment::human('2026-01-01T00:00:00Z')).'"'),
    'The size says when it was measured, the exact time in the tooltip.');
env_assert(str_contains($firstPage, 'data-env-site-history') && str_contains($firstPage, 'href="/admin/history?object=demo-owned.test"'), '«Вся история» is «История» narrowed to the site.');
$filledRequest = $asking($admin, $site);
$filledRequest->headers->set('X-Larena-Fragment', 'live:'.SiteAdminController::HISTORY_PART);
$filled = $historyCard->show($filledRequest, $domain)->getData()['siteHistory'];
$titles = array_column($filled['rows'], 'state');
env_assert(!$filled['pending'] && count($filled['rows']) === SiteAdminController::HISTORY_ROWS && $titles === ['succeeded', 'failed', 'succeeded', 'succeeded', 'succeeded'],
    'Asked as the part: this site\'s five latest runs, the others\' left out: '.json_encode($filled['rows'], JSON_UNESCAPED_UNICODE));
env_assert($filled['rows'][0]['who'] === $lines['runs']['by_schedule'] && $filled['rows'][1]['status'] === 'error' && str_contains($filled['rows'][0]['href'], sprintf('%024x', 1)),
    'Who started it, how it ended, its own page.');
$filledPage = (string) $factory->make('larena-env::admin.site', $historyCard->show($filledRequest, $domain)->getData())->render();
env_assert(substr_count($filledPage, 'data-env-site-history-row=') === 5 && !str_contains($filledPage, 'data-env-site-history-pending'), 'The part shows the five runs.');

// Equal runs one after another are one row «×N» with the newest's time and page (panel audit 2026-11-18).
$run = static fn (string $title, string $href, string $state = 'succeeded', string $who = ''): array => ['title' => $title, 'state' => $state, 'who' => $who, 'href' => $href];
$runs = [];
foreach ([$run('Очистка корзины', '/j/3'), $run('Очистка корзины', '/j/2'), $run('Очистка корзины', '/j/1'), $run('Очистка корзины', '/j/0', 'failed'),
    $run('Сайт снят с паузы', '/j/-1'), $run('Очистка корзины', '/j/-2')] as $one) {
    $runs = SiteAdminController::addRun($runs, $one);
}
env_assert(array_column($runs, 'times') === [3, 1, 1, 1] && $runs[0]['href'] === '/j/3' && $runs[1]['state'] === 'failed',
    'Three equal runs in a row are one row ×3 keeping the newest; a different end or a run between starts a new row: '.json_encode($runs, JSON_UNESCAPED_UNICODE));
$timesPage = (string) $factory->make('larena-env::admin.site', ['siteHistory' => ['pending' => false, 'url' => '', 'all' => '', 'rows' => [['title' => 'Очистка корзины', 'state' => 'succeeded', 'status' => 'success', 'words' => 'Готово', 'ago' => null, 'time' => '', 'who' => '', 'href' => '/j/3', 'times' => 3]]]] + $historyCard->show($filledRequest, $domain)->getData())->render();
env_assert(substr_count($timesPage, 'data-env-site-history-row=') === 1 && str_contains($timesPage, 'data-env-site-history-times="3"') && str_contains($timesPage, '×3'), 'The row says ×3.');

echo "SecretFilesTest passed.\n";
