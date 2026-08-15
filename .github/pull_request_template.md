## What changes, and why

<!-- The reason matters more than the diff: what was wrong, or what it now
makes possible. Link an issue if there is one. -->

## Checked before pushing

```bash
npm run build          # the dashboard's JS carries translatable strings
vendor/bin/pint --test # formatting, the same rules CI enforces
php artisan test       # the suite
```

- [ ] The suite passes against a real PostgreSQL
- [ ] `vendor/bin/pint --test` is clean
- [ ] New user-facing strings are English, inline in `__()`, `trans_choice()` or `T()`
- [ ] `lang/de.json` carries their German lines (or no UI strings changed)
