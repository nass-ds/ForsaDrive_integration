<?php
/**
 * Tiered ride/post boost (spec §3.2) — single source of truth.
 *
 * Shared by the REST API (web/api/feed.php) and the server-rendered feed
 * (web/Pages/feed.php) so both backends price and time boosts identically.
 *
 * Each tier maps a key to a wallet price (TND) and a duration. Boosts
 * auto-expire on read: once boost_expires_at passes the post ranks normally.
 */

const BOOST_TIERS = [
    '12h' => ['label' => '12 hours', 'price' => 1.5,  'sql_modifier' => '+12 hours'],
    '24h' => ['label' => '24 hours', 'price' => 2.5,  'sql_modifier' => '+24 hours'],
    '48h' => ['label' => '48 hours', 'price' => 4.0,  'sql_modifier' => '+48 hours'],
    '7d'  => ['label' => '7 days',   'price' => 10.0, 'sql_modifier' => '+7 days'],
];

/** Return the tier definition (with its 'key') for $key, or null if invalid. */
function boost_tier(?string $key): ?array {
    if ($key === null || $key === '') return null;
    if (!isset(BOOST_TIERS[$key])) return null;
    return ['key' => $key] + BOOST_TIERS[$key];
}

/**
 * SQL fragment evaluating to 1 when the post is *currently* boosted, else 0.
 *
 * A boost counts as active while is_boosted = 1 and the expiry is still in the
 * future. Legacy boosts predating tiers carry a NULL expiry and stay active
 * (they were sold as permanent), so NULL is treated as "no expiry".
 */
function boost_active_sql(string $alias = 'p'): string {
    return "(CASE WHEN $alias.is_boosted = 1
                   AND ($alias.boost_expires_at IS NULL
                        OR $alias.boost_expires_at > datetime('now'))
              THEN 1 ELSE 0 END)";
}
