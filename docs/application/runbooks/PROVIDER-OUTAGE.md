# External Provider Outage Runbook

## Payment
Disable new card intents if the gateway is unhealthy; preserve COD/Coins only when business rules permit. Continue signed webhook ingestion where possible. Reconcile before re-enabling.

## Courier
Pause new labels for the affected service, retain existing shipment state, and never fabricate delivery events. Communicate realistic delays.

## SMS/KYC/Email
Use only pre-approved fallback providers. Do not bypass phone/KYC payout gates because a provider is down. Queue customer communications for retry where safe.

Provider recovery requires a healthy probe plus a clean reconciliation run, not merely a successful login to the provider dashboard.
