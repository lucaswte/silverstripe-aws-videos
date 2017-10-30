<?php
/**
 * Created by PhpStorm.
 * User: Conrad
 * Date: 18/10/2017
 * Time: 10:42 AM
 */

class TranscodeVideoTask extends BuildTask
{
    /**
     * Execute the task.
     *
     * @param SS_HTTPRequest $request
     */
    public function run($request)
    {
       if ($id = $request->getVar('ID')) {
           try {
               Injector::inst()->get('AdvancedLearning\AWSVideos\Services\VideoService')->commit($id);
           } catch (Exception $e) {
               SS_Log::log($e->getMessage(), SS_Log::ERR);
           }
       }
    }

}
