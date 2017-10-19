<?php

namespace AdvancedLearning\AWSVideos\Services;


use AdvancedLearning\AWSVideos\Config\Configurable;
use AdvancedLearning\AWSVideos\Models\VideoModel;
use Aws\ElasticTranscoder\ElasticTranscoderClient;
use Aws\S3\S3Client;
use function basename;
use DevTaskRun;
use function getenv;
use InvalidArgumentException;
use League\Flysystem\AwsS3v2\AwsS3Adapter;
use League\Flysystem\Filesystem;
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
     * @var array
     */
    protected static $playlist_outputs;

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
        $video->setJob($job)
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
            if ($job['Status'] === 'complete') {
                $this->complete($video, $job);
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
        $playlist = '';

        if (!empty($job['Playlists'])) {
            $playlist = $job['Playlists'][0]['Name'];
        }

        $video->setOutputs($outputs, $playlist, $thumbnail);

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

        foreach ($job['Outputs'] as $output) {
            $outputs[] = $output['Key'];
        }

        return $outputs;
    }

    /**
     * Extracts the first thumbnail from output.
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
                    [$this->setExtension($output['Key'], null), '00001'],
                    $output['ThumbnailPattern']
                );
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
            $this->s3Client = S3Client::factory($this->getAWSConfig());
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
            $this->transcoderClient = ElasticTranscoderClient::factory($this->getAWSConfig());
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
            'region' => 'ap-southeast-2'
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

        $job = [
            'PipelineId' => $pipeline,
            'Input' => [
                'Key' => $filename
            ],
            'Outputs' => []
        ];

        // setup outputs
        foreach ($outputs as $preset => $config) {
            $name = $this->setExtension($filename, null);

            // replace name in key
            $config['Key'] = str_replace('{name}', $name, $config['Key']);

            // replace name in thumbnail
            if (!empty($config['ThumbnailPattern'])) {
                $config['ThumbnailPattern'] = str_replace('{name}', $name, $config['ThumbnailPattern']);
            }

            // set preset
            $config['PresetId'] = $preset;

            $job['Outputs'][] = $config;
        }

        // setup playlist
        if ($playlist) {
            $playlistConfig = [
                'Format' => $playlist,
                'Name' => $this->setExtension($filename, 'mpd'),
                'OutputKeys' => []
            ];

            // add outputs to playlist
            foreach ($job['Outputs'] as $output) {
                if (in_array($output['Key'], $playlistOutputs)) {
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
     * Replaces extension on filename with the passed extension.
     *
     * @param string $filename The filename to have extension replaced.
     * @param string $ext      The extension to be used.
     *
     * @return string
     */
    protected function setExtension($filename, $ext)
    {
        return substr($filename, 0, strrpos($filename, '.')) . '.' . $ext;
    }

    /**
     * Gets the AWS API Key. Checks env for AWS_VIDEO_KEY, falls back to config var aws_key.
     *
     * @return string
     */
    protected function getAWSKey()
    {
        return getenv('AWS_VIDEO_KEY') ?: self::config()->aws_key;
    }

    /**
     * Gets the AWS API Secret. Checks env for AWS_VIDEO_SECRET, falls back to config var aws_secret.
     *
     * @return string
     */
    protected function getAWSSecret()
    {
        return getenv('AWS_VIDEO_KEY') ?: self::config()->aws_secret;
    }
}
