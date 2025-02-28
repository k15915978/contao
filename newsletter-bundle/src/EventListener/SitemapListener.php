<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\NewsletterBundle\EventListener;

use Contao\CoreBundle\Event\SitemapEvent;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Database;
use Contao\NewsletterChannelModel;
use Contao\NewsletterModel;
use Contao\PageModel;

class SitemapListener
{
    public function __construct(private ContaoFramework $framework)
    {
    }

    public function __invoke(SitemapEvent $event): void
    {
        $arrRoot = $this->framework->createInstance(Database::class)->getChildRecords($event->getRootPageIds(), 'tl_page');

        // Early return here in the unlikely case that there are no pages
        if (empty($arrRoot)) {
            return;
        }

        $arrPages = [];
        $arrProcessed = [];
        $time = time();

        // Get all calendars
        $objNewsletters = $this->framework->getAdapter(NewsletterChannelModel::class)->findAll();

        if (null === $objNewsletters) {
            return;
        }

        // Walk through each channel
        foreach ($objNewsletters as $objNewsletter) {
            if (!$objNewsletter->jumpTo) {
                continue;
            }

            // Skip channels outside the root nodes
            if (!empty($arrRoot) && !\in_array($objNewsletter->jumpTo, $arrRoot, true)) {
                continue;
            }

            // Get the URL of the jumpTo page
            if (!isset($arrProcessed[$objNewsletter->jumpTo])) {
                $objParent = $this->framework->getAdapter(PageModel::class)->findWithDetails($objNewsletter->jumpTo);

                // The target page does not exist
                if (null === $objParent) {
                    continue;
                }

                // The target page has not been published (see #5520)
                if (!$objParent->published || ($objParent->start && $objParent->start > $time) || ($objParent->stop && $objParent->stop <= $time)) {
                    continue;
                }

                // The target page is protected (see #8416)
                if ($objParent->protected) {
                    continue;
                }

                // The target page is exempt from the sitemap (see #6418)
                if ('noindex,nofollow' === $objParent->robots) {
                    continue;
                }

                // Generate the URL
                $arrProcessed[$objNewsletter->jumpTo] = $objParent->getAbsoluteUrl('/%s');
            }

            $strUrl = $arrProcessed[$objNewsletter->jumpTo];

            // Get the items
            $objItems = $this->framework->getAdapter(NewsletterModel::class)->findSentByPid($objNewsletter->id);

            if (null === $objItems) {
                continue;
            }

            foreach ($objItems as $objItem) {
                $arrPages[] = sprintf(preg_replace('/%(?!s)/', '%%', $strUrl), ($objItem->alias ?: $objItem->id));
            }
        }

        foreach ($arrPages as $strUrl) {
            $event->addUrlToDefaultUrlSet($strUrl);
        }
    }
}
