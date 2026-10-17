# Recover a locked-out Owner

Give an Owner who lost their device fifteen minutes to get back in and re-enrol.

## Steps

From the server's shell, not from a browser:

```bash
php artisan laranail::db-console.security.recover 42
```

The operator counts as enrolled until the printed expiry, and the bypass stands in for one step-up. The security recipients are alerted. Within the window, the operator re-enrols in two-factor in their account settings and saves the new recovery codes; running the command again ends the earlier bypass and starts a new one.

If the operator cannot sign in at all because the login itself asks for the lost factor, reset their two-factor from the same shell (for authkit, `TwoFactorAuthentication::disable()` in `tinker`). The last-Owner protection does not apply in the shell. See [Operator security](../tools/operator-security.md).

---

[← Docs index](../../README.md#documentation)
