# Extensibility hooks

Stillgate exposes a small, deliberately narrow set of hooks. Most
sites will never need any of these; they exist for the cases a filter on
protected types or a plugin with its own write paths cannot otherwise be
accommodated.

The filters marked **experimental** may still change shape in a `0.x`
release without a major version bump; the rest are considered stable from
the release that introduced them.

## `deployment_guard_protected_types` (filter, stable)

```php
apply_filters( 'deployment_guard_protected_types', array $protected );
```

`$protected` is `[ 'post_types' => string[], 'taxonomies' => string[] ]`,
the effective set of post types and taxonomies a freeze protects. Return a
modified array with the same two keys. An invalid return value (missing
either key, or a non-array value) is ignored and the default list is used
instead.

```php
add_filter(
	'deployment_guard_protected_types',
	function ( array $protected ) {
		// Never protect this specific post type.
		$protected['post_types'] = array_diff( $protected['post_types'], array( 'event' ) );

		return $protected;
	}
);
```

## `deployment_guard_is_restricted` (filter, stable)

```php
apply_filters( 'deployment_guard_is_restricted', bool $restricted, int $user_id, string $context );
```

Fires only while a freeze is active, and only for the `web` and `cli`
contexts (never for `cron`: see [architecture.md](architecture.md#actor-contexts)
for why cron cannot be exempted). `$restricted` is the capability-based
answer already computed by Stillgate; `$context` is `'web'` or
`'cli'`. Return `false` to exempt this specific actor, or `true` to
additionally restrict someone Stillgate would not otherwise have
restricted. The result is remembered for the rest of the request, per
site, user, and context.

## `deployment_guard_rest_is_protected` (filter, experimental)

```php
apply_filters( 'deployment_guard_rest_is_protected', bool $protected, WP_REST_Request $request, array $handler );
```

Fires for every REST write request Stillgate has matched to a
target. `$handler` is the matched route's handler array (see
`WP_REST_Server::dispatch()`). Return `false` to let a specific route
through regardless of Stillgate's own resolution.

A route whose controller Stillgate does not recognize at all (a
plugin's own controller class that is neither a registered post type's or
taxonomy's controller nor an instance of a core content controller) never
reaches this filter; see
[architecture.md](architecture.md#rest-target-resolution).

## `deployment_guard_submissions_paused` (filter, experimental)

```php
apply_filters( 'deployment_guard_submissions_paused', bool $paused, string $kind );
```

Fires when a visitor submission arrives (never on an ordinary page view),
after a freeze is active, the site chose to pause that `$kind` (`comments` or
`forms`, see Coverage in the settings screen), and the person submitting is
not somebody who can bypass the freeze. `$paused` is `true`. Return `false`
to let this particular submission through, for example a form that must keep
working during a freeze.

To have a form plugin that Stillgate does not recognize paused, use the
`deployment_guard_form_requests` filter below.

## `deployment_guard_visitor_message` (filter, experimental)

```php
apply_filters( 'deployment_guard_visitor_message', string $text, string $kind );
```

The plain-text message a visitor sees when a comment or form submission is
paused. `$kind` is `comments` or `forms`.

```php
add_filter(
	'deployment_guard_visitor_message',
	function ( string $text, string $kind ) {
		return 'forms' === $kind
			? 'Our forms are paused for maintenance until 14:00. Email hello@example.com instead.'
			: $text;
	},
	10,
	2
);
```

## `deployment_guard_paused_comment_types` (filter, experimental)

```php
apply_filters( 'deployment_guard_paused_comment_types', string[] $types );
```

The comment types a pause on comments applies to. The default is the types a
visitor can write: `comment` (also stored as an empty string), `review`,
`pingback` and `trackback`. Internal types such as WooCommerce order notes are
not in the list and keep working. Add a type from a plugin that stores visitor
input as comments, or remove one.

## `deployment_guard_form_requests` (filter, experimental)

```php
apply_filters( 'deployment_guard_form_requests', array $requests );
```

How Stillgate recognizes a visitor form submission. WordPress has no single
place every form passes through, so a request is recognized by what it looks
like. `$requests` has three keys:

| Key | Matches | Value |
| --- | --- | --- |
| `ajax_actions` | the `action` of an `admin-ajax.php` request | list of strings |
| `rest_routes` | the route of a `POST` REST request | list of regular expressions, matched against the route (for example `/contact-form-7/v1/contact-forms/12/feedback`) |
| `post_fields` | an ordinary form `POST` | map of field name to the value it must have, or `null` for any value |

The list of recognized forms and what each was checked against is in
[forms.md](forms.md). To cover a form plugin that is not listed:

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

A key you leave out falls back to the built-in list for that kind; to recognize
nothing of a kind, return it as an empty array. A return value that is not an
array at all is ignored and the defaults are used.

## `deployment_guard_receipt_post_types` (filter, experimental)

```php
apply_filters( 'deployment_guard_receipt_post_types', string[] $types );
```

The post types the [freeze receipt](receipt.md) inspects. The default is the
post types a freeze protects. A shop whose products change by themselves while it
sells (stock, sales counts, ratings) can leave them out so the receipt reports
editorial changes only. This does not change what a freeze protects; see
`deployment_guard_protected_types` for that. A return value that is not an array
is ignored.

```php
add_filter(
	'deployment_guard_receipt_post_types',
	static fn ( array $types ): array => array_values( array_diff( $types, array( 'product' ) ) )
);
```

## `deployment_guard_blocked` (action, stable)

```php
do_action( 'deployment_guard_blocked', string $vector, string $target, int $user_id );
```

Fires every time a write attempt is blocked — including repeated attempts
that the activity log itself drops as duplicates or throttles (see
[architecture.md](architecture.md#activity-history)), so a listener that
records or forwards these should apply its own rate limiting. `$vector` is
one of `admin`, `ajax`, `rest`, `xmlrpc`, `data`, `cli`, `cron`, `mail`.
`$target` is a short, non-sensitive identifier (never request content).

## `deployment_guard_state_changed` (action, stable)

```php
do_action( 'deployment_guard_state_changed', array $new, array $old, string $source );
```

Fires after every successful change to the freeze state (enable, disable,
schedule, clear schedule, timed freeze, extend, message, and the reset
performed on deactivation). `$new` and `$old` are the state array shape
(`FreezeConfig::to_array()`): `schema`, `enabled`, `schedule_start`,
`schedule_end`, `message`, `updated_at`, `updated_by`, each a scalar or
`null`. `$source` is `'ui'`, `'cli'`, `'rest'`, or `'deactivation'`.

A listener never inherits Stillgate's own internal CLI suspension
(see [architecture.md](architecture.md#suspension)) — neither does any other
hook callback that runs while a `wp stillgate` command is executing —
so a listener that itself tries to write protected content while a freeze
is active is still subject to normal enforcement. It is not an escape hatch
for `wp stillgate enable` to also perform an unrelated write.
