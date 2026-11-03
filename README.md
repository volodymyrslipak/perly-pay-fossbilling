# Perly Pay for FOSSBilling

Take bitcoin, USDC and USDT on your FOSSBilling invoices, paid straight to
your own wallet. Perly Pay never holds the money and takes nothing from a
payment: it costs 2.5¢ a payment, never more than 0.5% of it, billed after
the month, and a month of up to 21 payments costs $0.

FOSSBilling 0.8.4 or later, PHP 8.1+ with `curl` and `json`. Nothing to
install with Composer: Perly Pay's PHP SDK is inside, under its own
namespace.

## Install

From FOSSBilling's extension directory: **System → Payment Gateways →
Install from the Extension Directory**, find **Perly Pay** and press
**Install**. FOSSBilling unpacks it into `library/Payment/Adapter/Perlypay/`.

By hand: make the folder `library/Payment/Adapter/Perlypay/` in your
FOSSBilling and unzip `perly-pay-fossbilling.zip` into it, so that
`library/Payment/Adapter/Perlypay/Perlypay.php` exists.

Then **System → Payment Gateways → Add New Payment Gateway**: under
**Install Locally**, install **Perlypay**. It shows as Perlypay until you
give it a title (Perly Pay) in its settings.

## Configure

In the gateway's settings:

| Setting | What to put |
| --- | --- |
| Test secret key | Perly Pay → Developers → API keys, in test mode: `sk_test_…`. Used while **Enable Test Mode** is on; payments are test coins on test networks. |
| Test webhook signing secret | The `whsec_…` secret of the test-mode webhook (below). |
| Live secret key | The same page in live mode: `sk_live_…`. Takes real money. |
| Live webhook signing secret | The `whsec_…` secret of the live-mode webhook. |
| Payment page | **Over FOSSBilling** opens the checkout over FOSSBilling's payment page (add your FOSSBilling address in Perly Pay → Settings → Checkout → "Sites that may embed the checkout"; phones always get the page itself); **On pay.perly.io** sends the client there. |
| Accepted Currencies | USD and EUR. Perly Pay bills in those two only; on an invoice in another currency the client is asked to choose another way to pay. |
| Allow One Time Payments | On. Perly Pay does not take subscription payments: each invoice is paid on its own. |

The webhook: in Perly Pay → Developers → Webhooks, add this gateway's
**Callback URL** (FOSSBilling shows it on the gateway's settings page:
`https://your-fossbilling/ipn.php?gateway_id=…`) with the invoice and refund
events, and paste its signing secret. Do it once in test mode and once in
live mode: each mode signs with its own secret. **Send test** there puts a
"Perly Pay test event" in FOSSBilling's **Invoices → Transactions** when the
address and the secret are right.

FOSSBilling checks the settings when you enable the gateway: a missing key
for the gateway's mode, or a live key in a test field, stops it there.

## How it works

- The client opens an unpaid invoice, picks Perly Pay and presses **Pay
  Now**. The adapter makes one Perly Pay invoice for the invoice's total
  with tax, in its currency (or reuses the open one for the same amount),
  and opens it over the page or sends the client to it. The amount comes
  from FOSSBilling's invoice, never from the request.
- Perly Pay's signed webhook `invoice.fulfilled` (paid in full and the
  sender screened) arrives at FOSSBilling's `ipn.php`, which keeps it as a
  transaction. The adapter adds the amount to the client's balance and pays
  the invoice from it, as FOSSBilling's own Stripe adapter does. The
  transaction keeps the Perly Pay payment (`pay_…`) as its id, the amount,
  and a note naming the network, the coin amount and a link to the
  transaction on the chain.
- Part payments, expiry with money received and payments held for review
  are noted on the transaction and on the invoice (its admin notes), without
  recording a payment. An invoice that expired unpaid is noted on the
  transaction only; paying again makes a new Perly Pay invoice.
- A replayed or doubled webhook records nothing twice and leaves no
  transaction behind: the adapter claims the payment before recording it,
  and remembers every event it applied. A delivery that arrives while
  another is recording the same payment waits for it.
- Events for invoices another shop, another FOSSBilling or another gateway
  made on the same Perly Pay account are acknowledged and dropped.
- Refunds: FOSSBilling has no refund hook for payment gateways, so refunds
  are opened in Perly Pay (Payments → the payment → Refund). Perly Pay
  emails your client for an address; you send from your wallet. The refund
  events are noted on FOSSBilling's transactions, from the refund as Perly
  Pay's API returns it, never from the webhook.

The adapter keeps one table, `perlypay_invoice` (which Perly Pay invoice
belongs to which FOSSBilling invoice), made on first use.

If recording fails (the database is down), the webhook is answered 503 and
Perly Pay sends it again for up to 72 hours. FOSSBilling before 0.8.9
answers other errors with 200, so Perly Pay does not retry those: the
delivery stays in Invoices → Transactions as an error, and **Process** on it records
it (its signature is checked against the time FOSSBilling received it).

## Test

With **Enable Test Mode** on and the test key and secret saved, open an
unpaid invoice as the client, choose Perly Pay and press **Pay Now**. Pay
with test coins, or from the API (the invoice id `inv_…` is on the checkout
page):

```bash
curl -X POST https://pay.perly.io/api/v1/invoices/inv_…/simulate \
  -H "Authorization: Bearer $PERLY_PAY_SECRET_KEY" -H "Content-Type: application/json" -d '{}'
```

Within seconds the FOSSBilling invoice is **Paid**.

## Go live

Turn on live mode in Perly Pay and connect your wallet's public keys, paste
the `sk_live_…` key and the live webhook's secret, turn **Enable Test
Mode** off, then pay one small real invoice and refund it in Perly Pay.

## Security

- A webhook counts only with a valid `Perly-Signature` (HMAC-SHA256 of the
  timestamp and the raw body, compared in constant time), at most five
  minutes old when FOSSBilling received it, signed by the secret of the mode
  it claims, and only for Perly Pay invoices this gateway made.
- Refund details (status, amount, address, transaction) are also confirmed
  with Perly Pay's API, never taken from the webhook: a signed event cannot
  put another address in a note.
- The keys and secrets are password fields FOSSBilling masks in its admin
  and API (from FOSSBilling 0.8.7). The adapter never writes a key, a
  secret, a signature or a client's email to a note or a log.
- Everything the adapter prints is escaped; every PHP file refuses to run
  outside FOSSBilling.

## FOSSBilling sources used

- Extensions: https://docs.fossbilling.org/extensions-and-development/extensions/
- The adapters FOSSBilling ships (Custom, PayPalEmail, Stripe), `ServicePayGateway` (how adapters are found, configured and their logos resolved), `ServiceTransaction` and `ipn.php` (how a webhook becomes a transaction and is processed): https://github.com/FOSSBilling/FOSSBilling/tree/0.8.9/src
- How the extension directory installs a gateway (`Extension\Service::downloadAndExtract`): https://github.com/FOSSBilling/FOSSBilling/blob/0.8.9/src/modules/Extension/Service.php

## Licence

MIT. Perly Pay's PHP SDK inside (`vendor/perly-pay-sdk`) is MIT too.
FOSSBilling itself is Apache-2.0; this adapter uses its documented adapter
interface and ships none of its code.

## Changelog

- **1.0.1**: Every payment is confirmed with Perly Pay's API before an order is completed: a signed webhook alone no longer marks an order paid, so a leaked signing secret cannot ship an unpaid order. Refund details are also confirmed with Perly Pay's API, never taken from the webhook, so a leaked signing secret cannot put its own address in a refund note.
- **1.0.0**: First release.
