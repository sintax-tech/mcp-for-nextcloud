# Third-party notices

Inventory from `composer.lock`; `scripts/package.sh` installs only `packages`
with `composer install --no-dev`. Versions include the lockfile's exact reference
for development branches. Dependency files retain their upstream notices.
The Sintax attribution term does not apply to third-party software.

## Included in the production package

| Software | Version | SPDX license | Copyright / upstream notice | Use |
| --- | --- | --- | --- | --- |
| [smalot/pdfparser](https://github.com/smalot/pdfparser) | v2.12.5 | LGPL-3.0-or-later | Copyright (C) Sébastien MALOT and PdfParser contributors (years vary by source file; Parser.php: 2017) | PDF text extraction |
| [symfony/polyfill-mbstring](https://github.com/symfony/polyfill-mbstring) | v1.43.0 | MIT | Copyright (c) 2015-present Fabien Potencier | Multibyte string compatibility for PdfParser |
| [TCPDF-derived parser code](https://github.com/tecnickcom/TCPDF) | As embedded in PdfParser v2.12.5; original TCPDF version not recorded | LGPL-3.0-or-later | Nicola Asuni and TCPDF contributors; adapted by Konrad Abicht; Copyright (C) 2017 Sébastien MALOT | `vendor/smalot/pdfparser/src/Smalot/PdfParser/RawData/{FilterHelper,RawDataParser}.php`; original attribution retained |
| [cups-filters glyph mapping](https://github.com/OpenPrinting/cups-filters) | As embedded in PdfParser v2.12.5; original release not recorded | MIT | Copyright 2008,2012 Tobias Hoffmann | Mapping in `vendor/smalot/pdfparser/src/Smalot/PdfParser/Encoding/PostScriptGlyphs.php`; MIT/Expat attribution retained |
| [Unicode data](https://www.unicode.org/copyright.html) | Derived tables in Symfony Polyfill Mbstring v1.43.0; original Unicode data version not recorded | Unicode-3.0 | Copyright © 1991-2026 Unicode, Inc. | `vendor/symfony/polyfill-mbstring/Resources/unidata/`; current upstream permission notice in `LICENSES/Unicode-3.0.txt` |
| [Composer autoloader](https://github.com/composer/composer) | Generated at packaging time by installed Composer | MIT | Copyright (c) Nils Adermann, Jordi Boggiano | Generated vendor autoloader; `vendor/composer/LICENSE` retained |

PdfParser's lockfile uses the legacy label `LGPL-3.0`; its source headers
explicitly permit version 3 or any later version, hence `LGPL-3.0-or-later` above.
Its unmodified source is shipped in `vendor/smalot/pdfparser`, including
`LICENSE.txt`; `LICENSES/LGPL-3.0-or-later.txt` and `LICENSES/GPL-3.0-or-later.txt`
provide the LGPL permissions and incorporated GPL terms. The glyph mapping MIT
notice for Tobias Hoffmann is also included in `LICENSES/MIT.txt`. Unicode
[terms](https://www.unicode.org/copyright.html) license Unicode Data Files under
the [Unicode License v3](https://www.unicode.org/license.txt); the current full
notice is supplied for the derived tables whose original data version is not
recorded by Symfony. Symfony's MIT notice
is retained at `vendor/symfony/polyfill-mbstring/LICENSE` and `LICENSES/MIT.txt`.

The JavaScript, CSS and SVG app icons in `js/`, `css/` and `img/` are project
assets. No third-party JavaScript, CSS, fonts, images or icons are bundled.
Nextcloud's APIs and platform UI are provided by the host, not copied into the package.
External services accessed through APIs are not embedded software.

## Development dependencies (not included in the package)

| Software | Version | SPDX license | Copyright / upstream notice | Use |
| --- | --- | --- | --- | --- |
| [doctrine/dbal](https://github.com/doctrine/dbal) | 4.5.0 | MIT | Copyright (c) 2006-2018 Doctrine Project | Development and tests only; not shipped |
| [doctrine/deprecations](https://github.com/doctrine/deprecations) | 1.1.6 | MIT | Copyright (c) 2020-2021 Doctrine Project | Development and tests only; not shipped |
| [myclabs/deep-copy](https://github.com/myclabs/DeepCopy) | 1.14.0 | MIT | Copyright (c) 2013 My C-Sense | Development and tests only; not shipped |
| [nextcloud/ocp](https://github.com/nextcloud-deps/ocp) | dev-stable33 (`43727c5a1a74040945700b4c34056f7729fe87f3`) | AGPL-3.0-or-later | 2016-2024 Nextcloud GmbH and Nextcloud contributors; 2016 ownCloud, Inc. (additional notices vary by file) | Development and tests only; not shipped |
| [nikic/php-parser](https://github.com/nikic/PHP-Parser) | v5.9.0 | BSD-3-Clause | Copyright (c) 2011, Nikita Popov | Development and tests only; not shipped |
| [phar-io/manifest](https://github.com/phar-io/manifest) | 2.0.4 | BSD-3-Clause | Copyright (c) 2016-2019 Arne Blankerts <arne@blankerts.de>, Sebastian Heuer <sebastian@phpeople.de>, Sebastian Bergmann <sebastian@phpunit.de>, and contributors | Development and tests only; not shipped |
| [phar-io/version](https://github.com/phar-io/version) | 3.2.1 | BSD-3-Clause | Copyright (c) 2016-2017 Arne Blankerts <arne@blankerts.de>, Sebastian Heuer <sebastian@phpeople.de> and contributors | Development and tests only; not shipped |
| [phpunit/php-code-coverage](https://github.com/sebastianbergmann/php-code-coverage) | 10.1.16 | BSD-3-Clause | Copyright (c) 2009-2024, Sebastian Bergmann | Development and tests only; not shipped |
| [phpunit/php-file-iterator](https://github.com/sebastianbergmann/php-file-iterator) | 4.1.0 | BSD-3-Clause | Copyright (c) 2009-2023, Sebastian Bergmann | Development and tests only; not shipped |
| [phpunit/php-invoker](https://github.com/sebastianbergmann/php-invoker) | 4.0.0 | BSD-3-Clause | Copyright (c) 2011-2023, Sebastian Bergmann | Development and tests only; not shipped |
| [phpunit/php-text-template](https://github.com/sebastianbergmann/php-text-template) | 3.0.1 | BSD-3-Clause | Copyright (c) 2009-2023, Sebastian Bergmann | Development and tests only; not shipped |
| [phpunit/php-timer](https://github.com/sebastianbergmann/php-timer) | 6.0.0 | BSD-3-Clause | Copyright (c) 2010-2023, Sebastian Bergmann | Development and tests only; not shipped |
| [phpunit/phpunit](https://github.com/sebastianbergmann/phpunit) | 10.5.65 | BSD-3-Clause | Copyright (c) 2001-2026, Sebastian Bergmann | Development and tests only; not shipped |
| [psr/cache](https://github.com/php-fig/cache) | 3.0.0 | MIT | Copyright (c) 2015 PHP Framework Interoperability Group | Development and tests only; not shipped |
| [psr/clock](https://github.com/php-fig/clock) | 1.0.0 | MIT | Copyright (c) 2017 PHP Framework Interoperability Group | Development and tests only; not shipped |
| [psr/container](https://github.com/php-fig/container) | 2.0.2 | MIT | Copyright (c) 2013-2016 container-interop; Copyright (c) 2016 PHP Framework Interoperability Group | Development and tests only; not shipped |
| [psr/event-dispatcher](https://github.com/php-fig/event-dispatcher) | 1.0.0 | MIT | Copyright (c) 2018 PHP-FIG | Development and tests only; not shipped |
| [psr/log](https://github.com/php-fig/log) | 3.0.2 | MIT | Copyright (c) 2012 PHP Framework Interoperability Group | Development and tests only; not shipped |
| [sabre/dav](https://github.com/sabre-io/dav) | 4.7.0 | BSD-3-Clause | Copyright (C) 2007-2016 fruux GmbH (https://fruux.com/). | Development and tests only; not shipped |
| [sabre/event](https://github.com/sabre-io/event) | 5.1.7 | BSD-3-Clause | Copyright (C) 2013-2016 fruux GmbH (https://fruux.com/) | Development and tests only; not shipped |
| [sabre/http](https://github.com/sabre-io/http) | 5.1.12 | BSD-3-Clause | Copyright (C) 2009-2017 fruux GmbH (https://fruux.com/) | Development and tests only; not shipped |
| [sabre/uri](https://github.com/sabre-io/uri) | 2.3.4 | BSD-3-Clause | Copyright (C) 2014-2019 fruux GmbH (https://fruux.com/) | Development and tests only; not shipped |
| [sabre/vobject](https://github.com/sabre-io/vobject) | 4.5.6 | BSD-3-Clause | Copyright (C) 2011-2016 fruux GmbH (https://fruux.com/) | Development and tests only; not shipped |
| [sabre/xml](https://github.com/sabre-io/xml) | 2.2.11 | BSD-3-Clause | Copyright (C) 2009-2015 fruux GmbH (https://fruux.com/) | Development and tests only; not shipped |
| [sebastian/cli-parser](https://github.com/sebastianbergmann/cli-parser) | 2.0.1 | BSD-3-Clause | Copyright (c) 2020-2024, Sebastian Bergmann | Development and tests only; not shipped |
| [sebastian/code-unit](https://github.com/sebastianbergmann/code-unit) | 2.0.0 | BSD-3-Clause | Copyright (c) 2020-2023, Sebastian Bergmann | Development and tests only; not shipped |
| [sebastian/code-unit-reverse-lookup](https://github.com/sebastianbergmann/code-unit-reverse-lookup) | 3.0.0 | BSD-3-Clause | Copyright (c) 2016-2023, Sebastian Bergmann | Development and tests only; not shipped |
| [sebastian/comparator](https://github.com/sebastianbergmann/comparator) | 5.0.5 | BSD-3-Clause | Copyright (c) 2002-2025, Sebastian Bergmann | Development and tests only; not shipped |
| [sebastian/complexity](https://github.com/sebastianbergmann/complexity) | 3.2.0 | BSD-3-Clause | Copyright (c) 2020-2023, Sebastian Bergmann | Development and tests only; not shipped |
| [sebastian/diff](https://github.com/sebastianbergmann/diff) | 5.1.1 | BSD-3-Clause | Copyright (c) 2002-2024, Sebastian Bergmann | Development and tests only; not shipped |
| [sebastian/environment](https://github.com/sebastianbergmann/environment) | 6.1.0 | BSD-3-Clause | Copyright (c) 2014-2024, Sebastian Bergmann | Development and tests only; not shipped |
| [sebastian/exporter](https://github.com/sebastianbergmann/exporter) | 5.1.4 | BSD-3-Clause | Copyright (c) 2002-2025, Sebastian Bergmann | Development and tests only; not shipped |
| [sebastian/global-state](https://github.com/sebastianbergmann/global-state) | 6.0.2 | BSD-3-Clause | Copyright (c) 2001-2024, Sebastian Bergmann | Development and tests only; not shipped |
| [sebastian/lines-of-code](https://github.com/sebastianbergmann/lines-of-code) | 2.0.2 | BSD-3-Clause | Copyright (c) 2020-2023, Sebastian Bergmann | Development and tests only; not shipped |
| [sebastian/object-enumerator](https://github.com/sebastianbergmann/object-enumerator) | 5.0.0 | BSD-3-Clause | Copyright (c) 2016-2023, Sebastian Bergmann | Development and tests only; not shipped |
| [sebastian/object-reflector](https://github.com/sebastianbergmann/object-reflector) | 3.0.0 | BSD-3-Clause | Copyright (c) 2017-2023, Sebastian Bergmann | Development and tests only; not shipped |
| [sebastian/recursion-context](https://github.com/sebastianbergmann/recursion-context) | 5.0.2 | BSD-3-Clause | Copyright (c) 2002-2025, Sebastian Bergmann | Development and tests only; not shipped |
| [sebastian/type](https://github.com/sebastianbergmann/type) | 4.0.0 | BSD-3-Clause | Copyright (c) 2019-2023, Sebastian Bergmann | Development and tests only; not shipped |
| [sebastian/version](https://github.com/sebastianbergmann/version) | 4.0.1 | BSD-3-Clause | Copyright (c) 2013-2023, Sebastian Bergmann | Development and tests only; not shipped |
| [symfony/console](https://github.com/symfony/console) | v6.4.41 | MIT | Copyright (c) 2004-present Fabien Potencier | Development and tests only; not shipped |
| [symfony/deprecation-contracts](https://github.com/symfony/deprecation-contracts) | v3.7.1 | MIT | Copyright (c) 2020-present Fabien Potencier | Development and tests only; not shipped |
| [symfony/polyfill-ctype](https://github.com/symfony/polyfill-ctype) | v1.37.0 | MIT | Copyright (c) 2018-present Fabien Potencier | Development and tests only; not shipped |
| [symfony/polyfill-intl-grapheme](https://github.com/symfony/polyfill-intl-grapheme) | v1.43.0 | MIT | Copyright (c) 2015-present Fabien Potencier | Development and tests only; not shipped |
| [symfony/polyfill-intl-normalizer](https://github.com/symfony/polyfill-intl-normalizer) | v1.43.0 | MIT | Copyright (c) 2015-present Fabien Potencier | Development and tests only; not shipped |
| [symfony/service-contracts](https://github.com/symfony/service-contracts) | v3.7.3 | MIT | Copyright (c) 2018-present Fabien Potencier | Development and tests only; not shipped |
| [symfony/string](https://github.com/symfony/string) | v7.4.19 | MIT | Copyright (c) 2019-present Fabien Potencier | Development and tests only; not shipped |
| [theseer/tokenizer](https://github.com/theseer/tokenizer) | 1.3.1 | BSD-3-Clause | Copyright (c) 2017 Arne Blankerts <arne@blankerts.de> and contributors | Development and tests only; not shipped |

### Assets embedded in development dependencies

`sabre/dav` 4.7.0 includes Open Iconic assets under
`lib/DAV/Browser/assets/openiconic/`: copyright (c) 2014 Waybury,
icons under MIT (`ICON-LICENSE` retained), fonts under OFL-1.1
([upstream font license](https://github.com/iconic/open-iconic/blob/master/FONT-LICENSE)).
The embedded Open Iconic release is not recorded by sabre/dav.
They support sabre/dav's development browser UI and do not enter the app package.
The development Symfony polyfills also contain derived Unicode tables; the
Unicode notice described above applies to those tables as well.

## Copied development fixture

`tests/fixtures/info.xsd` is the Nextcloud app manifest schema, copyright
2016 Nextcloud GmbH and Nextcloud contributors, AGPL-3.0-or-later;
[source](https://apps.nextcloud.com/schema/apps/info.xsd).
Its original SPDX header is retained. Generated `tests/fixtures/nextcloud-api/*.php`
record public API signatures from Nextcloud, Deck and Talk; they are test metadata,
not runtime libraries, and are covered by `REUSE.toml`.
