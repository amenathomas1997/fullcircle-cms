## Taking payments

Payments let people pay you by card for a session, a class, a deposit, anything with a price. The paying itself happens on a secure page run by Stripe, a payment company. Card numbers never come to your website, so there is nothing for you to protect or store.

Payments are off until you turn them on. You need a free Stripe account at stripe.com; your installer or a helper can set that up with you.

### Turning payments on

1. In Settings, under Features, tick "Payments" and save.
2. Still in Settings, go to the "Payments" section. Paste your Stripe secret key, choose the currency (USD is the default), write the message people should read after paying (for example "We will email you within a day to book your time") and save. The key is kept private: it is never shown again, and leaving the box empty keeps the saved one.
3. **Test first.** A key that starts with `sk_test_` is a test key: nothing real is charged. Pay yourself with card number 4242 4242 4242 4242, any future expiry date and any three digits. When everything reads well, swap in the key that starts with `sk_live_`.
4. For the most reliable record of orders, add the webhook as well (see "Webhook" below). It is optional but recommended.

### Products

Products is the list of things people can pay for. Choose "Add a product" and fill in:

- **Name**: what they are paying for, as it should read on the button. For example "Reiki session".
- **Price**: in your currency, for example 25 or 25.50.
- **Description** (optional): a sentence or two shown above the button and on Stripe's page.
- **For sale now**: untick it to hide the button without deleting the product.
- **Options**: let people choose a quantity (1 to 10), ask for a note (an optional box for things like a preferred day), and collect a phone number (Stripe asks for it on the payment page).
- **Address**: the word used in the shortcode. Leave it empty to make one from the name.

Every product has a shortcode, shown in the list, such as `[pay reiki-session]`. Put it on any page and a short form appears with a button that reads "Pay $25.00 for Reiki session". The shortcode must match the product's address exactly; otherwise it shows as typed. Each product also has a plain address you can share in an email, `yoursite.com/pay/reiki-session/`.

### What a visitor hears

Under the button is a sentence saying they will pay on a secure page run by Stripe and that you never see their card. Pressing the button takes them to Stripe. Afterwards they come back to a "Thank you, your payment went through" page with your message. If they change their mind on Stripe's page, they come back to your page with "Your payment was cancelled and nothing was charged". On Spanish pages everything is in Spanish.

### Orders

Orders lists every payment, newest first: when, what, how much, the customer's name and email, their phone number and note if they gave one. Above the list are the totals for each month. "Download all orders as a spreadsheet (CSV)" gives you a file that opens in Excel or Numbers. You are also emailed each time someone pays, at the "Send messages to" addresses in Settings. Test payments are marked "test".

Refunds are done in your Stripe dashboard, not here.

### Webhook

Normally the website records an order when the customer comes back to the thank-you page. If they close the tab first, Stripe can still tell the website directly: that is the webhook. In your Stripe dashboard, under Developers, then Webhooks, add the address shown in Settings under Payments (it ends in `/pay/webhook/`), choose the event `checkout.session.completed`, then paste the "signing secret" Stripe gives you into Settings. Test and live keys each have their own webhook.

## Redirects

A redirect sends anyone who opens an old address to a new one. Use it after renaming a page, or to catch addresses from your old website that people still have in bookmarks and emails. Redirects is in the control panel menu and is always available.

To add one, fill in "From this address" (the old address, for example `/old-page/`; capital letters and a trailing slash do not matter), "Send people to" (a page on this site, like `/new-page/`, or a full `https://` address on another site), and the kind: "Moved for good (301)", the usual choice, which tells search engines to update their links, or "Moved for now (302)". Each redirect can be edited or deleted; deleting asks you to confirm.

### Missing addresses

The same screen lists "Missing addresses in the last 30 days": addresses people asked for that do not exist, with how many times and when last. Old links from emails, search engines or other sites show up here. Next to each is "Add a redirect", which fills in the old address for you so you only type where to send people. Automated scans and file names are left out of the list, and it keeps 90 days.

The Dashboard tells you when something needs attention: "5 addresses got 404s this week" with a link to the screen.
