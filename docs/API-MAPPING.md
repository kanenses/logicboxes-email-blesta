# KanumHost LogicBoxes Email 0.6.1 — API mapping

Management tabs reuse the provider order ID in `email_order_id`. They do not place a new order.

## Order / product

| Operation | Method | Endpoint |
|-----------|--------|----------|
| Add Business Email | POST | `/api/eelite/us/add.json` |
| Add Enterprise Email | POST | `/api/enterpriseemail/us/add.json` |
| Order details | GET | `/api/{eelite\|enterpriseemail}/us/details.json` |
| Add storage addon | POST | `/api/{prefix}/addon/add.json` (`addon=email_storage_5`) |
| Delete licenses | POST | `/api/{prefix}/delete-email-account.json` |
| Suspend / unsuspend | POST | `/api/{prefix}/suspend.json` / `unsuspend.json` |
| Renew | POST | `/api/{prefix}/renew.json` |
| DNS records | GET | `/api/{prefix}/dns-record.json` |

## Mail users

| Operation | Method | Endpoint |
|-----------|--------|----------|
| List users (paginated) | GET | `/api/mail/users/search.json` (`order-id`, `page-no`) |
| Add mailbox | POST | `/api/mail/user/add.json` |
| Add forward-only | POST | `/api/mail/user/add-forward-only-account.json` |
| Delete user | POST | `/api/mail/user/delete.json` |

## Customer / SSO

| Operation | Method | Endpoint |
|-----------|--------|----------|
| Search customer | GET | `/api/customers/search.json` |
| Customer details | GET | `/api/customers/details.json` |
| Signup customer | POST | `/api/customers/signup.json` |
| Generate login token | POST | (password-less generate-token flow; see module `manage_panel` action) |

## Notes

- Mailbox capacity is checked against a full (paginated) user search before create/downgrade.
- Pending OrderBox actions (`actionstatus` / `actioncompleted`) block quantity reconciliation and further edits until complete.
- API key is never exposed to the browser. Logs redact `api-key`, `passwd`, `password`, `auth-token`.


## Subscription status sync

| Source | Endpoint | Field |
|--------|----------|-------|
| Primary | `GET /api/{eelite\|enterpriseemail}/us/details.json` | `currentstatus` |
| Fallback | `GET /api/mail/subscription/get.json` | status / subscription-status |

Documented `currentstatus` values: `InActive`, `Active`, `Suspended`, `Pending Delete Restorable`, `Deleted`, `Archived`.
