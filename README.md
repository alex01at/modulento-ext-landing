# Landing page

An addon for [Modulento](https://github.com/alex01at/modulento) that lets an
administrator build one marketing landing page from ready-made blocks - no
HTML or CSS needed. As an addon, it can be active together with any other
addon and with the installation's one app.

- **Blocks**: a hero (eyebrow, heading, subheading, two buttons), a stat
  strip (up to six number/label pairs), a card grid (up to six tag/heading/
  text cards - apps, addons, pricing tiers, whatever fits), a text section
  (the same sanitised rich text the core's own content pages use) and a
  closing call to action. Add, reorder, switch off or remove a block from
  one page in the administration, the same pattern the core's own menu and
  home-page editors use - dragging a block's handle reorders it right away
  (the core's menu.js, already loaded on every administration page, does
  that for free).
- The page is reachable at `/landing` once published, with its own title
  and meta description, but is not linked from anywhere on its own - add it
  to the main menu or the footer under Content → Menu once it is ready.
- Its blocks are styled by this addon's own stylesheet, built entirely on
  the design tokens every theme already loads (accent colour, background,
  text colour, font, corner radius - Administration → Design), so the page
  matches the installation's own look on any theme, without the addon
  shipping a theme of its own.
- Only the language the administration is currently in is edited at a time,
  like the core's own menu and home-page editors; switch language in the
  administration header to fill in another one.

## Requirements

Modulento 0.64.0 or newer (the `ext_asset()` Twig function this addon's
stylesheet needs).

## Installing

From **Administration → Extensions**, install from this repository, or
unpack a release into `extensions/landing`. Needs the `landing.manage`
permission to show up for a role.

## Testing

```
MODULENTO_CORE=/path/to/modulento php tests/run.php
```
