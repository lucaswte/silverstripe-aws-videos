<?php
/**
 * Created by PhpStorm.
 * User: Conrad
 * Date: 17/10/2017
 * Time: 2:33 PM
 */

namespace AdvancedLearning\AWSVideos\Services;


interface VideoService
{
    /**
     * Adds the video to the service.
     *
     * @param integer $id   Identifier of the video.
     *
     * @return mixed
     */
    public function commit($id);

    /**
     * Check on the status of job. Update if complete.
     *
     * @param integer $id ID of Video Model.
     *
     * @return void
     */
    public function check($id);
}
