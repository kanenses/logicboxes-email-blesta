# KanumHost LogicBoxes Email module

Independent Blesta module for ResellerClub / LogicBoxes **Business Email** and **Enterprise Email** (US HTTP API).

## Refactoring branch status

This branch reorganizes the latest implementation from `main` into five traits without intentionally changing the module's service lifecycle or API behavior. The source of truth for this extraction is `main` at commit `8ff0230ed639cdb2171885c5d0caf79931adcd70` (functional module file blob `6bbf2bbff0e2f1ae46f5fca14f5fd08a6dd31990`).

This is a review/test branch, not a production-ready release. Do not merge or deploy until PHP syntax validation and Blesta/OrderBox sandbox testing have passed.

## Features retained from the functional reference

- Provisioning and service lifecycle: add, edit quantities, suspend, unsuspend, cancel, and renew.
- Client and admin service tabs: **Email accounts** and **DNS configuration**.
- List accounts (paginated), create mailboxes, create forwarding-only addresses, and delete accounts.
- Reduce unused purchased licences.
- Password-less SSO to the provider control panel.
- Automatic OrderBox-to-Blesta reconciliation of mailbox and storage quantities.
- API credentials remain server-side, and secrets are redacted from module logs.
- Service-change payment deferral relies on Blesta's native **Queue Service Changes Until Paid** setting; enable it on each client group that must wait for payment.

## Code structure

- `logicboxes_email.php`: Blesta entry point, constructor, module identity, and trait composition.
- `traits/ModuleConfigurationTrait.php`: module and package configuration.
- `traits/ServiceSyncTrait.php`: cron registration, deferred changes, and reconciliation.
- `traits/ServiceTabsTrait.php`: client/admin tabs, mailbox controls, and DNS management.
- `traits/ServiceProvisioningTrait.php`: validation and service provisioning/lifecycle methods.
- `traits/LogicboxesApiSupportTrait.php`: API helpers, response handling, diagnostics, and configurable-option parsing.
- `apis/logicboxes_api.php`, `apis/logicboxes_email_rest_api.php`, and `apis/logicboxes_response.php`: API transports and response parsing.

The traits are composed into the same `LogicboxesEmail extends Module` class expected by Blesta. Public and private methods have been extracted from the functional reference; this is an organizational change, not an API redesign.

## Requirements

- Blesta 5.x / 6.x (this branch reports module version 0.6.1).
- Package configurable options for mailbox quantity and optional extra-storage blocks, matching the names configured in the module.

## Install and test

1. Back up the existing `components/modules/logicboxes_email/` directory.
2. Install this branch in a **sandbox/test Blesta instance** first.
3. Confirm the module loads, its version is reported correctly, package options render, and client/admin tabs load.
4. Exercise service creation, configurable-option changes, mailbox and forwarding management, DNS retrieval, suspension, unsuspension, renewal, and reconciliation.
5. Enable **Queue Service Changes Until Paid** on the test client's group. Submit a quantity upgrade and confirm OrderBox is unchanged before payment; after paying the invoice and the native service-change cron runs, confirm the provider quantity is updated. Repeat with the setting disabled only when immediate provisioning is intended.
6. **Cancellation timing (checked against Blesta source documentation).** `Services::cancel()` schedules cancellation requests for cron processing. `date_canceled='end_of_term'` schedules cancellation at the end of the current term; a future date schedules it for that date; a past date triggers immediate cancellation. This module's `cancelService()` submits LogicBoxes `/delete` when Blesta processes the actual cancellation. For a non-destructive client-portal test, request end-of-term cancellation, then verify in Blesta that it is scheduled and in OrderBox that the order remains active; no `/delete` request should be sent when the request is first submitted. Test actual provider deletion only with a disposable OrderBox order or when deliberately allowing the service to terminate. Do not test immediate cancellation on a service that must remain active.
7. Inspect Blesta module logs and confirm API credentials/passwords are redacted.

Do not uninstall and reinstall a module row that already has services assigned.

## Validation status

- The extraction inventory matches the functional reference: 63 source methods total, four retained directly in the entry point, and 59 distributed across the five traits.
- The API helper that existed only on the earlier refactor branch is not carried forward: provisioning/status/renewal methods are copied from current `main`, preserving its existing request paths and response handling.
- PHP lint and Blesta/OrderBox integration tests have not been run in this environment and are required before merging or deployment.
