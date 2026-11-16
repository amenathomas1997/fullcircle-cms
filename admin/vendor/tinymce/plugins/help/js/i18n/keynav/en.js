// Keyboard navigation help for TinyMCE's Help dialog. The GPL package this site uses ships
// the folder without the file, which made the Help dialog log an error; this is the text
// TinyMCE shows under Help > Keyboard Navigation.
tinymce.Resource.add('tinymce.html-i18n.help-keynav.en',
'<h1>Begin keyboard navigation</h1>\n' +
'<dl>\n  <dt>Focus the Menu bar</dt>\n  <dd>Windows or Linux: Alt+F9</dd>\n  <dd>macOS: &#x2325;F9</dd>\n' +
'  <dt>Focus the Toolbar</dt>\n  <dd>Windows or Linux: Alt+F10</dd>\n  <dd>macOS: &#x2325;F10</dd>\n' +
'  <dt>Focus the footer</dt>\n  <dd>Windows or Linux: Alt+F11</dd>\n  <dd>macOS: &#x2325;F11</dd>\n' +
'  <dt>Focus a notification</dt>\n  <dd>Windows or Linux: Alt+F12</dd>\n  <dd>macOS: &#x2325;F12</dd>\n' +
'  <dt>Focus a contextual toolbar</dt>\n  <dd>Windows, Linux or macOS: Ctrl+F9</dd>\n</dl>\n' +
'<p>Navigation starts at the first UI item, which is highlighted or underlined in the case of the first item in the footer element path.</p>\n' +
'<h1>Navigate between UI sections</h1>\n' +
'<p>To move from one UI section to the next, press <strong>Tab</strong>.</p>\n' +
'<p>To move from one UI section to the previous, press <strong>Shift+Tab</strong>.</p>\n' +
'<p>The <strong>Tab</strong> order of these UI sections is:</p>\n' +
'<ol>\n  <li>Menu bar</li>\n  <li>Each toolbar group</li>\n  <li>Sidebar</li>\n  <li>Element path in the footer</li>\n  <li>Word count toggle button in the footer</li>\n  <li>Branding link in the footer</li>\n  <li>Editor resize handle in the footer</li>\n</ol>\n' +
'<p>If a UI section is not present, it is skipped.</p>\n' +
'<p>If the footer has keyboard navigation focus, and there is no visible sidebar, pressing <strong>Shift+Tab</strong> moves focus to the first toolbar group, not the last.</p>\n' +
'<h1>Navigate within UI sections</h1>\n' +
'<p>To move from one UI element to the next, press the appropriate <strong>Arrow</strong> key.</p>\n' +
'<p>The <strong>Left</strong> and <strong>Right</strong> arrow keys</p>\n' +
'<ul>\n  <li>move between menus in the menu bar.</li>\n  <li>open a sub-menu in a menu.</li>\n  <li>move between buttons in a toolbar group.</li>\n  <li>move between items in the footer\'s element path.</li>\n</ul>\n' +
'<p>The <strong>Down</strong> and <strong>Up</strong> arrow keys</p>\n' +
'<ul>\n  <li>move between menu items in a menu.</li>\n  <li>move between items in a toolbar pop-up menu.</li>\n</ul>\n' +
'<p><strong>Arrow</strong> keys cycle within the focused UI section.</p>\n' +
'<p>To close an open menu, an open sub-menu, or an open pop-up menu, press the <strong>Esc</strong> key.</p>\n' +
'<p>If the current focus is at the top of a particular UI section, pressing the <strong>Esc</strong> key also exits keyboard navigation entirely.</p>\n' +
'<h1>Execute a menu item or toolbar button</h1>\n' +
'<p>When the menu item or toolbar button you want to execute is highlighted, press <strong>Return</strong>, <strong>Enter</strong>, or the <strong>Space bar</strong>.</p>\n' +
'<h1>Navigate dialogs</h1>\n' +
'<p>Dialogs are either tabbed or not. When a dialog opens, keyboard focus goes to its first interactive component. Press <strong>Tab</strong> and <strong>Shift+Tab</strong> to move between components, <strong>Return</strong> or <strong>Enter</strong> to activate a button, and <strong>Esc</strong> to close the dialog. In a tabbed dialog, with focus on a tab, the <strong>Left</strong> and <strong>Right</strong> arrow keys move between tabs.</p>\n');
