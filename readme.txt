=== PressPrimer Certificate – Certificate Designer & Course Certification for Your LMS ===
Contributors: pressprimer
Tags: certificate, certification, lms, learndash, elearning
Requires at least: 6.4
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Design, issue, and verify certificates on your own site. A drag-and-drop designer, automatic awards from popular LMS plugins, and scannable QR proof.

== Description ==

**PressPrimer Certificate** brings the full certification lifecycle to your WordPress site. A drag-and-drop designer produces the certificate; unique credential IDs, QR codes, and a public verification page make it checkable; and it can all be natively connected to course activity in LearnDash, LifterLMS, Tutor LMS, or LearnPress.


**This is a genuinely free plugin.** Unlimited templates, unlimited certificates, the full designer, a suite of integrations, public verification, and email delivery are all included at no cost. 

= Why PressPrimer Certificate? =

Certificate tools built into LMS plugins are often afterthoughts: rigid and awkward layouts, no way to verify what students have earned, and no easy way to track certificate activity. Standalone certificate services charge monthly fees and hold your credentials on someone else's infrastructure.

PressPrimer Certificate delivers a complete, self-hosted credential workflow:

* **A true-size designer** – The canvas is the PDF. What you arrange on screen is exactly what recipients download, at print quality.
* **Credentials that can be checked** – Every certificate carries a unique, non-guessable credential ID and a QR code linking to a public verification page on your site.
* **Comprehensive integrations** – Certificates award themselves when a learner passes a quiz, earns a grade, or completes a course. Manual issuance is always available.
* **Records that outlive the template** – Issued certificates snapshot their design and data at issue time. Editing a template later never changes a certificate that has already been earned.

= Features Included Free =

**Certificate Designer**

* True-size WYSIWYG canvas with drag, resize, keyboard nudging, undo/redo, and zoom
* Six starter designs, each in Letter and A4, plus a blank option
* Six bundled open-license fonts (SIL OFL) with real bold and italic faces
* Text, merge field, image, signature, line/shape, QR code, and background elements
* Merge fields for recipient, certificate, site, quiz, assignment, and course data, including custom user meta and post meta
* Live PDF preview from the real renderer

**Design Defaults**

* Site-wide defaults for certificate size (Letter or A4), font, logo, and signature
* Brand colors (primary, accent, text) that starter templates apply automatically

**Issuance & Automation**

* Automatic triggers: PressPrimer Quiz pass thresholds, PressPrimer Assignment grades, and course, lesson, topic, or quiz completion in LearnDash, LifterLMS, Tutor LMS, and LearnPress
* Manually issue certificates with recipient search, a backdatable earned date, and an optional expiry date
* Validity periods: set how long certificates from a template stay valid (days, months, or years), pick an exact expiry date, or let them last forever
* Per-trigger control over repeat completions: suppress duplicates (the default) or issue a fresh certificate every time, for compliance and recertification courses
* Revoke certificates (with an optional reason) and reinstate them if revoked by mistake

**Credentials & Verification**

* Unique, non-guessable credential IDs with a check that catches typos
* Public verification page (shortcode and block) that anyone can use, no account needed
* Optional QR code on every certificate linking to its verification page
* A shareable certificate view page with PDF download
* My Certificates list (shortcode and block) so logged-in learners can see, verify, and download everything they have earned, with status filters and sorting by date, name, or upcoming expiry
* PDFs are protected against editing: Acrobat and other viewers refuse to modify the text, while printing and copying stay available
* Token placeholders for recipient, certificate, and site data

**Admin**

* Dashboard with certificate statistics, an awarded-over-time chart, quick actions, and recent certificates
* A guided setup tour that walks you from first template to first issued certificate in about five minutes
* Certificates screen with template, status, and source filters
* Resend a certificate's email with one click, and permanently delete test certificates
* Earned certificates listed on each user's profile screen with verify and download links
* Certificate templates list with trigger, page size, and status columns

**Security, Privacy & Accessibility**

* PDF and QR code generation happen locally on your server
* Capability-based access control and prepared, whitelisted database queries
* PDF files cannot be modified in Acrobat or other tools to change data like the student name
* WordPress Privacy API integration (Tools > Export/Erase Personal Data)
* Clean uninstall with optional complete data removal
* Keyboard navigation, screen reader support, and reduced motion preferences

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

All integrations are bundled in the free version, and the plugin is still fully functional if you don't use an LMS plugin.

= Built for Developers =

* Action hooks for issuance, revocation, and verification events
* Filter-based registries for custom trigger types and merge fields, the same interfaces the bundled integrations use
* REST API for templates, certificates, triggers, merge fields, and verification
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

If you have PressPrimer Quiz, PressPrimer Assignment, LearnDash, LifterLMS, Tutor LMS, or LearnPress installed, their triggers are enabled automatically. Open a certificate template's Award tab to connect it.

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
* **Terms of Service:** https://pressprimer.com/terms/
* **Privacy Policy:** https://pressprimer.com/privacy/

Dismissing the offer is remembered permanently and is stored only on your own site.

== Third-Party Libraries ==

The plugin bundles the following open-source software (nothing is loaded from a CDN and no external requests are made):

* **TCPDF** (6.11.3, pinned) — LGPL-3.0 by Nicola Asuni / Tecnick.com — renders certificate PDFs on your server. Its bundled barcode module also generates the QR codes. https://github.com/tecnickcom/TCPDF
* **Recharts** (3.x) — MIT — draws the dashboard's awarded-over-time chart. Compiled into the plugin's script bundles. https://github.com/recharts/recharts
* **Day.js** (1.x) — MIT — date handling in the admin apps. Compiled into the plugin's script bundles. https://github.com/iamkun/dayjs

The bundled fonts are all licensed under the SIL Open Font License 1.1, with license files shipped alongside each family: Playfair Display, Source Sans 3, EB Garamond, Quicksand, Great Vibes, and Alex Brush.

== Frequently Asked Questions ==

= Is this really free, or is it a limited trial? =

It's genuinely free and not locked down. PressPrimer Certificate includes unlimited templates, unlimited certificates, the full designer, automatic issuance triggers, public verification, and email delivery in the free version. We believe in earning upgrades by offering genuinely valuable features, not by crippling the free experience.

= Does this plugin require an LMS? =

No. PressPrimer Certificate is fully functional with no LMS installed; you can design certificates and issue them manually to any user. When PressPrimer Quiz, PressPrimer Assignment, LearnDash, LifterLMS, Tutor LMS, or LearnPress is detected, automatic issuance triggers become available.

= Does it work with PressPrimer Quiz and PressPrimer Assignment? =

Yes. Quiz pass thresholds and Assignment grades are included triggers with their own merge fields, so a certificate can show the score a learner earned.

= How does verification work? =

Every certificate can include a unique credential ID and/or a QR code. Scanning the QR code (or typing the ID into your site's verification page) shows whether the credential is valid, revoked, or expired, along with the recipient name, what it was awarded for, and the issue date. No account is needed to verify.

= How do learners see the certificates they have earned? =

Three ways. Each certificate is emailed as a PDF the moment it is issued. Learners can also visit a My Certificates page you create with the included block or `[ppcert_my_certificates]` shortcode, which lists everything they have earned with verify and download links, status filters, and sorting. And every certificate has its own shareable view page with the certificate image and a PDF download.

= What happens if I edit a template after certificates have been issued? =

Nothing changes for existing certificates. Each certificate snapshots its design and data at issue time, so a certificate always looks exactly as it did the day it was earned. Template edits only affect future issuance.

= Can someone edit a certificate PDF and change the name? =

Not casually. Certificate PDFs are generated with AES-256 protection that denies editing, so Acrobat and similar tools refuse to modify the text (unlike plain LMS certificates, which anyone can retype). Printing and text copying remain available. More importantly, every certificate's QR code and credential ID point to the verification page on your site, where the authoritative name, date, and status live. A tampered or fabricated PDF immediately mismatches what a verifier sees.

= What page sizes do certificates use? =

Letter and A4, in landscape or portrait. Every starter design ships in both sizes, and the PDF renders at print quality from the same layout you see on the canvas.

== Screenshots ==

1. Dashboard with award statistics, quick actions, and recent certificates
2. The certificate designer: a true-size canvas, element palette, and properties panel
3. Starter template gallery with Letter and A4 variants
4. Issuing a certificate manually with recipient search and earned date
5. The public verification page confirming a credential
6. Design defaults: brand colors, logo, signature, and certificate size
7. The My Certificates page where learners view, verify, and download their certificates

== Changelog ==

= 1.0.0 =
* Initial release: certificate designer, credential IDs, QR verification, automatic issuance from PressPrimer Quiz, PressPrimer Assignment, LearnDash, LifterLMS, Tutor LMS, and LearnPress, email delivery, and a public verification page.

== Upgrade Notice ==

= 1.0.0 =
Initial release of PressPrimer Certificate. Design, issue, and verify certificates on your own site — free forever.
