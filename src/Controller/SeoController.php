<?php

namespace App\Controller;

use App\Model\Setting;
use App\Service\SeoService;

class SeoController
{
    public function robotsTxt(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        echo SeoService::renderRobotsTxt();
        exit;
    }

    public function sitemapXml(): void
    {
        if (Setting::get('seo_sitemap_enabled', '1') !== '1') {
            http_response_code(404);
            echo 'Sitemap disabled';
            exit;
        }
        header('Content-Type: application/xml; charset=utf-8');
        echo SeoService::renderSitemapXml();
        exit;
    }
}
