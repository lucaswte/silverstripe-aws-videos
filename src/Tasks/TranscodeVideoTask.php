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
           Injector::inst()->get('AdvancedLearning\Services\VideoService')->commit($id);
       }
    }

}
