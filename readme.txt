=== PAP Texturize ===
Contributors: gitlost
Tags: Texturize, wptexturize
Requires at least: 4.6
Tested up to: 4.6.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Patch-as-plugin that texturizes text containing inline HTML tags.

== Description ==

Replaces the WP native wptexturize() filter with a patched version that
fixes texturization in the presence of inline HTML tags.

See trac ticket #18549.

Also includes fix for embedded quotes (quotes inside quotes), #29882.
