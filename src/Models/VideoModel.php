<?php

namespace AdvancedLearning\AWSVideos\Models;

/**
 * Interface VideoModel
 *
 * Represents a model for storing data about a video.
 *
 * @package AdvancedLearning\AWSVideos\Models\VideoModel
 */
interface VideoModel
{
    /**
     * Get the full path to the video.
     *
     * @return string
     */
    public function getVideoPath();

    /**
     * Find a VideoModel by it's ID.
     *
     * @param integer $id The ID of the VideoModel to find.
     *
     * @return VideoModel
     */
    public function findByID($id);

    /**
     * Store any outputs from video processing.
     *
     * @param array   $outputs   Array of video outputs.
     * @param string  $playlist  Optional path of playlist.
     * @param string  $thumbnail The path to the thumbnail.
     * @param integer $duration  The duration of video.
     *
     * @return static
     */
    public function setOutputs(array $outputs, $playlist = null, $thumbnail = null, int $duration = 0);

    /**
     * Get the outputs from previous video processing.
     *
     * @return array
     */
    public function getOutputs();

    /**
     * Called when processing videos is complete.
     *
     * @return mixed
     */
    public function onProcessingComplete();

    /**
     * Store data about the job for processing the video.
     *
     * @param array $job Job data.
     *
     * @return static
     */
    public function setJobData(array $job);

    /**
     * Get the stored job data.
     *
     * @return array
     */
    public function getJobData();

    /**
     * Save any updated data.
     *
     * @return void
     */
    public function persist();
}