<?php

use AdvancedLearning\AWSVideos\Models\VideoModel;

/**
 * Represents a video stored in AWS
 */
class AWSVideo extends DataObject implements VideoModel
{
    private static $singular_name = 'AWS Video';

    private static $plural_name = 'AWS Videos';

    /**
     * Base URL for videos, typically CDN or other hosting service.
     *
     * @config
     * @var string
     */
    private static $video_base_url;

    private static $db = [
        'Original' => 'Varchar(150)',
        'DeleteUploaded' => 'Boolean',
        'Submitted' => 'Boolean',
        'Videos' => 'Text',
        'Thumbnail' => 'Varchar(255)',
        'Playlist' => 'Varchar(255)',
        'Job' => 'Text',
        'Duration' => 'Int',
    ];

    private static $defaults = [
        'DeleteUploaded' => 1
    ];

    private static $has_one = [
        'File' => 'File'
    ];

    private static $summary_fields = [
        'Thumb' => 'Thumb',
        'Original' => 'Original',
        'Submitted.Nice' => 'Submitted'
    ];

    private static $casting = [
        'Thumb' => 'HTMLText',
        'Fallbacks' => 'ArrayList'
    ];

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        // set original filename
        if ($this->File()->exists()) {
            $this->Original = basename($this->File()->Filename);
        }
    }

    public function onAfterWrite()
    {
        parent::onAfterWrite();

        if ($this->File()->exists() && !$this->Submitted)
        {
            $this->getVideoService()->queue($this->ID);
            $this->Submitted = true;
            $this->write();
        }
    }

    /**
     * @inheritdoc
     *
     * @return static
     */
    public function setOutputs(array $outputs, $playlist = null, $thumbnail = null, int $duration = 0)
    {
        $this->Videos = json_encode($outputs);

        if (null !== $playlist) {
            $this->Playlist = $playlist;
        }

        if (null !== $thumbnail) {
            $this->Thumbnail = $thumbnail;
        }

        $this->Duration = $duration;

        $this->write();

        return $this;
    }

    /**
     * @inheritdoc
     *
     * @return array
     */
    public function getOutputs()
    {
        return [
            'Videos' => $this->Videos ? json_decode($this->Videos, true) : null,
            'Playlist' => $this->Playlist ?: null,
            'Thumbnail' => $this->Thumbnail ?: null
        ];
    }

    /**
     * @inheritdoc
     *
     * @return static
     */
    public function findByID($id)
    {
        return self::get()->byID($id);
    }

    /**
     * @inheritdoc
     */
    public function onProcessingComplete()
    {
        if ($this->DeleteUploaded) {
            $this->File()->delete();
        }
    }

    /**
     * @inheritdoc
     *
     * @return string
     */
    public function getVideoPath()
    {
        return $this->File()->getFullPath();
    }

    /**
     * @inheritdoc
     *
     * @return $this
     */
    public function setJobData(array $job)
    {
        $this->Job = json_encode($job);
        $this->write();

        return $this;
    }

    /**
     * @inheritdoc
     *
     * @return array|null
     */
    public function getJobData()
    {
        return $this->Job ? json_decode($this->Job, true) : null;
    }

    /**
     * @inheritdoc
     *
     * @return void
     */
    public function persist()
    {
        $this->write();
    }

    /**
     * Gets an image tag for thumbnail. Used for summary fields.
     *
     * @return string
     */
    public function Thumb()
    {
        return DBField::create_field('HTMLText', $this->Thumbnail
            ? "<img src=\"{$this->getHostedThumbnail()}\" style=\"width: 100px; height: auto;\">"
            : '');
    }

    /**
     * Gets hosted url of the thumbnail.
     *
     * @return string
     */
    public function getHostedThumbnail()
    {
       return $this->getHostedUrl($this->Thumbnail);
    }

    /**
     * Gets hosted url of the playlist file.
     *
     * @return string
     */
    public function getHostedPlaylist()
    {
        return $this->getHostedUrl($this->Playlist);
    }

    /**
     * Gets the full hosted url (appends video_base_url).
     *
     * @param string $url Relative url.
     *
     * @return string
     */
    private function getHostedUrl($url)
    {
        return self::config()->get('video_base_url') . '/' . $url;
    }

    /**
     * Generates fallbacks to be used for video source tag.
     *
     * @return array
     */
    public function getFallbacks()
    {
        $outputs = $this->getOutputs();

        $fallbacks = [];

        foreach ($outputs['Videos'] as $video) {
            $file = new SplFileInfo($video);
            $fallbacks[$this->getVideoService()->typeFromExt($file->getExtension())] = $this->getHostedUrl($video);
        }

        return $fallbacks;
    }

    /**
     * Returns getFallbacks as JSON.
     *
     * @return string
     */
    public function getFallbacksJSON()
    {
        return json_encode($this->getFallbacks());
    }

    /**
     * Get the video service.
     *
     * @return AdvancedLearning\AWSVideos\Services\AWSVideoService
     */
    protected function getVideoService()
    {
        return Injector::inst()->get('AdvancedLearning\AWSVideos\Services\VideoService');
    }
}
