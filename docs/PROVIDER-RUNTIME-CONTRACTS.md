# VSN Live Provider Contracts

## Courier HTTP contract

Create shipment: `POST {VSN_COURIER_API_BASE_URL}{VSN_COURIER_CREATE_PATH}` with bearer token. Response must provide `shipmentId`, `trackingNumber`, optional `labelUrl`, `estimatedDeliveryAt`.

Lookup: `GET ...{VSN_COURIER_LOOKUP_PATH}` where `{id}` is provider shipment ID. Response must provide tracking number and one supported normalized status.

Webhook must include provider event ID, shipment reference/tracking, status and occurrence time. Sign exact raw body using `X-VSN-Signature: sha256=<hex HMAC-SHA256>`.

Supported statuses: pending, label_created/created, ready/ready_for_pickup, picked_up/pickup, in_transit/transit, out_for_delivery, delivered, delivery_failed/failed, return_to_origin/rto, returned_to_sender/returned, cancelled/canceled.

## KYC HTTP contract

Submission: multipart `POST` with `externalId`, verification `type`, `countryCode`, only `documentNumberLast4`, plus private document files. Full identity number is never sent as a normal text field by this adapter.

Response: `verificationId`/`id`, optional status.

Lookup: configured path with `{id}` provider verification reference.

Webhook: eventId, verificationId, status, optional reason/expiresAt, signed with the same raw-body HMAC convention. Replay of the same event ID with a different payload hash is rejected.

## Reconciliation rule

Reconciliation is read/compare only. A mismatch creates review evidence; it does not silently mutate payment, shipment or KYC source-of-truth rows.
