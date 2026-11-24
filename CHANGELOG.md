# Changelog

## 0.2.0

The first release after 0.1.0. It carries the fixes made for a 0.1.1 that was never tagged.

- Requires `videxo/videxo-php` 0.2.0 or newer. The facade names its new methods: `tts()`,
  `adaptive()`, `detectSilence()`, `removeSilence()` and `voices()`, the private voices.

### Fixes since 0.1.0

- Apply the remaining job wait budget to each Laravel HTTP attempt.
- Add `webhook.raw_body_paths` for bounded raw bodies on manually registered webhook routes.
- Require the PHP SDK's timeout-aware client interface (0.2.0 or newer).

## 0.1.0

The first release: the service provider, the published config, the `Videxo` facade, requests through
Laravel's Http client, and a webhook route with a signature check that dispatches `WebhookReceived`,
`JobSucceeded`, `JobFailed` and `JobCancelled`.
