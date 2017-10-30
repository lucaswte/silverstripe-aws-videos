<?php
/**
 * Created by PhpStorm.
 * User: Conrad
 * Date: 18/10/2017
 * Time: 12:38 PM
 */

class TranscodeVideoCheckTask extends BuildTask
{
    /**
     * Execute task.
     *
     * @param SS_HTTPRequest $request The request.
     */
    public function run($request)
    {
       if ($id = $request->getVar('ID')) {
           try {
               Injector::inst()->get('AdvancedLearning\AWSVideos\Services\VideoService')->check($id);
           } catch (Exception $e) {
               SS_Log::log($e->getMessage(), SS_Log::ERR);
           }
       }
    }
}
