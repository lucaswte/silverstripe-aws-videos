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
           Injector::inst()->get('AdvancedLearning\Services\VideoService')->check($id);
       }
    }
}
