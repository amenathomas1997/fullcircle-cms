## Building a page from blocks

In the Blocks editor (choose "Blocks" under Editor on any page or post), each block is a form field with a label. Besides paragraphs, headings, lists, quotes, questions and answers and images, the Block type list offers whole sections, so a home page never needs HTML:

- **Hero (page opening).** A heading, an opening paragraph shown larger, and up to two buttons, each with a label and an address. Leave a button's label empty to leave it out. The Heading level list is Heading 2 unless you have ticked "Don't show the page title as a heading"; then choose Heading 1, because every page needs exactly one.
- **Features or services list.** An optional heading and a list of items, each with a Title and a Description. Choose "Add item" to add one (focus moves to its Title) and "Remove item" to take one away. Items are shown as cards side by side on wide screens and stacked on phones.
- **Call to action.** A short heading, one sentence and one button, shown in a coloured box.
- **Contact details.** An optional heading, then Phone number, Email address, Address (one line per line) and Hours. Empty ones are left out. The phone number becomes a link phones can dial and the email a link that opens a new message.
- **Two columns.** Two text boxes shown side by side on wide screens and one above the other on phones. A blank line starts a new paragraph; start lines with "- " for a bulleted list or "1. " for a numbered list.

The new site's home page is built from a hero, a features list and a call to action, so you can start by changing the words in those blocks. If you ever paste HTML the blocks cannot describe, it is kept as an HTML block exactly as written.

## Describing pictures with help from Claude

If the site has an Anthropic API key (Settings, then Ask a question assistant), two buttons appear:

- In the Blocks editor, under the Alt text field of an Image block, **Suggest a description** asks Claude to look at the picture. The one-sentence suggestion is put into the Alt text field and read out; a longer description appears under it in case you want more detail. Change the words if they are not right: you know the picture's purpose better than anyone.
- On Images and files, each picture has a **Describe** button in the Description column. The suggestion appears in a read-only text field next to it, with a Copy description button, and it is remembered: the next time you add that picture in the Blocks editor the Alt text field is filled in for you (you hear "Alt text filled in from Images and files").

Without a key the buttons are not shown and Images and files says why in one line. Descriptions are limited to 200 a day.

## Accessibility check before publishing

Every time you save a page, blog post, cheat sheet or success story, its content is checked for the things that most often trip up screen reader users. Nothing is blocked; the "Saved" message ends with "N things to check for accessibility" and the first two, and the full list is at the top of the edit form until you fix them and save again. It looks for:

- pictures without alt text (an empty alt text, meaning decorative, is fine);
- more than one heading level 1, or a page with a hidden title and no heading level 1;
- heading levels that skip (a heading 4 straight after a heading 2);
- links that say only "click here", "here", "read more", "more" or "link", links whose text is just a web address, and links or buttons with no text at all;
- tables without header cells and embedded frames (maps, videos) without a title;
- text written all in capitals, 20 letters or more;
- paragraphs over 1,200 characters.

The Dashboard shows "Accessibility: N pages or posts have things to check", and the Accessibility screen in the menu lists every page and post with its warnings and a link to edit it.
