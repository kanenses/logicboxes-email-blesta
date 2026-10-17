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

Add Form for forward, between lines 1232 and 1233 and, betwee;
            $output .= '</section>';
 
 add form above here
 
        }


            // OrderBox-style purchased-license deletion dialog. This deletes unused
            // purchased licences, not individual mailbox users, and is non-refundable.
            $output .= '<section class="logicboxes-email-delete-licenses" style="margin-top:18px;">';
            $output .= '<button type="button" class="btn btn-default" onclick="document.getElementById(&quot;logicboxesDeleteLicensesModal&quot;).style.display=&quot;block&quot;">Delete Email Account(s)</button>';
            $output .= '<div id="logicboxesDeleteLicensesModal" role="dialog" aria-modal="true" aria-labelledby="logicboxesDeleteLicensesTitle" style="display:none;position:fixed;z-index:99999;inset:0;background:rgba(0,0,0,.55);padding:8vh 12px 12px;overflow:auto;">';
            $output .= '<div style="max-width:560px;margin:0 auto;background:#fff;color:#333;border:1px solid #aaa;border-radius:7px;box-shadow:0 8px 28px rgba(0,0,0,.35);overflow:hidden;">';
            $output .= '<div style="background:#f2f2f2;padding:12px 16px;border-bottom:1px solid #ddd;"><button type="button" aria-label="Fechar" onclick="document.getElementById(&quot;logicboxesDeleteLicensesModal&quot;).style.display=&quot;none&quot;" style="float:right;border:0;background:transparent;font-size:20px;line-height:1;">&times;</button><strong id="logicboxesDeleteLicensesTitle">Delete Email Account(s)</strong><div>' . $escapePanel($domain) . '</div></div>';
            $output .= '<form method="post" style="padding:16px;">';
            $output .= '<input type="hidden" name="_csrf_token" value="' . $escapePanel($csrfPanel) . '"><input type="hidden" name="email_action" value="delete_licenses">';
            $output .= '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 18px;align-items:center;">';
            $output .= '<div><strong>Purchased Accounts</strong></div><div>' . ($purchasedLicenses === null ? 'Indisponível' : $escapePanel($purchasedLicenses)) . '</div>';
            $output .= '<div><strong>Currently Used</strong></div><div>' . $escapePanel($usedLicenses) . '</div>';
            $output .= '<label for="logicboxesAccountsToDelete"><strong>Accounts to be deleted</strong></label><div><input id="logicboxesAccountsToDelete" type="number" name="accounts_to_delete" min="1" max="' . $escapePanel($unusedLicenses) . '" required ' . ($unusedLicenses < 1 ? 'disabled' : '') . ' style="width:100%;" value="' . ($unusedLicenses > 0 ? '1' : '') . '"></div>';
            $output .= '</div><p style="margin-top:18px;color:#555;">Deleting email accounts will not result in a refund. Licenças em utilização não podem ser removidas aqui; elimina primeiro as mailboxes no painel de email.</p>';
            $output .= '<div style="text-align:right;margin-top:16px;"><button type="button" class="btn btn-default" onclick="document.getElementById(&quot;logicboxesDeleteLicensesModal&quot;).style.display=&quot;none&quot;">Cancelar</button> <button type="submit" class="btn btn-danger" ' . ($unusedLicenses < 1 || $purchasedLicenses === null ? 'disabled' : '') . ' onclick="return confirm(&quot;Tens a certeza de que queres eliminar licenças contratadas? Esta operação não tem reembolso.&quot;);">Delete Now</button></div>';
            $output .= '</form></div></div>';
            $output .= '<script>(function(){var m=document.getElementById("logicboxesDeleteLicensesModal");if(m){m.addEventListener("click",function(e){if(e.target===m)m.style.display="none";});document.addEventListener("keydown",function(e){if(e.key==="Escape")m.style.display="none";});}})();</script>';
            $output .= '</section>';
