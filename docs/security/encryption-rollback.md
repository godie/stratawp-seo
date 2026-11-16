# Secret encryption: backup, rollback and key separation

Operational guide for the encrypted API-key store in `SWPS_Encryption`.
Read this before upgrading, downgrading, restoring a backup, or rotating salts.

**Never print a secret while following this guide.** Every command below prints
only option names, lengths, or booleans.

## What is encrypted

Every option listed by `SWPS_Encryption::secret_option_names()`:

- `swps_api_key` (legacy global key, kept for the Anthropic copy flow)
- `swps_anthropic_api_key`, `swps_openai_api_key`, `swps_google_api_key`,
  `swps_xai_api_key`, `swps_opencode_go_api_key`
- `swps_unsplash_api_key`, `swps_pexels_api_key`, `swps_pixabay_api_key`
- `swps_jon_ai_secret`
- `swps_gsc_client_secret`

`swps_indexnow_api_key` is deliberately **not** encrypted: it is a public
verification key by design.

## Storage formats

| Prefix | Meaning | Status |
| --- | --- | --- |
| `encrypted:v2:` | AES-256-GCM, `base64( iv \| tag \| ciphertext )` | Current; written on save |
| `encrypted:` (no `v2`) | AES-256-CBC, `base64(iv):ciphertext` | Legacy; read-only, re-encrypted on next save |
| no prefix | Plaintext | Legacy; migrated on the next admin request |

The key is `hash( 'sha256', wp_salt( 'auth' ), true )`. That is the single most
important operational fact in this document: **rotating `AUTH_SALT` makes every
stored ciphertext undecryptable.** `decrypt()` fails safe (returns `''` and
logs), so a corrupt credential is never sent to a provider, but the admin must
re-enter each key.

## Before any upgrade

1. Export the database to storage that is not world readable:

   ```bash
   wp db export --add-drop-table /srv/backups/db-before-encryption-change.sql
   chmod 600 /srv/backups/db-before-encryption-change.sql
   ```

   The dump contains the **ciphertext**, not the plaintext. Treat it as
   sensitive anyway.

2. Copy `wp-config.php` (it holds the salts that decrypt that ciphertext):

   ```bash
   install -m 600 wp-config.php /srv/backups/wp-config-before.sql.php
   ```

   Ciphertext without its salt is unrecoverable. Keep the two backups together,
   or keep the salts in a password manager.

3. Record the plugin version you are leaving:

   ```bash
   wp plugin get stratawp-seo --field=version
   ```

## Verify state without printing secrets

```bash
wp eval '
foreach ( SWPS_Encryption::secret_option_names() as $option ) {
    $value = (string) get_option( $option, "" );
    printf(
        "%-28s len=%-4d %s\n",
        $option,
        strlen( $value ),
        "" === $value ? "empty" : ( SWPS_Encryption::is_encrypted( $value ) ? "encrypted" : "PLAINTEXT" )
    );
}'
```

Expected after a successful migration: every row `encrypted`, and
`wp option get swps_secret_options_migrated_v1` returns `1`.

A row reporting `PLAINTEXT` means the migration has not completed; it retries on
each admin request while any plaintext value remains.

## Rollback

Decide which version you are rolling back **to**.

### A. Rolling back to a version that understands encryption

Both formats above are stable, so the stored values stay readable.

```bash
wp plugin deactivate stratawp-seo
wp plugin install stratawp-seo --version=<previous-version> --force
wp plugin activate stratawp-seo
```

Then confirm with the verification snippet above. No key re-entry is needed.

### B. Rolling back to a version from before encryption existed

That version treats `encrypted:...` as the literal API key. Nothing is
destroyed, but every provider call fails authentication until you either
roll forward again or restore the paired database dump.

Recommended sequence, with the site in maintenance mode:

```bash
wp maintenance-mode activate

# 1. restore the matching database first
wp db import /srv/backups/db-before-encryption-change.sql

# 2. then the matching plugin version
wp plugin install stratawp-seo --version=<pre-encryption-version> --force

wp maintenance-mode deactivate
```

Restoring only one half (code or database) leaves the site in the "A" case at
best, and with mismatched settings at worst. Restore both from the same point
in time.

### C. Lost or rotated `AUTH_SALT`

Symptoms: settings fields render empty, and the log shows
`SWPS_Encryption: GCM decrypt failed (rotated salts or tampered value)`.

- **Recovery 1 (preferred):** restore the original `AUTH_SALT` from the
  `wp-config.php` backup. All ciphertext becomes readable again immediately.
- **Recovery 2:** re-enter each API key in settings. The ciphertext is
  unrecoverable; this is a credential re-entry, not a data loss.

Never rotate `AUTH_SALT` on a site with encrypted keys unless you have the
backup in hand or have completed the migration in the next section.

## Key separation from `wp_salt( 'auth' )` — migration plan

This branch deliberately does **not** change the key derivation. Switching
derivation in place would make every existing ciphertext undecryptable, with no
way to migrate because the old key would no longer be derivable. The safe path
is a new payload version with a key identifier, rolled out read-first:

1. **Add the key constant.** In `wp-config.php`, define a dedicated 32-byte key
   (base64 is fine) as `SWPS_ENCRYPTION_KEY`, stored in the same backup set as
   the salts.
2. **Ship dual-read first.** `decrypt()` resolves the key by the identifier in
   the payload header (`encrypted:v3:<key-id>:<payload>`); `v2` and legacy `v1`
   payloads keep using the salt-derived key. This release writes nothing new.
3. **Verify** every option decrypts under the v3 reader before any write path
   changes. Run the verification snippet; it should still report `encrypted`,
   and a smoke test of each provider should succeed.
4. **Switch writes.** New saves and the migration loop write `v3` payloads.
5. **Migrate with compare-and-swap.** Re-encrypt v2 → v3 per option using
   `compare_and_swap_option()`, which now compares a SHA-256 hash of the stored
   value, so a concurrent admin save is never clobbered and the plaintext never
   enters the SQL text.
6. **Keep the salt-derived key readable** for one release cycle so an
   interrupted migration can be resumed and rolled back.
7. **Rollback within this design:** the `SWPS_ENCRYPTION_KEY` constant must stay
   available as long as any v3 payload exists. Losing it is the same failure as
   losing `AUTH_SALT` — keep it in the backup set.

Do not ship steps 2-4 as a single change: a site that upgrades and rolls back
between them would otherwise lose access to everything written in between.

## Do not

- Paste key values, or `wp option get` output, into tickets, chat, CI logs or
  error trackers. Use the length/encrypted report above.
- Copy the database dump to a shared or synced directory.
- Rotate salts "for hygiene" without the backup, and without re-entering keys.
- Back up the database without backing up `wp-config.php` (or the salts).

