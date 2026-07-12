<?php

declare(strict_types=1);

/*
 * Per-case enum label overrides, keyed enums.<slug>.<case-value>. English
 * labels ship as #[Label] attributes on the enum cases themselves, so this
 * file stays empty by default — publish it (or add a locale sibling) to
 * override or translate labels without touching code. Resolution order:
 * translation key here, then the #[Label] attribute, then the humanized
 * case name.
 */
return [];
