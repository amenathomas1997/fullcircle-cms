# Owner's guide to your website

This guide is for the person who looks after the website day to day. It assumes you use a screen reader such as JAWS, NVDA or VoiceOver and have no technical background. Nothing here needs code. The person who hosts the site has a separate guide (README.md) for the technical parts.

## How the control panel is laid out

The control panel is at your website address followed by `/admin/`, for example `https://example.com/admin/`.

- Every screen starts with a "Skip to main content" link, then a banner with the site name, then a navigation region called "Control panel" that lists the screens you can open in four named groups: Content (Dashboard, Pages, Blog posts, Images and files, Menu, Footer, Events, Forms, Products and so on), People and messages (Messages, Email updates, Orders, People, Activity, My account), Checks and tools (Visitors, Link check, Accessibility, Redirects, Import, Translations, System, Sites, Backups) and Site (Site template, Settings). The main region holds the screen's heading (the only level 1 heading) and its content.
- Long screens such as Settings and Site template start with a navigation region called "On this screen", with a link to each section. On Settings the list is made from the sections that are actually there, so it changes when a feature is turned on or off. Sections are level 2 headings, so you can also jump with your heading key.
- After you save, a message appears at the top and focus moves to it, so you hear it straight away.
- If a form has a problem, nothing is saved. A box headed "There is a problem" (or "There are 3 problems") takes focus and lists each problem as a link. Press Enter on a link and you land on the field that needs fixing. The field itself is marked invalid and repeats the message.
- Every field has a visible label. Hints are read after the label.
- Tables have captions and row headings, and every Edit, View and Delete link says which item it belongs to, so you can read links out of context.
- The footer of every screen says which version of the kit the site runs, for example "Full Circle CMS 1.2.0".

## Accounts and roles

Everyone who works on the site has their own account: a name, an email address and a password. Changes are recorded with your name, and the Activity screen shows who did what. There are three roles:

- **Owner**: everything, including Settings, the site template, People, Activity, System, Sites and Backups. There is always at least one owner.
- **Editor**: every content screen: pages, blog posts, the footer, forms, events, products and orders, redirects, cheat sheets, testimonials, videos, stories, images and files, the menu, messages, email updates, visitors, the link check and the accessibility check. Not Settings, the site template, People, Activity or Backups.
- **Author**: blog posts and images only. An author's posts are always saved as drafts; an editor or owner opens the post and sets it to Published.

Screens you can't open aren't in your navigation. If you land on one anyway, the screen says so and who to ask.

## Signing in

1. Open `/admin/`. The heading is "Sign in".
2. Type your email address in the Email address field and your password in the Password field, then press Enter or activate the "Sign in" button.
3. Five wrong tries pause sign-in for 15 minutes. You are signed out after 8 hours without activity. To sign out, use the "Sign out" button after the navigation.

The first time you sign in from a browser or device the site has not seen before, it emails a six-digit code to your sign-in address after the password (unless the owner turned this off under Settings, or you use an authenticator app). The screen is headed "Enter your sign-in code": type the code in the "Code from the email" field within 10 minutes and press "Finish signing in". If it does not arrive, check the junk folder or press "Send me a new code". After that, the same browser is remembered for 30 days and is not asked again.

If Microsoft 365 sign-in is turned on, the screen also has a "Sign in with Microsoft 365" button before the email form. It takes you to Microsoft, where you sign in with your own work account and its two-step verification, then brings you back signed in. It works for anyone whose account on the People screen has the same email address as their Microsoft 365 account. Email and password keep working too.

If your site was set up before accounts existed, the sign-in screen still takes the old shared site password once. The first person to use it is asked for a name, an email address and a new password, becomes the owner, and the shared password is retired.

### Forgot your password?

1. On the sign-in screen, choose "Forgot your password?".
2. Type your email address and press "Email me a link".
3. If that address has an account, an email arrives with a link that works once, for one hour. Open it and choose a new password of at least 12 characters.

If the site can't send email yet, the screen says so. An owner can send you a link from the People screen, or whoever hosts the site can run a command to set a new one.

### My account

The My account screen has your name, a "Change password" form (current password, new password twice) and the two-factor sign-in section. Changing your password signs you out anywhere else you were signed in.

### Two-factor sign-in (optional)

Two-factor sign-in adds a second step after your password: a six-digit code from an authenticator app on your phone or computer (Microsoft Authenticator, Google Authenticator, 1Password, Authy and others). It keeps your account safe even if someone learns your password.

1. On My account, under "Two-factor sign-in", press "Set up two-factor sign-in".
2. In your authenticator app, add an account by typing a key (apps call this "Enter a setup key" or "Manual entry"). The screen shows the Secret key as groups of four letters and digits; spaces don't matter. Name the entry after the site. Apps that take a full address instead can use the `otpauth://` address shown under the key.
3. Type the code from the app into "Six-digit code from the app" and press "Turn on two-factor sign-in".
4. Save the ten recovery codes that appear. They are shown only this once; "Download the codes" saves them as a text file. Each code signs you in once if you lose your app.

From then on, after your password, the "Enter your sign-in code" screen asks for the current code from the app, or one of your recovery codes. Under My account you can "Make new recovery codes" or turn two-factor off; both ask for your password. If you lose both the app and the codes, an owner can turn two-factor off for you on the People screen.

## People (owners)

The People screen lists everyone with an account: name, email, role, whether two-factor is on, and when they last signed in.

- **Invite a person**: their name, email address and role. They get an email with a link to choose their password (one hour, one use). If the site can't send email yet, the link is shown to you to pass on by hand.
- **Edit** (the Edit link in a person's row) to change their role, "Send a set-password link" (which also signs them out everywhere), "Turn off two-factor sign-in" if they are locked out, or remove them. Removing someone signs them out at once; what they wrote is kept. The only owner can't be removed or demoted; make someone else an owner first.

## Activity (owners)

The Activity screen lists the last 200 things people did, newest first: sign-ins and failed sign-ins, pages and posts created, edited or deleted, settings changed, people invited, changed or removed, backups downloaded. Use "Show activity by" to pick one person. The site keeps the most recent 5,000 entries.

## The dashboard

The dashboard is a list of cards, each with a level 2 heading that is a link: Write a blog post, Add a page, Edit pages (with the page count), Blog posts (posts and drafts), Messages (how many, and how many were not emailed), Testimonials (how many wait for approval), Visitors (the last 7 days), Cheat sheets, Menu, and a card for each feature that has news: Forms (new answers), Events, Orders (this month's total), Accessibility (how many pages or posts have things to check), Redirects (how many addresses got 404s this week), Sites (how many of your other sites are fine) and System (when a kit update is waiting). Below them, a "Backups" heading tells you when the last nightly backup ran and has a "Download a backup now" button.

If email sending is not set up yet, a warning explains that contact messages are saved but not emailed.

## Writing a blog post

1. From the dashboard or the Blog posts screen, choose "Write a blog post" (or "Write a new post").
2. Type the Post title. It becomes the main heading of the post, so inside the post start your sections with Heading 2.
3. Move to "Post content" and write it in the editor you have chosen.

### Choosing an editor

Above the content there is a group of radio buttons called **Editor** with three choices. Your choice is remembered in this browser. Pages have the same three choices and start in HTML.

- **Visual**: the what-you-see-is-what-you-get editor (TinyMCE), described below.
- **Blocks**: built for JAWS, NVDA and VoiceOver. Every paragraph, heading, list, quote and image is its own form field with a label, so there is no editing area that behaves differently in each screen reader. Each block has a **Block type** list and buttons to **Add block below**, **Move up**, **Move down** and **Delete block**. After each action a short message tells you what happened and where your focus is.
- **HTML**: the raw HTML in a plain text box, for people who prefer to write it directly.

Switching between editors keeps your content; nothing is lost.

### The Blocks editor

The Block type list offers Paragraph, Heading 2 to 4, Bulleted list, Numbered list, Quote, Question and answer, Image, HTML, and five whole sections, so a home page never needs HTML:

- **Question and answer**: an expandable FAQ entry. The question is one field and the answer another; a blank line starts a new paragraph and lines beginning with "- " make a list.
- **Image**: the picture (upload it or paste its address), its Alt text and an optional caption.
- **Hero (page opening)**: a heading, an opening paragraph shown larger, and up to two buttons, each with a label and an address. Leave a button's label empty to leave it out. The Heading level list is Heading 2 unless you have ticked "Don't show the page title as a heading"; then choose Heading 1, because every page needs exactly one.
- **Features or services list**: an optional heading and a list of items, each with a Title and a Description. "Add item" adds one (focus moves to its Title) and "Remove item" takes one away. Items are shown as cards side by side on wide screens and stacked on phones.
- **Call to action**: a short heading, one sentence and one button, shown in a coloured box.
- **Contact details**: an optional heading, then Phone number, Email address, Address (one line per line) and Hours. Empty ones are left out. The phone number becomes a link phones can dial and the email a link that opens a new message.
- **Two columns**: two text boxes shown side by side on wide screens and one above the other on phones. A blank line starts a new paragraph; start lines with "- " for a bulleted list or "1. " for a numbered list.

For bold, italic, a link or code inside a paragraph, select the words and press the **Bold**, **Italic**, **Link** or **Code** button, or type the marks yourself: two asterisks around bold words, one asterisk around italic words, and `[link text](https://example.com)` for a link. A new site's home page is built from a hero, a features list and a call to action, so you can start by changing the words in those blocks. Anything the blocks can't describe, such as a table, appears as an HTML block and is kept exactly as it was.

### Describing pictures with help from Claude

If the site has an Anthropic API key (Settings, then Ask a question assistant), two buttons appear:

- In the Blocks editor, under the Alt text field of an Image block, **Suggest a description** asks Claude to look at the picture. The one-sentence suggestion is put into the Alt text field and read out; a longer description appears under it in case you want more detail. Change the words if they are not right: you know the picture's purpose better than anyone.
- On Images and files, each picture has a **Describe** button in the Description column. The suggestion appears in a read-only text field next to it, with a "Copy description" button, and it is remembered: the next time you add that picture in the Blocks editor the Alt text field is filled in for you (you hear "Alt text filled in from Images and files").

Without a key the buttons are not shown and Images and files says why in one line. Descriptions are limited to 200 a day.

### Using the visual editor

The editor is TinyMCE 7. Inside the editing area you type as in a word processor.

- Alt+F10 moves to the toolbar. Use Left and Right Arrow (or Tab) to move between buttons, Enter to use one, Escape to return to your text.
- Alt+F9 opens the menu bar: Edit, Insert, Format, Table, Tools, Help.
- Alt+0 opens the full list of keyboard shortcuts.
- Command+S on a Mac or Control+S on Windows saves from anywhere on the screen.

Headings: in the toolbar, the "Blocks" drop-down offers Paragraph, Heading 2, Heading 3 and Heading 4. Choose one and it applies to the paragraph your cursor is in. Bold, italic, bulleted and numbered lists are on the toolbar too, and Control+B and Control+I (Command on a Mac) work while typing.

Links: select the text, then press Control+K (Command+K on a Mac) or use the Link button. Type or paste the address and press Enter. Links to other websites get `https://` added for you.

Images: choose the Image button (or Insert, then Image). In the dialog, the Upload tab lets you pick a picture from your computer; it is uploaded straight away. Fill in "Alternative description" with what the picture shows. Tick "Image is decorative" only when the picture adds nothing a reader needs. You can also add a caption. Pictures can be JPEG, PNG, GIF or WebP.

Tables, block quotes and "Remove formatting" are on the toolbar as well. "Source code" shows the HTML if you ever want to check it. "Fullscreen" gives the editor the whole window.

### Draft, publish, schedule

- Status: "Published" or "Draft, only you can see it". New posts start as drafts. A draft can be viewed on the website only while you are signed in, which is handy for checking it. Authors' posts stay drafts until an editor or owner publishes them.
- "Publish date and time": the post appears on the blog at this moment. Leave it as now to publish immediately, or pick a future date and time to schedule it; the list of posts then shows it as "Scheduled".
- "Post address" is made from the title if you leave it empty.
- "Summary" is shown in the blog list and search results. If empty, the first sentences are used.
- "Topics" are separated by commas. The hint lists topics you have used before. Readers can browse by topic.

### Adding an audio version

Under the "Audio version (optional)" group, choose an M4A or MP3 recording of you reading the post, up to about 60 MB. Save, and a player appears at the top of the post and the recording joins the podcast feed. The same group has a "Replace the audio file" field and a "Remove the audio version" tick box once a recording exists.

### Saving

Use "Save post" to stay on the screen, "Save and view post" to open it on the website, or "Preview without saving", which opens a new tab with a yellow note that nothing is saved yet. After saving, the message tells you whether the post is live, scheduled or a draft. If you try to leave with unsaved changes, the browser asks first.

Under "More options" you find "View this post on the website", "Earlier versions of this post" and "Delete this post". Earlier versions are saved every time you save; you can view and restore any of the last 25.

### Previous versions and undo

Every page, post and cheat sheet edit screen ends with a **Previous versions** heading: a numbered list of the last 10 saves, each read as "Saved <date> by <name>", with a "See it" link and a "Put this version back" button. Putting a version back restores its title and content as a new save; the version it replaced goes to the top of the list, marked where it matters, so pressing "Put this version back" on that one undoes the change. The address, status and other settings are never changed by this.

### The accessibility check after saving

Every time you save a page, blog post, cheat sheet or success story, its content is checked for the things that most often trip up screen reader users. Nothing is blocked; the "Saved" message ends with "N things to check for accessibility" and the first two, and the full list is at the top of the edit form until you fix them and save again. It looks for:

- pictures without alt text (an empty alt text, meaning decorative, is fine);
- more than one heading level 1, or a page with a hidden title and no heading level 1;
- heading levels that skip (a heading 4 straight after a heading 2);
- links that say only "click here", "here", "read more", "learn more", "more", "this" or "link", and links or buttons with no text at all;
- tables without header cells and embedded frames (maps, videos) without a title;
- text written all in capitals, 20 letters or more;
- paragraphs over 1,200 characters.

The Dashboard's Accessibility card says how many pages or posts have things to check, and the **Accessibility** screen lists every page and post with its warnings and a link to edit it.

## Editing a page

On the Pages screen, the table lists every page with Edit, View and Delete links. Choose Edit.

- "Page title" is the browser tab title and, unless you tick "Don't show the page title as a heading", the page's main heading.
- "Page address" is the part after your domain. Changing it breaks old links; add a redirect if you do (see Redirects below).
- "Parent page", under the address, puts this page inside another one (see "Pages inside other pages" below). Leave it as "None" for an ordinary top-level page.
- The content has the same Editor switch as posts: Visual, Blocks or HTML. Pages start in HTML. In HTML, use `<h2>` for section headings and `<p>` for paragraphs. Type `[contact-form]` where the contact form should appear; other shortcodes are listed in README.md and on the Forms, Events and Products screens.
- "Same page in Spanish" (or whichever second language the site has) links a translation when second language pages are on.
- "Search engine description" is one or two sentences for search results.
- Status: published or draft.

Save with "Save page", "Save and view page" or Command+S / Control+S. "Preview without saving" opens a new tab. "More options" has the earlier versions and delete links. The home page cannot be deleted.

### Pages inside other pages

A page can live inside another: Services at `/services/`, Reiki inside it at `/services/reiki/`, and Pricing inside that at `/services/reiki/pricing/`. Three levels is the limit.

1. Open the page and go to the "Parent page" list under the address. Each choice reads out the address the page would get, for example "Services (/services/)". Pages that can't be chosen (the page itself, the pages inside it, pages in the other language, and anything that would go past three levels) are simply not in the list.
2. Save. The page's address becomes its parent's address plus its own name, and old links to the page still work: anyone who opens the old address is sent to the new one.

- The **Pages** screen shows the family as a tree: a page inside another is listed under its parent, and you hear "Level 2:" or "Level 3:" before its title.
- Visitors see a "You are here" trail above the heading of a nested page, for example Home, Services, Reiki, Pricing, each a link. It is a navigation region, so you can jump to it. On second-language pages the trail is in that language.
- A page's own name stays unique across the site, so two pages can't both be called `pricing`, even under different parents.
- Renaming or moving a page carries the pages inside it along, and menu links to any address that changed are updated for you.
- Deleting a page that has pages inside it asks "What should happen to the pages inside it?": move them up a level (the usual choice: they take the deleted page's place and their addresses change) or delete them too. Either way a copy of every deleted page is kept under Earlier versions.
- Menus are unchanged: link to the full address, such as `/services/reiki/`. A menu item for `/services/` is announced as current on every page inside it.

### Scheduling a page

A page can be published ahead of time. Set its status to Published and fill in **Publish on** with a date and time. Until that moment visitors get "Page not found" and the Pages list shows the page as **Scheduled**; you can still open it yourself while signed in, which is how you check it. When the time comes it simply appears. Leave "Publish on" empty to publish straight away. A scheduled page is not added to the menu on its own: add it from the Menu screen when you want the link to show.

### Review links

A review link lets someone see one page or post exactly as visitors will, without signing in, even while it is a draft or scheduled. Use it to show a colleague or client a page before it goes live.

1. Open the page or post in the control panel and go to the **Review links** heading at the end of the screen.
2. Choose how long the link should work (1 to 30 days; 7 is the usual) and press "Create a review link".
3. The new link appears in a box under Review links. Put your cursor in the box, select all and copy, then paste it into an email or chat.

For a page that is already live, you can also keep a change off the website until someone has looked at it: press **Save as a pending edit** instead of "Save page". The website keeps showing the page as it was; a notice at the top of the page form, "There is a pending edit of this page", offers "Edit the pending version", "Publish the pending edit" and "Discard the pending edit". Under Review links, a new "What the new link shows" choice lets you make a link that shows the pending edit, so the site owner can read it before it is published. Publishing the pending edit is an ordinary save, so it appears under Previous versions and can be undone.

Whoever opens the link sees the page with a yellow banner at the top saying it is a review copy, who shared it and until when. Search engines are told to stay away from it. When the time is up, or when you press **Revoke** next to the link, the address shows "This review link has expired" with a link to your contact page. Deleting a page or post removes its review links too. Anyone who has the link can open it, so treat it like the page itself.

## Adding a page to the menu

When you create a new page, tick "Add this page to the main menu" before saving and it is added to the end of the menu. For an existing page, use the Menu screen.

## The Menu screen

When the site has a second language, the screen starts with a small navigation, "Which menu", to switch between the English menu and the second language's menu ("Spanish menu", for example). Each menu item is a group announced as "Menu item 2 of 4: About", with "Link text" and "Link address" fields and buttons "Move up", "Move down" and "Remove". Moving or removing saves straight away and announces the new position. Editing text or addresses needs the "Save menu" button.

Under "Add a link", either pick one of your pages from "Link to one of your pages" (or Blog) and activate "Add to menu", or type a Link text and a Link address for anything else, including other websites, email and phone links.

## The Footer screen

The footer is the bottom of every page. You build it on the **Footer** screen without writing any HTML.

- **Contact column.** Tick "Show a Contact column" and the footer shows the phone number and email address from Settings, under a "Contact" heading. Change them in Settings and the footer follows. If neither is set, the column links to your contact page instead.
- **Link groups.** Each group has a heading (for example "More" or "Our services") and a list of links. Each link has its text and an address, such as `/about/` or `https://example.com/`. Use "Add link", "Move up", "Move down" and "Remove" inside a group, and "Add a link group", "Move group up", "Move group down" and "Remove group" for the groups themselves. Every one of those buttons saves the footer straight away and tells you what changed. You can have up to 6 groups and 20 links in each.
- **Small print.** One line of plain text under the columns, for example a registration or licence number. The copyright line with the year and your name is added on its own.

With second language pages turned on, a "Which footer" navigation switches between the English footer and the second language's footer. Second-language pages use their own footer when it has something in it, and the English footer otherwise. If your site was set up before the Footer screen existed, it keeps using the footer HTML from the Site template screen until you tick the Contact column or add a group.

## Images and files

Choose a file with "Choose a file to upload" and activate "Upload file". Images (JPEG, PNG, GIF, WebP), PDFs and Word documents up to 15 MB are accepted. The message after uploading reads out the file's address.

The table lists files newest first with their address, size, date and, for pictures, a Description column with the "Describe" button explained above. "Copy address" puts the address on your clipboard and announces "Copied" followed by the address. Paste it into a page as a link or an image, always with alt text, for example `<img src="/uploads/2026/05/team.jpg" alt="The team outside the office">`. "Delete" asks you to confirm; a deleted file cannot be brought back, and any page using it will show a broken image.

## Messages from the contact form

Every message sent through the contact form is kept here, even if emailing it failed. Each message is a level 2 heading with the sender's name and the date, followed by a list: Email, Phone, Message, and "Emailed to you" with Yes or No and the reason. A "Delete message from …" button follows each one.

## Forms and Submissions

Forms lets you build your own forms, for example a booking request, a class sign-up or a short questionnaire, without any code. It is on unless the host has turned it off under Settings, then Features.

### Building a form

1. Choose **Forms**, then **Add a form**.
2. Give it a title (this becomes the subject of the email you get), a short name for the shortcode, the message people see after sending, and, if you like, the email addresses the answers go to. Leave the addresses empty to use your usual "Send messages to" addresses from Settings.
3. Add fields. Each field has a type, a label (the question), an optional hint, and a Required box. The types are: short text, long text, email address, phone number, number, date, choice list (choose one), checkboxes (choose several), a yes/no checkbox, a heading, and a paragraph of explanatory text. Choice lists and checkboxes take their choices one per line.
4. Use **Add this field to the end**, **Move up**, **Move down** and **Remove** to arrange the fields. Each of those updates the screen straight away and tells you what changed; press **Save form** when you are done.
5. Copy the shortcode shown in the Forms list, for example `[form booking]`, and paste it into any page. The form appears there, with the same labels, hints, error messages and thank-you message as the contact form.

### Reading the answers

- Every answer is kept, even if the email could not be sent. Choose **Submissions** next to a form to read them, newest first. New ones are marked "New"; press **Mark all as read** when you have seen them.
- The Dashboard's Forms card shows how many new answers are waiting.
- The email you get has the person's email address as the reply address when the form asks for one, so you can just reply.
- "Download all as a CSV file" gives you a spreadsheet of every answer that opens in Excel, Numbers or Google Sheets.
- Each submission has a Delete link with a confirmation. Deleting a form keeps its submissions until you delete them.

Forms have the same protections as the contact form: a hidden field that only robots fill in, a time check, and a limit of five sendings an hour from one visitor.

## Events

Events gives your website an events page with a calendar feed. Turn it on under Settings, then Features, then "Events".

1. Choose **Events**, then **Add an event**.
2. Enter the title, the start date and time, and, if you like, an end date or time. Tick **All day** for events without times.
3. Choose whether it repeats: never, every week on the same day, or every month on the same date (an event on the 31st happens only in months that have a 31st). Enter a "Repeat until" date, or leave it empty to repeat 52 times.
4. Enter a place, an online link (Zoom, Teams and so on), or both. The online link is shown as "Join online".
5. Write the description with the same editors as blog posts, choose Published or Draft, and save.

Where events appear: `/events/` lists upcoming events by month, with past ones folded away under "Past events". Each event has its own page at `/events/<address>/` with an **Add to my calendar** link that downloads a file Outlook, Apple Calendar and Google Calendar open. `/events/feed.ics` is a calendar feed people can subscribe to. Put `[upcoming-events]` in any page to show the next five events with a link to all of them, or `[events-list]` to show the whole list on a page of your own. With second language pages on, the list is also on that side of the site (`/es/eventos/` for Spanish). Dates and times are shown in the time zone chosen in Settings, with the day of the week.

## Testimonials

Visitors send a testimonial with a form on whichever page has `[testimonial-form]`. Nothing appears on the site until you approve it. The screen has three sections: "Waiting for approval", "Showing on the website" and "Hidden", each with a count. Every testimonial is its own form with "Name shown", "About them" and "Testimonial" fields, so you can fix a typo first. Buttons: "Save and show on the website" (approves it), "Save changes", "Hide from the website" and "Delete". Approved testimonials appear wherever a page has `[testimonials]`.

## Email updates

People sign up on whichever page has `[subscribe-form]`, and confirm by email before they receive anything. The screen shows how many are confirmed, waiting or unsubscribed.

Before you can send, Settings needs a "Mailing address for email updates" (a PO box is fine). The law requires it in every update, and the screen says so until it is there.

To write an update:

1. Under "Write an update", choose "What's it about?": a recent blog post or cheat sheet, or "Nothing, just my message". Choosing an item adds its title, summary and a link for you.
2. Type an Email subject, or leave it empty to use "New from … :" and the item's title.
3. Type your message in plain text, with a blank line between paragraphs.
4. Activate "Send a test to me". It goes to your own address and the message tells you where.
5. Activate "Send to 12 subscribers…". A review screen shows the number of people and a preview; confirm with "Yes, send it" or go back.

Emails leave a few at a time over the next minutes; the "Sent updates" table shows progress. Every email has an unsubscribe link, and a copy appears at `/updates/archive/` on the site. The "Subscribers" table lets you remove anyone.

## Cheat sheets

When Cheat sheets is turned on, the screen lists them with Edit, View and Delete. "Add a cheat sheet" opens a form with Title, Summary, the editor for the content, Status and Address. Write a Heading 2 for each section and a two-column table of actions and keys, and spell keys out in words ("Control + Option + Space") so the braille reads well. Each sheet is offered on the site as a web page, a Word document, a large-print Word document and a braille-ready file, all made automatically from what you write, plus a practice quiz.

## Products and Orders (payments)

Payments let people pay you by card for a session, a class, a deposit, anything with a price. The paying itself happens on a secure page run by Stripe, a payment company. Card numbers never come to your website, so there is nothing for you to protect or store. Payments are off until an owner turns them on; you need a free Stripe account, which your host or a helper can set up with you.

### Turning payments on (owners)

1. In Settings, under Features, tick "Payments" and save.
2. Still in Settings, go to the "Payments" section. Paste your Stripe secret key, choose the currency (US dollars is the default), write the message people should read after paying (for example "We will email you within a day to book your time") and save. The key is kept private: it is never shown again, and leaving the box empty keeps the saved one.
3. **Test first.** A key that starts with `sk_test_` is a test key: nothing real is charged. Pay yourself with card number 4242 4242 4242 4242, any future expiry date and any three digits. When everything reads well, swap in the key that starts with `sk_live_`.
4. For the most reliable record of orders, ask your host to add the webhook as well (the address is shown in that section). It is optional but recommended.

### Products

Products is the list of things people can pay for. Choose "Add a product" and fill in:

- **Name**: what they are paying for, as it should read on the button. For example "Reiki session".
- **Price**: in your currency, for example 25 or 25.50.
- **Description** (optional): a sentence or two shown above the button and on Stripe's page.
- **For sale now**: untick it to hide the button without deleting the product.
- **Options**: let people choose a quantity (1 to 10), ask for a note (an optional box for things like a preferred day), and collect a phone number (Stripe asks for it on the payment page).
- **Address**: the word used in the shortcode. Leave it empty to make one from the name.

Every product has a shortcode, shown in the list, such as `[pay reiki-session]`. Put it on any page and a short form appears with a button that reads "Pay $25.00 for Reiki session". Each product also has a plain address you can share in an email, `yoursite.com/pay/reiki-session/`.

Under the button is a sentence saying the visitor will pay on a secure page run by Stripe and that you never see their card. Afterwards they come back to a thank-you page with your message; if they change their mind on Stripe's page, they come back with "Your payment was cancelled and nothing was charged". On second-language pages everything is in that language (fully translated for Spanish; for other languages, whatever you have filled in on the Translations screen).

### Orders

Orders lists every payment, newest first: when, what, how much, the customer's name and email, their phone number and note if they gave one. Above the list are the totals for each month. "Download all orders as a spreadsheet (CSV)" gives you a file that opens in Excel or Numbers. You are also emailed each time someone pays, at the "Send messages to" addresses in Settings. Test payments are marked as tests. Refunds are done in your Stripe dashboard, not here.

## Redirects and missing addresses

A redirect sends anyone who opens an old address to a new one. Use it after renaming a page, or to catch addresses from your old website that people still have in bookmarks and emails. Redirects is always in the control panel menu.

To add one, fill in "From this address" (the old address, for example `/old-page/`; capital letters and a trailing slash do not matter), "Send people to" (a page on this site, like `/new-page/`, or a full `https://` address on another site), and the kind: "Moved for good (301)", the usual choice, which tells search engines to update their links, or "Moved for now (302)". Each redirect can be edited or deleted; deleting asks you to confirm.

The same screen lists "Missing addresses in the last 30 days": addresses people asked for that do not exist, with how many times and when last. Old links from emails, search engines or other sites show up here. Next to each is "Add a redirect", which fills in the old address for you so you only type where to send people. Automated scans and file names are left out. The Dashboard tells you when something needs attention: "5 addresses got 404s this week".

## Importing an old website (owners)

The **Import** screen brings an existing website into this one as drafts. Nothing goes live on its own: every imported page and post is saved as a draft, a page or post whose address is already in use here is skipped rather than replaced, and you see a preview first ("This file contains 12 pages, 40 posts and 85 media files") and confirm before anything is written. There are three ways in:

1. **A WordPress export file.** In WordPress, go to Tools, then Export, choose "All content" and download the file (it ends in `.xml`). Upload it here; files up to 64 MB are accepted and the screen says if your server allows less. Pages, blog posts (with their dates and categories), and the pictures and files they use come across; the old site must still be online for the pictures to download. Menus, tags, featured images and the logo do not come across, and WordPress shortcodes such as `[gallery]` are left as text and listed in the report.
2. **A zip of HTML files.** For a Google Sites Takeout, an old hand-made site, or any folder of web pages: zip it and upload it. Each file becomes a page, using its title and main content; a folder's `index.html` becomes that folder's page with the other files inside it, and the old home page is saved as a draft called `imported-home` because this site already has one.
3. **A live website address.** For Wix, Squarespace, Google Sites and other builders with no export: type the home page address. The importer reads it and follows links on the same site, up to 60 pages. The report lists addresses it could not read and how many pages were left when the limit was reached.

**How long it takes.** The preview and the import run while you wait: the preview reads the file or crawls the site, and the import downloads the pictures. Each step holds the screen until it has finished, which can take a few minutes for a big site. Leave the tab alone until it comes back. If you close it, the work still finishes and the report is under "Recent imports".

**The report** lists the pages and posts created (each with an Edit link), the media files (saved under Images and files too), anything skipped and why, and notes. When imported pages have different addresses from the old site (a WordPress post at `/2020/05/hello/` is now `/blog/hello/`), the report has a button, "Add redirects for 12 old addresses". Press it and the old addresses are redirected to the new ones for good; ones already on the Redirects screen are not added twice. The screen keeps the last five reports, and each import is noted on the Activity screen.

Afterwards, open each draft, check it with the accessibility check, give it a parent page if it belongs inside another, and set it to Published when you are happy.

## Visitors

A private count made on your own server: no cookies and nothing shared with anyone. A visitor is counted once a day, and your own visits are not counted while you are signed in. A "Time period" navigation offers the last 7, 30, 90 days or year. Totals come first, then a "By day" table of visitors and page views.

## Link check

Once a month the server checks every link on the website and emails you anything broken. "Check all links now" asks for a check within the hour; come back to this screen for the results. The screen also reports the daily health check (SSL certificate and errors) and lists links to look at, most serious first, with the pages they are on.

## Settings basics (owners)

Settings has an "On this screen" navigation to its sections.

- **Site details**: site name, tagline, description, mailing address, posts per page; your contact details (legal name, email, phone, contact page, and the second language's contact page when one is on), used wherever the site tells people how to reach you; "Languages", two drop-downs described below; time zone; "Colors", a group of six radio buttons (Purple, Blue, Green, Teal, Rust red, Charcoal), all with the same high contrast; and "Logo", a drop-down of pictures you have uploaded under Images and files. Save with "Save site details".
- **Features**: tick boxes that turn parts of the site on or off, including Forms, Events, Payments and "Second language pages". Turning one off hides its screens and pages; nothing is deleted.
- **Email sending**: how the site sends email. "Send email through" offers the web server's own mail program, an ordinary email account's SMTP server (Gmail, Outlook.com or a mailbox from your web host), or Microsoft 365. For an email account, fill in the SMTP server name, port, user name and password; Gmail and Outlook.com need an app password, not the one you sign in with, and the Password field is empty every time you open Settings (leave it empty to keep the saved one). "Save and send a test email" tells you which step failed if something is wrong.
- **Payments**: the Stripe key, currency, thank-you message and webhook details described above.
- **Two-step sign-in by email**: a tick box, on unless you turn it off, that asks people for an emailed code the first time they sign in from a new browser; it cannot be turned on until email sending works. Owners also get "Forget all remembered browsers", which makes everyone type a code at their next sign-in.
- **Monthly report**: a tick box for the email that comes on the 1st of each month (visitors, appointments, form answers, messages, the sites you watch, backups, the certificate and kit changes), and "Send me this month's report now" to see one straight away.
- **Text messages**: the Twilio account SID, auth token and sending number that let the site text a reminder the day before an appointment to visitors who asked for one. Leave them empty to send no texts; "Save and send a test text" checks the set-up against your own mobile.
- **Your password and people**: links to My account, People and Activity.

The other sections, Ask a question assistant, Next opening and Microsoft 365 sign-in, are usually set up by the person who hosts the site.

## A second language (owners)

Your site is written in one language, English unless you say otherwise, and can have a second side in another language: its own home page, pages, blog, search, menu, footer and a language switch in the header of every page.

### Choosing the languages

1. In Settings, under Site details, go to the "Languages" heading. "Language the site is written in" is normally English.
2. In "Second language", choose from the list: Spanish, French, German, Italian, Portuguese, Dutch, Polish, Arabic, Chinese, Japanese, Korean, Hindi, Vietnamese or Tagalog. Choosing one turns its pages on; choosing "None" turns them off again but keeps the pages.
3. Save site details.

The same choice appears under Features as a tick box, "Second language pages (Spanish)", named after the language you chose; ticking or unticking it does the same thing. Arabic pages are laid out right to left. Only English and Spanish come fully translated: for any other language, the words the site writes itself stay in English until you fill them in on the Translations screen.

### Second-language pages and their addresses

A second-language page is an ordinary page whose address starts with the language's code and a hyphen: for Spanish, `es-contacto` appears at `/es/contacto/`, and the page editor's address hint tells you which prefix to use. The second-language home page is the page whose address is just the code (`es`). Blog posts work the same way, so `es-hola` appears at `/es/blog/hola/`. Use "Same page in Spanish" on an English page (and "Same page in English" on the Spanish one) to link the two; the language switch then goes straight to the translation. The Menu and Footer screens each gain a tab for the second language. When the site was created with a second language, five starter pages were made for you: in Spanish, or in English with a note at the top asking for a translation for any other language.

### The Translations screen

Translations (under Checks and tools) holds the words the website uses itself in the second language: buttons, labels, headings and messages such as "Skip to main content", "Search" or "Send". Page content is not here; that is under Pages.

- Each row is one phrase: its key, the English text, and a text box for the second language. Leave a box empty to use the kit's wording (for Spanish) or the English text (for a language the kit has not translated yet).
- "Show only rows containing" filters the list by a word, for example "blog"; "Show all" brings everything back.
- Keep any `%s`, `%d`, `{site}` or `{contact}` you see in a phrase: the site fills them in.
- One button, "Save all Spanish translations", saves every row. The website uses the new words straight away.

If the site is written in a language other than English, the screen has a tab per language and English pages' words are edited there too.

## Site template (owners)

Under Site template you can edit the layout HTML, the footer HTML, the second language's footer and the stylesheet. These change every page at once. Leave them alone unless you are comfortable with HTML and CSS; earlier versions can be restored from the links next to each Save button. The footer boxes are used only when the Footer screen has nothing in it.

## System, Sites and Backups (owners)

- **System** shows the kit version, PHP version, how much space the site uses, and whether a newer version of the kit is available. "Check now" looks straight away. Updates are applied by your host; the screen tells you when to ask.
- **Sites** is for people who run more than one website built from this kit. Each site has a key ("Make a key for this site"); paste the other sites' keys here and "Check all" asks every one how it is doing, answering in plain words: "All good" or "2 things to look at: 1 contact message not emailed; the last backup was November 10, 2026".
- **Backups** lists every nightly backup with its date, size and the kit version that made it, and has three actions for each: **Download** saves it to your computer; **Restore one item** puts back a single page, post, cheat sheet, video or story from that night (choose it from the list, named "Page: About" and so on, and press "Restore this item"; the version that was on the website is kept under Earlier versions, so you can undo this); **Restore everything** puts back the whole site as it was that night. Because it replaces everything, you type the backup's date to confirm, and a copy of the site as it is now is saved in the backup folder first. Your passwords, email settings and the people who can sign in are never touched by a restore.
- **Export this site** downloads one zip file with everything that makes the site yours: pages, posts, uploads, settings, messages and the stylesheet, plus a note of the kit version. Passwords and keys are left out. Keep it somewhere safe, or use it to move the site to another server.
- **Import a site** loads an export zip made by another copy of the kit. On a brand-new site it just loads. On a site that already has content, importing replaces that content, so you type this site's name to confirm, and a copy of the current site is saved in the backup folder first. The site keeps its own web address; everything else comes from the file. Check Settings and the home page afterwards.

If no backup is listed, the nightly job has not been set up yet: ask your host.

## Display options on the public site

Every page has a "Display options" button in the header. It opens a panel with two groups of radio buttons: "Text size" (Standard, Large, Extra large) and "Colors" (Match my device, Light, Dark, High contrast, yellow on black). Changes apply at once and are remembered in that browser. "Reset to standard" undoes them and "Close display options" or Escape closes the panel. Nothing is sent to the website.

The site also has a skip link, a navigation region named "Main" for the menu, one main heading per page, and a status message that takes focus after a form is sent.

## When something goes wrong

- **A red message after saving.** Nothing was saved. The message says what to fix; the error list links to each field.
- **"Someone else saved this after you opened it."** Two people had the same item open. Open it in a new tab to read their version, or press Save again to replace it with yours.
- **"Something went wrong" page.** Your last action may not have been saved. Go back to the dashboard and try again. If it keeps happening, tell your host; the details are in the server's log.
- **"You can't open that screen."** Your role doesn't include it. Ask the owner to change your role on the People screen.
- **You can't sign in.** Use "Forgot your password?" on the sign-in screen. If you have two-factor on and have lost the app, use a recovery code, or ask an owner to turn two-factor off for you under People.
- **You deleted something by mistake.** Pages, posts and cheat sheets keep a copy in their earlier versions, and an owner can bring back a single item from a nightly backup on the Backups screen. Files under Images and files cannot be undeleted.
- **The nightly backup.** Every night the server saves all pages, posts, uploads, messages and settings and keeps 30 days of copies. The dashboard shows when the last one ran. You can also download a backup yourself from the dashboard at any time; keep one before big changes.
- **Contact messages or form answers say "Emailed: No".** The message is still saved. Ask your host to check Email sending in Settings.

Your website is hosted by: ______________________________

Phone or email for help: ______________________________
