<?php

namespace AdvancedLearning\AWSVideos\Tasks;

use AdvancedLearning\AWSVideos\Services\VideoService;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;

class TranscodeVideoCheckTask extends BuildTask
{
    protected $title = "TranscodeVideoCheckTask";

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
