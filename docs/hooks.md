# Extensibility hooks

Stillgate exposes a small, deliberately narrow set of hooks. Most
sites will never need any of these; they exist for the cases a filter on
protected types or a plugin with its own write paths cannot otherwise be
accommodated.

The two filters marked **experimental** may still change shape in a `0.x`
release without a major version bump; the rest are considered stable from
`0.1.0`.

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
schedule, clear schedule, message, and the reset performed on
deactivation). `$new` and `$old` are the state array shape
(`FreezeConfig::to_array()`): `schema`, `enabled`, `schedule_start`,
`schedule_end`, `message`, `updated_at`, `updated_by`, each a scalar or
`null`. `$source` is `'ui'`, `'cli'`, or `'deactivation'`.

A listener never inherits Stillgate's own internal CLI suspension
(see [architecture.md](architecture.md#suspension)) — neither does any other
hook callback that runs while a `wp stillgate` command is executing —
so a listener that itself tries to write protected content while a freeze
is active is still subject to normal enforcement. It is not an escape hatch
for `wp stillgate enable` to also perform an unrelated write.
