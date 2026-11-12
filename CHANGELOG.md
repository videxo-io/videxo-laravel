# Changelog

## 0.1.1 (2026-11-12)

- Apply the remaining job wait budget to each Laravel HTTP attempt.
- Add `webhook.raw_body_paths` for bounded raw bodies on manually registered webhook routes.
- Require PHP SDK 0.1.1 or newer for the timeout-aware client interface.

## 0.1.0

The first release: the service provider, the published config, the `Videxo` facade, requests through
Laravel's Http client, and a webhook route with a signature check that dispatches `WebhookReceived`,
`JobSucceeded`, `JobFailed` and `JobCancelled`.
