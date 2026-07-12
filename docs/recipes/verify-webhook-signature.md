# Verify a webhook signature

Confirm a delivery came from your DBConsole install.

## Steps

Each delivery carries `X-DBConsole-Event` and `X-DBConsole-Signature: sha256=<hmac>`. Recompute the HMAC over the raw body with your subscription's signing secret and compare:

```php
$expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $signingSecret);
if (! hash_equals($expected, $request->header('X-DBConsole-Signature'))) {
    abort(401);
}
```

The payload never contains a secret — only the fact of what happened. See [Webhooks](../tools/webhooks.md).

---

[← Docs index](../../README.md#documentation)
