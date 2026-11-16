# Owner's guide to your website

This guide is for the person who looks after the website day to day. It assumes you use a screen reader such as JAWS, NVDA or VoiceOver and have no technical background. Nothing here needs code. The person who hosts the site has a separate guide (README.md) for the technical parts.

## How the control panel is laid out

The control panel is at your website address followed by `/admin/`, for example `https://example.com/admin/`.

- Every screen starts with a "Skip to main content" link, then a banner with the site name, then a navigation region called "Control panel" that lists the screens. The main region holds the screen's heading (the only level 1 heading) and its content.
- Long screens such as Settings and Site template start with a navigation region called "On this screen", with a link to each section. Sections are level 2 headings, so you can also jump with your heading key.
- After you save, a message appears at the top and focus moves to it, so you hear it straight away.
- If a form has a problem, nothing is saved. A box headed "There is a problem" (or "There are 3 problems") takes focus and lists each problem as a link. Press Enter on a link and you land on the field that needs fixing. The field itself is marked invalid and repeats the message.
- Every field has a visible label. Hints are read after the label.
- Tables have captions and row headings, and every Edit, View and Delete link says which item it belongs to, so you can read links out of context.

## Signing in

1. Open `/admin/`. The heading is "Sign in".
2. Type your password in the Password field and press Enter, or activate the "Sign in" button.
3. Five wrong passwords pause sign-in for 15 minutes. You are signed out after 8 hours without activity. To sign out, use the "Sign out" button after the navigation.

If Microsoft 365 sign-in is turned on, the screen also has a "Sign in with Microsoft 365" button before the password form. It takes you to Microsoft, where you sign in with your own work account and its two-step verification, then brings you back signed in. Only people on the list in Settings can use it. The password still works too.

Forgotten the password? The person who hosts the site can set a new one.

## The dashboard

The dashboard is a list of cards, each with a level 2 heading that is a link: Write a blog post, Add a page, Edit pages (with the page count), Blog posts (posts and drafts), Messages (how many, and how many were not emailed), Testimonials (how many wait for approval), Visitors (the last 7 days), Cheat sheets, and Menu. Below them, a "Backups" heading tells you when the last nightly backup ran and has a "Download a backup now" button.

If email sending is not set up yet, a warning explains that contact messages are saved but not emailed.

## Writing a blog post

1. From the dashboard or the Blog posts screen, choose "Write a blog post" (or "Write a new post").
2. Type the Post title. It becomes the main heading of the post, so inside the post start your sections with Heading 2.
3. Move to "Post content". This is the visual editor. Activating its label puts you inside the editing area, which announces itself as "Post content".

### Choosing an editor

Above the content there is a group of radio buttons called **Editor** with three choices. Your choice is remembered in this browser.

- **Visual**: the what-you-see-is-what-you-get editor (TinyMCE), described below.
- **Blocks**: built for JAWS, NVDA and VoiceOver. Every paragraph, heading, list, quote and image is its own form field with a label, so there is no editing area that behaves differently in each screen reader. Each block has a **Block type** list (Paragraph, Heading 2 to 4, Bulleted list, Numbered list, Quote, Image, HTML) and buttons to **Add block below**, **Move up**, **Move down** and **Delete block**. After each action a short message tells you what happened and where your focus is. For bold, italic, a link or code, select the words and press the **Bold**, **Italic**, **Link** or **Code** button, or type the marks yourself: two asterisks around bold words, one asterisk around italic words, and `[link text](https://example.com)` for a link. An image block asks for the picture (upload it or paste its address), its alt text, and an optional caption. Anything the blocks can't describe, such as a table, appears as an HTML block and is kept exactly as it was.
- **HTML**: the raw HTML in a plain text box, for people who prefer to write it directly.

Switching between editors keeps your content; nothing is lost.

### Using the visual editor

The editor is TinyMCE 7. Inside the editing area you type as in a word processor.

- Alt+F10 moves to the toolbar. Use Left and Right Arrow (or Tab) to move between buttons, Enter to use one, Escape to return to your text.
- Alt+F9 opens the menu bar: Edit, Insert, Format, Table, Tools, Help.
- Alt+0 opens the full list of keyboard shortcuts.
- Command+S on a Mac or Control+S on Windows saves the post from anywhere on the screen.

Headings: in the toolbar, the "Blocks" drop-down offers Paragraph, Heading 2, Heading 3 and Heading 4. Choose one and it applies to the paragraph your cursor is in. Bold, italic, bulleted and numbered lists are on the toolbar too, and Control+B and Control+I (Command on a Mac) work while typing.

Links: select the text, then press Control+K (Command+K on a Mac) or use the Link button. Type or paste the address and press Enter. Links to other websites get `https://` added for you.

Images: choose the Image button (or Insert, then Image). In the dialog, the Upload tab lets you pick a picture from your computer; it is uploaded straight away. Fill in "Alternative description" with what the picture shows. Tick "Image is decorative" only when the picture adds nothing a reader needs. You can also add a caption. Pictures can be JPEG, PNG, GIF or WebP.

Tables, block quotes and "Remove formatting" are on the toolbar as well. "Source code" shows the HTML if you ever want to check it. "Fullscreen" gives the editor the whole window.

### Draft, publish, schedule

- Status: "Published" or "Draft, only you can see it". New posts start as drafts. A draft can be viewed on the website only while you are signed in, which is handy for checking it.
- "Publish date and time": the post appears on the blog at this moment. Leave it as now to publish immediately, or pick a future date and time to schedule it; the list of posts then shows it as "Scheduled".
- "Post address" is made from the title if you leave it empty.
- "Summary" is shown in the blog list and search results. If empty, the first sentences are used.
- "Topics" are separated by commas. The hint lists topics you have used before. Readers can browse by topic.

### Adding an audio version

Under the "Audio version (optional)" group, choose an M4A or MP3 recording of you reading the post, up to about 60 MB. Save, and a player appears at the top of the post and the recording joins the podcast feed. The same group has a "Replace the audio file" field and a "Remove the audio version" tick box once a recording exists.

### Saving

Use "Save post" to stay on the screen, "Save and view post" to open it on the website, or "Preview without saving", which opens a new tab with a yellow note that nothing is saved yet. After saving, the message tells you whether the post is live, scheduled or a draft. If you try to leave with unsaved changes, the browser asks first.

Under "More options" you find "View this post on the website", "Earlier versions of this post" and "Delete this post". Earlier versions are saved every time you save; you can view and restore any of the last 25.

## Editing a page

Pages are written directly as HTML, not in the visual editor. On the Pages screen, the table lists every page with Edit, View and Delete links. Choose Edit.

- "Page title" is the browser tab title and, unless you tick "Don't show the page title as a heading", the page's main heading.
- "Page address" is the part after your domain. Changing it breaks old links.
- "Page HTML" is a large text box. Use `<h2>` for section headings and `<p>` for paragraphs. Type `[contact-form]` where the contact form should appear. Other shortcodes are listed in README.md.
- "Same page in Spanish" links a translation when Spanish pages are on.
- "Search engine description" is one or two sentences for search results.
- Status: published or draft.

Save with "Save page", "Save and view page" or Command+S / Control+S. "Preview without saving" opens a new tab. "More options" has the earlier versions and delete links. The home page cannot be deleted.

## Adding a page to the menu

When you create a new page, tick "Add this page to the main menu" before saving and it is added to the end of the menu. For an existing page, use the Menu screen.

## The Menu screen

The screen starts with a small navigation, "Which menu", to switch between the English menu and the Spanish menu. Each menu item is a group announced as "Menu item 2 of 4: About", with "Link text" and "Link address" fields and buttons "Move up", "Move down" and "Remove". Moving or removing saves straight away and announces the new position. Editing text or addresses needs the "Save menu" button.

Under "Add a link", either pick one of your pages from "Link to one of your pages" (or Blog) and activate "Add to menu", or type a Link text and a Link address for anything else, including other websites, email and phone links.

## Images and files

Choose a file with "Choose a file to upload" and activate "Upload file". Images (JPEG, PNG, GIF, WebP), PDFs and Word documents up to 15 MB are accepted. The message after uploading reads out the file's address.

The table lists files newest first with their address, size and date. "Copy address" puts the address on your clipboard and announces "Copied" followed by the address. Paste it into a page's HTML as a link or an image, always with alt text, for example `<img src="/uploads/2026/05/team.jpg" alt="The team outside the office">`. "Delete" asks you to confirm; a deleted file cannot be brought back, and any page using it will show a broken image.

## Messages from the contact form

Every message sent through the contact form is kept here, even if emailing it failed. Each message is a level 2 heading with the sender's name and the date, followed by a list: Email, Phone, Message, and "Emailed to you" with Yes or No and the reason. A "Delete message from …" button follows each one.

## Testimonials

Visitors send a testimonial with a form on whichever page has `[testimonial-form]` in its HTML. Nothing appears on the site until you approve it. The screen has three sections: "Waiting for approval", "Showing on the website" and "Hidden", each with a count. Every testimonial is its own form with "Name shown", "About them" and "Testimonial" fields, so you can fix a typo first. Buttons: "Save and show on the website" (approves it), "Save changes", "Hide from the website" and "Delete". Approved testimonials appear wherever a page has `[testimonials]`.

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

When Cheat sheets is turned on, the screen lists them with Edit, View and Delete. "Add a cheat sheet" opens a form with Title, Summary, the visual editor for the content, Status and Address. Write a Heading 2 for each section and a two-column table of actions and keys, and spell keys out in words ("Control + Option + Space") so the braille reads well. Each sheet is offered on the site as a web page, a Word document, a large-print Word document and a braille-ready file, all made automatically from what you write, plus a practice quiz.

## Visitors

A private count made on your own server: no cookies and nothing shared with anyone. A visitor is counted once a day, and your own visits are not counted while you are signed in. A "Time period" navigation offers the last 7, 30, 90 days or year. Totals come first, then a "By day" table of visitors and page views.

## Link check

Once a month the server checks every link on the website and emails you anything broken. "Check all links now" asks for a check within the hour; come back to this screen for the results. The screen also reports the daily health check (SSL certificate and errors) and lists links to look at, most serious first, with the pages they are on.

## Settings basics

Settings has an "On this screen" navigation to its sections.

- **Site details**: site name, tagline, description, mailing address, posts per page; your contact details (legal name, email, phone, contact page), used wherever the site tells people how to reach you; time zone; "Colors", a group of six radio buttons (Purple, Blue, Green, Teal, Rust red, Charcoal), all with the same high contrast; and "Logo", a drop-down of pictures you have uploaded under Images and files. Save with "Save site details".
- **Features**: tick boxes that turn parts of the site on or off. Turning one off hides its screens and pages; nothing is deleted.
- **Change password**: current password, new password twice, at least 12 characters.

The other sections, Email sending, Ask a question assistant, Next opening, and Microsoft 365 sign-in, are set up by the person who hosts the site. You can add a person to the Microsoft 365 list yourself with "Add a person" if that sign-in is in use.

## Site template

Under Site template you can edit the layout HTML, the footer HTML, the Spanish footer and the stylesheet. These change every page at once. Leave them alone unless you are comfortable with HTML and CSS; earlier versions can be restored from the links next to each Save button.

## Display options on the public site

Every page has a "Display options" button in the header. It opens a panel with two groups of radio buttons: "Text size" (Standard, Large, Extra large) and "Colors" (Match my device, Light, Dark, High contrast, yellow on black). Changes apply at once and are remembered in that browser. "Reset to standard" undoes them and "Close display options" or Escape closes the panel. Nothing is sent to the website.

The site also has a skip link, a navigation region named "Main" for the menu, one main heading per page, and a status message that takes focus after a form is sent.

## When something goes wrong

- **A red message after saving.** Nothing was saved. The message says what to fix; the error list links to each field.
- **"Someone else saved this after you opened it."** Two people had the same item open. Open it in a new tab to read their version, or press Save again to replace it with yours.
- **"Something went wrong" page.** Your last action may not have been saved. Go back to the dashboard and try again. If it keeps happening, tell your host; the details are in the server's log.
- **You deleted something by mistake.** Pages, posts and cheat sheets keep a copy in their earlier versions, so your host can bring them back. Files under Images and files cannot be undeleted.
- **The nightly backup.** Every night the server saves all pages, posts, uploads, messages and settings and keeps 30 days of copies. The dashboard shows when the last one ran. You can also download a backup yourself from the dashboard at any time; keep one before big changes.
- **Contact messages say "Emailed to you: No".** The message is still saved. Ask your host to check Email sending in Settings.

Your website is hosted by: ______________________________

Phone or email for help: ______________________________
