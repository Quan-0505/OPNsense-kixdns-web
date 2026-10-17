# OPNsense 26.7.7 compatibility record

Test date: 2026-10-17. Source baseline: v0.5.2, commit `89817ab112e057be2b19c89911e21b094f0d171c`.

## Environment and upstream change

Tests used an official OPNsense nano image upgraded with the official updater in a disposable QEMU VM. Access was forwarded through localhost only. No production gateway was upgraded, reconfigured or restarted. DNS tests used synthetic configuration and loopback ports 15354/15355; no subscriptions, credentials or production DNS configuration were used.

| Component | Tested version |
| --- | --- |
| OPNsense CE | 26.7.7 amd64 |
| FreeBSD | 15.1-RELEASE-p4 |
| PHP / Phalcon | 8.5.11 / 5.22.1 |
| Python / pkg | 3.13.15 / 2.8.4 |
| Plugin package | os-kixdns-community 0.5.2, FreeBSD 15 amd64 |

Package SHA256: `1a33f5a29e23f84e347849819b8dbf8c5db468d17acb88561cbf5c84b20a045b`.

OPNsense 26.7.6 changed the base service controller to inspect configd start/stop results and return `status_msg` when a service action fails. The plugin view previously discarded that message. This is documented in the [official release notes](https://docs.opnsense.org/releases/CE_26.7.html) and [26.7.7 base controller](https://github.com/opnsense/core/blob/26.7.7/src/opnsense/mvc/app/controllers/OPNsense/Base/ApiMutableServiceControllerBase.php).

## Reproduced problems and changes

1. **Apply could report success after startup failure.** Occupying the DNS listening port before Apply returned `status: ok`, then the service was stopped. The engine log contained `Address already in use (os error 48)`. `daemon(8)` had returned successfully before its child failed. The controller now polls service status five times with 200 ms delays after a successful enabled Apply. Failure changes the result to `failed` with a diagnostic message. Parent framework errors and disabled-service semantics are retained. This checks immediate process survival, not long-term health or DNS correctness.
2. **Failure details were hidden.** Both Settings Apply and Pipeline Editor Save/Apply now display the server's `status_msg`, falling back to the existing generic message. The message is inserted as text so backend content cannot inject HTML.
3. **An older standalone rc script has separate lifecycle bugs.** Testing the exact script with its original engine and synthetic DNS confirmed that `daemon -p child.pid -r` survived a child-only stop and restarted DNS after the pidfile was removed. A stale pidfile also blocked startup. The original engine itself resolved UDP and TCP on 26.7.7. A supervisor-PID rc.subr candidate passed both stop and stale-PID tests in the VM. It was not installed on a production gateway and is not a drop-in migration for an active legacy supervisor. These findings do not conclusively identify a historical upgrade failure whose logs are no longer available.

## Verification results

| Test | Baseline v0.5.2 | Patched source |
| --- | --- | --- |
| Package install and config model/template migration | Pass | Same package baseline |
| Management page; status/version/settings/statistics APIs | Pass | Pass |
| Three consecutive enabled Applies and UDP/TCP DNS | Pass | Pass |
| Stop/start/restart and UDP/TCP DNS after restart | Pass | Pass |
| Two disabled Applies, followed by re-enable | Pass | Pass |
| Busy listener port | Incorrect successful Apply | Failed Apply with diagnostic |
| Release busy port, re-Apply and UDP/TCP DNS | Pass | Pass |
| Stale PID recovery in current package | Pass | Unchanged service script |
| Standalone controller regressions | Not present | Six cases pass on PHP 8.5.11 |
| VM reboot, automatic service start and UDP/TCP DNS | Not separately tested | Pass |

Run the controller regressions with PHP, without an OPNsense bootstrap or service commands:

```sh
php -l src/opnsense/mvc/app/controllers/OPNsense/KixDNS/Api/ServiceController.php
php -n tests/startup_guard.php
```

The six cases cover healthy startup, early exit, delayed exit during the observation window, disabled configuration, preserved framework error details, and GET behavior. Live API tests additionally exercised the real OPNsense controller, configd and rc.d service, with actual DNS queries after each successful Apply.

## Limits and rollout

These results cover amd64 and synthetic loopback DNS, not every production pipeline, arm64, all upstream transports or a custom PR engine's extra statistics. They show that the current package can run on 26.7.7; they do not establish that every standalone installation can be upgraded in place.

Keep the current production engine and configuration until a separate migration has backed up the old service script, verified who owns the DNS sockets, identified the supervisor and child, and established a rollback procedure. Do not layer a new supervisor onto an existing listener. These source changes have not been deployed to production or released as a new package.
