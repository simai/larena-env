<?php

// larena/auth and larena/setting are not discovered (composer.json): the panel runs their full providers, as the
// platform's full profile does, because the first run and the administrators' screens live there.
return [
    Larena\Auth\Providers\AuthServiceProvider::class,
    Larena\Setting\Providers\MinimalSettingServiceProvider::class,
    Larena\Setting\Providers\SettingServiceProvider::class,
    Larena\Env\Providers\PanelEditionServiceProvider::class,
];
