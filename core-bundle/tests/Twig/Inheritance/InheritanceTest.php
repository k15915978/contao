<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Tests\Twig\Inheritance;

use Contao\CoreBundle\HttpKernel\Bundle\ContaoModuleBundle;
use Contao\CoreBundle\Tests\TestCase;
use Contao\CoreBundle\Twig\Extension\ContaoExtension;
use Contao\CoreBundle\Twig\Loader\ContaoFilesystemLoader;
use Contao\CoreBundle\Twig\Loader\ContaoFilesystemLoaderWarmer;
use Contao\CoreBundle\Twig\Loader\TemplateLocator;
use Contao\CoreBundle\Twig\Loader\ThemeNamespace;
use Doctrine\DBAL\Connection;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Twig\Environment;

/**
 * Integration tests.
 */
class InheritanceTest extends TestCase
{
    public function testInheritsMultipleTimes(): void
    {
        $environment = $this->getDemoEnvironment();
        $html = $environment->render('@Contao/text.html.twig', ['content' => 'This &amp; that']);

        // Global > App > BarBundle > FooBundle > CoreBundle
        $expected = '<global><app><bar><foo>Content: This &amp; that</foo></bar></app></global>';

        $this->assertSame($expected, $html);
    }

    public function testInheritsMultipleTimesWithTheme(): void
    {
        $environment = $this->getDemoEnvironment();

        $page = new \stdClass();
        $page->templateGroup = 'templates/my/theme';

        $GLOBALS['objPage'] = $page;

        $html = $environment->render('@Contao/text.html.twig', ['content' => 'This &amp; that']);

        // Theme > Global > App > BarBundle > FooBundle > CoreBundle
        $expected = '<theme><global><app><bar><foo>Content: This &amp; that</foo></bar></app></global></theme>';

        $this->assertSame($expected, $html);

        unset($GLOBALS['objPage']);
    }

    public function testThrowsIfTemplatesAreAmbiguous(): void
    {
        $bundlePath = Path::canonicalize(__DIR__.'/../../Fixtures/Twig/inheritance/vendor-bundles/InvalidBundle');

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage('There cannot be more than one "foo" template in "'.$bundlePath.'/templates".');

        $this->getDemoEnvironment(['InvalidBundle' => ['path' => $bundlePath]]);
    }

    private function getDemoEnvironment(array $bundlesMetadata = null): Environment
    {
        $projectDir = Path::canonicalize(__DIR__.'/../../Fixtures/Twig/inheritance');

        $bundlesMetadata ??= [
            'CoreBundle' => ['path' => Path::join($projectDir, 'vendor-bundles/CoreBundle')],
            'FooBundle' => ['path' => Path::join($projectDir, 'vendor-bundles/FooBundle')],
            'BarBundle' => ['path' => Path::join($projectDir, 'vendor-bundles/BarBundle')],
            'App' => ['path' => Path::join($projectDir, 'contao')],
        ];

        $bundles = array_combine(
            array_keys($bundlesMetadata),
            array_fill(0, \count($bundlesMetadata), ContaoModuleBundle::class)
        );

        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchFirstColumn')
            ->willReturn(['templates/my/theme'])
        ;

        $themeNamespace = new ThemeNamespace();

        $templateLocator = new TemplateLocator($projectDir, $bundles, $bundlesMetadata, $themeNamespace, $connection);
        $loader = new ContaoFilesystemLoader(new NullAdapter(), $templateLocator, $themeNamespace, $projectDir);
        $filesystem = $this->createMock(Filesystem::class);

        $warmer = new ContaoFilesystemLoaderWarmer($loader, $templateLocator, $projectDir, 'cache', 'prod', $filesystem);
        $warmer->warmUp('');

        $environment = new Environment($loader);

        $contaoExtension = new ContaoExtension($environment, $loader);
        $environment->addExtension($contaoExtension);

        return $environment;
    }
}
