=== PressPrimer Certificate – Certificate Designer, Credential IDs & Public Verification ===
Contributors: pressprimer
Tags: certificate, credentials, verification, lms, learndash
Requires at least: 6.4
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Design, issue, and verify certificates on your own site. Drag-and-drop designer, credential IDs, QR verification, and LMS-agnostic issuance.

== Description ==

**Note: PressPrimer Certificate is in development. This readme is a working draft and will be completed before WordPress.org submission.**

PressPrimer Certificate brings the full credential lifecycle to a site you own. A drag-and-drop designer produces the certificate; unique credential IDs, QR codes, and a public verification page make it checkable; and issuance rides real evidence — PressPrimer Quiz pass thresholds, PressPrimer Assignment grades, or course completion in LearnDash, LifterLMS, Tutor LMS, or LearnPress.

Certificates, templates, and issuance records are independent of any one LMS. Switching LMSs keeps every credential intact.

= Features =

* **Template-first canvas designer** — true-size WYSIWYG editing with starter templates, bundled open-license fonts, and sample data always visible
* **Merge fields** — recipient, certificate, site, quiz, assignment, and course data, including custom user meta and post meta
* **Evidence-based issuance** — manual issuance plus automatic triggers from PressPrimer Quiz, PressPrimer Assignment, and four LMS course-completion events
* **Credential IDs and QR codes** — every certificate carries a unique, non-guessable credential ID and a QR code linking to its verification page
* **Public verification** — anyone can check a credential's validity, status, and details on your site
* **Recipient wallet** — a My Certificates view with PDF downloads, available as a shortcode and a block
* **Print-quality PDFs** — 300 DPI output rendered on your server

= Privacy =

PDF and QR code generation happen locally on your server. All plugin data stays in your WordPress database and is preserved on uninstall unless you explicitly opt in to data removal. No certificate or recipient data is ever transmitted to external servers. The single exception to "nothing leaves your site" is the optional email-course opt-in described under External Services — it sends only an email address that an administrator explicitly typed in and submitted.

== External Services ==

This plugin offers an optional free email course for administrators. When — and only when — a user types their email address into the opt-in form and clicks the subscribe button, the plugin connects to pressprimer.com to register the subscription.

* **When:** Only on an explicit opt-in submission. No request is ever made automatically — no telemetry, no activation pings, no environment data.
* **What data:** The typed email address and a tag naming which screen the form was on. Nothing else.
* **Unsubscribing:** Every email includes an unsubscribe link, honored immediately.
* **Terms of Service:** https://pressprimer.com/terms/
* **Privacy Policy:** https://pressprimer.com/privacy/

Dismissing the offer is remembered permanently and is stored only on your own site.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/pressprimer-certificate/`, or install through the WordPress plugins screen
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to PPCert Certificates in the admin menu to design your first certificate

== Frequently Asked Questions ==

= Does this plugin require an LMS? =

No. PressPrimer Certificate is fully functional with no LMS installed — you can design certificates and issue them manually. When LearnDash, LifterLMS, Tutor LMS, or LearnPress is detected, course-completion triggers become available automatically.

= Does it work with PressPrimer Quiz and PressPrimer Assignment? =

Yes. Quiz pass thresholds and Assignment grades are first-class issuance triggers with their own merge fields.

== Screenshots ==

1. Certificate designer (coming before submission)

== Changelog ==

= 1.0.0 =
* Initial release.
