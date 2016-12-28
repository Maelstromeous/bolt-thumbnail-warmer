<?php

namespace Bolt\Extension\Maelstromeous\ThumbnailWarmer;


use Bolt\Events\StorageEvent;
use Bolt\Events\StorageEvents;
use Bolt\Extension\SimpleExtension;
use Bolt\Filesystem\Handler\Image\Dimensions;
use Bolt\Thumbs\Action;
use Bolt\Thumbs\Controller;
use Bolt\Thumbs\Transaction;
use Silex\Application;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * ThumbnailWarmer Extension for pre-caching / warming thumbnails
 *
 * @author Matt Cavanagh <maelstrome26@gmail.com>
 */
class ThumbnailWarmerExtension extends SimpleExtension
{
    protected $basePath;
    protected $images = [];

    public static function getSubscribedEvents()
    {
        $parent = parent::getSubscribedEvents();
        $events = [
            StorageEvents::POST_SAVE => [
                ['check', 0]
            ]
        ];

        return $parent + $events;
    }

    /**
     * Checks appropiate configs and the upload event for paths to hit for generation.
     *
     * @param  Bolt\Events\StorageEvent $event
     *
     * @return void
     */
    public function check(StorageEvent $event)
    {
        $app = $this->getContainer();

        // Ensure we have the root path so we can build our thumbnail requests
        $this->basePath = $app['resources']->getUrl('hosturl');

        // Get ContentType info
        $type = $event->getContenttype();
        $contentType = $app['config']->get("contenttypes/{$type}");
        $fields = $contentType['fields'];

        // Get upload info
        $data = $event->getSubject()->_fields;

        // Check to see if we have any images
        foreach ($fields as $key => $image) {
            if ($image['type'] === 'image' && (! empty($image['cache']))) {
                // Check if we have a reference in the event. If not, abort.
                if (empty($data[$key])) {
                    return false;
                }

                // Get the filepath we'll need to run the processing on
                $file = $data[$key]['file'];

                if (! empty($image['cache']['aliases'])) {
                    $this->processAliases($image, $file);
                }
                if (! empty($image['cache']['sizes'])) {
                    $this->processSizes($image, $file);
                }
            }
        }

        if (! empty($this->images)) {
            $this->generate();
        }
    }

    /**
     * Reviews all aliases and builds paths if required
     *
     * @param  array  $image Image descriptior
     * @param  string $file  Image path
     * @return void
     */
    public function processAliases($image, $file)
    {
        if (count($image) === 0) {
            return false;
        }

        $app = $this->getContainer();

        foreach ($image['cache']['aliases'] as $alias) {
            // Check for the existance of the alias record
            if (empty($app['thumbnails.aliases'][$alias])) {
                $app['session']->getFlashBag()->set('error', "Invalid cache alias configuration for: {$alias}");
                continue;
            }

            // Build the URL and add it to the hit array
            $this->images[] = [
                'image' => $app['thumbnails.aliases'][$alias],
                'file' => $file,
                'request' => "/thumbs/{$alias}/{$file}",
                'type' => 'alias',
                'alias' => $alias
            ];
        }
    }

    /**
     * Reviews all sizes and builds paths if required
     *
     * @param  [type] $image [description]
     * @param  [type] $file  [description]
     *
     * @return [type]        [description]
     */
    public function processSizes($image, $file)
    {
        if (count($image) === 0) {
            return false;
        }
        var_dump('sizes');
    }

    /**
     * Hits the paths required to generate the thumbnails
     *
     * @return boolean
     */
    public function generate()
    {
        $app = $this->getContainer();

        // Go through each image and generate the thumbnail
        foreach ($this->images as $key => $data) {

            if ($data['type'] === 'alias') {
                $response = $this->alias(
                    $app,
                    $data['file'],
                    $data['alias'],
                    $data['request']
                );
            }
        }
    }

    public function alias(Application $app, $file, $alias, $thumbPath)
    {
        $config = isset($app['thumbnails.aliases'][$alias]) ? $app['thumbnails.aliases'][$alias] : false;

        $width = isset($config['size'][0]) ? $config['size'][0] : 0;
        $height = isset($config['size'][1]) ? $config['size'][1] : 0;
        $action = isset($config['cropping']) ? $config['cropping'] : Action::CROP;

        return $this->serve($app, $file, $thumbPath, $action, $width, $height);
    }

    /**
     * A clone of the Thumbnail generator serve function but without the response redirect
     *
     * @param  Application $app     [description]
     * @param  [type]      $file    [description]
     * @param  [type]      $action  [description]
     * @param  [type]      $width   [description]
     * @param  [type]      $height  [description]
     *
     * @return [type]               [description]
     */
    public function serve(Application $app, $file, $thumbPath, $action, $width, $height)
    {
        if (strpos($file, '@2x') !== false) {
            $file = str_replace('@2x', '', $file);
            $width *= 2;
            $height *= 2;
        }

        $transaction = new Transaction($file, $action, new Dimensions($width, $height), $thumbPath);

        $thumbnail = $app['thumbnails']->respond($transaction);
    }
}
