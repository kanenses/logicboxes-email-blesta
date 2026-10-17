# KanumHost LogicBoxes Email module 0.6.1

Independent Blesta module for ResellerClub / LogicBoxes **Business Email** and **Enterprise Email** (US HTTP API).

## Features

- Provisioning and full service lifecycle (add, edit quantities, suspend, unsuspend, renew).
- Client and admin service tabs: **Email accounts** and **DNS configuration**.
- List accounts (paginated), create mailboxes, create forwarding-only addresses, delete accounts.
- Reduce unused purchased licenses.
- SSO token to the provider control panel (password-less).
- Automatic OrderBox → Blesta reconciliation of mailbox and storage quantities (cron + on-tab sync).
- API key is never sent to the browser; secrets are redacted from module logs.

## Requirements

- Blesta 5.x / 6.x (developed against 6.0.x).
- Configurable options on the package (recommended names):
  - **Mailboxes** / `logicboxes_email_mailboxes` — number of email accounts
  - **Extra storage (5 GB blocks)** / `logicboxes_email_extra_storage` — optional storage add-on blocks

## Install

1. Back up the current `components/modules/logicboxes_email/` directory if present.
2. Extract the `logicboxes_email` directory into `components/modules/` so the final path is:

   `components/modules/logicboxes_email/logicboxes_email.php`

3. In Blesta: **Settings → Company → Modules** and verify the module version shows **0.5.8**.
   Do **not** uninstall/reinstall a module row that already has services assigned.
4. Test on a demo/sandbox service first.

## Important operational notes

- Management tabs reuse the existing OrderBox order ID (`email_order_id`). They do **not** place a new order.
- `cancelService` does not delete the OrderBox order (Blesta may schedule cancellation at term end). Terminate or delete the order in the provider panel when appropriate.
- Customer panel login uses the documented password-less token flow. The module no longer stores the OrderBox customer password after signup.
- OrderBox actions can stay **pending**. The module refuses further quantity edits while a pending action is detected and retries sync via cron / tab load.

## Changelog (0.5.8)

- Restored / implemented **subscription status sync** (`syncSubscriptionStatusFromOrderBox`):
  reads OrderBox `currentstatus` from `{eelite|enterpriseemail}/us/details` (fallback `mail/subscription/get`).
  Maps Active → active, Suspended/Deleted/Archived → suspended (never auto-cancels).
- Cron now always reconciles status for every service with `email_order_id`; quantity sync still gated by `email_sync_pending`.

## Changelog (0.5.8)

- Preserves the existing module behavior from the KanumHost 0.5.7 baseline.
- Removes verbose logging of submitted `editService` variables.
- Adds provider subscription status reconciliation to the existing `sync_orderbox_services` Cron task.
- Keeps quantity/config-option reconciliation limited to services marked `email_sync_pending`.
- Uses conservative state mapping: `Active` may reactivate a suspended service; `Suspended`, `Deleted`, `Archived`, and pending-delete states suspend an active service; ambiguous `InActive` does not change Blesta state. No automatic cancellation.
- Aligns `config.json`, README, and `getVersion()` to 0.5.8.

## API mapping

See `docs/API-MAPPING.md`.

## Disclaimer

PHP syntax is checked in this package. Always validate create/delete mailbox, DNS retrieval, license reduction and panel SSO against your OrderBox demo/live account before enabling for customers.
