<?php

declare(strict_types=1);

namespace Larena\Env\Warnings;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Admin\Alerts\AdminAlert;
use Larena\Admin\Alerts\AdminAlertAction;
use Larena\Admin\Alerts\AdminAlertLevel;
use Larena\Admin\Contracts\AdminAlertSource;
use Larena\Env\Mail\MailLetters;
use Larena\Env\Mail\PanelMail;
use Throwable;

/**
 * «Почта не уходит — проверьте настройки» in the admin's alert bar (owner decision C335, step 2): while mail is on and
 * the last letter that ended was given up after its tries. Said to those who may change the server's settings, with
 * the way to «Настройки» → «Почта»; the next letter that leaves, or a test letter, takes it away.
 */
final readonly class MailAlertSource implements AdminAlertSource
{
    public function __construct(
        private PanelMail $mail,
        private MailLetters $letters,
        private Translator $translator,
        private ?AccessOperationAuthorizer $authorizer = null,
    ) {
    }

    public function alerts(Request $request): array
    {
        try {
            if (!($this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? false) || !$this->mail->isOn() || !$this->letters->failing()) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        return [new AdminAlert(
            id: 'env:mail-failing',
            level: AdminAlertLevel::Warning,
            text: $this->text('failing'),
            source: $this->text('failing_source'),
            action: new AdminAlertAction($this->text('failing_go'), route('larena.env.admin.settings.mail', [], false)),
        )];
    }

    private function text(string $key): string
    {
        return (string) $this->translator->get('larena-env::mail.'.$key);
    }
}
