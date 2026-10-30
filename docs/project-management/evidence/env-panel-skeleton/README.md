# Evidence: env panel skeleton

`composer run quality:gate` is the proof at this stage; it needs no database and no server.

Not proved here, and needs an assembly or the stand:

- the screen inside the real admin shell (its test renders the view on a stub layout);
- a real SSH call to the gateway (`testing/stand/stand.sh up` in simai-env, then `LARENA_ENV_GATEWAY=ssh`, port 12222,
  key `~/.simai-stand/panel_ed25519`).
