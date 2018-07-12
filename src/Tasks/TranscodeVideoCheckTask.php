<?php

use AdvancedLearning\AWSVideos\Services\VideoService;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;

class TranscodeVideoCheckTask extends BuildTask
{
    public function run($request)
    {
       if ($id = $request->getVar('ID')) {
           try {
               Injector::inst()->get(VideoService::class)->check($id);
           } catch (Exception $e) {
			   Injector::inst()->get(LoggerInterface::class)->error($e->getMessage());
           }
       }
    }
}
