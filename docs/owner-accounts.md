## Signing in, people and roles

Everyone who works on the site has their own account: a name, an email address and a password. You sign in at `/admin/` with the email address and password. Changes are recorded with your name, and the Activity screen shows who did what.

### Roles

- **Owner**: everything, including Settings, the site template, People, Activity and backups. There is always at least one owner.
- **Editor**: every content screen: pages, blog posts, cheat sheets, testimonials, videos, stories, images and files, the menu, messages, email updates, visitors and the link check. Not Settings, the site template, People or Activity.
- **Author**: blog posts and images only. An author's posts are always saved as drafts; an editor or owner opens the post and sets it to Published.

Screens you can't open aren't in your navigation. If you land on one anyway, the screen says so and who to ask.

### Forgot your password?

On the sign-in screen, choose "Forgot your password?", type your email address and press "Email me a link". If that address has an account, an email arrives with a link that works once, for one hour. Open it and choose a new password (at least 12 characters). If the site can't send email yet, the screen says so; the owner can send you a link from the People screen, or whoever hosts the site can run `php tools/set-password.php`.

### My account

The My account screen has your name, a "Change password" form (current password, new password twice) and two-factor sign-in. Changing your password signs you out anywhere else you were signed in.

### Two-factor sign-in (optional)

Two-factor sign-in adds a second step after your password: a six-digit code from an authenticator app on your phone or computer (Microsoft Authenticator, Google Authenticator, 1Password, Authy and others). It keeps your account safe even if someone learns your password.

1. On My account, under "Two-factor sign-in", press "Set up two-factor sign-in".
2. In your authenticator app, add an account by typing a key (apps call this "Enter a setup key" or "Manual entry"). The screen shows the key as eight groups of four letters and digits; spaces don't matter. Name the entry after the site. Apps that take a full address instead can use the `otpauth://` address shown under the key.
3. Type the six-digit code the app shows into "Six-digit code from the app" and press "Turn on two-factor sign-in".
4. Save the ten recovery codes that appear. They are shown only once; there is a button to download them as a text file. Each code signs you in once if you lose your app.

From then on, after your password, the "Enter your sign-in code" screen asks for the current code from the app, or one of your recovery codes. Under My account you can make new recovery codes or turn two-factor off; both ask for your password. If you lose both the app and the codes, an owner can turn two-factor off for you on the People screen.

### People (owners)

The People screen lists everyone with an account: name, email, role, whether two-factor is on, and when they last signed in.

- **Invite a person**: their name, email address and role. They get an email with a link to choose their password (one hour, one use). If the site can't send email yet, the link is shown to you to pass on by hand.
- **Edit** a person (the Edit link in their row) to change their role, send them a set-password link (which also signs them out everywhere), turn off their two-factor sign-in if they are locked out, or remove them. Removing someone signs them out at once; what they wrote is kept. The only owner can't be removed or demoted; make someone else an owner first.

### Activity (owners)

The Activity screen lists the last 200 things people did, newest first: sign-ins and failed sign-ins, pages and posts created, edited or deleted, settings changed, people invited, changed or removed, backups downloaded. Use "Show activity by" to pick one person. The site keeps the most recent 5,000 entries.

### Microsoft 365 sign-in

If the owner has turned it on in Settings, the sign-in screen also has "Sign in with Microsoft 365". It works for anyone whose account on the People screen has the same email address as their Microsoft 365 account; they get that account's role. Your email address and password keep working too.
