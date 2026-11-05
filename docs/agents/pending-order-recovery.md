---
description: 'How an order the buyer verified with Two but never returned from is settled: the resolver, the scheduled check, the admin button.'
---

# Pending order recovery

An order waits in the pending-payment state with the Two pending status until
the buyer comes back to the shop from Two's verification. A buyer who verifies
and then closes the tab never comes back, so the shop also asks Two itself
(TWO-26300).

## One resolver, three triggers

`Service\Payment\PendingOrderResolver::resolve()` settles one waiting order. It
is called with a trigger:

- `buyer_return`: the confirm return controller (`Controller\Payment\Confirm`).
- `scheduled_check`: `Cron\CheckPendingOrders`, every 15 minutes.
- `admin_check`: the order view's Check status button
  (`Controller\Adminhtml\Order\CheckStatus`).

All three take one lock per order (`LockManagerInterface`, name
`two_gateway_pending_order_<entity id>`), then read the order afresh
(`OrderService::reloadOrder()`) and leave it alone unless it still awaits the
buyer. The scheduled check takes the lock with no wait and skips a held order;
the return and the button wait up to 10 seconds. A return that still finds the
lock held shows the success page if the order has meanwhile been processed, and
otherwise a notice, without failing the order. The confirm request carries the
existing idempotency key, so Two's side is safe too.

What Two reports decides the action:

| Two reports | Scheduled check and button | Buyer's return |
| --- | --- | --- |
| VERIFIED | Confirm with Two, then process as the return does (order email, address update, `processOrder()`) | The same |
| CONFIRMED or later (FULFILLING, FULFILLED, DELIVERED, FULFILMENT_NEEDS_MANUAL_RESOLUTION) | Process without a confirm call | The same |
| CANCELLED, or a `status` of REJECTED or DECLINED | `OrderService::cancelLocally()`: a Magento cancel with no cancel sent back to Two | Cart restored, order failed, cancelled message |
| UNVERIFIED | Nothing; the button adds a comment | Cart restored, order failed |
| REFUNDED or anything else | Nothing; error log, and one comment per state | Cart restored, order failed |
| The read or the confirm fails | Nothing; error log, the button adds a comment; the next run retries | The exception goes to `failBuyerReturn()` as before |

Every action by the scheduled check or the button adds a merchant-only comment
naming the trigger (the admin user for the button). The return adds none beyond
what it always added.

A local cancel must never send a cancel back: `cancelLocally()` sets
`OrderService::SKIP_CANCEL_SYNC` on the order before `Order::cancel()`, and
`Observer\SalesOrderCancelAfter` returns early on it.

## Selection

`CheckPendingOrders::selectOrders()` picks orders in the pending-payment state
with the Two pending status and a Two order id, created between the threshold
and 7 days ago, whose `sales_order.two_pending_checked_at` is empty or over 15
minutes old: oldest check first, 50 per run. The resolver stamps that column
(UTC) on every check. The threshold is the hidden setting
`payment/two_payment/pending_check_after_minutes` (default 30, no admin field).

The job `two_gateway_check_pending_orders` runs in its own cron group
`two_gateway` (`etc/cron_groups.xml`, separate process). Its schedule can be
overridden at
`crontab/two_gateway/jobs/two_gateway_check_pending_orders/schedule/cron_expr`.

## The button

`Plugin\Block\Widget\Button\Toolbar` adds Check status with the product name to
the order view while the order is a Two-stack order in pending payment with a
Two order id, and only for an admin holding the ACL resource
`Two_Gateway::check_pending_order` (`etc/acl.xml`, under Sales > Operations >
Orders > Actions). The button POSTs with the form key; the controller reports
the Two state and what changed as an admin message, and acts at any order age.

## Tests

- `Test/Unit/Service/Payment/PendingOrderResolverTest.php`: the state mapping
  for each trigger, the lock waits, and an order that moved on.
- `Test/Unit/Controller/Payment/ConfirmTwoStateTest.php`: the buyer's return
  for each state.
- `Test/Unit/Cron/CheckPendingOrdersTest.php`: the selection query and the run.
- `e2e/tests/pending-recovery.spec.ts`: places an order with the return
  blocked, then settles it with the button (and checks the late return), or,
  when the dev store is set up for it (`E2E_PENDING_CRON=1`: threshold 2
  minutes, job every minute, cron running), by the scheduled check.
