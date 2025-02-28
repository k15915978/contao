<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Image\Studio;

use Contao\CoreBundle\Event\FileMetadataEvent;
use Contao\CoreBundle\Exception\InvalidResourceException;
use Contao\CoreBundle\File\Metadata;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\String\HtmlAttributes;
use Contao\CoreBundle\Util\LocaleUtil;
use Contao\FilesModel;
use Contao\Image\ImageInterface;
use Contao\Image\PictureConfiguration;
use Contao\Image\ResizeOptions;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\Validator;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Use the FigureBuilder class to create Figure result objects. The class
 * has a fluent interface to configure the desired output. When you are ready,
 * call build() to get a Figure. If you need another instance with similar
 * settings, you can alter values and call build() again - it will not affect
 * your first instance.
 */
class FigureBuilder
{
    private Filesystem $filesystem;
    private InvalidResourceException|null $lastException = null;

    /**
     * The resource's absolute file path.
     */
    private string|null $filePath = null;

    /**
     * The resource's file model if applicable.
     */
    private FilesModel|null $filesModel = null;

    /**
     * User defined size configuration.
     *
     * @phpcsSuppress SlevomatCodingStandard.Classes.UnusedPrivateElements
     */
    private PictureConfiguration|array|int|string|null $sizeConfiguration = null;

    /**
     * User defined resize options.
     *
     * @phpcsSuppress SlevomatCodingStandard.Classes.UnusedPrivateElements
     */
    private ResizeOptions|null $resizeOptions = null;

    /**
     * User defined custom locale. This will overwrite the default if set.
     */
    private string|null $locale = null;

    /**
     * User defined metadata. This will overwrite the default if set.
     */
    private Metadata|null $metadata = null;

    /**
     * Determines if a metadata should never be present in the output.
     */
    private bool|null $disableMetadata = null;

    /**
     * User defined link attributes. These will add to or overwrite the default values.
     *
     * @var array<string, string|null>
     */
    private array $additionalLinkAttributes = [];

    /**
     * User defined lightbox resource or url. This will overwrite the default if set.
     */
    private ImageInterface|string|null $lightboxResourceOrUrl = null;

    /**
     * User defined lightbox size configuration. This will overwrite the default if set.
     */
    private PictureConfiguration|array|int|string|null $lightboxSizeConfiguration = null;

    /**
     * User defined lightbox resize options.
     */
    private ResizeOptions|null $lightboxResizeOptions = null;

    /**
     * User defined lightbox group identifier. This will overwrite the default if set.
     */
    private string|null $lightboxGroupIdentifier = null;

    /**
     * Determines if a lightbox (or "fullsize") image should be created.
     */
    private bool|null $enableLightbox = null;

    /**
     * User defined template options.
     *
     * @phpcsSuppress SlevomatCodingStandard.Classes.UnusedPrivateElements
     *
     * @var array<string, mixed>
     */
    private array $options = [];

    /**
     * @internal Use the Contao\CoreBundle\Image\Studio\Studio factory to get an instance of this class
     *
     * @param array<string> $validExtensions
     */
    public function __construct(
        private ContainerInterface $locator,
        private string $projectDir,
        private string $uploadPath,
        private array $validExtensions,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * Sets the image resource from a FilesModel.
     */
    public function fromFilesModel(FilesModel $filesModel): self
    {
        $this->lastException = null;

        if ('file' !== $filesModel->type) {
            $this->lastException = new InvalidResourceException(sprintf('DBAFS item "%s" is not a file.', $filesModel->path));

            return $this;
        }

        $this->filePath = Path::makeAbsolute($filesModel->path, $this->projectDir);
        $this->filesModel = $filesModel;

        if (!$this->filesystem->exists($this->filePath)) {
            $this->lastException = new InvalidResourceException(sprintf('No resource could be located at path "%s".', $this->filePath));
        }

        return $this;
    }

    /**
     * Sets the image resource from a tl_files UUID.
     */
    public function fromUuid(string $uuid): self
    {
        $this->lastException = null;

        $filesModel = $this->getFilesModelAdapter()->findByUuid($uuid);

        if (null === $filesModel) {
            $this->lastException = new InvalidResourceException(sprintf('DBAFS item with UUID "%s" could not be found.', $uuid));

            return $this;
        }

        return $this->fromFilesModel($filesModel);
    }

    /**
     * Sets the image resource from a tl_files ID.
     */
    public function fromId(int $id): self
    {
        $this->lastException = null;

        $filesModel = $this->getFilesModelAdapter()->findByPk($id);

        if (null === $filesModel) {
            $this->lastException = new InvalidResourceException(sprintf('DBAFS item with ID "%s" could not be found.', $id));

            return $this;
        }

        return $this->fromFilesModel($filesModel);
    }

    /**
     * Sets the image resource from an absolute or relative path.
     *
     * @param bool $autoDetectDbafsPaths Set to false to skip searching for a FilesModel
     */
    public function fromPath(string $path, bool $autoDetectDbafsPaths = true): self
    {
        $this->lastException = null;

        // Make sure path is absolute and in a canonical form
        $path = Path::isAbsolute($path) ? Path::canonicalize($path) : Path::makeAbsolute($path, $this->projectDir);

        // Only check for a FilesModel if the resource is inside the upload path
        if ($autoDetectDbafsPaths && Path::isBasePath(Path::join($this->projectDir, $this->uploadPath), $path)) {
            $filesModel = $this->getFilesModelAdapter()->findByPath($path);

            if (null !== $filesModel) {
                return $this->fromFilesModel($filesModel);
            }
        }

        $this->filePath = $path;
        $this->filesModel = null;

        if (!$this->filesystem->exists($this->filePath)) {
            $this->lastException = new InvalidResourceException(sprintf('No resource could be located at path "%s".', $this->filePath));
        }

        return $this;
    }

    /**
     * Sets the image resource from an ImageInterface.
     */
    public function fromImage(ImageInterface $image): self
    {
        return $this->fromPath($image->getPath());
    }

    /**
     * Sets the image resource by guessing the identifier type.
     *
     * @param int|string|FilesModel|ImageInterface|null $identifier Can be a FilesModel, an ImageInterface, a tl_files UUID/ID/path or a file system path
     */
    public function from(FilesModel|ImageInterface|int|string|null $identifier): self
    {
        if (null === $identifier) {
            $this->lastException = new InvalidResourceException('The defined resource is "null".');

            return $this;
        }

        if ($identifier instanceof FilesModel) {
            return $this->fromFilesModel($identifier);
        }

        if ($identifier instanceof ImageInterface) {
            return $this->fromImage($identifier);
        }

        if (\is_string($identifier) && $this->getValidatorAdapter()->isUuid($identifier)) {
            return $this->fromUuid($identifier);
        }

        if (is_numeric($identifier)) {
            return $this->fromId((int) $identifier);
        }

        return $this->fromPath($identifier);
    }

    /**
     * Sets a size configuration that will be applied to the resource.
     *
     * @param int|string|array|PictureConfiguration|null $size A picture size configuration or reference
     */
    public function setSize(PictureConfiguration|array|int|string|null $size): self
    {
        $this->sizeConfiguration = $size;

        return $this;
    }

    /**
     * Sets resize options.
     *
     * By default, or if the argument is set to null, resize options are derived
     * from predefined image sizes.
     */
    public function setResizeOptions(ResizeOptions|null $resizeOptions): self
    {
        $this->resizeOptions = $resizeOptions;

        return $this;
    }

    /**
     * Sets custom metadata.
     *
     * By default, or if the argument is set to null, metadata is trying to be
     * pulled from the FilesModel.
     */
    public function setMetadata(Metadata|null $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Disables creating/using metadata in the output even if it is present.
     */
    public function disableMetadata(bool $disable = true): self
    {
        $this->disableMetadata = $disable;

        return $this;
    }

    /**
     * Sets a custom locale.
     *
     * By default, or if the argument is set to null, the locale is determined
     * from the request context and/or system settings.
     */
    public function setLocale(string|null $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    /**
     * Adds a custom link attribute.
     *
     * Set the value to null to remove it. If you want to explicitly remove an
     * auto-generated value from the results, set the $forceRemove flag to true.
     */
    public function setLinkAttribute(string $attribute, string|null $value, bool $forceRemove = false): self
    {
        if (null !== $value || $forceRemove) {
            $this->additionalLinkAttributes[$attribute] = $value;
        } else {
            unset($this->additionalLinkAttributes[$attribute]);
        }

        return $this;
    }

    /**
     * Sets all custom link attributes as an associative array.
     *
     * This will overwrite previously set attributes. If you want to explicitly
     * remove an auto-generated value from the results, set the respective
     * attribute to null.
     */
    public function setLinkAttributes(HtmlAttributes|array $attributes): self
    {
        if ($attributes instanceof HtmlAttributes) {
            $this->additionalLinkAttributes = iterator_to_array($attributes);

            return $this;
        }

        foreach ($attributes as $key => $value) {
            if (!\is_string($key) || !\is_string($value)) {
                throw new \InvalidArgumentException('Link attributes must be an array of type <string, string>.');
            }
        }

        $this->additionalLinkAttributes = $attributes;

        return $this;
    }

    /**
     * Sets the link href attribute.
     *
     * Set the value to null to use the auto-generated default.
     */
    public function setLinkHref(string|null $url): self
    {
        $this->setLinkAttribute('href', $url);

        return $this;
    }

    /**
     * Sets a custom lightbox resource (file path or ImageInterface) or URL.
     *
     * By default, or if the argument is set to null, the image/target will be
     * automatically determined from the metadata or base resource. For this
     * setting to take effect, make sure you have enabled the creation of a
     * lightbox by calling enableLightbox().
     */
    public function setLightboxResourceOrUrl(ImageInterface|string|null $resourceOrUrl): self
    {
        $this->lightboxResourceOrUrl = $resourceOrUrl;

        return $this;
    }

    /**
     * Sets a size configuration that will be applied to the lightbox image.
     *
     * For this setting to take effect, make sure you have enabled the creation
     * of a lightbox by calling enableLightbox().
     *
     * @param int|string|array|PictureConfiguration|null $size A picture size configuration or reference
     */
    public function setLightboxSize(PictureConfiguration|array|int|string|null $size): self
    {
        $this->lightboxSizeConfiguration = $size;

        return $this;
    }

    /**
     * Sets resize options for the lightbox image.
     *
     * By default, or if the argument is set to null, resize options are derived
     * from predefined image sizes.
     */
    public function setLightboxResizeOptions(ResizeOptions|null $resizeOptions): self
    {
        $this->lightboxResizeOptions = $resizeOptions;

        return $this;
    }

    /**
     * Sets a custom lightbox group ID.
     *
     * By default, or if the argument is set to null, the ID will be empty. For
     * this setting to take effect, make sure you have enabled the creation of
     * a lightbox by calling enableLightbox().
     */
    public function setLightboxGroupIdentifier(string|null $identifier): self
    {
        $this->lightboxGroupIdentifier = $identifier;

        return $this;
    }

    /**
     * Enables the creation of a lightbox image (if possible) and/or
     * outputting the respective link attributes.
     *
     * This setting is disabled by default.
     */
    public function enableLightbox(bool $enable = true): self
    {
        $this->enableLightbox = $enable;

        return $this;
    }

    /**
     * Sets all template options as an associative array.
     */
    public function setOptions(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Returns the last InvalidResourceException that was captured when setting
     * resources or null if there was none.
     */
    public function getLastException(): InvalidResourceException|null
    {
        return $this->lastException;
    }

    /**
     * Creates a result object with the current settings, throws an exception
     * if the currently defined resource is invalid.
     *
     * @throws InvalidResourceException
     */
    public function build(): Figure
    {
        if (null !== $this->lastException) {
            throw $this->lastException;
        }

        return $this->doBuild();
    }

    /**
     * Creates a result object with the current settings, returns null if the
     * currently defined resource is invalid.
     */
    public function buildIfResourceExists(): Figure|null
    {
        if (null !== $this->lastException) {
            return null;
        }

        return $this->doBuild();
    }

    /**
     * Creates a result object with the current settings.
     */
    private function doBuild(): Figure
    {
        if (null === $this->filePath) {
            throw new \LogicException('You need to set a resource before building the result.');
        }

        // Freeze settings to allow reusing this builder object
        $settings = clone $this;

        $imageResult = $this->locator
            ->get('contao.image.studio')
            ->createImage($settings->filePath, $settings->sizeConfiguration, $settings->resizeOptions)
        ;

        // Define the values via closure to make their evaluation lazy
        return new Figure(
            $imageResult,
            \Closure::bind(
                function (Figure $figure): ?Metadata {
                    $event = new FileMetadataEvent($this->onDefineMetadata());

                    $this->locator->get('event_dispatcher')->dispatch($event);

                    return $event->getMetadata();
                },
                $settings
            ),
            \Closure::bind(
                fn (Figure $figure): array => $this->onDefineLinkAttributes($figure),
                $settings
            ),
            \Closure::bind(
                fn (Figure $figure): ?LightboxResult => $this->onDefineLightboxResult($figure),
                $settings
            ),
            $settings->options
        );
    }

    /**
     * Defines metadata on demand.
     */
    private function onDefineMetadata(): Metadata|null
    {
        if ($this->disableMetadata) {
            return null;
        }

        $getUuid = static function (?FilesModel $filesModel): ?string {
            if (null === $filesModel || null === $filesModel->uuid) {
                return null;
            }

            // Normalize UUID to ASCII format
            return Validator::isBinaryUuid($filesModel->uuid)
                ? StringUtil::binToUuid($filesModel->uuid)
                : $filesModel->uuid;
        };

        $fileReferenceData = array_filter([Metadata::VALUE_UUID => $getUuid($this->filesModel)]);

        if (null !== $this->metadata) {
            return $this->metadata->with($fileReferenceData);
        }

        if (null === $this->filesModel) {
            return null;
        }

        // Get fallback locale list or use without fallbacks if explicitly set
        $locales = null !== $this->locale ? [$this->locale] : $this->getFallbackLocaleList();
        $metadata = $this->filesModel->getMetadata(...$locales);

        if (null !== $metadata) {
            return $metadata->with($fileReferenceData);
        }

        // If no metadata can be obtained from the model, we create a container
        // from the default meta fields with empty values instead
        $metaFields = $this->getFilesModelAdapter()->getMetaFields();

        $data = array_merge(
            array_combine($metaFields, array_fill(0, \count($metaFields), '')),
            $fileReferenceData
        );

        return new Metadata($data);
    }

    /**
     * Defines link attributes on demand.
     */
    private function onDefineLinkAttributes(Figure $result): array
    {
        $linkAttributes = [];

        // Open in a new window if lightbox was requested but is invalid (fullsize)
        if ($this->enableLightbox && !$result->hasLightbox()) {
            $linkAttributes['target'] = '_blank';
        }

        return array_merge($linkAttributes, $this->additionalLinkAttributes);
    }

    /**
     * Defines the lightbox result (if enabled) on demand.
     */
    private function onDefineLightboxResult(Figure $result): LightboxResult|null
    {
        if (!$this->enableLightbox) {
            return null;
        }

        $getMetadataUrl = static function () use ($result): ?string {
            if (!$result->hasMetadata()) {
                return null;
            }

            return $result->getMetadata()->getUrl() ?: null;
        };

        $getResourceOrUrl = function ($target): array {
            if ($target instanceof ImageInterface) {
                return [$target, null];
            }

            $validExtension = \in_array(Path::getExtension($target, true), $this->validExtensions, true);
            $externalUrl = 1 === preg_match('#^https?://#', $target);

            if (!$validExtension) {
                return [null, null];
            }

            if ($externalUrl) {
                return [null, $target];
            }

            $target = urldecode($target);

            $filePath = Path::isAbsolute($target) ?
                Path::canonicalize($target) :
                Path::makeAbsolute($target, $this->projectDir);

            if (!is_file($filePath)) {
                $filePath = null;
            }

            return [$filePath, null];
        };

        // Use explicitly set data (1), fall back to using metadata (2) or use the base resource (3) if empty
        $lightboxResourceOrUrl = $this->lightboxResourceOrUrl ?? $getMetadataUrl() ?? $this->filePath;

        [$filePathOrImage, $url] = $getResourceOrUrl($lightboxResourceOrUrl);

        if (null === $filePathOrImage && null === $url) {
            return null;
        }

        return $this->locator
            ->get('contao.image.studio')
            ->createLightboxImage(
                $filePathOrImage,
                $url,
                $this->lightboxSizeConfiguration,
                $this->lightboxGroupIdentifier,
                $this->lightboxResizeOptions
            )
        ;
    }

    /**
     * @return FilesModel
     *
     * @phpstan-return Adapter<FilesModel>
     */
    private function getFilesModelAdapter(): Adapter
    {
        $framework = $this->locator->get('contao.framework');
        $framework->initialize();

        return $framework->getAdapter(FilesModel::class);
    }

    /**
     * @return Validator
     *
     * @phpstan-return Adapter<Validator>
     */
    private function getValidatorAdapter(): Adapter
    {
        $framework = $this->locator->get('contao.framework');
        $framework->initialize();

        return $framework->getAdapter(Validator::class);
    }

    /**
     * Returns a list of locales (if available) in the following order:
     *  1. language of current page,
     *  2. root page fallback language.
     */
    private function getFallbackLocaleList(): array
    {
        $page = $GLOBALS['objPage'] ?? null;

        if (!$page instanceof PageModel) {
            return [];
        }

        $locales = [LocaleUtil::formatAsLocale($page->language)];

        if (null !== $page->rootFallbackLanguage) {
            $locales[] = LocaleUtil::formatAsLocale($page->rootFallbackLanguage);
        }

        return array_unique(array_filter($locales));
    }
}
