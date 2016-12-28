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
use Symfony\Component\HttpFoundation\Request;

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

        // If we have images to generate, then let's send them off!
        if (! empty($this->images)) {
            $this->generate();
        }

        die;
    }

    /**
     * Reviews all aliases and builds image data if required
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
                'type' => 'alias',
                'file' => $file,
                'thumbPath' => "/thumbs/{$alias}/{$file}",
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

        foreach ($image['cache']['sizes'] as $sizes) {
            $this->images[] = [
                'type' => 'size',
                'sizes' => $sizes,
                'file' => $file,
                'thumbPath' => "/thumbs/{$alias}/{$file}"
            ];
        }
    }

    /**
     * Starts the generation process
     *
     * @return boolean
     */
    public function generate()
    {
        $app = $this->getContainer();

        $successful = true;

        // Go through each image and generate the thumbnail
        foreach ($this->images as $key => $data) {
            if ($data['type'] === 'alias') {
                $response = $this->generatorAlias(
                    $app,
                    $data['file'],
                    $data['alias'],
                    $data['thumbPath']
                );
            } else {
                $response = $this->generatorSizes(
                    $app,
                    $data['file'],
                    $data['sizes'],
                    $data['thumbPath']
                );
            }
            if ($response === false) {
                $successful = false;
            }
        }

        if ($successful === true) {
            $app['session']->getFlashBag()->set('success', 'Thumbnails successfully cached. If you didn\'t notice any changes, please clear your thumbnail cache and save the record again.');
        } else {
            $app['session']->getFlashBag()->set('error', 'There was an error in generating the thumbnails. Please check your contenttypes.yml, your theme.yml file and ensure the original file exists.');
        }
    }

    /**
     * Builds the correct information for the Thumbnail Generator Alias function
     *
     * @param  Application $app       Bolt Application
     * @param  string      $file      Location of the original file
     * @param  string      $alias     The alias of the image
     * @param  string      $thumbPath The path of the generated thumbnail
     *
     * @return void
     */
    public function generatorAlias(Application $app, $file, $alias, $thumbPath)
    {
        $controller = new Controller();
        $request = new Request; // "Spoof" a request. I feel dirty.

        // Set the request path which the Thumbnail Generator uses to build a filepath
        $request->server->set('REQUEST_URI', $thumbPath);

        // Send the thumbnail to the generator
        $response = $controller->alias(
            $app,
            $request,
            $file,
            $alias
        );

        if ($response->getStatusCode() !== 200 ||
            $response->getThumbnail()->getImage()->getPath() === 'view/img/default_notfound.png') {
            return false;
        }

        return true;
    }

    /**
     * Builds the correct information for the Thumbnail Generator Sizes function
     *
     * @param  Application $app       Bolt Application
     * @param  string      $file      Location of the original file
     * @param  string      $sizes     Dimensions of the image
     * @param  string      $thumbPath The path of the generated thumbnail
     *
     * @return void
     */
    public function generatorSizes(Application $app, $file, $sizes, $action, $thumbPath)
    {
        $controller = new Controller();
        $request = new Request; // "Spoof" a request. I feel dirty.

        // Set the request path which the Thumbnail Generator uses to build a filepath
        $request->server->set('REQUEST_URI', $thumbPath);

        // Figure out action based off 3rd property from the cache config
        $actions = [
            'c' => Action::CROP,
            'r' => Action::RESIZE,
            'b' => Action::BORDER,
            'f' => Action::FIT,
        ];

        $action = Action::CROP;

        if (! empty($sizes[2])) {
            if (array_key_exists($sizes[2], $actions)) {
                $action = $actions[$sizes[2]];
            }
        }

        // Send the thumbnail to the generator
        $response = $controller->thumbnail(
            $app,
            $request,
            $file,
            $action,
            $sizes[0], // Width
            $sizes[1] // Height
        );

        if ($response->getStatusCode() !== 200 ||
            $response->getThumbnail()->getImage()->getPath() === 'view/img/default_notfound.png') {
            return false;
        }

        return true;
    }
}
