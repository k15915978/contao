# API changes

## Version 4.* to 5.0

### tl_content.ptable

Contao no longer treats an empty `tl_content.ptable` column like it had been set to `tl_article`. Make
sure to always set the `ptable` column.

### disableInsertTags

The `disableInsertTags` config option has been removed. Use the `contao.insert_tags.allowed_tags`
parameter instead.

### runonce.php

The support for `runonce.php` files has been dropped. Use the migration framework instead.

### onrestore_callback

The `onrestore_callback` has been removed. Use the `onrestore_version_callback` instead.

### getSearchablePages hook

The `getSearchablePages` hook has been removed. Use the `SitemapEvent` instead.

### Backend::addFileMetaInformationToRequest

`Backend::addFileMetaInformationToRequest()` and the corresponding `addFileMetaInformationToRequest` hook
have been removed. Use the image handling services and the `FileMetadataEvent` instead.

### FormTextarea->value

The value of the `FormTextarea` widget is no longer encoded with `specialchars()`. Encode the value in
your custom `form_textarea` templates instead.

### languages.php, getLanguages and $GLOBALS['TL_LANG']['LNG']

The `System::getLanguages()` method, the `getLanguages` hook and the `config/languages.php` file have been
removed. Use or decorate the `contao.intl.locales` service instead.

To add or remove countries, you can use the `contao.intl.locales` or `contao.intl.enabled_locales
configuration. `$GLOBALS['TL_LANG']['LNG']` can still be used for overwriting translations, but no longer to
retrieve language names.

### countries.php, getCountries and $GLOBALS['TL_LANG']['CNT']

The `System::getCountries()` method, the `getCountries` hook and the `config/countries.php` file have been
removed. Use or decorate the `contao.intl.countries` service instead.

To add or remove countries, you can use the `contao.intl.countries` configuration. `$GLOBALS['TL_LANG']['CNT']`
can still be used for overwriting translations, but no longer to retrieve country names.

### UnresolvableDependenciesException

The following classes and interfaces have been removed from the global namespace:

 - `listable`
 - `editable`
 - `executable`
 - `uploadable`
 - `UnresolvableDependenciesException`
 - `UnusedArgumentsException`

### Model

The protected `$arrClassNames` property was removed from the `Contao\Model` base class.

### Request

The `Contao\Request` library has been removed. Use another library such as `symfony/http-client` instead.

### Renamed resources

The following resources have been renamed:

 - `ContentMedia` -> `ContentPlayer`
 - `FormCheckBox` -> `FormCheckbox`
 - `FormRadioButton` -> `FormRadio`
 - `FormSelectMenu` -> `FormSelect`
 - `FormTextField` -> `FormText`
 - `FormTextArea` -> `FormTextarea`
 - `FormFileUpload` -> `FormUpload`
 - `ModulePassword` -> `ModuleLostPassword`
 - `form_textfield` -> `form_text`

### CSS classes "first", "last", "even" and "odd"

The CSS classes `first`, `last`, `even`, `odd`, `row_*` and `col_*` are no longer applied anywhere.
Use CSS selectors instead.

### Template changes

The items in the `ce_list` and `ce_table` templates no longer consist of an associative array
containing the item's CSS class and content. Instead, it will only be the content.

```php
<!-- OLD -->
<?php foreach ($this->items as $item): ?>
  <li<?php if ($item['class']): ?> class="<?= $item['class'] ?>"<?php endif; ?>><?= $item['content'] ?></li>
<?php endforeach; ?>

<!-- NEW -->
<?php foreach ($this->items as $item): ?>
  <li><?= $item ?></li>
<?php endforeach; ?>
```

### Input type "textStore"

The `textStore` input type was removed. Use `password` instead.

### Global functions

The following global functions have been removed:

 - `scan()`
 - `specialchars()`
 - `standardize()`
 - `strip_insert_tags()`
 - `deserialize()`
 - `trimsplit()`
 - `ampersand()`
 - `nl2br_html5()`
 - `nl2br_xhtml()`
 - `nl2br_pre()`
 - `basename_natcasecmp()`
 - `basename_natcasercmp()`
 - `natcaseksort()`
 - `length_sort_asc()`
 - `length_sort_desc()`
 - `array_insert()`
 - `array_dupliacte()`
 - `array_move_up()`
 - `array_move_down()`
 - `array_delete()`
 - `array_is_assoc()`
 - `utf8_chr()`
 - `utf8_ord()`
 - `utf8_convert_encoding()`
 - `utf8_decode_entities()`
 - `utf8_chr_callback()`
 - `utf8_hexchr_callback()`
 - `utf8_detect_encoding()`
 - `utf8_romanize()`
 - `utf8_strlen()`
 - `utf8_strpos()`
 - `utf8_strrchr()`
 - `utf8_strrpos()`
 - `utf8_strstr()`
 - `utf8_strtolower()`
 - `utf8_strtoupper()`
 - `utf8_substr()`
 - `utf8_ucfirst()`
 - `utf8_str_split()`
 - `nl2br_callback()`

Most of them have alternatives in either `StringUtil`, `ArrayUtil` or may have PHP native alternatives such as
the `mb_*` functions. For advanced UTF-8 handling, use `symfony/string`.

### eval->orderField in PageTree and Picker widgets

Support for a separate database `orderField` column has been removed. Use `isSortable` instead which
stores the order in the same database column.

### Removed {{post::*}} insert tag

The `{{post::*}}` insert tag has been removed. To access submitted form data on forward pages, use the
new `{{form_session_data::*}}` insert tag instead.

### $_SESSION access no longer mapped to Symfony Session

The use of `$_SESSION` is discouraged because it makes testing and configuring alternative storage
back ends hard. In Contao 4, access to `$_SESSION` has been transparently mapped to the Symfony session.
This has been removed. Use `$request->getSession()` directly instead.

### database.sql files

Support for `database.sql` files has been dropped. Use DCA definitions and/or Doctrine DBAL schema
listeners instead.

### Simple Token Parser

Tokens which are not valid PHP variable names (e.g. `##0foobar##`) are not supported anymore by the
Simple Token Parser.

### $GLOBALS['TL_KEYWORDS']

Keyword support in articles, and as such also `$GLOBALS['TL_KEYWORDS']`, has been removed.

### Legacy routing

The legacy routing has been dropped. As such, the `getPageIdFromUrl` and `getRootPageFromUrl` hooks do
not exist anymore. Use Symfony routing instead.

### Custom entry points

The `initialize.php` file has been removed, so custom entry points will no longer work. Register your
entry points as controllers instead.

### ClassLoader

The `Contao\ClassLoader` has been removed. Use Composer autoloading instead.

### Encryption

The `Contao\Encryption` class and the `eval->encrypt` DCA flag have been removed. Use `save_callback`
and `load_callback` and libraries such as `phpseclib/phpseclib` instead.

### Internal CSS editor

The internal CSS editor has been removed. Export your existing CSS files, import them in the file manager
and then add them as external CSS files to the page layout.

### log_message()

The function `log_message()` has been removed. Use the Symfony logger services instead. Consequently, the
`Automator::rotateLogs()` method has been removed, too.

### config.dataContainer

The DCA `config.dataContainer` property needs to be a FQCN instead of just `Table` or `Folder`.

More information: https://github.com/contao/contao/pull/4322

### pageSelector and fileSelector widgets

The back end widgets `pageSelector` and `fileSelector` have been removed. Use the `picker` widget instead.

### Public folder

The public folder is now called `public` by default. It can be renamed in the `composer.json` file.

### Figure

The `Contao\CoreBundle\Image\Studio\Figure::getLinkAttributes()` method will now return an
`Contao\CoreBundle\String\HtmlAttributes` object instead of an array. Use `iterator_to_array()` to transform it
back to an array representation. If you are just using array access, nothing needs to be changed.
