<?php

use AdvancedLearning\AWSVideos\Models\VideoModel;

/**
 * Represents a video stored in AWS
 */
class AWSVideo extends DataObject implements VideoModel
{
    private static $singular_name = 'AWS Video';

    private static $plural_name = 'AWS Videos';

    private static $db = [
        'Original' => 'Varchar(150)',
        'DeleteUploaded' => 'Boolean',
        'Submitted' => 'Boolean',
        'Videos' => 'Text',
        'Thumbnail' => 'Varchar(255)',
        'Playlist' => 'Varchar(255)',
        'Job' => 'Text'
    ];

    private static $defaults = [
        'DeleteUploaded' => 1
    ];

    private static $has_one = [
        'File' => 'File'
    ];

    public function onAfterWrite()
    {
        parent::onAfterWrite();

        if ($this->File()->exists() && !$this->Submitted)
        {
//            Injector::inst()->get('VideoService')->queue($this->ID);
        }
    }

    /**
     * @inheritdoc
     *
     * @return static
     */
    public function setOutputs(array $outputs, $playlist = null, $thumbnail = null)
    {
        $this->Videos = json_encode($outputs);

        if (null !== $playlist) {
            $this->Playlist = $playlist;
        }

        if (null !== $thumbnail) {
            $this->Thumbnail = $thumbnail;
        }

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
     * @return array|null
     */
    public function getJobData()
    {
        return $this->Job ? json_decode($this->Job) : null;
    }

    public function persist()
    {
        $this->write();
    }
}
