# Forms, events and sending email through your own email account

## Sending email through an email account (SMTP)

Your website can send its email (contact form messages, form answers, email updates, the test email) through an ordinary email account instead of the web server's own mail program. That often gets messages delivered more reliably.

1. In the control panel, go to Settings, then Email sending.
2. Under "Send email through", choose "An email account's SMTP server".
3. Fill in the SMTP server fields:
   - **Gmail:** server `smtp.gmail.com`, port 587, user name is your Gmail address, and the password is an **app password**. To make one: in your Google Account go to Security, turn on 2-Step Verification if it is off, then open App passwords, name it after your website and copy the 16-character password it gives you.
   - **Outlook.com (Hotmail, Live):** server `smtp-mail.outlook.com`, port 587, user name is your address, and the password is an app password from your Microsoft account's Security page (Advanced security options, App passwords).
   - **A mailbox from your web host:** the server name, port and password are in your host's email settings, usually under Email Accounts, then Connect Devices or Configure Mail Client. Use the "SSL/TLS" settings: port 465 or 587.
4. Leave "From address (SMTP)" empty to send from the account itself. Most servers only allow that anyway.
5. Fill in "Send messages to" if it is empty, then choose **Save and send a test email**. If something is wrong, the message at the top says which step failed (connecting, starting a secure connection, signing in, or the server refusing the message) so you know what to check.

The password is kept on the server and never shown again. The Password field is empty every time you open Settings; leave it empty to keep the saved one, or type a new one to replace it.

## Forms

Forms lets you build your own forms, for example a booking request, a class sign-up or a short questionnaire, without any code.

### Building a form

1. In the control panel, choose **Forms**, then **Add a form**.
2. Give it a title (this becomes the subject of the email you get), a short name for the shortcode, the message people see after sending, and, if you like, the email addresses the answers go to. Leave the addresses empty to use your usual "Send messages to" addresses from Settings.
3. Add fields. Each field has a type, a label (the question), an optional hint, and a Required box. The types are: short text, long text, email address, phone number, number, date, choice list (choose one), checkboxes (choose several), a yes/no checkbox, a heading, and a paragraph of explanatory text. Choice lists and checkboxes take their choices one per line.
4. Use **Add this field to the end**, **Move up**, **Move down** and **Remove** to arrange the fields. Each of those updates the screen straight away and tells you what changed; press **Save form** when you are done.
5. Copy the shortcode shown in the Forms list, for example `[form booking]`, and paste it into the HTML of any page. The form appears there, with the same labels, hints, error messages and "Thank you" message as the contact form.

### Reading the answers

- Every answer is kept, even if the email could not be sent. Choose **Submissions** next to a form to read them, newest first. New ones are marked "New"; press **Mark all as read** when you have seen them.
- The Dashboard's Forms card shows how many new answers are waiting.
- The email you get has the person's email address as the reply address when the form asks for one, so you can just reply.
- **Download all as a CSV file** gives you a spreadsheet of every answer that opens in Excel, Numbers or Google Sheets.
- Each submission has a Delete link with a confirmation. Deleting a form keeps its submissions until you delete them.

Forms have the same protections as the contact form: a hidden field that only robots fill in, a time check, and a limit of five sendings an hour from one visitor.

## Events

Events gives your website an events page with a calendar feed. Turn it on under Settings, then Features, then "Events".

### Adding an event

1. Choose **Events** in the control panel, then **Add an event**.
2. Enter the title, the start date and time, and, if you like, an end date or time. Tick **All day** for events without times.
3. Choose whether it repeats: never, every week on the same day, or every month on the same date (an event on the 31st happens only in months that have a 31st). Enter a "Repeat until" date, or leave it empty to repeat 52 times.
4. Enter a place, an online link (Zoom, Teams and so on), or both. The online link is shown as "Join online".
5. Write the description with the same editor as blog posts, choose Published or Draft, and save.

### Where events appear

- `/events/` lists upcoming events by month. Past events are folded away under "Past events".
- Each event has its own page at `/events/<address>/` with an **Add to my calendar** link, which downloads a file that Outlook, Apple Calendar and Google Calendar open.
- `/events/feed.ics` is a calendar feed: people can subscribe to it in their calendar app and see every published event, including new ones, automatically.
- Put `[upcoming-events]` in any page to show the next five events with a link to all of them, or `[events-list]` to show the whole list on a page of your own, for example a Spanish page.
- When the Spanish feature is on, the events list and pages are also available in Spanish at `/es/eventos/`, with dates written in Spanish.

Dates and times are shown in the time zone chosen in Settings, with the day of the week, for example "Saturday, November 21, 2026, 10:00 am to 11:30 am".
