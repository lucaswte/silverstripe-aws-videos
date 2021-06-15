<?php

namespace AdvancedLearning\AWSVideos\Services;

use AdvancedLearning\AWSVideos\Config\Configurable;
use AdvancedLearning\AWSVideos\Models\VideoModel;
use Aws\ElasticTranscoder\ElasticTranscoderClient;
use Aws\S3\S3Client;
use function basename;
use DevTaskRun;
use function getenv;
use function in_array;
use InvalidArgumentException;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\Filesystem;
use function method_exists;
use const PHP_EOL;
use function str_replace;
use function strrpos;

class AWSVideoService implements VideoService
{
    use Configurable;

    /**
     * @var S3Client
     */
    protected $s3Client;

    /**
     * @var ElasticTranscoderClient
     */
    protected $transcoderClient;

    /**
     * AWS API Key, overridden by env AWS_VIDEO_KEY.
     *
     * @config
     * @var string
     */
    protected static $aws_key;

    /**
     * AWS API Secret, overridden by env AWS_VIDEO_SECRET.
     *
     * @config
     * @var string
     */
    protected static $aws_secret;

    /**
     * S3 bucket to load videos from
     *
     * @var string
     */
    protected static $bucket;

    /**
     * S3 bucket to store transcoded videos
     *
     * @config
     * @var string
     */
    protected static $transcoded_bucket;

    /**
     * AWS Transcode pipeline to use.
     *
     * @config
     * @var string
     */
    protected static $pipeline;

    /**
     * AWS Transcoder outputs to be used to generate outputs. Key is the preset id which has an array of key value/pairs
     * of output setings to pass to the AWS Transcoder. {name} in Key and ThumbnailPattern is replaced with the name of
     * the file (extension removed).
     *
     * e.g.
     *
     * <code>
     * [
     *     '1351620000001-500020' => [
     *         Key: '{name}-4800k.mp4',
     *         ThumbnailPattern: '{name}-{count}.jpg',
     *         SegmentDuration: '10'
     *     ]
     * ]
     * </code>
     *
     * @config
     * @var array
     */
    protected static $outputs;

    /**
     * Optional playlist.
     *
     * @config
     * @var string
     */
    protected static $playlist;

    /**
     * Outputs to include in the playist. Array of the preset ids.
     *
     * @config
     * @var array
     */
    protected static $playlist_outputs;

    /**
     * File extension of the thumbnail. Used to get the thumbnail file name.
     *
     * @config
     * @var string
     */
    protected $thumbnail_extension;

    /**
     * The thumbail to use. Number of thumbnails are generated based on the interval set. Defaults to 1 (first).
     *
     * @config
     * @var integer
     */
    protected $thumbnail_number = 1;

    /**
     * If true, output files are stored inside a directory the same as the input filename with no extension.
     *
     * @config
     * @var boolean
     */
    protected static $use_directory = false;

    /**
     * Model for accessing persistent storage of video data.
     *
     * @var VideoModel
     */
    protected $model;

    public function __construct(VideoModel $model)
    {
        $this->model = $model;
    }

    /**
     * Queue an AWS Video to be transcoded.
     *
     * @param integer $id The id of the Video Model to be transcoded.
     *
     * @return void
     */
    public function queue($id)
    {
        $task = DevTaskRun::create([
            'Task' => 'TranscodeVideoTask',
            'Params' => 'ID=' . $id,
            'Status' => 'Queued'
        ]);

        $task->write();
    }

    /**
     * Queue a task to check on the status of the AWS Transcoder Job.
     *
     * @param integer $id The ID of the AWS Video
     *
     * @return void
     */
    public function queueCheck($id)
    {
        $task = DevTaskRun::create([
            'Task' => 'TranscodeVideoCheckTask',
            'Params' => 'ID=' . $id,
            'Status' => 'Queued'
        ]);

        $task->write();
    }

    /**
     * Commits an AWS Video to AWS, uploading it and queueing for transcoding.
     *
     * @param integer $id The id of the Video.
     *
     * @return void
     */
    public function commit($id)
    {
        $video = $this->model->findByID($id);

        if (!$video) {
            throw new InvalidArgumentException('Could not find a video with ID ' . $id);
        }

        $path = $video->getVideoPath();

        // upload
        $this->uploadToS3($path);

        // transcode
        $job = $this->transcode(
            $path,
            self::config()->get('pipeline'),
            self::config()->get('outputs'),
            self::config()->get('playlist'),
            self::config()->get('playlist_outputs')
        );

        // record AWS job so we can lookup later
        $video->setJobData($job)
            ->persist();

        // setup check to see if it is transcoded
        $this->queueCheck($id);
    }

    /**
     * Check on the status of job. Update if complete.
     *
     * @param integer $id ID of Video Model.
     *
     * @return void
     */
    public function check($id)
    {
        $video = $this->model->findByID($id);
        $client = $this->getTranscoderClient();
        $originalJob = $video->getJobData();

        if (empty($originalJob) || empty($originalJob['Id'])) {
            throw new InvalidArgumentException("No job data could be found for video {$id}");
        }

        $job = $client->readJob(['Id' => $originalJob['Id']])->get('Job');

        if ($job && $job['Status']) {
            if (strtolower($job['Status']) === 'complete') {
                $this->complete($video, $job);
            } else if (strtolower($job['Status']) === 'error') {
                throw new \Exception('Failed to process video ' . $video->getVideoPath());
            } else {
                $this->queueCheck($id);
            }
        }
    }

    /**
     * Perform complete tasks for trancoding job. Extracts outputs to be saved.
     *
     * @param VideoModel $video The Video Model for storage.
     * @param array      $job   The job data from AWS.
     *
     * @return void
     */
    protected function complete(VideoModel $video, array $job)
    {
        // get transcoded outputs
        $outputs = $this->extractVideoOutputs($job);
        $thumbnail = $this->extractThumbnail($job);
        $duration = $this->extractVideoDuration($job);
        $playlist = '';

        if (!empty($job['Playlists'])) {
            $playlist = $job['Playlists'][0]['Name'] . '.' . self::config()->get('playlist_extension');
        }

        $video->setOutputs($outputs, $playlist, $thumbnail, $duration);

        // make files public
        foreach ($outputs as $output) {
            $this->publish($output);
        }

        // make playlist public
        if (!empty($playlist)) {
            $this->publish($playlist);
        }

        // make thumbnail public
        if (!empty($thumbnail)) {
            $this->publish($thumbnail);
        }

        // trigger complete on video
        if (method_exists($video, 'onProcessingComplete')) {
            $video->onProcessingComplete();
        }
    }

    /**
     * Gets the Key (filename) of video outputs.
     *
     * @param array $job The job data.
     *
     * @return array
     */
    protected function extractVideoOutputs(array $job)
    {
        $outputs = [];
        $playlistOutputs = self::config()->get('playlist_outputs');

        foreach ($job['Outputs'] as $output) {
            // don't include files in playlist
            if (
                !$playlistOutputs ||
                !in_array($output['PresetId'], $playlistOutputs)
            ) {
                $outputs[] = $output['Key'];
            }
        }

        return $outputs;
    }

    /**
     * Gets the duration(seconds) of video outputs.
     *
     * @param array $job The job data.
     *
     * @return integer
     */
    protected function extractVideoDuration(array $job): int
    {
        $duration = 0;
        $durationOutput = self::config()->get('duration');

        $output = $job['Output'];

        if (is_array($output) && $durationOutput !== null && isset($output[$durationOutput])) {
            $duration = $output[$durationOutput];
        }

        return $duration;
    }

    /**
     * Extracts the first thumbnail from output. Assumes only one output generates a thumbnail.
     *
     * @param array $job The job data.
     *
     * @return string|null
     */
    protected function extractThumbnail(array $job)
    {
        foreach ($job['Outputs'] as $output) {
            // find the first output with a thumbnail pattern
            if (!empty($output['ThumbnailPattern'])) {
                // return the first thumbnail
                return str_replace(
                    ['{name}', '{count}'],
                    [$this->setExtension($output['Key'], null), '0000' . self::config()->get('thumbnail_number')],
                    $output['ThumbnailPattern']
                ) . '.' . self::config()->get('thumbnail_extension');
            }
        }

        return null;
    }

    /**
     * Gets the S3 client for AWS.
     *
     * @return S3Client
     */
    protected function getS3Client()
    {
        if (!$this->s3Client) {
            $this->s3Client = new S3Client($this->getAWSConfig());
        }

        return $this->s3Client;
    }

    /**
     * Gets the transcoder client for AWS.
     *
     * @return ElasticTranscoderClient
     */
    protected function getTranscoderClient()
    {
        if (!$this->transcoderClient) {
            $config = $this->getAWSConfig();
            // set transcoder api version
            $config['version'] = '2012-09-25';
            $this->transcoderClient = new ElasticTranscoderClient($config);
        }

        return $this->transcoderClient;
    }

    /**
     * Gets the config for connecting to AWS.
     *
     * @return array
     */
    protected function getAWSConfig()
    {
        return [
            'credentials' => [
                'key' => $this->getAWSKey(),
                'secret' => $this->getAWSSecret()
            ],
            'region' => 'ap-southeast-2',
            'version' => '2006-03-01'
        ];
    }

    /**
     * Upload the file to S3.
     *
     * @param $path
     */
    protected function uploadToS3($path)
    {
        $filename = basename($path);

        $adapter = new AwsS3Adapter($this->getS3Client(), self::config()->get('bucket'));
        $filesystem = new Filesystem($adapter);

        // check if it exists
        if (!$filesystem->has($filename)) {
            // get file stream
            $fileStream = fopen($path, 'r');

            // write
            $filesystem->writeStream($filename, $fileStream);

            // close stream if still open
            if (is_resource($fileStream)) {
                fclose($fileStream);
            }
        }
    }

    /**
     * Publish a file and make public.
     *
     * @param string $filename The object to make public.
     *
     * @return static
     */
    public function publish($filename)
    {
        $adapter = new AwsS3Adapter($this->getS3Client(), self::config()->get('transcoded_bucket'));
        $filesystem = new Filesystem($adapter);

        if ($filesystem->has($filename)) {
            $filesystem->setVisibility($filename, 'public');
        }

        return $this;
    }

    /**
     * Transcode the video. Returns the job response as an array.
     *
     * @param string $path            Path to file to be transcoded.
     * @param string $pipeline        Id of pipeline.
     * @param array  $outputs         Array of outputs to transcode the video to.
     * @param string $playlist        Type of playlist (optional).
     * @param array  $playlistOutputs Array of preset ids of outputs to be included in playlist.
     *
     * @return array
     */
    protected function transcode($path, $pipeline, $outputs, $playlist = null, array $playlistOutputs = null)
    {
        $filename = basename($path);
        $name = $this->setExtension($filename, null);
        $prefix = self::config()->get('use_directory') ? $prefix = $name . '/' : '';

        $job = [
            'PipelineId' => $pipeline,
            'Input' => [
                'Key' => $filename
            ],
            'Outputs' => []
        ];

        // setup outputs
        foreach ($outputs as $preset => $config) {
            // replace name in key
            $config['Key'] = $prefix . str_replace('{name}', $name, $config['Key']);


            // replace name in thumbnail
            if (!empty($config['ThumbnailPattern'])) {
                $config['ThumbnailPattern'] = $prefix . str_replace('{name}', $name, $config['ThumbnailPattern']);
            }

            // set preset
            $config['PresetId'] = $preset;

            $job['Outputs'][] = $config;
        }

        // setup playlist
        if ($playlist) {
            $playlistConfig = [
                'Format' => $playlist,
                'Name' => $prefix . $name,
                'OutputKeys' => []
            ];

            // add outputs to playlist
            foreach ($job['Outputs'] as $output) {
                if (in_array($output['PresetId'], $playlistOutputs)) {
                    $playlistConfig['OutputKeys'][] = $output['Key'];
                }
            }

            $job['Playlists'][] = $playlistConfig;
        }

        $client = $this->getTranscoderClient();
        $response = $client->createJob($job);

        return $response->get('Job');
    }

    /**
     * Gets the type for a Video source tag from the file extension.
     *
     * @param string $ext The file extension.
     *
     * @return string
     */
    public function typeFromExt($ext)
    {
        switch ($ext) {
            case 'webm':
                return 'video/webm';
            case 'mp4':
            default:
                return 'video/mp4';
        }
    }

    /**
     * Replaces extension on filename with the passed extension. If no extension is passed, the extension is removed.
     *
     * @param string $filename The filename to have extension replaced.
     * @param string $ext      The extension to be used.
     *
     * @return string
     */
    protected function setExtension($filename, $ext = null)
    {
        $name = substr($filename, 0, strrpos($filename, '.'));

        if (null !== $ext) {
            $name .= '.' . $ext;
        }

        return $name;
    }

    /**
     * Gets the AWS API Key. Checks env for AWS_VIDEO_KEY, falls back to config var aws_key.
     *
     * @return string
     */
    protected function getAWSKey()
    {
        return defined('AWS_VIDEO_KEY') ? AWS_VIDEO_KEY : self::config()->get('aws_key');
    }

    /**
     * Gets the AWS API Secret. Checks env for AWS_VIDEO_SECRET, falls back to config var aws_secret.
     *
     * @return string
     */
    protected function getAWSSecret()
    {
        return defined('AWS_VIDEO_SECRET') ? AWS_VIDEO_SECRET : self::config()->get('aws_secret');
    }
}
