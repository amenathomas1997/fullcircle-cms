## Accounts, roles and two-factor sign-in

The control panel uses named accounts instead of one shared password. Accounts live in `data/users.json` (id, name, lowercase unique email, password hash, role, two-factor secret, hashed recovery codes, created, last sign-in, and a `sessions_valid_from` time that ends old sessions after a password change). Roles are `owner`, `editor` and `author`; `admin/index.php` maps each screen to its least role in the `ACTION_ROLES` array near the top, and `require_role()` enforces it. Screens registered by features default to editor; People, Activity, Settings, Site template and backups are owner-only; authors get blog posts and images, always as drafts.

### Setting up

`php tools/setup.php` asks for the owner's name, email and password (at least 12 characters) and creates the owner account. Environment variables: `SETUP_OWNER_NAME` (default "Site owner"), `SETUP_OWNER_EMAIL` (default: `SETUP_EMAIL`), `SETUP_PASSWORD`. The web first-run screen asks for the same three fields. Setup no longer writes `password_hash` to `data/config.php`.

### Existing sites with a site password

A site that only has `password_hash` in `data/config.php` keeps working. The sign-in screen still takes the site password; the first person to use it is asked for a name, email and new password, becomes the owner, and the site password is retired (`password_hash` is emptied). If the site had a Microsoft 365 allow-list (`admin_people` in `config.php`), those people become editor accounts the first time the code runs; they sign in with Microsoft 365 as before, or use "Forgot your password?" to set one.

### tools/set-password.php

```
php tools/set-password.php name@example.com      # reset that person's password
php tools/set-password.php                       # no owner yet: creates the owner account
php tools/set-password.php new@example.com --owner   # add another owner from the command line
```

Resetting a password signs that person out everywhere and clears the sign-in lock.

### Passwords by email

Invitations, resets from the People screen and "Forgot your password?" email a link (`/admin/?a=set_password&t=…`) through `send_mail_to()`, so Settings > Email sending must be filled in. Tokens are random, stored hashed in `data/password-tokens.json`, valid for one hour and used once; a new link for a person replaces the old one. "Forgot your password?" always answers the same way, is limited to 5 requests an hour per address and 3 per email (`data/password-reset-rate.json`), and tells people to ask the host to run `tools/set-password.php` when email isn't set up. When the owner invites or resets someone and mail can't be sent, the People screen shows the link to pass on by hand.

### Two-factor sign-in

TOTP as in RFC 6238: 30-second steps, six digits, SHA-1, one step of drift either way, implemented in `lib/features/accounts.php` with its own base32 code (the test vectors from RFC 6238 check out). The set-up screen shows the secret in groups of four and the `otpauth://` address as text, so it can be typed into any authenticator app without a camera; no QR image is produced. Ten recovery codes are stored as SHA-256 hashes and each works once. Owners can turn two-factor off for a locked-out person on the People screen.

### Sessions

Sessions store the account id. A session ends at the next click when the account is removed, when its password is changed or an owner sends a set-password link, when Microsoft 365 sign-in is turned off for someone who used it, and after the existing 8 hours idle. The Microsoft 365 sign-in now matches an account by email and gives that account's role; Settings > Microsoft 365 sign-in only holds the on/off switch.

### Activity log

`data/activity.jsonl`, one JSON object per line: time, user id, name, email, what, detail. Saves and deletes of every item type are recorded through `register_item_hook('after_save' / 'after_delete')`; sign-ins, failed sign-ins, settings parts saved, people changes, two-factor changes and backup downloads are recorded directly. The file is trimmed to its last 5,000 lines; the Activity screen (owner) shows 200. `users.json`, the token file and the rate file are written with mode 600 and, like `config.php`, are left out of the downloadable backup.

### Files

- `lib/features/accounts.php`: accounts, roles, tokens, TOTP, activity log, item hooks, screen registration.
- `admin/people.php`: the People, Activity and My account screens and the sign-in screens for forgotten passwords, set-password links, two-factor codes and the one-time owner creation. Denied over the web by `admin/.htaccess`.
