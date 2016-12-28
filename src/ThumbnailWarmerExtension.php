<?php

namespace Bolt\Extension\Maelstromeous\ThumbnailWarmer;

use Bolt\Events\StorageEvent;
use Bolt\Events\StorageEvents;
use Bolt\Extension\SimpleExtension;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise;
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
    protected $pathsToHit = [];

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

                var_dump($image);
                var_dump($file);

                if (! empty($image['cache']['aliases'])) {
                    $this->processAliases($image, $file);
                }
                if (! empty($image['cache']['sizes'])) {
                    $this->processSizes($image, $file);
                }
            }
        }

        if (! empty($this->pathsToHit)) {
            $this->hitPaths();
        }
        die;
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
            $this->pathsToHit[] = $this->basePath . "/thumbs/{$alias}/{$file}";
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
     * Hits the paths requires to generate the thumbnails
     *
     * @return boolean
     */
    public function hitPaths()
    {
        $client = $this->getContainer()['guzzle.client'];

        $promises = [];

        // Build Async request with Guzzle
        foreach ($this->pathsToHit as $key => $path) {
            $promises[] = $client->getAsync($path);
        }

        // Trigger the paths and generate the thumbs
        try {
            $results = Promise\unwrap($promises);
            $this->getContainer()['session']->getFlashBag()->set(
                'success',
                'Thumbnails were successfully cached.'
            );
        } catch (RequestException $e) {
            $this->getContainer()['session']->getFlashBag()->set(
                'error',
                "There was an error in caching your thumbnails. Error reported was: {$e->getMessage()}"
            );
        }

        var_dump($results);
    }
}
