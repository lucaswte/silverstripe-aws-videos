<?php

/**
 * Admin for AWSVideoAdmin
 */
class AWSVideoAdmin extends ModelAdmin
{
    private static $menu_title = 'AWS Videos';

    private static $managed_models = ['AWSVideo'];

    private static $url_segment = 'aws-videos';
}
