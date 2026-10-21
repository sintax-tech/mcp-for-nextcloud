<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Documentation translations

English is the canonical source. Operational documentation supports Spanish (`es`),
Catalan (`ca`), Brazilian Portuguese (`pt-BR`) and European Portuguese (`pt-PT`).
UI catalogs remain independent: English source text plus `de`, `es` and `pt_BR`.
German UI support does not create a German documentation tree. The German catalog
and its rendering in the administration interface still require human review.

Draft translations may be versioned and included in the operational package when
explicitly marked `translation-review: pending`, with a visible human-review notice
and an English source link. This supports preparation and review without pretending
that human approval has occurred. Only a human reviewer may certify `reviewed`.
Use English filenames in each locale and language names instead of flags. Missing
pages link explicitly to English; contributor documentation may stay English-only.
Do not add empty translated pages or silently link a different-language landing page.

Every translated page records its canonical repository path, a real full source
commit, and `translation-review: pending` or `reviewed` in HTML comments. Commit
English source first. The source revision must remain an ancestor of HEAD after
integration; preserve these source commits instead of squashing them away. If
history changes, establish a durable source commit and compare again before updating
metadata. Do not manufacture a SHA or automatically certify language review.

```markdown
<!-- translation-source: docs/en/guides/permissions.md -->
<!-- translation-source-revision: FULL_SOURCE_COMMIT_SHA -->
<!-- translation-review: pending -->
```

The token above is illustrative. Published metadata must use a real revision where
the source path exists. Compare source bytes at that revision with current bytes;
an unrelated commit does not make a translation stale. Pending or changed content
needs a visible notice and an English source link. A reviewed unchanged source
means current provenance, not automated proof of translation quality.

Missing/stale translations are advisory and do not block ordinary development.
Invalid metadata, missing history and broken links fail validation. Full Git history
is required; the validator never fetches it silently. English authors add a review
notice when they know a translation is stale, without needing to translate the
change. Human reviewers may advance the revision and remove the notice after review.
Record the reviewer and reviewed pages in the pull request review.

Prioritize overview, installation, clients, permissions and troubleshooting.
All translated pages remain explicitly pending until human review. Pending pages
without a visible notice fail validation, including notices hidden in code or HTML
comments. A changed previously reviewed page remains an advisory review request.
Use prose notices beginning “Traducción pendiente”, “Traducció pendent” or “Revisão
pendente” / “Revisão humana pendente”, followed by the equivalent English source link.

Contributor documentation is maintained in English.
The operational coverage set consists of root/app READMEs and every page under
`docs/en/` except `development/`. Keep each Portuguese variant independent: adapting
Brazilian spelling alone does not establish European Portuguese review.
