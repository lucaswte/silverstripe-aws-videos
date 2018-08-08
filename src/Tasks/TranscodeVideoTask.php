<?php

namespace AdvancedLearning\AWSVideos\Tasks;

use AdvancedLearning\AWSVideos\Services\VideoService;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;

class TranscodeVideoTask extends BuildTask
{
    protected $title = "TranscodeVideoTask";

    public function run($request)
    {
       if ($id = $request->getVar('ID')) {
           try {
               Injector::inst()->get(VideoService::class)->commit($id);
           } catch (Exception $e) {
			   Injector::inst()->get(LoggerInterface::class)->error($e->getMessage());
           }
       }
    }
}
