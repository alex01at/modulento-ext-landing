-- One page: a singleton row (id is always 1, nothing ever inserts a second
-- one - see LandingPage::save()). "content" is the whole page as JSON (the
-- title/meta per language, and the ordered list of blocks with their own
-- per-language texts) - the same shape the core's own CustomMenu and
-- HomeLayout already use for an ordered list of typed entries, just kept in
-- this extension's own table instead of the core's settings.

CREATE TABLE x_landing_page (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    published TINYINT(1) NOT NULL DEFAULT 0,
    content MEDIUMTEXT NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
