# Pausing comments and forms

A freeze protects editorial content. Visitors write to the database too:
every comment, and every contact or lead form that stores its entries, is a
write that can be lost when the database is replaced. Stillgate can pause
both for the length of a freeze. It is **off by default**; turn it on under
Coverage on the settings screen, or:

```bash
wp stillgate scope --comments=on --forms=on
```

Who is paused: anyone who is not allowed to bypass the freeze, which means
anonymous visitors and logged-in people without the `deployment_guard_bypass`
capability. People who can bypass can still comment and submit forms.

When it is checked: when a submission arrives, never when a page is shown.
An ordinary page view does not read the freeze state at all, so a full-page
cache cannot serve a stale "closed" page that outlives the freeze, and a form
cannot be shown and then fail without explanation.

Every refusal is counted in the [freeze receipt](receipt.md). That is one small
database write per refused submission.

## Comments

New comments, reviews, pingbacks and trackbacks are refused through the
comment form, the REST API and XML-RPC, with the message "Comments are
temporarily paused while the site is being updated." The REST API answers
`423`.

- Internal comment types (WooCommerce order notes, for example) are not
  paused.
- Comments inserted straight with `wp_insert_comment()` (importers,
  `wp comment create`) do not pass through the check that Stillgate uses and
  are not paused.
- WordPress runs its own checks first (flood protection, duplicate comment,
  comments closed). A request refused by one of those gets WordPress's answer
  and is not counted by the receipt.
- Product reviews from visitors are comments and are paused with them.

## Forms

WordPress has no single place every form passes through, so Stillgate
recognizes a submission by what the request looks like: an `admin-ajax.php`
action, a REST route, or a field in an ordinary POST. It does not read or
change anything about a form itself, and a request is only inspected if it
already looks like one of those.

| Plugin | How the submission arrives | Recognized by |
| --- | --- | --- |
| Contact Form 7 | REST (and an ordinary POST without JavaScript) | route `/contact-form-7/v1/contact-forms/<id>/feedback`; field `_wpcf7` |
| WPForms | `admin-ajax.php` (and an ordinary POST) | action `wpforms_submit`; field `wpforms` |
| Ninja Forms | `admin-ajax.php` | action `nf_ajax_submit` |
| Fluent Forms | `admin-ajax.php` | action `fluentform_submit` |
| Forminator | `admin-ajax.php`, or an ordinary POST with AJAX off | action and field `action=forminator_submit_form_custom-forms` |
| Formidable | `admin-ajax.php`, or an ordinary POST | action `frm_entries_create`; field `frm_action=create` |
| Everest Forms | an ordinary POST by default, `admin-ajax.php` with AJAX on | field `everest_forms`; action `everest_forms_ajax_form_submission` |
| Gravity Forms | REST, ordinary POST | route `/gf/v2/forms/<id>/submissions`; field `gform_submit` |
| Elementor Pro forms | `admin-ajax.php` | action `elementor_pro_forms_send_form` |

**Checked on real sites:** Contact Form 7, WPForms, Ninja Forms, Fluent Forms,
Forminator, Formidable and Everest Forms were each installed on a throwaway
WordPress site, submitted with the freeze off, refused with it on, and
submitted again as an administrator and after the freeze ended. In every case
no entry was stored and no notification was sent while the submission was
refused. What the visitor sees is described below.

**Not tested:** Gravity Forms and Elementor Pro forms are paid plugins and
were not installed. They are recognized by their known request shapes and are
refused, but Stillgate cannot promise what a visitor sees. Any other form is
not recognized until you add it.

## What the visitor sees

A form plugin's own script only displays a message from the server when the
answer looks like one of its own answers. Anything else ends in a spinner that
never stops, a button that stays disabled, a generic error, or no message at
all. So the refusal is shaped to what each script reads (`FormResponses`).

Each of these was run in a real browser (Chrome, driven by script) on a
throwaway site: submitted with the freeze on, retried, submitted as an
administrator, and submitted again after the freeze ended.

| Plugin | What the visitor sees while frozen | Can they retry? |
| --- | --- | --- |
| Contact Form 7 (REST) | The message in the red response box under the form. | Yes, values are kept. |
| WPForms (AJAX) | The message in a red box above the button. | Yes. |
| Ninja Forms (AJAX) | The message in red text under the button. | Yes, and the same open page submits normally once the freeze ends. |
| Fluent Forms (AJAX) | The message in small red text under the button. | Yes. |
| Forminator (AJAX) | The message in an error banner above the form. | Yes. |
| Everest Forms (AJAX on) | The message in a red banner above the form. | Yes. |
| Formidable (AJAX) | The message in a red banner above the form, without leaving the page. | Yes, and the same open page submits normally once the freeze ends. |
| Forminator with AJAX off | A bare WordPress page with the message and a "Back" link. | Yes, after Back; the entered values are restored except, in one case, a phone field. |
| Everest Forms, default mode | The same bare page and "Back" link. | Yes, after Back, with the values restored. |

In every case no entry was stored and no notification was sent while a
submission was refused, administrators were never refused, and the receipt
counted one refusal per attempt.

How each answer is shaped, and why:

- Contact Form 7, WPForms, Forminator and Everest Forms only display a message
  from a `2xx` answer. Any other status shows nothing, a stuck spinner, or a
  generic error, so these are answered with `200` and `success: false` (or
  Contact Form 7's own `aborted` status).
- Fluent Forms and Ninja Forms are answered with `423`. Fluent Forms shows a
  raw key and the message twice if given a `200`; Ninja Forms works with either.
  Ninja Forms also treats every error key except two as blocking and then
  refuses to submit again until the page is reloaded, so the message is sent
  under one of the two it treats as informational.
- Formidable calls `.replace()` on the answer, so it must be `text/html`; a
  parsed JSON answer breaks its script, and any non-2xx answer makes it submit
  the form again as an ordinary post, which lands on a bare error page and is
  counted twice.
- An ordinary form post (a plugin with AJAX off, or a browser without
  JavaScript) is refused with a WordPress error page, status `423`, the message
  and a Back link. That page is plain and unstyled; it is not a form plugin's
  own screen.

**Not checked in a browser:** the shipped build for Formidable and Ninja
Forms (their answers were proven in the browser with a throwaway shim carrying
exactly the shape now in the plugin, and the shipped code is covered by
automated tests), Formidable and WPForms with AJAX off, Ninja
Forms answered with `200`, browsers other than Chrome, anything with
JavaScript disabled, and real mail delivery (a mail recorder counted what would
have been sent). WPForms Lite and Contact Form 7 keep no entries, so "nothing
stored" was checked through the mail log for those.

A form with no adapter, including Gravity Forms and Elementor Pro forms, is
refused with a plain JSON error and a `423` status. It is not stored, but the
visitor sees whatever generic error that plugin shows for a failed request.

Every refusal that can carry a header sends `X-Stillgate-Paused: forms`: the
AJAX answers, the Contact Form 7 answer and the error page for an ordinary
post. A refusal through any other REST route is a standard REST error
(`code`, `message`, `data.status`) without it.

## Adding a form

Add the `admin-ajax.php` action, REST route or POST field your form plugin
uses with the `deployment_guard_form_requests` filter (see
[hooks.md](hooks.md)). Find them in your browser's network tab while you
submit the form: look for a `POST` request, and note its URL, its `action`
field, or its distinctive field names.

```php
add_filter(
	'deployment_guard_form_requests',
	function ( array $requests ) {
		$requests['ajax_actions'][] = 'my_form_submit';
		$requests['rest_routes'][]  = '#^/my-forms/v1/entries$#';
		$requests['post_fields']['my_form_nonce'] = null;

		return $requests;
	}
);
```

A form added this way is refused with the plain `423` answer. The text
shown to the visitor can be changed with `deployment_guard_visitor_message`.

## Shops

Checkout, orders and order notes keep working during a freeze; they are never
paused. Visitor product reviews are comments, and are paused when comments are.
Stock, sales counts and ratings change by themselves while a shop sells, so on
a shop the receipt will list products as changed. The
`deployment_guard_receipt_post_types` filter can leave `product` out of the
receipt without changing what the freeze protects.
