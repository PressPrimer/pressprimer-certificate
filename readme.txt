=== PressPrimer Certificate – Certificate Designer & Course Certification for Your LMS ===
Contributors: pressprimer
Tags: certificate, certification, lms, learndash, elearning
Requires at least: 6.4
Tested up to: 7.1
Stable tag: 2.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Design, issue, and verify certificates on your own site. A drag-and-drop designer, automatic awards from popular LMS plugins, and scannable QR proof.

== Description ==

**PressPrimer Certificate** brings the full certification lifecycle to your WordPress site. A drag-and-drop designer produces the certificate. Unique credential IDs, QR codes, and a public verification page make it authoritative. And it can all be connected to course activity in LearnDash, LifterLMS, Tutor LMS, or LearnPress.

**This is a genuinely free plugin.** Unlimited templates, unlimited certificates, the full designer, a suite of integrations, public verification, and email delivery are all included at no cost.

https://www.youtube.com/watch?v=3MVuPFKdhzo

= Why PressPrimer Certificate? =

Certificate tools built into LMS plugins are often afterthoughts: rigid and awkward layouts, no way to verify what students have earned, and no easy way to track certificate activity. Standalone certificate services charge monthly fees and hold your credentials on someone else's infrastructure.

PressPrimer Certificate delivers a complete, self-hosted credential workflow, including:

* **A true-size designer** – The canvas is the PDF. What you build on screen is exactly what recipients download.
* **Credentials that can be checked** – Every certificate carries a unique, non-guessable credential ID and a QR code linking to a public verification page on your site.
* **Comprehensive integrations** – Certificates award themselves when a learner passes a quiz, earns a grade, or completes a course. Manual certificate generation is also available.
* **Records that outlive the template** – Issued certificates snapshot their design and data at issue time. Editing a template later never changes a certificate that has already been earned.

= Features Included Free =

**Certificate Designer**

* Interactive WYSIWYG canvas with drag, resize, keyboard nudging, undo/redo, and zoom
* Eight starter designs - Formal, Modern, Playful, and Geometric, each in portrait and landscape - every one in Letter and A4, plus a blank option
* Image, logo, signature, and background elements accept PNG, JPEG, and GIF, plus WebP and AVIF where your server supports them
* Six bundled open-license fonts (SIL OFL) with real bold and italic faces
* Text, merge field, image, signature, line/shape, QR code, and background elements
* Merge fields for recipient, certificate, site, quiz, assignment, and course data, including custom user and post meta
* Merge fields inside text: write "Expires:" and insert the expiry date field, and the whole line centers and wraps as one piece
* Alignment tools: align and distribute grouped elements, and guides to help with alignment
* Live PDF preview from the real renderer

**Design Defaults**

* Site-wide defaults for certificate size (Letter or A4), font, logo, and signature
* Brand colors (primary, accent, text) that starter templates apply automatically

**Issuance & Automation**

* Automatic triggers: PressPrimer Quiz pass thresholds, PressPrimer Assignment grades, and course, lesson, topic, or quiz completion in LearnDash, LifterLMS, Tutor LMS, and LearnPress
* "Any" award options: one trigger can cover any quiz, any course, or any lesson in a chosen course, so one certificate serves your whole catalog
* Certificate display names built from merge fields, so a template reused across courses names each certificate after its course everywhere it appears
* Manually issue certificates with optional backdating and expiry dates
* Validity periods: set how long certificates from a template stay valid (days, months, or years), pick an exact expiry date, or let them last forever
* Per-trigger control over repeat completions: suppress duplicates or issue a fresh certificate every time (for compliance and recertification courses)
* Revoke certificates and reinstate them if revoked by mistake
* Send yourself a template's award email with sample values before it goes live

**Credentials & Verification**

* Unique, non-guessable credential IDs with a check that catches typos
* Public verification page (shortcode and block) that anyone can use, no account needed
* Optional QR code on every certificate linking to its verification page
* The QR code and credential ID on issued PDFs are clickable, so an emailed certificate verifies in one click
* A shareable certificate view page with PDF download
* Download PDF on the verification page for valid and expired certificates, so a verifier can keep a copy
* Certificate Link block and shortcode: one button on a course, lesson, topic, or quiz page that opens the logged-in learner's own certificate for that item, hidden until it is earned
* My Certificates list (shortcode and block) so logged-in learners can see, verify, and download everything they have earned, with status filters and sorting by date, name, or upcoming expiry
* PDFs are protected against editing: Acrobat and other viewers refuse to modify the text, while printing and copying stay available

**Admin**

* Dashboard with certificate statistics, an awarded-over-time chart, quick actions, and recent certificates
* A guided setup tour that walks you from a starter template to your first issued certificate in five minutes
* Certificates screen with search by recipient, certificate name, or exact credential ID, and template, status, source, and issue-date filters
* Resend a certificate's email with one click
* Certificate merge fields in the email subject and body, so the email can name the exact course or quiz that was completed
* Earned certificates listed on each user's profile page with verify and download links
* Certificate templates list with trigger, page size, and status columns

**Security, Privacy & Accessibility**

* PDF and QR code generation happen locally on your server
* PDF files cannot be modified in Acrobat or other tools to change data like the student name
* WordPress Privacy API integration (Tools > Export/Erase Personal Data)
* Clean uninstall with optional complete data removal
* Keyboard navigation, screen reader support, and reduced motion preferences

= Premium Add-ons =

Everything above is free on WordPress.org. Optional add-ons from [pressprimer.com](https://pressprimer.com/pressprimer-certificate-pricing/) extend the plugin in three tiers, each building on the one below it:

**Educator**

* **Custom Fonts** – Upload your brand's TTF fonts; they appear in every designer font control and render identically on screen and in the PDF.
* **Multi-Page Certificates** – Add, duplicate, and reorder pages in the designer; every page renders in the PDF.
* **Bulk Awarding** – Award a certificate to many recipients at once from a user list or a CSV, with a full per-row preview before anything sends.
* **Expiry Reminder Emails** – Remind recipients before their certificate expires, on a schedule you control, with an editable message and a test send.
* **Branded Verification Page** – Your logo, accent color, intro line, and footer on the public verification page, with a live preview.
* **Social Sharing** – One-click LinkedIn add-to-profile, share composers for LinkedIn, X, and Facebook, copy link, and link previews that show the certificate itself.

**School** *(everything in Educator, plus)*

* **Issuing Organizations** – Issuer profiles with their own name, logo, and web address; members as owners or issuers; templates assigned per organization so members see and award only their own.
* **Public Registry** – An issuer profile page and per-credential program pages with a sample certificate image and earner count.
* **Credential Directory** – A searchable public directory of credential holders, listed according to your site policy and per-certificate consent.
* **Organization-Branded Verification** – The verification page carries the issuing organization's logo, accent color, intro, and footer.
* **Award Past Completions** – Find everyone who completed a course, lesson, quiz, or assignment before the trigger existed, preview every row, and award them in one run.
* **Email Copies** – Per-template CC and BCC on award emails, plus optional copies to LearnDash Group Leaders and PressPrimer Teachers.

**Enterprise** *(everything in School, plus)*

* **Audit Log** – A single, filterable record of who did what and when across the suite, with per-category retention controls and CSV export.
* **White Label** – Run the certificate system under your own brand across the admin, every email, new PDFs, and the public verification page.
* **Verification API and Embeddable Widget** – A key-authenticated JSON endpoint that answers what the verification page shows, and a drop-in widget partners paste into their own pages.

= Perfect For =

* **Course creators** using LearnDash, LifterLMS, Tutor LMS, or LearnPress who need real, verifiable certificates
* **Training providers** whose completion certificates must survive audits and employer checks
* **Universities and schools** issuing program or workshop credentials
* **Membership and community sites** recognizing achievements with shareable certificates
* **Standalone WordPress educators** issuing certificates manually (no LMS is required)

= Built-in Integrations =

PressPrimer Certificate automatically detects and integrates with:

**PressPrimer Quiz:** Award a certificate when a learner passes a quiz, with quiz score and title merge fields.

**PressPrimer Assignment:** Award a certificate when an assignment is graded and passed, with grade and assignment merge fields.

**LearnDash:** Award on course, lesson, topic, or quiz completion, with associated merge fields.

**LifterLMS:** Award on course or quiz completion.

**Tutor LMS:** Award on course or quiz completion.

**LearnPress:** Award on course or quiz completion, including quizzes inside course sections.

Every integration also offers "Any" options, so one template can cover every course or quiz without a trigger per item.

All integrations are bundled in the free version, and the plugin is still fully functional if you don't use an LMS plugin.

= Built for Developers =

* Public `ppcert_*()` functions for issuing, rendering, and linking certificates from your own plugin
* Action hooks for issuance, revocation, and verification events
* Filter-based registries for custom trigger types (including value-only "threshold" triggers) and merge fields
* REST API support
* Custom database tables with automatic schema migration

= Documentation & Support =

* [Knowledge Base](https://pressprimer.com/knowledge-base/pressprimer-certificate/)

= Source Code & Development =

The full uncompressed source code for all JavaScript and CSS files is available in our public GitHub repository:

* [GitHub Repository](https://github.com/PressPrimer/pressprimer-certificate)

The `/src` directory contains all unminified source files. The plugin uses webpack for building production assets. To rebuild from source:

1. Clone the repository
2. Run `npm install` to install dependencies
3. Run `npm run build` to compile assets

== Installation ==

= Automatic Installation =

1. Go to **Plugins > Add New** in your WordPress admin
2. Search for "PressPrimer Certificate"
3. Click **Install Now** and then **Activate**
4. Navigate to **Certificates** in your admin menu; the setup tour will walk you through your first certificate

= LMS Integration =

If you have PressPrimer Quiz, PressPrimer Assignment, LearnDash, LifterLMS, Tutor LMS, or LearnPress installed, their triggers are enabled automatically. Open a certificate template's Award tab to connect it, choose a specific course or quiz, or an "Any" option to cover them all.

== Privacy ==

PressPrimer Certificate stores certificate data (templates, issued certificates, recipient references, and issuance events) in your WordPress database under your full control. PDF and QR code generation happen locally on your server. No certificate or recipient data is ever transmitted to external servers. The single exception to "nothing leaves your site" is the optional email-course opt-in described under External Services; it sends only an email address that an administrator explicitly typed in and submitted.

The plugin integrates with the WordPress Privacy API:

* **Tools > Export Personal Data** includes all certificates issued to the requested user.
* **Tools > Erase Personal Data** permanently deletes the requested user's certificates.

Verification and view events are stored without IP addresses or user agents, and prunable event rows are cleaned up on a configurable retention schedule. Administrators can permanently delete all plugin data via Settings > Advanced > "Remove all data on uninstall" before uninstalling the plugin.

== External Services ==

This plugin offers an optional free email course for administrators. When (and only when) a user types their email address into the opt-in form and clicks the subscribe button, the plugin connects to pressprimer.com to register the subscription.

* **When:** Only on an explicit opt-in submission. No request is ever made automatically (no telemetry, no activation pings, no environment data).
* **What data:** The typed email address and a tag naming which screen the form was on. Nothing else.
* **Unsubscribing:** Every email includes an unsubscribe link, honored immediately.
* **Terms of Service:** https://pressprimer.com/terms-conditions/
* **Privacy Policy:** https://pressprimer.com/privacy-policy/

Dismissing the offer is remembered permanently and is stored only on your own site.

== Third-Party Libraries ==

The plugin bundles the following open-source software (nothing is loaded from a CDN and no external requests are made):

* **TCPDF** (6.11.3, pinned) — LGPL-3.0 by Nicola Asuni / Tecnick.com — renders certificate PDFs on your server. Its bundled barcode module also generates the QR codes. https://github.com/tecnickcom/TCPDF
* **Recharts** (3.x) — MIT — draws the dashboard's awarded-over-time chart. Compiled into the plugin's script bundles. https://github.com/recharts/recharts
* **Day.js** (1.x) — MIT — date handling in the admin apps. Compiled into the plugin's script bundles. https://github.com/iamkun/dayjs

The bundled fonts are all licensed under the SIL Open Font License 1.1, with license files shipped alongside each family: Playfair Display, Source Sans 3, EB Garamond, Quicksand, Great Vibes, and Alex Brush.

== Frequently Asked Questions ==

= Is this really free, or is it a limited trial? =

It's genuinely free and not locked down. PressPrimer Certificate includes unlimited templates, unlimited certificates, the full designer, plugin integrations, public verification, and email delivery in the free version. Optional Educator, School, and Enterprise add-ons add features such as custom fonts, bulk awarding, issuing organizations, and an audit log; the Upgrade page under Certificates compares them, and the free plugin never loses a feature.

= Does this plugin require an LMS? =

No. PressPrimer Certificate is fully functional with no LMS installed; you can design certificates and issue them manually to any user. When PressPrimer Quiz, PressPrimer Assignment, LearnDash, LifterLMS, Tutor LMS, or LearnPress is detected, automatic issuance triggers become available.

= Does it work with PressPrimer Quiz and PressPrimer Assignment? =

Yes. Quiz pass thresholds and Assignment grades are included triggers with their own merge fields, so a certificate can show the score a learner earned.

= How does verification work? =

Every certificate can include a unique credential ID and/or a QR code. Scanning the QR code, clicking it, or typing the ID into your site's verification page shows whether the credential is valid, revoked, or expired, along with the recipient name, what it was awarded for, and the issue date. No account is needed to verify.

= How do learners see the certificates they have earned? =

Three ways. Each certificate is emailed as a PDF the moment it is issued. Learners can also visit a My Certificates page you create with the included block or `[ppcert_my_certificates]` shortcode, which lists everything they have earned with verify and download links, status filters, and sorting. And every certificate has its own shareable view page with the certificate image and a PDF download.

= Can I put a link to a learner's certificate on the course page itself? =

Yes. Add the Certificate Link block (or the `[ppcert_certificate_link]` shortcode) to a course, lesson, topic, or quiz page, or to any page where you have placed a PressPrimer quiz or assignment with its block or shortcode. Each logged-in learner sees one button that opens their own certificate for that item, and nothing at all until they have earned it. If the learner has earned it more than once, the newest certificate is linked. Add a message in the block's settings and it appears in a panel with the button, only when the button shows, so you can congratulate the learner and explain the certificate without that text stranding on the page for everyone else. Point the block at a specific quiz, assignment, or post by ID, or at a template, when the page itself is not the source.

= Can a verifier download the PDF from the verification page? =

Yes. Valid and expired certificates show a Download PDF button on the verification page, so an employer or registrar can keep a copy. Revoked certificates do not offer a download.

= What happens if I edit a template after certificates have been issued? =

Nothing changes for existing certificates. Each certificate snapshots its design and data at issue time, so a certificate always looks exactly as it did the day it was earned. That includes the display name: a certificate keeps the name it was issued with. Template edits only affect future issuance.

= Can one certificate template cover all of my courses? =

Yes. On a template's Award tab, choose an "Any" option instead of a specific course, quiz, or lesson, and that one trigger awards for every item of that type (lessons and topics are scoped to a course you pick). Pair it with a certificate display name like "{{source.course_title}} Certificate" and each certificate is named after what earned it in the My Certificates list, on the verification page, and in emails.

= Can a merge field sit inside a sentence? =

Yes. In any text element, put the cursor where the value belongs and use the Insert Merge Field button. The line centers, wraps, and styles as one piece, so "Awarded to {{recipient.display_name}} on [date]" behaves like a single line of text. Merge fields also work in the certificate email's subject and body.

= Can someone edit a certificate PDF and change the name? =

Certificate PDFs are generated with AES-256 protection that denies editing, so Acrobat and similar tools refuse to modify the text (unlike plain LMS certificates, which anyone can retype). Printing and text copying remain available. More importantly, every certificate's QR code and credential ID point to the verification page on your site, where the authoritative name, date, and status live. A tampered or fabricated PDF immediately mismatches what a verifier sees.

= What page sizes do certificates use? =

Letter and A4, in landscape or portrait. Every starter design ships in both sizes, and the PDF renders at print quality from the same layout you see on the canvas.

== Screenshots ==

1. Dashboard with award statistics, quick actions, and recent certificates
2. The certificate designer: a true-size canvas, element palette, and properties panel
3. Starter template gallery with Letter and A4 variants
4. Design defaults: brand colors, logo, signature, and certificate size
5. The My Certificates page where learners view, verify, and download their certificates

== Changelog ==

= 2.0.0 =
* Added: Search and filters on the Certificates screen. Find certificates by recipient, certificate name, or exact credential ID, and filter by status, template, and issue date range.
* Added: Send yourself a test award email from the template editor, with sample values filled in, before the template goes live.
* Added: Four Geometric starter templates - a modern design family drawn from your brand colors, in portrait and landscape for both A4 and Letter sizes.
* Added: Multi-page certificate rendering. Certificates designed with multiple pages (created with the Educator addon) download, email, and verify as complete multi-page PDFs.
* Added: An Upgrade page under the Certificates menu comparing the free plugin with the new Educator, School, and Enterprise tiers.
* Added: Certificate Link block and shortcode. Drop one button on a course, lesson, topic, or quiz page, or on any page that contains a PressPrimer quiz or assignment, and each logged-in learner sees a link to their own certificate for that item, hidden until they earn it. Point it at a specific quiz, assignment, post, or template when the page itself is not the source, and add an optional message that appears with the button, such as congratulations and what the certificate is for.
* Added: Download PDF button on the public verification page for valid and expired certificates, so a verifier can keep a copy without an account.
* Added: WebP and AVIF images in certificate designs render in PDFs and previews on servers whose image library supports them. When a server cannot render a chosen image's format, the designer says so under the image picker and Preview PDF reports the skipped image.
* Added: Short upgrade notes for administrators on the Settings Email tab and in the designer, pointing to the Educator and School features that would appear in those spots. They disappear once the addon is active and are never shown to other users.
* Added: Public developer API. New `ppcert_*()` functions let other plugins issue certificates, render PDFs, look up a learner's certificate, and build view and download links through a supported, stable surface.
* Added: Value-only award triggers. Integrations can register triggers with no source object - points thresholds, membership tenure, credits earned - and they configure in the Award tab like any other trigger.
* Added: Third-party certificate integrations now appear with their proper names everywhere the built-in integrations do, including while their plugin is deactivated.
* Added: A duplicate-suppression scoping filter for integrations whose completion events carry per-occurrence references.
* Added: The extension points the Educator, School, and Enterprise addons build on - template settings and issuer assignment, designer sidebar tabs and slots, list columns and row actions, email sender and footer filters, PDF metadata, admin branding, template lifecycle actions, and the certificate title and directory-visibility columns. Existing data is untouched.
* Improved: Designer sidebar labels and help icons render at higher contrast.
* Improved: Send Test Email on the Email settings tab saves your unsaved changes first, so the test always reflects what is on the page.
* Fixed: The verification form's Verify button uses the same button style as the rest of the plugin, and the credential ID field is sized for a credential ID instead of stretching across the page.
* Fixed: The Valid For controls in the designer's Award tab keep the number readable while its up and down controls are showing.
* Fixed: Designer extensions such as the Educator page rail appear on a newly created template without reloading the page.
* Fixed: Custom merge-field groups registered with a sub-field named "label", "key", or "resolver" no longer disappear from the designer palette.
* Fixed: The certificate view page's preview image keeps its layout wrapper instead of rendering flush against the details panel.
* Fixed: The verification result's status colors appear on the JavaScript-enhanced page as well as the no-JavaScript page.
* Fixed: Admin styling loads on every suite screen, including addon pages, so dropdowns keep their intended focus style.
* Fixed: The Settings page shows its Save button on addon tabs.
* Fixed: When a design uses a font that is unavailable, the designer canvas substitutes the same face the PDF uses, keeping the two identical.
* Fixed: Temporary PDF files are removed through the WordPress file API, and uninstall cleanup queries use identifier placeholders throughout.

= 1.1.0 =
* Added: Merge fields now work inside text elements. 
* Added: "Any" award options in the trigger picker. One trigger can award for any quiz, any course, or any lesson in a chosen course, so a single certificate covers your whole catalog.
* Added: The QR code and credential ID on issued PDFs are now clickable links to the certificate's verification page.
* Added: Alignment tools in the designer. Align and distribute selected elements from the toolbar, and matching-margin guides show measurements when two elements sit the same distance from opposite page edges.
* Added: Certificate merge fields in the issuance email subject and body, so the email can name the exact course or quiz that was completed.
* Added: Certificate display names. Set a display name pattern on the Award tab, like "{{source.course_title}} Certificate", and each certificate is named after what earned it in the My Certificates list, on its view and verification pages, in the admin list, and in emails. 
* Improved: Sample values in the designer's merge field menus render at higher contrast.

= 1.0.0 =
* Initial release: certificate designer, credential IDs, QR verification, automatic issuance from PressPrimer Quiz, PressPrimer Assignment, LearnDash, LifterLMS, Tutor LMS, and LearnPress, email delivery, and a public verification page.

== Upgrade Notice ==

= 2.0.0 =
Certificate search and filters, test award emails, Geometric starter templates, a Certificate Link block, PDF download on the verification page, multi-page rendering, a public developer API, and the new Educator, School, and Enterprise premium tiers. Existing certificates and templates are unchanged.

= 1.1.0 =
Merge fields inside text, "Any" award triggers, certificate names from merge fields, clickable PDF credentials, designer alignment tools, and email merge fields. Existing certificates and templates are unchanged.

= 1.0.0 =
Initial release of PressPrimer Certificate. Design, issue, and verify certificates on your own site — free forever.
