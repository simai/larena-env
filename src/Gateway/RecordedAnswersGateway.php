<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

use JsonException;
use Larena\Env\Contracts\PanelGateway;

/**
 * Replays answers recorded from a real gateway, for tests and demos. The folder holds simai-env's recorded files and
 * their manifest (`simai-panel-fixtures/1`); a file that no longer matches its digest is refused, so a change of the
 * format cannot pass unnoticed. A file's name is the request. Nothing here reaches a server.
 */
final readonly class RecordedAnswersGateway implements PanelGateway
{
    private const string FIXTURES_SCHEMA = 'simai-panel-fixtures/1';
    private const string ANSWER_SCHEMA = 'simai-panel-answer/1';

    private RecordedReplayState $state;

    public function __construct(private string $directory)
    {
        $this->state = new RecordedReplayState();
    }

    public function source(): string
    {
        return 'recorded';
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        $owner = $request->actor->owner;
        $domain = $request->args['domain'] ?? null;
        // As the gateway: an owner may not make owners, and a new site is always the owner's own, whatever its domain.
        if ($owner !== null && str_starts_with($request->command, 'owner:')) {
            return $this->replay($request, 'refused.not-allowed.json');
        }
        // A safe update is the administrator's alone.
        if ($owner !== null && str_starts_with($request->command, 'self:update')) {
            return $this->replay($request, 'refused.owner-admin-only.update.json');
        }
        // The server's services too (simai-env 1.33.0).
        if ($owner !== null && str_starts_with($request->command, 'service:')) {
            return $this->replay($request, 'refused.owner-admin-only.service-restart.json');
        }
        // And the firewall, who reaches the panel and their timed changes (simai-env 1.35.0).
        if ($owner !== null && (str_starts_with($request->command, 'firewall:') || str_starts_with($request->command, 'panel:') || str_starts_with($request->command, 'change:'))) {
            return $this->replay($request, 'refused.owner-admin-only.firewall-status.json');
        }
        // And the server's PHP versions (simai-env 1.37.0); a site's own extensions stay readable.
        if ($owner !== null && str_starts_with($request->command, 'php:') && $request->command !== 'php:extensions') {
            return $this->replay($request, 'refused.owner-admin-only.php-list.json');
        }
        // And a site's own nginx directives (simai-env 1.41.0).
        if ($owner !== null && (str_starts_with($request->command, 'site:nginx-custom') || $request->command === 'site:apache')) {
            return $this->replay($request, 'refused.owner-admin-only.site-nginx-custom-show.json');
        }
        // And the server's software and the system's updates (simai-env 1.38.0).
        if ($owner !== null && str_starts_with($request->command, 'software:')) {
            return $this->replay($request, 'refused.owner-admin-only.software-list.json');
        }
        // And the token the server keeps for DNS certificates (simai-env 1.36.0); whether one is kept, anyone may read.
        if ($owner !== null && $request->command === 'ssl:dns-credentials') {
            return $this->replay($request, 'refused.owner-admin-only.ssl-dns-credentials.json');
        }
        if ($request->command === 'ssl:dns-status') {
            return $this->replay($request, 'ssl-dns-status.json');
        }
        if ($owner !== null && $domain !== null && $request->command !== 'site:add') {
            $own = $this->document("self-describe.owner-{$owner}.json");
            if (is_string($own)) {
                return $this->unavailable($request, $own);
            }
            $sites = is_array($own['data']['sites'] ?? null) ? $own['data']['sites'] : [];
            if (!in_array($domain, array_column($sites, 'domain'), true)) {
                // Recorded again for a command whose refusal the recording holds itself (cron:job-add, simai-env 1.34.0).
                $own = 'refused.owner-scope.'.str_replace(':', '-', $request->command).'.json';

                return $this->replay($request, is_file($this->directory.'/'.$own) ? $own : 'refused.owner-scope.json');
            }
        }

        // The set holds one scheduled job; another id is a job the server does not have.
        if (str_starts_with($request->command, 'cron:job-') && $request->command !== 'cron:job-add' && is_string($request->args['id'] ?? null)) {
            $runs = $this->document('cron-job-runs.json');
            if (!is_string($runs) && ($runs['data']['id'] ?? null) !== $request->args['id']) {
                return $this->replay($request, 'refused.unknown-cron-job.json');
            }
        }

        // A rule of a site's check (simai-env 1.55.1) is answered as the server would from the site's recorded check: a
        // rule the check does not offer a fix for is unknown, one that passes is already fixed; the set holds the state of
        // one rule and the plan of another, and any other is missing, never answered with theirs.
        if (in_array($request->command, ['site:check-state', 'site:check-fix'], true) && is_string($domain) && is_string($request->args['id'] ?? null)) {
            $file = $this->checkFile($request, $domain, $request->args['id']);
            if ($file === null) {
                return $this->unavailable($request, PanelReason::RECORDING_MISSING);
            }

            return $this->replay($request, $file);
        }

        // A site's creation route (simai-env 1.56.0): the set holds a plan with the archive the server found, a plan with a
        // folder named, and the refusals of a folder that is a link, a copy too large for the disk and an address that is
        // not public; any other folder or address is missing, never answered with another's.
        if ($request->command === 'bitrix:restore-ready' && !$request->confirm) {
            $file = $this->restoreFile($request->args);
            if ($file === null) {
                return $this->unavailable($request, PanelReason::RECORDING_MISSING);
            }

            return $this->replay($request, $file);
        }
        // A release from Git (site:deploy, the Laravel repository route of simai-env 1.56.2): the set holds the plan of one
        // repository and ref for one site; any other is missing, never answered with that one's.
        if ($request->command === 'site:deploy' && !$request->confirm) {
            $plan = $this->document('plan.site-deploy-git.json');
            $recorded = is_array($plan) && is_array($plan['data']['args'] ?? null) ? $plan['data']['args'] : null;
            $asked = $request->args;
            if ($recorded !== null) {
                ksort($recorded);
                ksort($asked);
            }
            if (is_array($plan) && $recorded !== $asked) {
                return $this->unavailable($request, PanelReason::RECORDING_MISSING);
            }

            return $this->replay($request, 'plan.site-deploy-git.json');
        }
        // A kind the server has switched off (node on the recording's server) is refused before a plan.
        if ($request->command === 'site:add' && ($request->args['profile'] ?? null) === 'node') {
            return $this->replay($request, 'refused.profile-disabled.json');
        }
        // WordPress installed: the job, and its first answer once it ended carries the administrator's password; every
        // later answer says it was shown once.
        if ($request->command === 'job:status' && $this->state->startedCommand === 'wp:install') {
            $file = $this->state->secretGiven ? 'job-wp-install-status-again.json' : 'job-wp-install-status.json';
            $this->state->secretGiven = true;

            return $this->replay($request, $file);
        }
        if ($request->confirm) {
            $this->state->startedCommand = $request->command;
            $this->state->secretGiven = false;
            if ($request->command === 'wp:install') {
                return $this->replay($request, 'job-wp-install.json');
            }
        }

        $answer = $this->replay($request, $this->fileFor($request, $domain));
        // The set holds one job; a question about another one is missing, never answered with this one's.
        $recordedJob = is_string($answer->data['job_id'] ?? null) ? $answer->data['job_id'] : null;
        $askedJob = $request->args['job_id'] ?? null;
        if ($askedJob !== null && $recordedJob !== null && $recordedJob !== $askedJob) {
            return $this->unavailable($request, PanelReason::RECORDING_MISSING);
        }

        return $answer;
    }

    /** The recorded answer about one rule of a site's check; null when the set holds none for this rule. */
    private function checkFile(PanelRequest $request, string $domain, string $id): ?string
    {
        $doctor = $this->document("site-doctor.{$domain}.json");
        $status = null;
        foreach (is_array($doctor) && is_array($doctor['data']['checks'] ?? null) ? $doctor['data']['checks'] : [] as $check) {
            if (is_array($check) && ($check['fix'] ?? null) === $id) {
                $status = $check['status'] ?? null;
            }
        }
        if ($status === null) {
            return 'refused.unknown-check.json';
        }
        if ($request->command === 'site:check-state') {
            $state = $this->document('site-check-state.'.str_replace('.', '-', $id).'.json');

            return is_array($state) ? 'site-check-state.'.str_replace('.', '-', $id).'.json' : null;
        }
        if ($status === 'PASS') {
            return 'refused.check-ok.json';
        }
        $plan = $this->document('plan.site-check-fix.json');
        if (!is_array($plan) || ($plan['data']['args'] ?? null) !== ['domain' => $domain, 'id' => $id]) {
            return null;
        }

        return $request->confirm ? 'job-site-check-fix.json' : 'plan.site-check-fix.json';
    }

    /** @param array<string, mixed> $args */
    private function restoreFile(array $args): ?string
    {
        $source = $args['source'] ?? null;
        $url = $args['url'] ?? null;
        if (is_string($url)) {
            return str_contains($url, '://127.0.0.1') ? 'refused.url-invalid.json' : null;
        }
        if (is_string($source)) {
            return match ($source) {
                'upload/etc-link' => 'refused.source-invalid.json',
                'upload/huge' => 'refused.not-enough-disk.json',
                'upload/backup' => 'plan.bitrix-restore-ready-source.json',
                default => null,
            };
        }

        return 'plan.bitrix-restore-ready.json';
    }

    private function fileFor(PanelRequest $request, ?string $domain): string
    {
        $name = str_replace(':', '-', $request->command);
        // A searched log is recorded once, for the search the recording made.
        if ($request->command === 'logs:nginx' && isset($request->args['match'])) {
            return 'logs-nginx.match.json';
        }
        // An action is recorded twice: what it would do, and the start of the one job the recording holds.
        if (PanelCommands::kind($request->command) === PanelCommands::ACTION) {
            // A new site is recorded again for the kinds that ask more: an app on a port and a 1C-Bitrix site.
            $profile = $request->command === 'site:add' ? ($request->args['profile'] ?? null) : null;
            if (!$request->confirm && in_array($profile, ['proxy', 'bitrix', 'laravel'], true)) {
                return "plan.{$name}-{$profile}.json";
            }
            // A certificate through DNS is recorded again (simai-env 1.36.0).
            if (!$request->confirm && $request->command === 'ssl:letsencrypt' && ($request->args['challenge'] ?? null) === 'dns') {
                return "plan.{$name}-dns.json";
            }

            return $request->confirm ? 'job-start.json' : "plan.{$name}.json";
        }
        // A job's runs are recorded once, whoever asks (simai-env 1.34.0).
        if ($request->command === 'cron:job-runs') {
            return "{$name}.json";
        }
        // A service's journal is recorded for nginx.
        if ($request->command === 'service:log' && is_string($request->args['name'] ?? null)) {
            return "{$name}.{$request->args['name']}.json";
        }
        // What an update changes is recorded for each of its targets.
        if ($request->command === 'self:update-plan' && is_string($request->args['target'] ?? null)) {
            return "{$name}.{$request->args['target']}.json";
        }
        if ($domain !== null) {
            return "{$name}.{$domain}.json";
        }

        return $request->actor->owner === null ? "{$name}.json" : "{$name}.owner-{$request->actor->owner}.json";
    }

    private function replay(PanelRequest $request, string $file): PanelAnswer
    {
        $document = $this->document($file);

        return is_string($document)
            ? $this->unavailable($request, $document)
            : PanelAnswer::fromDocument($request->requestId, $document);
    }

    private function unavailable(PanelRequest $request, string $reason): PanelAnswer
    {
        return PanelAnswer::failed($request->requestId, $reason);
    }

    /**
     * The recorded answer, or the reason it cannot be used.
     *
     * @return array<array-key, mixed>|string
     */
    private function document(string $file): array|string
    {
        $manifest = $this->json('manifest.json');
        if ($manifest === null || ($manifest['schema'] ?? null) !== self::FIXTURES_SCHEMA
            || ($manifest['request_schema'] ?? null) !== PanelRequest::SCHEMA || ($manifest['answer_schema'] ?? null) !== self::ANSWER_SCHEMA) {
            return PanelReason::RECORDING_TAMPERED;
        }
        $digest = $manifest['files'][$file]['sha256'] ?? null;
        if (!is_string($digest)) {
            return PanelReason::RECORDING_MISSING;
        }
        $path = $this->directory.'/'.$file;
        if (!is_file($path) || !hash_equals($digest, (string) hash_file('sha256', $path))) {
            return PanelReason::RECORDING_TAMPERED;
        }
        $document = $this->json($file);

        return $document === null || ($document['schema'] ?? null) !== self::ANSWER_SCHEMA ? PanelReason::RECORDING_TAMPERED : $document;
    }

    /** @return array<array-key, mixed>|null */
    private function json(string $file): ?array
    {
        $bytes = is_file($this->directory.'/'.$file) ? file_get_contents($this->directory.'/'.$file) : false;
        if ($bytes === false) {
            return null;
        }
        try {
            $document = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($document) ? $document : null;
    }
}
