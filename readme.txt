=== BE PlumX Metrics ===
Contributors: bigears
Tags: plumx, metrics, doi, open access, consent
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.2.6
License: GPLv2 or later

== Changelog ==
= 0.2.6 =
- Fix: undefined $title_html variable in card renderer caused PHP notice and missing card title output

= 0.2.5 =
- New: "hide until ready" rendering – cards without consent are hidden by default and only shown when PlumX actually renders content
- Blocked state shows only the consent UI first; the metrics body appears after click
- Ensures the "Metrics" H2 can never be visible on its own when PlumX returns no data
