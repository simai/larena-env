# Evidence: the panel's actions

`composer run quality:gate` is the proof at this stage; it needs no database and no server. The tests use the answers
recorded from simai-env 1.25.0 (`resources/recorded-answers`, the set in its `testing/fixtures/panel`), so both sides
check the same files: simai-env that it still answers in this shape, the panel that it can show it.

Not proved here, and needs the stand or a server:

- a real certificate, backup, rollback or restart on a live host (the recorded set holds one plan, one started job,
  one job status and one job log);
- a job that is still running: the recorded job is already finished, so the screen's «read on» cursor is only
  exercised against the recorded answer;
- the screens inside the real admin shell (their tests render the views on a stub layout).
