# Composer advisory review

Reviewed 2026-07-24 with `composer audit --format=json` against the committed lock file. This is an
inventory and maintenance recommendation only; no dependency was upgraded in Run 3.

| Locked package | Advisory | Severity | Recommended action |
|---|---|---:|---|
| `psy/psysh` `v0.12.18` | [CVE-2026-25129 / GHSA-4486-gxhx-5mg7](https://github.com/advisories/GHSA-4486-gxhx-5mg7): CWD `.psysh.php` auto-load can enable local privilege escalation | Medium | Update to a release newer than `0.12.18`; do not run Tinker/PsySH from untrusted working directories until updated. |
| `symfony/yaml` `v7.4.1` | [CVE-2026-45304](https://symfony.com/cve-2026-45304): recursive collection-alias expansion can cause exponential memory allocation | Low | Update to `7.4.12` or newer and continue treating untrusted YAML as hostile input. |
| `symfony/yaml` `v7.4.1` | [CVE-2026-45305](https://symfony.com/cve-2026-45305): parser cleanup regex can suffer catastrophic backtracking | Low | Update to `7.4.12` or newer and apply input-size/resource limits to untrusted YAML. |
| `symfony/yaml` `v7.4.1` | [CVE-2026-45133](https://symfony.com/cve-2026-45133): deeply nested YAML can exhaust the parser stack | Low | Update to `7.4.12` or newer and reject excessive nesting at trust boundaries. |

## Recommended maintenance change

Make the remediation a separate dependency-only change. In the disposable Hetzner checkout,
`composer update psy/psysh symfony/yaml --with-all-dependencies --dry-run --no-interaction` resolved
cleanly to:

- `psy/psysh` `v0.12.24`;
- `symfony/yaml` `v7.4.14`;
- transitive `nikic/php-parser` `v5.8.0`.

That change must still run the full PHP suite, `gate:eval`, routing proof, structured cloud smoke, and
explicitly confirm that `prism-php/prism v0.92.0` and `vizra/vizra-adk 0.0.42` remain co-installed.
Run 3 intentionally leaves `composer.json` and `composer.lock` unchanged.
