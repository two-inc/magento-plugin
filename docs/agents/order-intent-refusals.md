---
description: 'Cross-country company guards on the order intent, and how Two API refusals are worded for the buyer.'
---

# Order intent refusals

## An order intent is never sent for a company from another country

An organisation number belongs to its own country's registry, and the intent
and the order go out under the billing country, so the API refuses any other
pairing (TWO-26295). Two guards, one per route a company can arrive by:
`applyCompanyData()` refuses a stored company whose country stamp differs from
the shipping panel's country (TWO-24867), and the source resolver never falls
back from a number-less billing capture to the shipping company while the
quote's shipping and billing addresses are in different countries. Both fail
open while a country is unknown. Nor does it fall back from a billing capture in
manual entry, in any country, a Sole trader click still pending over manual entry
included (it asks the billing panel's `isManualEntry()`): the buyer has named the
invoice company, and the shipping number would bill another one (TWO-26775).

The API's own text never reaches the buyer: it is written for an integrator or
for the merchant. A refusal whose `error_json` names a field the buyer can fix
says which, in our own words ("Phone Number is not valid."), with the field
chosen from its path and never from the message; `Two::getFieldErrorLabels()` is
the one path-to-label map, and both the order intent tile (through the checkout
config) and order create read it. A path drops any segment starting with a
capital letter, which is where a validator inserts model names. A refusal that
names nothing the buyer can fix shows a standard message: the general error, or
at order create the "not available for this order" notice for a non-validation
refusal. `PROXY_REFUSED`, a refusal this module made before calling the API,
keeps its own translated sentence. The buyer's return to the shop follows the
same rule: `OrderService` throws `Exception\TwoRefusalException`, whose message
is Two's full account for the order comment and admin notice, and whose buyer
message is what the return pages show. All three return controllers (confirm,
cancel, failed verification) hand any exception to
`OrderService::failBuyerReturn()`, which restores the cart and fails the order
(each step guarded, so neither can keep the buyer from a message) and answers
the refusal's buyer message, this module's own translated sentence for a plain
`LocalizedException`, or the general message for anything else, such as a
database error, whose text is logged and never shown. A server error (5xx) is
always generic, whatever field path it carries. The full detail always reaches
the merchant through the error log or the order comment.

A return link acts only on an order still awaiting the buyer's verification
(`OrderService::isAwaitingVerification()`: the pending-payment state and the
pending status set at placement). Any other order, for example one invoiced,
shipped or already failed, is left as it is and the buyer is sent to the cart,
so a replayed link changes nothing (TWO-27057). The one exception is a repeated
confirm return on a processing or complete order (`OrderService::isConfirmed()`),
such as a refresh while the first confirm was still running: it changes nothing
either, but sends the buyer to the success page, setting the checkout session's
last order and quote from the order the reference found. What the confirm
return does with the state Two reports, including a cancelled order (cart
restored, order failed), is decided by the resolver it shares with the
scheduled check and the admin button: see
[pending-order-recovery.md](pending-order-recovery.md) (TWO-26300). A buyer cancel, and a
failed verification, fail the order only after Two accepted the cancel: when Two
refuses or does not answer,
`OrderService::keepRefusedCancel()` records the account as an order comment and
leaves both the order and the cart as they are.
