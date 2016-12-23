<?php

namespace Bolt\Extension\Maelstromeous\ThumbnailWarmer;

use Bolt\Events\StorageEvent;
use Bolt\Events\StorageEvents;
use Bolt\Extension\SimpleExtension;
use Silex\Application;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Local Extension allowing for more custom functionality.
 *
 * @author Matt Cavanagh <maelstrome26@gmail.com>
 */
class ThumbnailWarmerExtension extends SimpleExtension
{
    public static function getSubscribedEvents()
    {
        $parent = parent::getSubscribedEvents();
        $events = [
            StorageEvents::POST_SAVE => [
                ['process', 0]
            ]
        ];

        return $parent + $events;
    }

    public function process(StorageEvent $event)
    {
        $app = $this->getContainer();
        $type = $event->getContenttype();
        $contentType = $app['config']->get("contenttypes/{$type}");

        $fields = $contentType['fields'];
        var_dump($fields);
        // Check to see if we have any images
        foreach ($fields as $key => $field) {
            if ($field['type'] === 'image' && (! empty($field['cache']))) {
                var_dump($field['cache']);
                var_dump("found!");
            }
        }
        var_dump($fields);die;
    }
}
