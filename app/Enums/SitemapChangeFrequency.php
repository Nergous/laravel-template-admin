<?php

namespace App\Enums;

/** How often an address in /sitemap.xml changes (the changefreq tag). */
enum SitemapChangeFrequency: string
{
    case Always = 'always';
    case Hourly = 'hourly';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case Never = 'never';
}
