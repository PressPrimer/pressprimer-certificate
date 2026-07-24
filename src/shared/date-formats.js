/**
 * Admin date/time display standard.
 *
 * One format for every admin-side picker and date display: "Jul 15,
 * 2026". Change it here, and every admin surface follows (the
 * ecosystem convention set by Assignment's shared module). The
 * PHP-side equivalent for server-rendered admin strings is 'M j, Y'.
 *
 * Public-facing PHP surfaces (verification page, view page, list
 * dates) intentionally do NOT use this — they follow the site's
 * WordPress date settings so site owners and translators stay in
 * control.
 *
 * @since 1.0.0
 */

export const ADMIN_DATETIME_FORMAT = 'MMM D, YYYY h:mm A';

// Matching time-column config so every DatePicker popup looks the same.
export const ADMIN_SHOWTIME = {
	use12Hours: true,
	format: 'h:mm A',
	minuteStep: 15,
};

// Date-only variant for date pickers and compact displays.
export const ADMIN_DATE_FORMAT = 'MMM D, YYYY';
