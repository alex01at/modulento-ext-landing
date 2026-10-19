# Landing page

An addon for [Modulento](https://github.com/alex01at/modulento) that lets an
administrator build one marketing landing page from ready-made blocks - no
HTML or CSS needed. As an addon, it can be active together with any other
addon and with the installation's one app.

- **Blocks**: a hero (eyebrow, heading, subheading, two buttons), a stat
  strip (up to six number/label pairs), a card grid (up to six tag/heading/
  text cards - apps, addons, pricing tiers, whatever fits), a text section
  (the same sanitised rich text the core's own content pages use) and a
  closing call to action.
- **Two ways to fill it in**, both ending up at the same page:
  - **Directly on the page itself** (`/landing?edit=1`, from the "Edit
    directly on the page" link on its own admin screen): click a heading,
    a stat's number, a card's text - anywhere a block's own text shows -
    and type; it saves itself and looks exactly as a visitor will see it,
    because it *is* that page. This reuses the core's own inline-editing
    scripts (the same ones Administration → Home page uses), not anything
    of this addon's own.
  - **A plain form** (Administration → Landing page) for everything else
    (a block's buttons/links, adding, reordering, publishing) - a list of
    blocks, each closed by default, dragging a block's handle reorders it
    right away.
- The page is reachable at `/landing` once published, with its own title
  and meta description, but is not linked from anywhere on its own - add it
  to the main menu or the footer under Content → Menu once it is ready, or
  tick **"Use as the start page"** on its own edit screen to have `/` lead
  straight to it (this also publishes it, since an unpublished start page
  would 404 the whole site).
- Its blocks are styled by this addon's own stylesheet, built entirely on
  the design tokens every theme already loads (accent colour, background,
  text colour, font, corner radius - Administration → Design), so the page
  matches the installation's own look on any theme, without the addon
  shipping a theme of its own.
- Only the language the administration is currently in is edited at a time,
  like the core's own menu and home-page editors; switch language in the
  administration header to fill in another one.
- The whole page - published or not, every block, every language - is one
  of the sections **Administration → Export/Import** can write to a file
  or read back, the core's own generic mechanism
  (`Registrar::exportable()`); no mechanism of this addon's own.

## A ready-made sales page

`examples/landing-sales-page.json` is a finished page - a hero, a stats
strip, three card grids (apps, addons, themes), a text section and a
closing call to action, in German and English - written to sell Modulento
itself as a product. Import it under **Administration → Export/Import**
(it names only the `landing.page` section, so nothing else about the
installation is touched) to get it as a starting point, then adjust the
text and the two GitHub links to taste. It imports unpublished on purpose,
so it can be reviewed before it goes live.

## Requirements

Modulento 0.66.0 or newer (the export/import mechanism this addon's own
page is a section of).

## Installing

From **Administration → Extensions**, install from this repository, or
unpack a release into `extensions/landing`. Needs the `landing.manage`
permission to show up for a role.

## Testing

```
MODULENTO_CORE=/path/to/modulento php tests/run.php
```
