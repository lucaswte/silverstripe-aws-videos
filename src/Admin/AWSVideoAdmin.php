<?php

namespace AdvancedLearning\AWSVideos\Admin;

use AdvancedLearning\AWSVideos\Models\AWSVideo;
use SilverStripe\Admin\ModelAdmin;

/**
 * Admin for AWSVideoAdmin
 */
class AWSVideoAdmin extends ModelAdmin
{
    private static $menu_title = 'AWS Videos';

    private static $managed_models = [AWSVideo::class];

    private static $url_segment = 'aws-videos';
}
