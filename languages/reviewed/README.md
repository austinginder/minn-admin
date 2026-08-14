# Reviewed translations

A native speaker's correction, kept where a regeneration cannot reach it.

Everything in `languages/*.po` is generated and is rewritten in full whenever
the catalog is rebuilt. Anything in **this** directory wins over that, always,
and is never overwritten. Put a correction here and it is permanent.

## Fixing a string

Add the entry to `reviewed/<locale>.po` — a plain gettext catalog, so any
translation tool understands it. Only the strings you are correcting need to be
in the file.

```po
msgid "No activity yet."
msgstr "Todavía no hay actividad."
```

Then rebuild and check:

```
node bin/i18n/import-batch.js es_ES
node bin/i18n/qa.js es_ES --verbose
```

The importer reports these as `reviewed` in its summary, and `qa.js` runs the
same checks over them as everything else — a reviewed entry that drops a `%s`
is still a crash, and is still rejected.

## What belongs here

Corrections a person made and stands behind. Not a whole catalog: a full
translation belongs in the generated pipeline, where the glossary and the
validators can see it.
