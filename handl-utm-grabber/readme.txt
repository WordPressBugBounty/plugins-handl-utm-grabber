=== HandL UTM Grabber / Tracker ===
Contributors: haktansuren
Tags: utm,tracker,gclid,tracking,utm tracking,grabber,shortcodes,contact form 7,leads,collect,collect leads
Requires at least: 3.6.0
Tested up to: 6.9
Stable tag: 2.8.4
Requires PHP: 5.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

The WordPress attribution plugin used by over 200,000+ sites to capture UTMs, gclid, and source data in your forms, CRM, and revenue workflows.

== Description ==
= UTM Tracking for WordPress (Built for Attribution) =

UTM Grabber helps marketing teams reduce attribution loss between click and conversion.

It captures UTM parameters and click IDs on first visit, stores them in first-party cookies, and makes them available site-wide so your forms, CRM syncs, and checkout flows keep campaign context intact.

Trusted by over **200,000 WordPress sites**, UTM Grabber is built for teams that need reliable source data to scale what works.

> #### What you get in the free plugin
>
> * Capture the core attribution parameters: `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, and `gclid`
> * Store attribution data in first-party cookies
> * Use shortcodes to inject tracking values into forms and content
> * Track key context fields like referrer and landing page
> * Push clean attribution data into major workflows (forms, WooCommerce, CRM automations)
> * Includes iOS 14+ friendly tracking support

= Why teams choose UTM Grabber =

* **Attribution continuity:** Preserve campaign context beyond the first pageview and across the customer journey.
* **First-party data model:** Your attribution data stays in your WordPress stack.
* **Built for real WordPress setups:** Designed for form-heavy, plugin-heavy, and marketing-driven sites.
* **Fast to deploy:** Activate, map fields, and start collecting usable source data quickly.

= Major integrations and compatibility =

UTM Grabber works with the most-used WordPress forms and revenue plugins out of the box:

* Contact Form 7
* Gravity Forms
* Ninja Forms
* Elementor
* WPForms
* Formidable Forms
* Caldera Forms
* Thrive Leads
* WooCommerce
* Ultimate Member
* Fluent Forms
* Forminator
* Typeform
* Webflow
* and many more

Need CRM or ops workflow delivery? UTM Grabber integrates with automation platforms so you can route attribution to tools like:

* Salesforce
* HubSpot
* Pipedrive
* Zoho
* ActiveCampaign
* Google Sheets
* Slack
* Mailchimp
* Klaviyo
* and many others via Zapier/Make

= Upgrade to HandL UTM Grabber V3 =

Upgrade to unlock the full attribution stack and advanced controls:  
[**See all premium features**](https://utmgrabber.com/?utm_source=WordPress_FREE&utm_medium=wordpress_update_link&utm_campaign=HandL_Plugin_Page)

* Facebook Conversion API (FB CAPI) workflows for paid social and WooCommerce
* AI-Powered Insights for anomaly detection and optimization recommendations
* GCLID Reporter for Google Ads click tracking and offline conversion workflows
* Track all UTM + advanced click IDs (`fbclid`, `gclid`, `msclkid`, ValueTrack) and custom parameters
* First-touch and last-touch attribution fields
* Track source categories (Paid, Organic, Referral, Direct)
* Customize cookie duration to match your sales cycle
* Server-side and client-side tracking options
* Google Analytics client ID tracking
* Cross-domain/iframe attribution passing
* Site-to-site (S2S) postback options
* Expanded integrations for forms, CRMs, and automation workflows
* GDPR-ready setup with broad consent-tool compatibility

= Documentation, support, and community =

* [Documentation](https://docs.utmgrabber.com/?utm_medium=referral&utm_source=wordpress.org&utm_campaign=HandL+UTM+Grabber+Readme&utm_content=Documentation)
* [Leave a review](https://wordpress.org/support/view/plugin-reviews/handl-utm-grabber#postform)
* [Join support Slack](https://www.haktansuren.com/slack-handlwp/)

**SPECIAL THANKS:** This plugin has been tested on various operating systems and browsers thanks to <a href='https://www.browserstack.com'>BrowserStack!</a>

== Installation ==

1. Upload `handl-utm-grabber` to the `/wp-content/plugins/` directory (or install from WordPress Plugins).
2. Activate the plugin through the `Plugins` menu in WordPress.
3. Add hidden fields/shortcodes to your forms (Contact Form 7, Gravity Forms, Ninja Forms, Elementor, etc.).
4. Submit a test lead with UTM parameters and confirm values are captured correctly.

== Frequently Asked Questions ==

= Is this plugin really suitable for serious attribution work? =
Yes. UTM Grabber is built specifically for attribution accuracy on WordPress and is trusted by over 200,000 sites. If your team runs paid traffic and needs dependable source data in forms and CRM, this is exactly what it is designed for.

= What can I track with the free version? =
The free plugin tracks core UTM parameters (`utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`) plus `gclid`, along with key context like landing page and referrer.

= How long does attribution data persist? =
In the free plugin, tracking cookies persist for 30 days. In V3, you can customize the duration to fit your funnel and sales cycle.

= Can I use this with WooCommerce? =
Yes. UTM Grabber includes WooCommerce support so order-level records can retain campaign/source context for better revenue attribution.

= Can I send attribution data to my CRM? =
Yes. You can pass data through supported form integrations and automation tools (including Zapier/Make) into CRMs such as HubSpot, Salesforce, Zoho, and others.

= Does V3 include FB CAPI, AI Insights, and GCLID reporting? =
Yes. HandL UTM Grabber V3 includes Facebook CAPI workflows, AI-Powered Insights, and the GCLID Reporter, plus expanded attribution controls for paid media and offline conversion reporting.

= Is UTM Grabber GDPR-friendly? =
Yes. UTM Grabber is built with a first-party data approach and supports GDPR-oriented tracking workflows. Advanced compliance controls are available in V3.

== Screenshots ==

1. It should look like this after install.
1. Gravity Form Integration.
1. Salesforce Integration.
1. Append UTM variables to all URLs automatically.
1. Contact Form 7 Integration
1. Elementor Integration
1. Ninja Forms Integration
1. Ultimate Member Integration
1. WooCommerce to Webhook/Postback/IPN Integration
1. WPForms Integration
1. Zapier Integration

== Changelog ==
= 2.8.4 =
* Promotional updates & improvements

= 2.8.3 =
* Security: Improved output escaping and sanitization
* Security: Added direct file access protection

= 2.8.2 =
* New: Added Elementor integration for tracking UTM parameters in forms

= 2.8.1 =
* Security: Fixed reflected XSS vulnerabilities in shortcode output
* Security: Improved input sanitization for UTM and tracking parameters following WordPress best practices
* Security: Added context-aware output escaping using esc_html() and esc_url()
* Security: Removed urldecode() from output to prevent double-encoding bypass attacks
* Security: Implemented "sanitize early, escape late" pattern throughout the codebase

= 2.8 =
* Brand new interface
* Fixed UI bugs and improved user experience in Contact Form 7 integration

= 2.7.32 =
Contact Form 7 Bugfix for 6.1

= 2.7.31 =
* Added shortcuts for Contact Form 7 integration and more documentation for CF7 and Gravity

= 2.7.30 =
* Added httponly cookie setting (default is still httponly=false)

= 2.7.29 =
* Fixed ninja form check for site status health check

= 2.7.28 =
* Fixed append UTM to all the links bug (potential XSS vulnerability)

= 2.7.27 =
* Fixed decoding problem

= 2.7.26 =
* Added more to the knowledge base. Fixed PHP8.1 related bug as well.

= 2.7.25 =
* Introduced knowledge base

= 2.7.24 =
* Dr. UTM Lab release and other notifications.

= 2.7.23 =
* Fixing the append utm feature bug

= 2.7.22 =
* Fixing fatal error caused by 2.7.21

= 2.7.21 =
* Gravity form improvements

= 2.7.19 =
* Best practise for tracking on IOS 14 has been added. Dead links are corrected.

= 2.7.18 =
* Plugin maintenance and cleanups

= 2.7.17 =
* GCLID Reporter apps released.

= 2.7.16 =
* Bugfix: Potential XSS fix. Big shout out to Marcos Oliveira (@marcosvixtor). Thanks for reporting it.

= 2.7.15 =
* Bugfix: Subscribers can see UTM on the Toolbar. Thanks @risoedus for reporting

= 2.7.14 =
* Some new seasonal notifications added.

= 2.7.13 =
* UTM Grabber notifications added.

= 2.7.12 =
* Health check and best practice of collecting UTMs in Ninja Forms

= 2.7.11 =
* New domain update.

= 2.7.10 =
* Health check and best practice of collecting UTMs related to caching. Check "site health" page to make sure you are not missing any data.

= 2.7.9 =
* Health check and best practice of collecting UTMs in Gravity Forms

= 2.7.8 =
* BugFix: UTM field values having `%` was breaking the JavScript.
* Coming Soon: UTM Grabber smart troubleshooting / notifications

= 2.7.7 =
* Health check and best practice of collecting UTMs in Contact Form 7

= 2.7.6 =
* Adding new health check for website audit and tracking js bug fix for utm-out

= 2.7.5 =
* Fixing WP CLI related problems.

= 2.7.4 =
* Improvements, plugin page description change, screenshot added. PHP 5.4 fix for array notation.

= 2.7.3 =
* Various improvements including menu bar for UTM and health check recommendations. 

= 2.7.2 =
* fix for headers already sent. Thanks for reporting [@labatt](https://wordpress.org/support/topic/php-warning-cannot-modify-header-information-headers-already-sent-5/)

= 2.7.1 =
* fix for null coalescing operator for PHP < 7.0 compatiblility

= 2.7 =
* Zapier integration added for Contact Form 7, Ninja Form, Gravity Form

= 2.6.6 =
* simple_html_dom.php dependency upgraded to the latest

= 2.6.5 =
* Critical Bug Fix: Possible cross-site request forgery (CSRF) due to add_option, update_option usage

= 2.6.4 =
* Varnish cache and WP Engine workaround fix (JS based COOKIE save)
* 502 error fixed. Possibly caused by printed text before we set the COOKIES. 
* PHP 7.3 related warnings due to simple_html_dom.php fixed

= 2.6.3 =
* BUG FIX: https://wordpress.org/support/topic/php-notice-undefined-index-ninja-php/ & https://wordpress.org/support/topic/php-deprecated-function/

= 2.6.2 =
* NEW FEATURE: append UTM parameters to all the anchor tag (<a>) having class “.utm-out”

= 2.6.1 =
* Absolutely nothing, just trying to fix the version

= 2.6.0 =
* Ninja Form Merge Tags implemented for all the variables used in HandL UTM Grabber / Tracker (e.g. {handl:utm_campaign})

= 2.5.13 =
* localhost cookie problem fixed.  

= 2.5.12 =
* Bugfix for [handl_landing_page] and [handl_url]: the URL was not populating on secure sites (https://). Subdomain suport for all the UTM variables and other shortcodes. Special thanks to David W for sponsoring the update. 

= 2.5.11 =
* Security Bugfix: Potential XSS attack using cookies. Special thanks to Robert Tubridy for reporting. 

= 2.5.10 =
* Bugfix: Fix for append UTM variables to all the links: it was adding the UTMs even though the feature is turned off.

= 2.5.9 =
* Bugfix: Initialize SERVER variables and fix nav_menu_link_attributes  

= 2.5.8 =
* Bugfix: Visual Composer Accordion/Tabs fix (Append UTM feature conflict). Thanks [@radasonea](https://wordpress.org/support/topic/accordion-visual-doesnt-work-after-plugin-activates/)   

= 2.5.7 =
* Bug-fix caused by v2.5.7.

= 2.5.6 =
* Append UTM fix for WP menu, new shortcodes: [username] and [email]

= 2.5.5 =
* Fix for the JS in footer for website uses minify JS (Thanks [sylvainww](https://wordpress.org/support/topic/plugin-adds-uncaught-referenceerror-2/))

= 2.5.4 =
* Added CouponHut theme support (Thanks [zizzi17](https://wordpress.org/support/topic/append-to-all-urls-works-only-partially/))

= 2.5.3 =
* WooCommerce support: All parameters (UTM and others) are appended to the corresponding order's meta when available. 

= 2.5 =
* One click to aappend UTM variables to all the links on your site. 

= 2.3 =
* Fix for php close tag at the end of the file. 

= 2.2 =
* New shortcodes added for leads tracking (e.g. Original Referral URL, Referral URL, IP, Landing Page etc.)

= 2.1 =
* Shortcode support for CF7 and Salesforce (Thanks to jenrstretch and wpkmi)

= 2.0 =
* Hassle Free Implementation (No Shortcode)

= 1.4 =
* Gravity Forms support added (Thanks [hashimwarren](https://wordpress.org/support/topic/gravity-forms-45 ))

= 1.3 =
* BugFix for Text Widget (Thanks [eddygbarrett](https://wordpress.org/support/topic/handl-not-working))

= 1.2 =
* BugFix for Contact Form 7 (Thanks [wpkmi](https://wordpress.org/support/topic/contact-form-7-form-submission-hangs-when-utm-grabber-plugin-is-enabled))

= 1.1 =
* Shortcodes changed to support form input
* World's most effective written code :)

= 1.0 =
* Hello World :)


== Upgrade Notice ==

= 1.0 =
HandL UTM Grabber's birthday :)
