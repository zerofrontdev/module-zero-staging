# Zero_Staging — Content Staging & Scheduling

Campaigns of changes that go live together at a set minute and, when the
campaign has an end, are undone together then. Preview the store as of any
moment before it happens; visitors see nothing early. Part of the Zero
Enterprise set (licensed, from the 0 Marketplace's Composer repository).

Black Friday, planned in October: Affirm Water Bottle at $4.90, a Black
Friday name on it, the hero's first slide saying "30% off bags", the 30%
cart rule switched on, all live at 07:00 on the 27th, undone at 07:00 on
Monday.

## What a campaign can change

| Kind | Fields | Per store view |
| --- | --- | --- |
| Product | name, price, special price, status, badge, short description, description | yes (global and website attributes follow their scope) |
| Category | name, enabled, description, meta title, meta description | yes |
| CMS page | title, content heading, enabled, meta title, meta description, Zero CMS content | the content; the rest belongs to the page |
| CMS block | title, enabled, Zero CMS content | the content |
| Banner slide | enabled, eyebrow, heading, text, button, link, pictures, alt | no |
| Catalog price rule | active, discount amount | no |
| Cart price rule | active, discount amount | no |

Another module adds its own kind with a `Model\Target\TargetInterface` in
`Model\Target\Pool` (di.xml). It has to implement `stores()` (the store
views a value lands in, the rule its `write()` follows), which the conflict
check below relies on.

## Using it

- **Content > Staging > Campaigns**: the next four weeks on a timeline, the
  campaigns coming up and live, the ones done; "See the store as of" opens
  a preview of any date and time.
- **A campaign**: name, start, end (store time; no end: the changes stay),
  its changes as "from → to", and Preview, Start now, End now, Delete.
- **Schedule a Change** on a product, category, CMS page, block, banner,
  catalog or cart rule form opens the change form for it: tick the fields,
  give their new values, pick a campaign or make one.
- **The Zero CMS editor** has **Schedule…** next to Save: what the editor
  holds goes into a campaign, the page stays as it is. From the campaign,
  Edit content opens the editor on the scheduled content; its Save keeps
  it in the campaign.

Two campaigns can't set the same field of the same entity at the same time
(the second one is refused, naming the first), counted where the value
lands: a global field (or a CMS page's title) from any two store views, a
website-scope one (price, special price, status; price by Catalog Price
Scope) from store views of one website. After changing Catalog Price Scope,
save scheduled campaigns again so the check runs. Once a campaign is live
its changes are fixed; its end can still move.

## Going live and back

Cron, every minute (`zero_staging_run`, or `bin/magento zero:staging:run`):
campaigns whose end has come are undone first, then those whose start has
come go live, so back-to-back campaigns hand fields over cleanly.

- **Going live**, each change keeps the values it replaces (with whether a
  store view's value was its own or inherited), stored before the change is
  written: a write that stops halfway still knows them, and a failed change
  shows them (as "from") for an undo by hand.
- **Start now, End now and edits** wait a few seconds for a cron run in
  progress, else say so ("try again in a minute"): a campaign is never
  started twice, nor edited while it is being applied.
- **Undoing** writes the kept values back, but only into fields that still
  hold the campaign's value: a price someone changed by hand during the
  campaign keeps that value, and the change says so. An inherited value
  becomes inherited again (the store view's own value is removed).
- A change whose entity is gone fails on its own; the others go.
- Known limit: a write that fails partway through a website's store views
  (a website-scope price, say) leaves the change *failed* with the
  campaign's value in some of them. Ending the campaign undoes only applied
  changes, so those values stay until someone puts them back by hand; the
  failed change shows the kept values ("from") to do it.
- Pages showing the entities are purged by their cache tags (built-in page
  cache or Varnish). Catalog rule changes reindex the rule's products.
- `bin/magento zero:staging:list` lists every campaign (UTC).

## Preview

Preview (a campaign's start) or "See the store as of" sends the admin to
`/zero_staging/preview/start` with a signed link (the installation's key,
valid an hour). The storefront then shows what it will hold at that moment:
scheduled campaigns running then add their values, live ones that will have
ended give theirs back. A bar at the bottom names the moment and has Exit.

A preview is an HTTP context value, so its pages get their own
`X-Magento-Vary` and are never cached (`Plugin\Preview\LayoutCacheable`);
cached blocks get a key of the preview's own (`Observer\Preview\
BlockCacheKey`), product cards vary by the context already. Previewed:
product and category texts and prices (product page, cards, menu), CMS page
and block content and titles, banner slides (texts, pictures, on/off).
Switching a product, category or page on or off and rule discounts take
effect when the campaign starts.

## Install

Licensed: `composer require zerofront/module-zero-staging` with the key from
My Account > Licenses, then `bin/magento setup:upgrade`. Needs Zero_Core,
Zero_Cms and Zero_Banner (Packagist) and the Magento cron.

Tables: `zero_staging_campaign`, `zero_staging_change`. ACL:
`Zero_Staging::staging` (Content > Staging).

AI Assistant tools for this module: the bridge module `Zero_StagingAiAssistant` (needs Zero_AiAssistant too).

What changed in each release: [CHANGELOG.md](CHANGELOG.md).
