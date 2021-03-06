# Bolt Thumbnail Cache / Warmer Extension

This extension is a work in progress. DO **NOT** use in production!

This extension only caches thumbnails upon new records and newly saved / edited records. It *won't* go through all of your current records and cache them.

## Usage

Edit your `contenttypes.yml` file on any `image` type using the below as a guide:
```
image:
    type: image
    upload: test
    cache:
        aliases: ['some-alias', 'some-other-alias']
        sizes: [ [900, 500], [500, 300] ]
```
Note the new `cache` property.

## Using aliases
As of Bolt 3.2, you're now able to define sizes using Aliases. For more information, please see the Bolt documentation on the matter: https://docs.bolt.cm/3.2/configuration/thumbnails#thumbnail-aliases

Example:

```
cache:
    aliases: ['some-alias', 'some-other-alias']
```

This extension will simply use the aliases defined in the `theme.yml` file and pre-cache them on your behalf.

## Using sizes
If you don't wish to use aliases, then you're able to define the sizes yourself.

Example:
```
cache:
    sizes: [ [1000, 500] ]
```
Will generate a single thumbnail for your image at the size of 1000x500px.

You can provide multiple arrays to generate a number of thumbnails at various resolutions.
